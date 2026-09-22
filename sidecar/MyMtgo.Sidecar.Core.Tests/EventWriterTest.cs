using Microsoft.Extensions.Logging;
using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Tests;

/// <summary>Collects formatted warnings so a test can count them.</summary>
public sealed class CapturingLogger : ILogger
{
    public List<string> Warnings { get; } = [];

    public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

    public bool IsEnabled(LogLevel logLevel) => true;

    public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
    {
        if (logLevel == LogLevel.Warning)
        {
            Warnings.Add(formatter(state, exception));
        }
    }
}

public sealed class FakeClock(DateTimeOffset start) : IClock
{
    public DateTimeOffset UtcNow { get; set; } = start;
    public void Advance(TimeSpan by) => UtcNow += by;
}

public class EventWriterTest : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "sidecar-writer-" + Guid.NewGuid().ToString("N"));
    private readonly FakeClock _clock = new(new DateTimeOffset(2026, 8, 5, 11, 20, 47, 0, TimeSpan.Zero));
    private readonly List<Exception> _errors = [];

    public EventWriterTest() => Directory.CreateDirectory(_dir);
    public void Dispose() => Directory.Delete(_dir, recursive: true);

    private EventWriter NewWriter(Func<bool>? verified = null, ILogger? log = null) =>
        new(_dir, "aaaaaaaa-0000-0000-0000-000000000001", _clock.UtcNow, verified ?? (() => true), _clock, _errors.Add, log);

    private static PendingEvent Ev(string type) => new(type, null, null, new Dictionary<string, object?>(), null);

    [Fact]
    public async Task Names_file_after_session_and_numbers_seq_from_one()
    {
        var w = NewWriter();
        w.Enqueue(Ev(EventTypes.SessionStarted));
        w.Enqueue(Ev(EventTypes.Probe));
        await w.CompleteAsync();

        Assert.Equal("events-aaaaaaaa-0000-0000-0000-000000000001.ndjson", w.FileName);
        var lines = File.ReadAllLines(Path.Combine(_dir, w.FileName));
        Assert.Equal(2, lines.Length);
        Assert.Contains("\"seq\":1,", lines[0]);
        Assert.Contains("\"seq\":2,", lines[1]);
        Assert.Equal(2, w.LastSeq);
        Assert.Empty(_errors);
    }

    [Fact]
    public async Task Each_line_is_flushed_before_the_next_is_accepted_as_written()
    {
        var w = NewWriter();
        w.Enqueue(Ev(EventTypes.SessionStarted));
        await w.WaitForIdleAsync(TimeSpan.FromSeconds(5));

        // Read while the writer still holds the file open: the line must already be on disk.
        using var fs = new FileStream(Path.Combine(_dir, w.FileName), FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
        using var reader = new StreamReader(fs);
        var text = await reader.ReadToEndAsync();
        Assert.EndsWith("\n", text);
        Assert.Single(text.TrimEnd('\n').Split('\n'));
        await w.CompleteAsync();
    }

    [Fact]
    public async Task Seq_is_gap_free_under_concurrent_enqueue()
    {
        var w = NewWriter();
        await Task.WhenAll(Enumerable.Range(0, 8).Select(_ => Task.Run(() =>
        {
            for (var i = 0; i < 250; i++) w.Enqueue(Ev(EventTypes.LifeChanged));
        })));
        await w.CompleteAsync();

        var seqs = File.ReadAllLines(Path.Combine(_dir, w.FileName))
            .Select(l => long.Parse(System.Text.RegularExpressions.Regex.Match(l, "\"seq\":(\\d+)").Groups[1].Value))
            .ToList();
        Assert.Equal(Enumerable.Range(1, 2000).Select(i => (long)i), seqs);
    }

    [Fact]
    public async Task Stamps_verified_and_ts_at_enqueue_time()
    {
        var verified = true;
        var w = NewWriter(() => verified);
        w.Enqueue(Ev(EventTypes.Probe));
        verified = false;
        _clock.Advance(TimeSpan.FromMilliseconds(250));
        w.Enqueue(Ev(EventTypes.CardTapped));
        await w.CompleteAsync();

        var lines = File.ReadAllLines(Path.Combine(_dir, w.FileName));
        Assert.Contains("\"ts\":\"2026-08-05T11:20:47.000Z\"", lines[0]);
        Assert.Contains("\"verified\":true", lines[0]);
        Assert.Contains("\"ts\":\"2026-08-05T11:20:47.250Z\"", lines[1]);
        Assert.Contains("\"verified\":false", lines[1]);
    }

    [Fact]
    public async Task Write_failure_is_reported_and_the_writer_keeps_running()
    {
        var w = NewWriter();
        w.Enqueue(Ev(EventTypes.SessionStarted));
        await w.WaitForIdleAsync(TimeSpan.FromSeconds(5));

        w.SimulateWriteFailureOnce(new IOException("disk full"));
        w.Enqueue(Ev(EventTypes.Probe));
        w.Enqueue(Ev(EventTypes.LifeChanged));
        await w.CompleteAsync();

        Assert.Single(_errors);
        Assert.IsType<IOException>(_errors[0]);
        // seq 2 was lost to the fault, seq 3 landed; PHP tolerates gaps (seq is only an idempotency key).
        var lines = File.ReadAllLines(Path.Combine(_dir, w.FileName));
        Assert.Equal(2, lines.Length);
        Assert.Contains("\"seq\":3,", lines[1]);
    }

    [Fact]
    public async Task Events_enqueued_after_completion_are_dropped_and_warned_about_once()
    {
        var log = new CapturingLogger();
        var w = NewWriter(log: log);
        w.Enqueue(Ev(EventTypes.SessionStarted));
        await w.CompleteAsync();
        Assert.Equal(1, w.LastSeq);

        // A callback still in flight across teardown. The channel is closed, so these can never
        // reach the file; burning seq numbers on them would look like write failures to PHP.
        w.Enqueue(Ev(EventTypes.Probe));
        w.Enqueue(Ev(EventTypes.LifeChanged));
        w.Enqueue(Ev(EventTypes.CardTapped));

        Assert.Equal(1, w.LastSeq);
        Assert.Equal(3, w.Dropped);
        Assert.Single(log.Warnings);
        Assert.Empty(_errors);
        Assert.Single(File.ReadAllLines(Path.Combine(_dir, w.FileName)));
    }

    [Fact]
    public async Task Writes_utf8_without_bom()
    {
        var w = NewWriter();
        w.Enqueue(Ev(EventTypes.SessionStarted));
        await w.CompleteAsync();
        var bytes = File.ReadAllBytes(Path.Combine(_dir, w.FileName));
        Assert.Equal((byte)'{', bytes[0]);
    }
}
