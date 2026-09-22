using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Model;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Tests;

public class GameRecorderTest
{
    private readonly List<PendingEvent> _out = [];
    private GameRecorder NewRecorder(int gameNumber = 1) => new("958291826", "288955358", gameNumber, _out.Add);
    private static readonly PlayerState P0 = Snap.Player(0, "local.player", active: true, priority: true);
    private static readonly PlayerState P1 = Snap.Player(1, "Opp_Name");
    private string Types() => string.Join(",", _out.Select(e => e.Type));

    [Fact]
    public void First_snapshot_emits_game_started_then_a_game_start_keyframe()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(0, "PreGame1", 0, 0, [P0, P1]));

        Assert.Equal("game_started,keyframe", Types());
        Assert.Equal("{\"players\":[{\"p\":0,\"name\":\"local.player\",\"on_play\":true},{\"p\":1,\"name\":\"Opp_Name\",\"on_play\":false}],\"game_number\":1}", Snap.Json(_out[0].Data));
        Assert.Equal("game_start", _out[1].Data["trigger"]);
        Assert.True(r.Started);
        Assert.Equal(["local.player", "Opp_Name"], r.PlayerNames);
    }

    [Fact]
    public void Snapshot_past_turn_one_is_a_reattach_with_unknown_on_play()
    {
        var r = NewRecorder(2);
        r.OnSnapshot(Snap.Game(5, "Draw", 1, 1, [P0 with { IsActive = false }, P1 with { IsActive = true }]));
        Assert.Equal("{\"players\":[{\"p\":0,\"name\":\"local.player\",\"on_play\":null},{\"p\":1,\"name\":\"Opp_Name\",\"on_play\":null}],\"game_number\":2}", Snap.Json(_out[0].Data));
        Assert.Equal("reattach", _out[1].Data["trigger"]);
    }

    [Fact]
    public void Snapshots_with_fewer_than_two_players_are_ignored_until_both_appear()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(0, "PreGame1", 0, 0, [P0]));
        Assert.Empty(_out);
        Assert.False(r.Started);
        r.OnSnapshot(Snap.Game(0, "PreGame1", 0, 0, [P0, P1]));
        Assert.Equal("game_started,keyframe", Types());
    }

    [Fact]
    public void Subsequent_snapshots_are_diffed()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(0, "PreGame1", 0, 0, [P0, P1]));
        _out.Clear();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        Assert.Equal("clock_tick,turn_started,clock_tick,keyframe,phase_changed", Types());
    }

    [Fact]
    public void Results_emit_game_end_clocks_then_game_ended_with_winner_slot()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        _out.Clear();
        r.OnResults([new("Opp_Name", false, 1_200_000), new("local.player", true, 1_300_000)]);

        Assert.Equal("clock_tick,clock_tick,game_ended", Types());
        Assert.Equal("{\"p\":0,\"remaining_ms\":1300000,\"trigger\":\"game_end\"}", Snap.Json(_out[0].Data));
        Assert.Equal("{\"p\":1,\"remaining_ms\":1200000,\"trigger\":\"game_end\"}", Snap.Json(_out[1].Data));
        Assert.Equal("{\"winner_p\":0,\"reason\":\"result\"}", Snap.Json(_out[2].Data));
        Assert.True(r.Ended);

        r.OnResults([new("local.player", true, 1)]);
        r.OnSnapshot(Snap.Game(2, "Untap", 1, 1, [P0, P1]));
        r.OnFinishedWithoutResults();
        r.OnAbandoned();
        Assert.Equal(3, _out.Count);
    }

    [Fact]
    public void Results_with_no_winner_yield_null_winner_and_unknown_names_are_skipped()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        _out.Clear();
        r.OnResults([new("local.player", false, null), new("Ghost", true, 5)]);
        Assert.Equal("game_ended", Types());
        Assert.Equal("{\"winner_p\":null,\"reason\":\"result\"}", Snap.Json(_out[0].Data));
    }

    [Fact]
    public void Finished_without_results_uses_last_snapshot_clocks_and_reason_unknown()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0 with { ClockMs = 900_000 }, P1 with { ClockMs = 1_100_000 }]));
        _out.Clear();
        r.OnFinishedWithoutResults();
        Assert.Equal("clock_tick,clock_tick,game_ended", Types());
        Assert.Equal("{\"p\":0,\"remaining_ms\":900000,\"trigger\":\"game_end\"}", Snap.Json(_out[0].Data));
        Assert.Equal("{\"winner_p\":null,\"reason\":\"unknown\"}", Snap.Json(_out[2].Data));
    }

    [Fact]
    public void Abandoned_after_start_emits_disconnect_and_before_start_emits_nothing()
    {
        var r = NewRecorder();
        r.OnAbandoned();
        Assert.Empty(_out);
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        _out.Clear();
        r.OnAbandoned();
        Assert.Equal("game_ended", Types());
        Assert.Equal("{\"winner_p\":null,\"reason\":\"disconnect\"}", Snap.Json(_out[0].Data));
    }

    [Fact]
    public void A_tick_missing_a_player_is_skipped_and_never_renumbers_the_slots()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1], Snap.Card(445, "Battlefield", owner: 1, controller: 1)));
        var keyframe = Snap.Json(_out[1].Data);
        _out.Clear();

        // The source dropped the local player from this tick. Diffing it would make the
        // opponent slot 0: every owner_p, controller_p and life_changed p would flip.
        r.OnSnapshot(Snap.Game(1, "Untap", 1, 1, [P1 with { Life = 12 }], Snap.Card(445, "Battlefield", owner: 1, controller: 1)));
        Assert.Empty(_out);

        // The next complete tick diffs against the last good snapshot, not the short one, so
        // the opponent's life change shows up exactly once and against slot 1.
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1 with { Life = 12 }], Snap.Card(445, "Battlefield", owner: 1, controller: 1)));
        Assert.Equal("life_changed", Types());
        Assert.Equal("{\"p\":1,\"life\":12}", Snap.Json(_out[0].Data));

        // And the keyframe ownership is the ownership pinned at game start.
        _out.Clear();
        r.OnSnapshot(Snap.Game(2, "Untap", 1, 1, [P0 with { IsActive = false, HasPriority = false }, P1 with { Life = 12, IsActive = true, HasPriority = true }], Snap.Card(445, "Battlefield", owner: 1, controller: 1)));
        var later = _out.Single(e => e.Type == "keyframe");
        Assert.Contains("\"owner_p\":1,\"controller_p\":1", Snap.Json(later.Data));
        Assert.Contains("\"owner_p\":1,\"controller_p\":1", keyframe);
    }

    [Fact]
    public void A_tick_whose_players_are_reordered_is_skipped_too()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        _out.Clear();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P1, P0 with { Life = 11 }]));
        Assert.Empty(_out);
    }

    [Fact]
    public void The_winner_is_resolved_once_and_exposed_as_both_slot_and_name()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        Assert.Null(r.WinnerSlot);
        Assert.Null(r.WinnerName);

        r.OnResults([new("Opp_Name", true, null), new("local.player", false, null)]);
        Assert.Equal(1, r.WinnerSlot);
        Assert.Equal("Opp_Name", r.WinnerName);
        Assert.Equal("{\"winner_p\":1,\"reason\":\"result\"}", Snap.Json(_out.Last().Data));
    }

    [Fact]
    public void A_game_that_ends_without_a_winner_exposes_no_name()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        r.OnAbandoned();
        Assert.Null(r.WinnerSlot);
        Assert.Null(r.WinnerName);
    }

    [Fact]
    public void Every_event_carries_game_and_match_ids()
    {
        var r = NewRecorder();
        r.OnSnapshot(Snap.Game(1, "Untap", 0, 0, [P0, P1]));
        r.OnResults([new("local.player", true, 1)]);
        Assert.All(_out, e => { Assert.Equal("958291826", e.Game); Assert.Equal("288955358", e.Match); });
    }
}
