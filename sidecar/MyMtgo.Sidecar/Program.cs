using Microsoft.Extensions.Logging;
using MTGOSDK.Core.Exceptions;
using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Gate;
using MyMtgo.Sidecar.Core.Host;
using MyMtgo.Sidecar.Core.Logging;
using MyMtgo.Sidecar.Core.Status;
using MyMtgo.Sidecar.Sdk;

SidecarOptions options;
try
{
    options = SidecarOptions.Parse(args);
}
catch (ArgumentException ex)
{
    Console.Error.WriteLine(ex.Message);
    Console.Error.WriteLine("usage: mymtgo-helper --out <dir> --parent-pid <pid> [--config <https url>] [--probe-interval <seconds>]");
    return 2;
}

try
{
    Directory.CreateDirectory(options.OutDir);
}
catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or ArgumentException or NotSupportedException)
{
    Console.Error.WriteLine($"cannot create --out directory {options.OutDir}: {ex.Message}");
    return 3;
}
var sidecarVersion = typeof(SdkAttachment).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";
using var loggerFactory = LoggerFactory.Create(b => b.AddProvider(new RollingFileLoggerProvider(Path.Combine(options.OutDir, "sidecar.log"))));
var log = loggerFactory.CreateLogger("Supervisor");
var clock = SystemClock.Instance;

using var shutdown = new CancellationTokenSource();
Console.CancelKeyPress += (_, e) =>
{
    e.Cancel = true;
    RequestShutdown();
};
var parentWatch = ParentWatch.RunAsync(options.ParentPid, ParentWatch.ProcessIsAlive, TimeSpan.FromSeconds(1), shutdown.Token);
_ = parentWatch.ContinueWith(
    _ =>
    {
        log.LogInformation("parent {Pid} exited, shutting down", options.ParentPid);
        RequestShutdown();
        // Hard watchdog: the SDK's attach and readiness wait cannot be cancelled, so a parent
        // death during those could otherwise keep this process alive for tens of seconds.
        // The spec allows 5 seconds; the 1 s poll can already have spent one, so 3 s here.
        // Exit code 0: this is the normal orphan path.
        _ = Task.Delay(TimeSpan.FromSeconds(3)).ContinueWith(__ =>
        {
            log.LogWarning("shutdown did not complete within 3 s of parent exit, exiting hard");
            Environment.Exit(0);
        });
    },
    TaskContinuationOptions.OnlyOnRanToCompletion);
_ = parentWatch.ContinueWith(
    t =>
    {
        // Fail safe, not open: a faulted watch must not leave the sidecar orphaned on the user's MTGO.
        log.LogError(t.Exception, "parent watch faulted, shutting down");
        RequestShutdown();
    },
    TaskContinuationOptions.OnlyOnFaulted);

await using var status = new StatusWriter(options.OutDir, clock, TimeSpan.FromSeconds(2));
status.Update(_ => SidecarStatus.Initial(Environment.ProcessId, SdkAttachment.SdkVersionString, sidecarVersion, clock.UtcNow));
status.Start();
log.LogInformation("sidecar {Version} started, out={Out}, parent={Pid}, config={Config}", sidecarVersion, options.OutDir, options.ParentPid, options.ConfigUrl is null ? "none" : "set");

// A session has to live this long before the backoff is forgiven. Resetting on a successful
// attach alone would let a connection that dies moments after attaching loop at one second
// forever, and every loop mints a fresh events-{session}.ndjson and a fresh PHP LogInstance.
var healthySession = TimeSpan.FromSeconds(60);
var backoff = TimeSpan.FromSeconds(1);
var gate = new VersionGate(options.OutDir, handler: null, clock, loggerFactory.CreateLogger("Gate"));

while (!shutdown.IsCancellationRequested)
{
    if (!SdkAttachment.MtgoIsRunning())
    {
        status.Update(s => s with { State = SidecarState.Waiting, LastError = null });
        if (!await Wait(backoff))
        {
            break;
        }

        backoff = Grow(backoff);
        continue;
    }

    SdkAttachment? attachment = null;
    SessionRunner? session = null;
    DateTimeOffset? attachedAt = null;
    try
    {
        attachment = await SdkAttachment.AttachAsync(loggerFactory, shutdown.Token);
        attachedAt = clock.UtcNow;

        var decision = await gate.DecideAsync(options.ConfigUrl, attachment.MtgoVersion, shutdown.Token);
        session = new SessionRunner(options, attachment, status, clock, loggerFactory, blocked: !decision.Allowed);
        log.LogInformation("version {Version} gate={Source} allowed={Allowed}", attachment.MtgoVersion, decision.Source, decision.Allowed);

        await session.RunUntilDetachedAsync(shutdown.Token);
    }
    catch (OperationCanceledException) when (shutdown.IsCancellationRequested)
    {
        break;
    }
    catch (Exception ex) when (ex is ProcessCrashedException or ServerOfflineException or SetupFailureException or ExternalErrorException or TimeoutException)
    {
        log.LogWarning(ex, "attach failed or connection lost");
        status.Update(s => s with { State = SidecarState.Error, LastError = ex.GetType().Name + ": " + ex.Message });
    }
    catch (Exception ex)
    {
        log.LogError(ex, "unexpected failure in session");
        status.Update(s => s with { State = SidecarState.Error, LastError = ex.GetType().Name + ": " + ex.Message });
    }
    finally
    {
        if (session is not null)
        {
            await session.DisposeAsync();
        }

        attachment?.Dispose();

        if (attachedAt is { } since && clock.UtcNow - since >= healthySession)
        {
            backoff = TimeSpan.FromSeconds(1);
        }
    }

    if (!await Wait(backoff))
    {
        break;
    }

    backoff = Grow(backoff);
}

status.Update(s => s with { State = SidecarState.Waiting, AttachedAt = null, CurrentFile = null });
log.LogInformation("sidecar exiting");
return 0;

async Task<bool> Wait(TimeSpan delay)
{
    try
    {
        await Task.Delay(delay, shutdown.Token);
        return true;
    }
    catch (OperationCanceledException)
    {
        return false;
    }
}

static TimeSpan Grow(TimeSpan current) => current >= TimeSpan.FromSeconds(30) ? TimeSpan.FromSeconds(30) : current * 2;

// Background callbacks can outlive the using scope of the CTS; a cancel after dispose is harmless.
void RequestShutdown()
{
    try
    {
        shutdown.Cancel();
    }
    catch (ObjectDisposedException)
    {
    }
}
