using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Fold;

/// <summary>One per match. Match-level events only; games are GameRecorder's job.</summary>
public sealed class MatchTracker(string matchId, Action<PendingEvent> emit)
{
    private readonly List<string> _names = [];
    private readonly int[] _score = new int[2];
    private readonly HashSet<int> _submittedThisWindow = [];
    private DateTimeOffset? _currentWindow;
    private int _games;
    private int _gamesEnded;
    private List<string>? _pendingEnd;

    public bool Started { get; private set; }
    public bool Ended { get; private set; }

    /// <summary>Game numbers claimed so far. This is the match's only game counter.</summary>
    public int GamesSeen => _games;
    public IReadOnlyList<string> PlayerNames => _names;
    public int NextGameNumber => _games + 1;

    /// <summary>
    /// <paramref name="eventType"/> is the parent event's kind: <c>league</c>, <c>tournament</c>,
    /// <c>casual</c> (a parentless match: direct challenge or practice, which the app does not
    /// tell apart) or <c>unknown</c>. Never <c>challenge</c>: in the app that word means the MTGO
    /// Challenge tournament, which is a <c>tournament</c> whose <paramref name="eventName"/>
    /// (the MTGO event description) carries the word. PHP classifies from the pair.
    /// </summary>
    public void OnMatchStarted(IReadOnlyList<string> playerNames, string format, string eventType, string? eventName = null)
    {
        if (Started)
        {
            return;
        }

        Started = true;
        _names.AddRange(playerNames);
        var players = new List<Dictionary<string, object?>>();
        for (var slot = 0; slot < _names.Count; slot++)
        {
            players.Add(new() { ["p"] = slot, ["name"] = _names[slot] });
        }

        emit(Ev(EventTypes.MatchStarted, new() { ["players"] = players, ["format"] = format, ["event_type"] = eventType, ["event_name"] = eventName }));
    }

    /// <summary>
    /// Claims the next game number and returns it. The claim is provisional: a caller that then
    /// fails to build the game gives it back with <see cref="ReleaseGameNumber"/>, so a game that
    /// is never recorded does not shift every later game_number in the match.
    /// </summary>
    public int OnGameStarted() => ++_games;

    /// <summary>Gives back a claim whose game never materialised.</summary>
    public void ReleaseGameNumber()
    {
        if (_games > 0)
        {
            _games--;
        }
    }

    /// <summary>
    /// Tallies a game win against the match score. The winner arrives as a name, not a slot:
    /// slots are ordinals within one game, and nothing guarantees game 2's ordering matches
    /// game 1's, so a slot carried up from a game could credit the wrong player. Resolving by
    /// name against the match's own slot list is what <see cref="OnMatchEnded"/> already does.
    /// </summary>
    public void OnGameEnded(string? winnerName)
    {
        if (_gamesEnded < _games)
        {
            _gamesEnded++;
        }

        if (winnerName is not null)
        {
            var slot = _names.IndexOf(winnerName);
            if (slot is >= 0 and < 2)
            {
                _score[slot]++;
            }
        }

        if (_pendingEnd is not null && _gamesEnded >= _games)
        {
            EmitMatchEnded(_pendingEnd);
        }
    }

    /// <summary>
    /// Opens a sideboard window. The deadline is nullable because the client does not always
    /// have one to give: a window with an unknown deadline still has to open, or the clock never
    /// starts on PHP's side and the next window's submissions are deduplicated as repeats.
    /// A known deadline identifies the window, so reporting the same one twice is one window.
    /// An unknown deadline identifies nothing, so every report opens a new window and the caller
    /// is responsible for reporting only the start of one.
    /// </summary>
    public void OnSideboardingStarted(DateTimeOffset? endsAt)
    {
        if (Ended || (endsAt is not null && _currentWindow == endsAt))
        {
            return;
        }

        _currentWindow = endsAt;
        _submittedThisWindow.Clear();
        emit(Ev(EventTypes.SideboardingStarted, new() { ["ends_at"] = endsAt is null ? null : Timestamps.Format(endsAt.Value) }));
    }

    public void OnSideboardSubmitted(string playerName)
    {
        if (Ended)
        {
            return;
        }

        var slot = _names.IndexOf(playerName);
        if (slot < 0 || !_submittedThisWindow.Add(slot))
        {
            return;
        }

        emit(Ev(EventTypes.SideboardSubmitted, new() { ["p"] = slot }));
    }

    /// <summary>
    /// The client can flag the match complete before the last game's result has landed: a
    /// concession ends the match instantly while the game recorder is still inside its result
    /// grace period. Emitting then would put match_ended before game_ended in the stream with a
    /// score missing the deciding game. So while a claimed game is still open the end is parked
    /// and goes out from <see cref="OnGameEnded"/>, which also covers the disconnect path
    /// because an abandoned game reports a null winner.
    /// </summary>
    public void OnMatchEnded(IReadOnlyList<string> winningNames)
    {
        if (Ended || _pendingEnd is not null)
        {
            return;
        }

        if (_gamesEnded < _games)
        {
            _pendingEnd = [.. winningNames];

            return;
        }

        EmitMatchEnded(winningNames);
    }

    private void EmitMatchEnded(IReadOnlyList<string> winningNames)
    {
        Ended = true;
        _pendingEnd = null;
        int? winner = null;
        foreach (var name in winningNames)
        {
            var slot = _names.IndexOf(name);
            if (slot >= 0)
            {
                winner = slot;
                break;
            }
        }

        emit(Ev(EventTypes.MatchEnded, new() { ["winner_p"] = winner, ["score"] = new[] { _score[0], _score[1] } }));
    }

    private PendingEvent Ev(string type, Dictionary<string, object?> data) => new(type, null, matchId, data, null);
}
