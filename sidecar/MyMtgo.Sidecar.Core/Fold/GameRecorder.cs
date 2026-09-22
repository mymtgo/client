using Microsoft.Extensions.Logging;
using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Model;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Fold;

/// <summary>
/// One per game. Owns the start and end of the game's event stream and feeds every tick in
/// between through SnapshotDiffer. Not thread safe: the adapter serialises calls per game.
/// </summary>
public sealed class GameRecorder(string gameId, string matchId, int gameNumber, Action<PendingEvent> emit, ILogger? log = null)
{
    private readonly List<string> _names = [];
    private int[] _indices = [];

    public bool Started { get; private set; }
    public bool Ended { get; private set; }
    public GameSnapshot? Last { get; private set; }
    public IReadOnlyList<string> PlayerNames => _names;

    /// <summary>
    /// The slot this recorder wrote into <c>game_ended.winner_p</c>, or null when the game ended
    /// without a known winner. Set once, by the same rule that writes the event, so no caller has
    /// to reimplement it.
    /// </summary>
    public int? WinnerSlot { get; private set; }

    /// <summary>
    /// The name behind <see cref="WinnerSlot"/>. Slots are meaningful only inside this game, so
    /// anything match level (the score, for one) has to resolve the winner by name instead.
    /// </summary>
    public string? WinnerName { get; private set; }

    public void OnSnapshot(GameSnapshot s)
    {
        if (Ended)
        {
            return;
        }

        if (!Started)
        {
            if (s.Players.Count < 2)
            {
                return;
            }

            Start(s);

            return;
        }

        // Slot `p` is the ordinal into the player array pinned at game start and is stable for
        // the life of the game. The source can hand us a tick whose player array is short one
        // entry (a null player is dropped on the way in), and diffing that would renumber every
        // slot in it: the survivor would become slot 0, so life_changed, the count events and
        // every owner_p and controller_p in a keyframe on that tick would name the wrong player.
        // Skip such a tick whole and keep the last good snapshot as the diff base.
        if (!MatchesPinnedPlayers(s))
        {
            log?.LogDebug(
                "skipping snapshot for game {GameId}: player indices [{Seen}] do not match the pinned [{Pinned}]",
                gameId,
                string.Join(",", s.Players.Select(p => p.Index)),
                string.Join(",", _indices));

            return;
        }

        foreach (var e in SnapshotDiffer.Diff(Last!, s, gameId, matchId))
        {
            emit(e);
        }

        Last = s;
    }

    public void OnResults(IReadOnlyList<PlayerResultInput> results)
    {
        if (Ended || !Started)
        {
            return;
        }

        int? winner = null;
        var clocks = new (int Slot, long Ms)[results.Count];
        var n = 0;
        foreach (var r in results)
        {
            var slot = _names.IndexOf(r.Name);
            if (slot < 0)
            {
                continue;
            }

            if (r.Won)
            {
                winner ??= slot;
            }

            if (r.ClockMs is { } ms)
            {
                clocks[n++] = (slot, ms);
            }
        }

        foreach (var (slot, ms) in clocks.Take(n).OrderBy(c => c.Slot))
        {
            emit(Ev(EventTypes.ClockTick, new() { ["p"] = slot, ["remaining_ms"] = ms, ["trigger"] = "game_end" }));
        }

        End(winner, "result");
    }

    public void OnFinishedWithoutResults()
    {
        if (Ended || !Started)
        {
            return;
        }

        for (var slot = 0; slot < Last!.Players.Count; slot++)
        {
            emit(Ev(EventTypes.ClockTick, new() { ["p"] = slot, ["remaining_ms"] = Last.Players[slot].ClockMs, ["trigger"] = "game_end" }));
        }

        End(null, "unknown");
    }

    public void OnAbandoned()
    {
        if (Ended || !Started)
        {
            return;
        }

        End(null, "disconnect");
    }

    private void Start(GameSnapshot s)
    {
        Started = true;
        Last = s;
        _indices = [.. s.Players.Select(p => p.Index)];
        var reattach = s.Turn > 1;
        var players = new List<Dictionary<string, object?>>();
        for (var slot = 0; slot < s.Players.Count; slot++)
        {
            _names.Add(s.Players[slot].Name);
            players.Add(new()
            {
                ["p"] = slot,
                ["name"] = s.Players[slot].Name,
                ["on_play"] = reattach ? null : s.Players[slot].Index == s.ActiveIndex,
            });
        }

        emit(Ev(EventTypes.GameStarted, new() { ["players"] = players, ["game_number"] = gameNumber }));
        emit(Ev(EventTypes.Keyframe, KeyframeBuilder.Build(s, reattach ? "reattach" : "game_start")));
    }

    /// <summary>
    /// True when the snapshot's players are the ones pinned at game start, in the same order.
    /// Both the set and the order matter: the ordinal is the slot.
    /// </summary>
    private bool MatchesPinnedPlayers(GameSnapshot s)
    {
        if (s.Players.Count != _indices.Length)
        {
            return false;
        }

        for (var i = 0; i < _indices.Length; i++)
        {
            if (s.Players[i].Index != _indices[i])
            {
                return false;
            }
        }

        return true;
    }

    private void End(int? winnerSlot, string reason)
    {
        Ended = true;
        WinnerSlot = winnerSlot;
        WinnerName = winnerSlot is { } slot && slot < _names.Count ? _names[slot] : null;
        emit(Ev(EventTypes.GameEnded, new() { ["winner_p"] = winnerSlot, ["reason"] = reason }));
    }

    private PendingEvent Ev(string type, Dictionary<string, object?> data) => new(type, gameId, matchId, data, null);
}
