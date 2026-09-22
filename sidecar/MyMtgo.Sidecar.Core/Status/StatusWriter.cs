using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Status;

/// <summary>
/// Owns status.json. Every Update writes immediately; a timer rewrites on an interval so the
/// heartbeat keeps moving while nothing changes. Writes go to status.json.tmp then File.Move
/// with overwrite, so a reader never sees a half-written file.
/// </summary>
public sealed class StatusWriter : IAsyncDisposable
{
    private readonly string _path;
    private readonly string _tmpPath;
    private readonly IClock _clock;
    private readonly TimeSpan _interval;
    private readonly object _gate = new();
    private readonly CancellationTokenSource _cts = new();
    private Task? _timer;
    private SidecarStatus _current;

    public StatusWriter(string directory, IClock clock, TimeSpan interval)
    {
        _path = Path.Combine(directory, "status.json");
        _tmpPath = _path + ".tmp";
        _clock = clock;
        _interval = interval;
        _current = SidecarStatus.Initial(Environment.ProcessId, "unknown", "0.0.0", clock.UtcNow);
    }

    public SidecarStatus Current
    {
        get
        {
            lock (_gate)
            {
                return _current;
            }
        }
    }

    public void Update(Func<SidecarStatus, SidecarStatus> mutate)
    {
        lock (_gate)
        {
            _current = mutate(_current) with { Heartbeat = _clock.UtcNow };
        }

        WriteNow();
    }

    public void WriteNow()
    {
        // The whole write happens under the lock: Update() runs on SDK callback threads and the
        // timer runs on its own, and both share one temp path. A status write that fails is
        // logged nowhere on purpose (this class has no logger); the next tick simply retries.
        lock (_gate)
        {
            _current = _current with { Heartbeat = _clock.UtcNow };
            var json = _current.ToJson();
            try
            {
                File.WriteAllText(_tmpPath, json, new System.Text.UTF8Encoding(false));
                File.Move(_tmpPath, _path, overwrite: true);
            }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
    }

    public void Start()
    {
        _timer ??= Task.Run(async () =>
        {
            while (!_cts.IsCancellationRequested)
            {
                try
                {
                    await Task.Delay(_interval, _cts.Token);
                }
                catch (OperationCanceledException)
                {
                    return;
                }

                try
                {
                    WriteNow();
                }
                catch (IOException)
                {
                    // transient; next tick retries
                }
            }
        });
    }

    public async ValueTask DisposeAsync()
    {
        _cts.Cancel();
        if (_timer is not null)
        {
            try
            {
                await _timer;
            }
            catch (OperationCanceledException)
            {
            }
        }

        _cts.Dispose();
    }
}
