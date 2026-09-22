using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Tests;

public class MatchTrackerTest
{
    private readonly List<PendingEvent> _out = [];
    private MatchTracker New() => new("288955358", _out.Add);
    private string Types() => string.Join(",", _out.Select(e => e.Type));

    [Fact]
    public void Match_started_matches_the_fixture_and_is_emitted_once()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        t.OnMatchStarted(["x", "y"], "CMODERN", "queue");
        Assert.Equal("match_started", Types());
        Assert.Equal("{\"players\":[{\"p\":0,\"name\":\"local.player\"},{\"p\":1,\"name\":\"Opp_Name\"}],\"format\":\"CPAUPER\",\"event_type\":\"league\"}", Snap.Json(_out[0].Data));
        Assert.Null(_out[0].Game);
        Assert.Equal("288955358", _out[0].Match);
        Assert.Equal(1, t.NextGameNumber);
    }

    [Fact]
    public void Game_numbers_advance_and_score_tallies_into_match_ended()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        t.OnGameStarted(); t.OnGameEnded("local.player");
        Assert.Equal(2, t.NextGameNumber);
        t.OnGameStarted(); t.OnGameEnded("Opp_Name");
        t.OnGameStarted(); t.OnGameEnded(null);
        t.OnMatchEnded([]);
        Assert.Equal("{\"winner_p\":null,\"score\":[1,1]}", Snap.Json(_out.Last().Data));
        Assert.True(t.Ended);
        t.OnMatchEnded(["local.player"]);
        Assert.Equal(2, _out.Count);
    }

    [Fact]
    public void Match_winner_is_resolved_by_name()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        t.OnGameStarted(); t.OnGameEnded("Opp_Name");
        t.OnGameStarted(); t.OnGameEnded("Opp_Name");
        t.OnMatchEnded(["Opp_Name"]);
        Assert.Equal("{\"winner_p\":1,\"score\":[0,2]}", Snap.Json(_out.Last().Data));
    }

    [Fact]
    public void Sideboarding_window_emits_start_once_and_submit_once_per_known_player()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        var ends = new DateTimeOffset(2026, 8, 5, 12, 27, 5, 0, TimeSpan.Zero);
        t.OnSideboardingStarted(ends);
        t.OnSideboardingStarted(ends);
        t.OnSideboardSubmitted("local.player");
        t.OnSideboardSubmitted("local.player");
        t.OnSideboardSubmitted("Nobody");
        Assert.Equal("match_started,sideboarding_started,sideboard_submitted", Types());
        Assert.Equal("{\"ends_at\":\"2026-08-05T12:27:05.000Z\"}", Snap.Json(_out[1].Data));
        Assert.Equal("{\"p\":0}", Snap.Json(_out[2].Data));

        t.OnSideboardingStarted(ends.AddMinutes(10));
        t.OnSideboardSubmitted("local.player");
        Assert.Equal("match_started,sideboarding_started,sideboard_submitted,sideboarding_started,sideboard_submitted", Types());
    }

    [Fact]
    public void Sideboarding_window_with_an_unknown_deadline_still_opens_and_clears_the_submitted_set()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        t.OnSideboardingStarted(null);
        t.OnSideboardSubmitted("local.player");
        t.OnSideboardSubmitted("local.player");
        Assert.Equal("match_started,sideboarding_started,sideboard_submitted", Types());
        Assert.Equal("{\"ends_at\":null}", Snap.Json(_out[1].Data));

        // A second window with an unknown deadline is a second window, not a repeat, so the
        // submitted set clears and the same player can submit again.
        t.OnSideboardingStarted(null);
        t.OnSideboardSubmitted("local.player");
        Assert.Equal("match_started,sideboarding_started,sideboard_submitted,sideboarding_started,sideboard_submitted", Types());
    }

    [Fact]
    public void Sideboarding_window_with_a_known_deadline_is_still_deduplicated()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        var ends = new DateTimeOffset(2026, 8, 5, 12, 27, 5, 0, TimeSpan.Zero);
        t.OnSideboardingStarted(null);
        t.OnSideboardingStarted(ends);
        t.OnSideboardingStarted(ends);
        Assert.Equal("match_started,sideboarding_started,sideboarding_started", Types());
        Assert.Equal("{\"ends_at\":null}", Snap.Json(_out[1].Data));
        Assert.Equal("{\"ends_at\":\"2026-08-05T12:27:05.000Z\"}", Snap.Json(_out[2].Data));
    }

    [Fact]
    public void Match_ended_without_a_start_still_emits()
    {
        var t = New();
        t.OnGameStarted(); t.OnGameEnded("anyone");
        t.OnMatchEnded(["anyone"]);
        Assert.Equal("match_ended", Types());

        // A match that never started has no slot names, so neither the game win nor the match
        // winner can be attributed to a slot. The event still has to go out: PHP builds the
        // match from the events it gets, and a missing match_ended is worse than an empty score.
        Assert.Equal("{\"winner_p\":null,\"score\":[0,0]}", Snap.Json(_out[0].Data));
    }

    [Fact]
    public void Score_follows_the_winner_name_when_a_later_game_reverses_the_slot_order()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");

        // Game 1's slot order happens to match the match's: its slot 0 is the local player.
        t.OnGameStarted(); t.OnGameEnded("local.player");

        // Game 2 arrives with the players the other way round, so its winning slot 0 is the
        // opponent. Passing the name instead of the slot is what keeps the score honest here;
        // a slot carried up from the game would have credited the local player twice.
        t.OnGameStarted(); t.OnGameEnded("Opp_Name");

        t.OnGameStarted(); t.OnGameEnded("local.player");
        t.OnMatchEnded(["local.player"]);
        Assert.Equal("{\"winner_p\":0,\"score\":[2,1]}", Snap.Json(_out.Last().Data));
    }

    [Fact]
    public void A_game_won_by_an_unknown_name_scores_for_nobody()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CPAUPER", "league");
        t.OnGameStarted(); t.OnGameEnded("Ghost");
        t.OnGameStarted(); t.OnGameEnded("local.player");
        t.OnMatchEnded(["local.player"]);
        Assert.Equal("{\"winner_p\":0,\"score\":[1,0]}", Snap.Json(_out.Last().Data));
    }

    [Fact]
    public void Released_game_numbers_come_back_from_the_one_counter()
    {
        var t = New();
        Assert.Equal(1, t.OnGameStarted());
        Assert.Equal(2, t.OnGameStarted());
        t.ReleaseGameNumber();
        Assert.Equal(1, t.GamesSeen);
        Assert.Equal(2, t.OnGameStarted());

        // A release with nothing outstanding cannot drive the counter negative.
        t.ReleaseGameNumber();
        t.ReleaseGameNumber();
        t.ReleaseGameNumber();
        t.ReleaseGameNumber();
        Assert.Equal(0, t.GamesSeen);
        Assert.Equal(1, t.NextGameNumber);
    }

    [Fact]
    public void Match_end_that_arrives_before_the_last_game_result_waits_for_it()
    {
        // Seen on 2026-09-22: a concession flagged MatchCompleted 60 ms before the recorder's
        // result grace period wrote game_ended, so match_ended went out first with score [0,0].
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CMODERN", "challenge");
        t.OnGameStarted();
        t.OnMatchEnded(["local.player"]);
        Assert.Equal("match_started", Types());
        Assert.False(t.Ended);

        t.OnGameEnded("local.player");
        Assert.Equal("match_started,match_ended", Types());
        Assert.Equal("{\"winner_p\":0,\"score\":[1,0]}", Snap.Json(_out.Last().Data));
        Assert.True(t.Ended);
    }

    [Fact]
    public void A_parked_match_end_is_flushed_by_a_disconnect_with_no_winner()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CMODERN", "challenge");
        t.OnGameStarted(); t.OnGameEnded("Opp_Name");
        t.OnGameStarted();
        t.OnMatchEnded(["Opp_Name"]);
        Assert.False(t.Ended);

        t.OnGameEnded(null);
        Assert.Equal("{\"winner_p\":1,\"score\":[0,1]}", Snap.Json(_out.Last().Data));
        Assert.True(t.Ended);
    }

    [Fact]
    public void A_second_match_end_while_one_is_parked_does_not_double_emit()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CMODERN", "challenge");
        t.OnGameStarted();
        t.OnMatchEnded(["local.player"]);
        t.OnMatchEnded(["local.player"]);
        t.OnGameEnded("local.player");
        Assert.Equal(1, _out.Count(e => e.Type == "match_ended"));
    }

    [Fact]
    public void Extra_game_ended_calls_do_not_confuse_the_open_game_count()
    {
        var t = New();
        t.OnMatchStarted(["local.player", "Opp_Name"], "CMODERN", "challenge");
        t.OnGameStarted(); t.OnGameEnded("local.player"); t.OnGameEnded(null);
        t.OnGameStarted();
        t.OnMatchEnded(["local.player"]);
        Assert.False(t.Ended);
        t.OnGameEnded("local.player");
        Assert.Equal("{\"winner_p\":0,\"score\":[2,0]}", Snap.Json(_out.Last().Data));
    }
}
