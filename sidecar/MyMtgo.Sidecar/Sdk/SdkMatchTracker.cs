using Microsoft.Extensions.Logging;
using MTGOSDK.API.Play;
using MTGOSDK.API.Play.Leagues;
using MTGOSDK.API.Play.Tournaments;
using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// Adapter between one SDK Match and one Core MatchTracker. Callbacks arrive from several SDK
/// threads, so every call into the Core tracker is serialised here.
/// <para>
/// Lock order in this session is game gate, then match gate, then the writer's sequence lock: the
/// game tick path reaches this class through the recorder's emit callback while holding the game
/// gate. Nothing here may therefore call into a recorder while holding the match gate, and no SDK
/// read that crosses IPC may happen under it either.
/// </para>
/// </summary>
public sealed class SdkMatchTracker
{
    private static readonly TimeSpan s_parentEventTimeout = TimeSpan.FromSeconds(20);

    private readonly Match _match;
    private readonly MatchTracker _tracker;
    private readonly ILogger _log;
    private readonly Lock _gate = new();
    private readonly List<SdkGameRecorder> _recorders = [];
    private bool _startRequested;
    private bool _sideboarding;

    public SdkMatchTracker(Match match, string matchId, Action<PendingEvent> emit, ILogger log)
    {
        _match = match;
        _log = log;
        MatchId = matchId;
        _tracker = new MatchTracker(matchId, emit);
    }

    /// <summary>The match id as it appears in every envelope for this match.</summary>
    public string MatchId { get; }

    /// <summary>
    /// Claims the next game number for this match. The Core tracker owns the only counter;
    /// claiming in one locked step keeps two concurrent game starts from taking the same number.
    /// The claim is provisional: the caller commits it with <see cref="Register"/> or gives it
    /// back with <see cref="ReleaseGameNumber"/>, so a game we fail to build does not burn a
    /// number and shift every later game_number in the match.
    /// </summary>
    public int ReserveGameNumber()
    {
        lock (_gate)
        {
            return _tracker.OnGameStarted();
        }
    }

    /// <summary>Commits a claim: the recorder exists, so the game counts.</summary>
    public void Register(SdkGameRecorder recorder)
    {
        lock (_gate)
        {
            _recorders.Add(recorder);
        }
    }

    /// <summary>Gives back a claim whose recorder could not be built.</summary>
    public void ReleaseGameNumber()
    {
        lock (_gate)
        {
            _tracker.ReleaseGameNumber();
        }
    }

    /// <summary>
    /// The winner arrives as a name because game slots and match slots are separate spaces:
    /// the Core tracker resolves it against the match's own slot list.
    /// </summary>
    public void OnGameEnded(string? winnerName)
    {
        lock (_gate)
        {
            _tracker.OnGameEnded(winnerName);
        }
    }

    /// <summary>
    /// Called once, with the first game's slot order, so match and game slots agree. The format
    /// and parent event reads happen on a background task because walking the joined events can
    /// take seconds; a timeout emits match_started with unknown rather than holding the stream.
    /// </summary>
    public void OnFirstGameStarted(IReadOnlyList<string> slotNames)
    {
        lock (_gate)
        {
            if (_startRequested)
            {
                return;
            }

            _startRequested = true;
        }

        var lookup = Task.Run(() =>
        {
            var format = ReadFormat();
            var eventType = ReadEventType();
            StartMatch(slotNames, format, eventType);
        });

        // The lookup keeps running after a timeout, so its fault would otherwise go unobserved.
        _ = lookup.ContinueWith(
            t => _log.LogDebug(t.Exception, "parent event lookup for match {MatchId} faulted after timing out", MatchId),
            CancellationToken.None,
            TaskContinuationOptions.OnlyOnFaulted,
            TaskScheduler.Default);

        _ = lookup
            .WaitAsync(s_parentEventTimeout)
            .ContinueWith(
                t =>
                {
                    if (!t.IsFaulted && !t.IsCanceled)
                    {
                        return;
                    }

                    _log.LogWarning("format or parent event lookup for match {MatchId} did not finish; using unknown", MatchId);
                    StartMatch(slotNames, "unknown", "unknown");
                },
                TaskScheduler.Default);
    }

