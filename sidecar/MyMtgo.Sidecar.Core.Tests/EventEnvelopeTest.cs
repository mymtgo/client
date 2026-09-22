using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Tests;

public class EventEnvelopeTest
{
    [Fact]
    public void Serialises_in_exact_key_order_with_nulls_for_absent_ids()
    {
        var env = new EventEnvelope(
            Session: "aaaaaaaa-0000-0000-0000-000000000001",
            SessionStartedAt: new DateTimeOffset(2026, 8, 5, 11, 20, 47, 0, TimeSpan.Zero),
            Seq: 2,
            Ts: new DateTimeOffset(2026, 8, 5, 11, 20, 47, 200, TimeSpan.Zero),
            Type: EventTypes.Probe,
            Game: null,
            Match: null,
            Verified: true,
            Data: new Dictionary<string, object?>
            {
                ["passed"] = true,
                ["username"] = "local.player",
                ["checks"] = new Dictionary<string, object?> { ["username"] = true, ["card_names"] = true, ["battlefield_cards"] = true },
            },
            Ref: null);

        var expected = "{\"v\":1,\"session\":\"aaaaaaaa-0000-0000-0000-000000000001\",\"session_started_at\":\"2026-08-05T11:20:47.000Z\",\"seq\":2,\"ts\":\"2026-08-05T11:20:47.200Z\",\"type\":\"probe\",\"game\":null,\"match\":null,\"verified\":true,\"data\":{\"passed\":true,\"username\":\"local.player\",\"checks\":{\"username\":true,\"card_names\":true,\"battlefield_cards\":true}},\"ref\":null}";

        Assert.Equal(expected, env.ToJsonLine().TrimEnd('\n'));
        Assert.EndsWith("\n", env.ToJsonLine());
    }

    [Fact]
    public void Ref_and_ids_are_emitted_when_present()
    {
        var env = new EventEnvelope("s", DateTimeOffset.UnixEpoch, 9, DateTimeOffset.UnixEpoch, EventTypes.CardTapped,
            Game: "958291826", Match: "288955358", Verified: false,
            Data: new Dictionary<string, object?> { ["c"] = "445" },
            Ref: new Dictionary<string, object?> { ["thing"] = 445 });

        Assert.Contains("\"game\":\"958291826\",\"match\":\"288955358\",\"verified\":false,\"data\":{\"c\":\"445\"},\"ref\":{\"thing\":445}}", env.ToJsonLine());
    }

    [Fact]
    public void Timestamp_format_is_utc_millis_with_z()
    {
        var ts = new DateTimeOffset(2026, 9, 21, 14, 3, 22, 418, TimeSpan.FromHours(2));
        Assert.Equal("2026-09-21T12:03:22.418Z", Timestamps.Format(ts));
    }
}
