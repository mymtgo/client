using System.Text.Json;
using MyMtgo.Sidecar.Core.Status;

namespace MyMtgo.Sidecar.Core.Tests;

public class StatusWriterTest : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "sidecar-status-" + Guid.NewGuid().ToString("N"));
    private readonly FakeClock _clock = new(new DateTimeOffset(2026, 8, 5, 12, 35, 2, 0, TimeSpan.Zero));

    public StatusWriterTest() => Directory.CreateDirectory(_dir);
    public void Dispose() => Directory.Delete(_dir, recursive: true);

    private SidecarStatus Fixture() => new(
        Pid: 4242, State: SidecarState.Attached, MtgoVersion: "1.2.3.4", SdkVersion: "3.0.0", SidecarVersion: "1.0.0",
        AttachedAt: new DateTimeOffset(2026, 8, 5, 11, 20, 47, 0, TimeSpan.Zero), LastEventSeq: 30,
        CurrentFile: "events-aaaaaaaa-0000-0000-0000-000000000001.ndjson", Heartbeat: _clock.UtcNow, LastError: null);

    [Fact]
    public void Status_json_matches_the_php_fixture_exactly()
    {
        var expected = "{\"pid\":4242,\"state\":\"attached\",\"mtgo_version\":\"1.2.3.4\",\"sdk_version\":\"3.0.0\",\"sidecar_version\":\"1.0.0\",\"attached_at\":\"2026-08-05T11:20:47.000Z\",\"last_event_seq\":30,\"current_file\":\"events-aaaaaaaa-0000-0000-0000-000000000001.ndjson\",\"heartbeat\":\"2026-08-05T12:35:02.000Z\",\"last_error\":null}";
        Assert.Equal(expected, Fixture().ToJson());
    }

    [Theory]
    [InlineData(SidecarState.Waiting, "waiting")]
    [InlineData(SidecarState.Attached, "attached")]
    [InlineData(SidecarState.Degraded, "degraded")]
    [InlineData(SidecarState.VersionBlocked, "version_blocked")]
    [InlineData(SidecarState.Error, "error")]
    public void State_wire_names(SidecarState state, string wire) => Assert.Equal(wire, SidecarStateNames.ToWire(state));

    [Fact]
    public async Task WriteNow_is_atomic_and_leaves_no_temp_file()
    {
        await using var w = new StatusWriter(_dir, _clock, TimeSpan.FromSeconds(2));
        w.Update(_ => Fixture());
        w.WriteNow();

        Assert.Equal(["status.json"], Directory.GetFiles(_dir).Select(Path.GetFileName).Order());
        var doc = JsonDocument.Parse(File.ReadAllText(Path.Combine(_dir, "status.json")));
        Assert.Equal("attached", doc.RootElement.GetProperty("state").GetString());
    }

    [Fact]
    public async Task Update_writes_immediately_and_refreshes_heartbeat()
    {
        await using var w = new StatusWriter(_dir, _clock, TimeSpan.FromSeconds(2));
        w.Update(s => s with { State = SidecarState.Waiting });
        _clock.Advance(TimeSpan.FromSeconds(7));
        w.Update(s => s with { State = SidecarState.Error, LastError = "boom" });

        var doc = JsonDocument.Parse(File.ReadAllText(Path.Combine(_dir, "status.json")));
        Assert.Equal("error", doc.RootElement.GetProperty("state").GetString());
        Assert.Equal("boom", doc.RootElement.GetProperty("last_error").GetString());
        Assert.Equal("2026-08-05T12:35:09.000Z", doc.RootElement.GetProperty("heartbeat").GetString());
    }

    [Fact]
    public async Task Timer_rewrites_heartbeat_without_a_state_change()
    {
        await using var w = new StatusWriter(_dir, _clock, TimeSpan.FromMilliseconds(50));
        w.Update(_ => Fixture());
        w.Start();
        var first = File.ReadAllText(Path.Combine(_dir, "status.json"));
        _clock.Advance(TimeSpan.FromSeconds(3));

        var deadline = DateTime.UtcNow + TimeSpan.FromSeconds(2);
        string second;
        while (true)
        {
            second = File.ReadAllText(Path.Combine(_dir, "status.json"));
            if (second != first || DateTime.UtcNow >= deadline)
            {
                break;
            }

            await Task.Delay(20);
        }

        Assert.NotEqual(first, second);
        Assert.Contains("\"heartbeat\":\"2026-08-05T12:35:05.000Z\"", second);
    }

    [Fact]
    public async Task A_stale_temp_file_from_a_crash_is_overwritten_not_fatal()
    {
        File.WriteAllText(Path.Combine(_dir, "status.json.tmp"), "garbage");
        await using var w = new StatusWriter(_dir, _clock, TimeSpan.FromSeconds(2));
        w.Update(_ => Fixture());
        w.WriteNow();
        Assert.Equal(["status.json"], Directory.GetFiles(_dir).Select(Path.GetFileName).Order());
    }
}