    public void OnStateChanged(MatchState state)
    {
        // Both reads cross to MTGO's heap, WinningPlayers once per user, so they happen before
        // the gate is taken. Holding it across them would block every game tick in the match.
        var sideboarding = state.HasFlag(MatchState.Sideboarding);
        var completed = state.HasFlag(MatchState.MatchCompleted);
        var deadline = sideboarding ? ReadSideboardingDeadline() : null;
        var winners = completed ? Try(() => _match.WinningPlayers.Select(u => u.Name).ToList(), []) : [];

        lock (_gate)
        {
            // Only the rising edge opens a window. The state hook repeats while sideboarding is
            // in progress, and a window whose deadline could not be read has no value Core can
            // deduplicate on, so without the edge check it would reopen on every repeat.
            if (sideboarding && !_sideboarding)
            {
                _tracker.OnSideboardingStarted(deadline);
            }

            _sideboarding = sideboarding;

            if (completed)
            {
                _tracker.OnMatchEnded(winners);
            }
        }
    }

    /// <summary>
    /// The SDK only surfaces the local player's sideboard submission, so the opponent's clock
    /// stays unknown. PHP already treats the per slot sideboard clock as nullable.
    /// </summary>
    public void OnLocalDeckSubmitted(string? localUsername)
    {
        if (localUsername is null)
        {
            return;
        }

        lock (_gate)
        {
            _tracker.OnSideboardSubmitted(localUsername);
        }
    }

    /// <summary>Connection lost: close every live game honestly, without inventing a result.</summary>
    public void AbandonAll()
    {
        foreach (var recorder in SnapshotRecorders(clear: false))
        {
            recorder.Abandon();
        }
    }

    public void DisposeAll()
    {
        foreach (var recorder in SnapshotRecorders(clear: true))
        {
            recorder.Dispose();
        }
    }

    /// <summary>
    /// Copies the recorder list so the caller can act on it outside the match gate. Abandon and
    /// Dispose both take a recorder's own game gate, and Dispose additionally makes a blocking
    /// ClearEvents call over IPC, so doing either under this gate would invert the lock order
    /// against the tick path and can deadlock teardown.
    /// </summary>
    private SdkGameRecorder[] SnapshotRecorders(bool clear)
    {
        lock (_gate)
        {
            var copy = _recorders.ToArray();
            if (clear)
            {
                _recorders.Clear();
            }

            return copy;
        }
    }

    private void StartMatch(IReadOnlyList<string> slotNames, string format, string eventType)
    {
        lock (_gate)
        {
            _tracker.OnMatchStarted(slotNames, format, eventType);
        }
    }

    /// <summary>
    /// Kind is not documented on this property. Measured on 2026-09-22 (machine on BST): read as
    /// UTC the deadline landed 63 minutes after the window opened, so the value is machine local
    /// time and the window is the expected 3 minutes once converted. PHP reads the event
    /// timestamps for the sideboard clock, so the offset is cosmetic either way. An unreadable
    /// or sentinel deadline yields null, which still opens the window.
    /// </summary>
    private DateTimeOffset? ReadSideboardingDeadline()
    {
        var ends = Try(() => _match.SideboardingEnds, DateTime.MinValue);

        return ends == DateTime.MinValue ? null : new DateTimeOffset(DateTime.SpecifyKind(ends, DateTimeKind.Local));
    }

    /// <summary>
    /// PlayFormat does not override ToString, so its type name is never a useful fallback.
    /// Code first, then the display Name, then "unknown".
    /// </summary>
    private string ReadFormat()
    {
        var code = Try<string?>(() => _match.Format.Code, null);
        if (!string.IsNullOrEmpty(code))
        {
            return code;
        }

        var name = Try<string?>(() => _match.Format.Name, null);

        return string.IsNullOrEmpty(name) ? "unknown" : name;
    }

    private string ReadEventType() => Try(
        () =>
        {
            var parent = EventManager.FindParentEvent(EventManager.JoinedEvents, _match);

            return parent switch
            {
                Tournament => "tournament",
                League => "league",
                Queue => "queue",
                Match => "challenge",
                null when !string.IsNullOrEmpty(Try<string?>(() => _match.ChallengeText, null)) => "challenge",
                _ => "unknown",
            };
        },
        "unknown");

    /// <summary>Every SDK read here crosses to MTGO's heap and can throw once the match is gone.</summary>
    private static T Try<T>(Func<T> read, T fallback)
    {
        try
        {
            return read();
        }
        catch (Exception)
        {
            return fallback;
        }
    }
}
