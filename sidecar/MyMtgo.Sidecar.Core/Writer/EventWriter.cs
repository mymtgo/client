using System.Text;
using System.Threading.Channels;
using Microsoft.Extensions.Logging;
using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Writer;

/// <summary>
/// Single consumer over an unbounded channel. Producers (SDK callbacks on SyncThread workers)
/// call Enqueue; the consumer assigns seq, serialises and appends with a flush per line.
/// ts and verified are captured at enqueue so they reflect the moment the SDK saw the change,
/// not when the disk caught up.
/// </summary>
public sealed class EventWriter : IAsyncDisposable
{
    private readonly Channel<EventEnvelope> _channel = Channel.CreateUnbounded<EventEnvelope>(new UnboundedChannelOptions { SingleReader = true });
    private readonly string _path;
    private readonly string _session;
    private readonly DateTimeOffset _sessionStartedAt;
    private readonly Func<bool> _verified;
    private readonly IClock _clock;
    private readonly Action<Exception> _onError;
    private readonly ILogger? _log;
    private readonly Task _pump;
    private readonly object _seqLock = new();
    private long _seq;
    private long _written;
    private long _dropped;
    private bool _dropWarned;
    private Exception? _injectedFault;

    public EventWriter(
        string directory,
        string session,
        DateTimeOffset sessionStartedAt,
        Func<bool> verified,
        IClock clock,
        Action<Exception> onError,
        ILogger? log = null)
    {
        _session = session;
        _sessionStartedAt = sessionStartedAt;
        _verified = verified;
        _clock = clock;
        _onError = onError;
        _log = log;
        FileName = $"events-{session}.ndjson";
        _path = Path.Combine(directory, FileName);
        _pump = Task.Run(PumpAsync);
    }

    public string FileName { get; }

    /// <summary>Highest seq handed out so far (not necessarily flushed yet).</summary>
    public long LastSeq
    {
        get { lock (_seqLock) { return _seq; } }
    }

    /// <summary>Events refused because the channel was already completed. Test seam.</summary>
    internal long Dropped
    {
        get { lock (_seqLock) { return _dropped; } }
    }

    public void Enqueue(PendingEvent e)
    {
        // seq assignment and the channel write must happen atomically together, otherwise two
        // producers can race between Increment and TryWrite and the file ends up with seq out
        // of order (e.g. 35 landing before 34). Locking both under the same section keeps the
        // channel's delivery order equal to seq order, which is the contract: seq is monotonic
        // per file with no gaps other than the ones write failures explicitly cause.
        lock (_seqLock)
        {
            var seq = _seq + 1;
            var env = new EventEnvelope(_session, _sessionStartedAt, seq, _clock.UtcNow, e.Type, e.Game, e.Match, _verified(), e.Data, e.Ref);
            if (!_channel.Writer.TryWrite(env))
            {
                // The channel is completed, so the session is tearing down and this event will
                // never reach the file. seq must not advance for it: seq is the idempotency key
                // PHP pairs with the file, and burning one here would look like a write failure
                // that never happened. Warn once, because teardown can race a whole callback's
                // worth of events and a line each would bury everything else in the log.
                _dropped++;
                if (!_dropWarned)
                {
                    _dropWarned = true;
                    _log?.LogWarning("event {Type} arrived after the writer closed; dropping it and any that follow", e.Type);
                }

                return;
            }

            _seq = seq;
        }
    }

    /// <summary>Test seam: the next append throws this once.</summary>
    internal void SimulateWriteFailureOnce(Exception fault) => _injectedFault = fault;

    /// <summary>Waits until every enqueued line so far has been appended and flushed.</summary>
    public async Task WaitForIdleAsync(TimeSpan timeout)
    {
        var sw = System.Diagnostics.Stopwatch.StartNew();
        var target = LastSeq;
        while (Interlocked.Read(ref _written) < target)
        {
            if (sw.Elapsed > timeout) throw new TimeoutException("writer did not drain");
            await Task.Delay(10);
        }
    }

    public async Task CompleteAsync()
    {
        _channel.Writer.TryComplete();
        await _pump;
    }

    public ValueTask DisposeAsync() => new(CompleteAsync());

    private async Task PumpAsync()
    {
        await using var stream = new FileStream(_path, FileMode.Append, FileAccess.Write, FileShare.Read, bufferSize: 1, FileOptions.WriteThrough);
        var utf8 = new UTF8Encoding(encoderShouldEmitUTF8Identifier: false);

        await foreach (var env in _channel.Reader.ReadAllAsync())
        {
            try
            {
                if (_injectedFault is { } fault)
                {
                    _injectedFault = null;
                    throw fault;
                }

                var bytes = utf8.GetBytes(env.ToJsonLine());
                await stream.WriteAsync(bytes);
                await stream.FlushAsync();
            }
            catch (Exception ex)
            {
                _onError(ex);
            }
            finally
            {
                Interlocked.Exchange(ref _written, env.Seq);
            }
        }
    }
}
