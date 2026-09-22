using System.Reflection;
using Microsoft.Extensions.Logging;
using MTGOSDK.API;
using MTGOSDK.Core.Remoting;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// Owns the SDK Client for one attach. Never launches MTGO (CreateProcess false) and never
/// closes it (CloseOnExit false). Dispose tears down the SDK's static RemoteClient, which is
/// what lets the next attach start clean.
/// </summary>
public sealed class SdkAttachment : IDisposable
{
    private static readonly string s_sdkVersion = ReadSdkVersion();

    private readonly Client _client;
    private readonly ILogger _log;
    private readonly EventHandler _onProcessExited;
    private volatile bool _disposed;

    private SdkAttachment(Client client, ILogger log)
    {
        _client = client;
        _log = log;

        // IsConnectedChanged is a non-generic EventProxy field, so the handler takes one
        // object? sender and no args (see examples/BasicBot in the SDK repo).
        _client.IsConnectedChanged += delegate (object? _)
        {
            if (!IsConnected)
            {
                RaiseDisconnected();
            }
        };

        _onProcessExited = (_, _) => RaiseDisconnected();
        RemoteClient.ProcessExited += _onProcessExited;
    }

    /// <summary>Raised when the SDK loses the client session or the MTGO process exits.</summary>
    public event Action? Disconnected;

    /// <summary>MTGO's own file version, read from the installed MTGO.exe.</summary>
    public string MtgoVersion => Client.Version.ToString();

    /// <summary>
    /// The MTGOSDK package version. The assembly version is stamped Major.0.0.0 by the SDK's
    /// MinVer setup, so the informational version is the only reading that identifies the build.
    /// </summary>
    public static string SdkVersionString => s_sdkVersion;

    /// <inheritdoc cref="SdkVersionString"/>
    public string SdkVersion => s_sdkVersion;

    /// <summary>Logged-in user, or null while the login screen is up.</summary>
    public string? Username
    {
        get
        {
            try
            {
                return _client.IsLoggedIn ? _client.CurrentUser.Name : null;
            }
            catch (Exception ex)
            {
                _log.LogDebug(ex, "username read failed");
                return null;
            }
        }
    }

    public bool IsConnected
    {
        get
        {
            try
            {
                return _client.IsConnected;
            }
            catch (Exception ex)
            {
                _log.LogDebug(ex, "connection state read failed");
                return false;
            }
        }
    }

    public static bool MtgoIsRunning() => Client.HasStarted;

    public static async Task<SdkAttachment> AttachAsync(ILoggerFactory loggerFactory, CancellationToken ct)
    {
        var log = loggerFactory.CreateLogger("Attach");
        var options = new ClientOptions { CreateProcess = false, CloseOnExit = false };

        // Client's constructor injects into the running MTGO process and can take several seconds.
        var client = await Task.Run(() => new Client(options, loggerFactory: loggerFactory), ct);
        try
        {
            // Fixed 500ms x 60 inside the SDK. Reading any property before this returns throws.
            if (!await client.WaitForClientReady())
            {
                throw new TimeoutException("MTGO client did not become ready within the SDK's readiness window");
            }
        }
        catch
        {
            try
            {
                client.Dispose();
            }
            catch (Exception disposeFailure)
            {
                log.LogWarning(disposeFailure, "client dispose failed after an incomplete attach");
            }

            throw;
        }

        log.LogInformation("attached to MTGO {Version} with SDK {SdkVersion}", Client.Version, s_sdkVersion);
        return new SdkAttachment(client, log);
    }

    public void Dispose()
    {
        _disposed = true;
        RemoteClient.ProcessExited -= _onProcessExited;

        // The EventProxy subscription is not removed: Client.Dispose tears down the whole static
        // RemoteClient, which drops the remote handler with it. _disposed guards the callback in
        // case one is already in flight.
        try
        {
            _client.Dispose();
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "client dispose failed");
        }
    }

    private void RaiseDisconnected()
    {
        if (_disposed)
        {
            return;
        }

        Disconnected?.Invoke();
    }

    private static string ReadSdkVersion()
    {
        var assembly = typeof(Client).Assembly;

        var informational = assembly.GetCustomAttribute<AssemblyInformationalVersionAttribute>()?.InformationalVersion;
        if (!string.IsNullOrWhiteSpace(informational))
        {
            var plus = informational.IndexOf('+');
            return plus >= 0 ? informational[..plus] : informational;
        }

        var file = assembly.GetCustomAttribute<AssemblyFileVersionAttribute>()?.Version;
        if (!string.IsNullOrWhiteSpace(file))
        {
            return file;
        }

        return assembly.GetName().Version?.ToString() ?? "unknown";
    }
}
