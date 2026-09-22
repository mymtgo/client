using System.Collections.Concurrent;
using Microsoft.Extensions.Logging;
using MTGOSDK.API.Collection;
using MTGOSDK.API.Play;
using MTGOSDK.API.Play.Games;
using MTGOSDK.API.Play.Games.Processors;
using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Host;
using MyMtgo.Sidecar.Core.Status;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// One attach, one session file. Everything game related hangs off GameProcessor.OnNewGame;
/// match level state comes from the two static Match hooks. The runner owns the event writer
/// for the session and is the only place that decides the verified flag.
/// </summary>
public sealed class SessionRunner : IAsyncDisposable
{
    private static readonly TimeSpan s_statusInterval = TimeSpan.FromSeconds(2);

    private readonly SidecarOptions _options;
    private readonly SdkAttachment _attachment;
    private readonly StatusWriter _status;
    private readonly IClock _clock;
    private readonly ILoggerFactory _loggerFactory;
    private readonly ILogger _log;
    private readonly bool _blocked;
    private readonly EventWriter _writer;
    private readonly CatalogResolver _catalog;
    private readonly ConcurrentDictionary<string, SdkMatchTracker> _matches = new();
    private readonly string _sidecarVersion;
    private volatile bool _verified;
    private volatile bool _tearingDown;
    private CancellationTokenRegistration _shutdownRegistration;
    private Action? _onDisconnected;
    private Action<Game>? _onNewGame;
    private Action<Match, MatchState>? _onMatchState;
    private Action<Match, Deck>? _onDeckSubmitted;

    public SessionRunner(
        SidecarOptions options,
        SdkAttachment attachment,
        StatusWriter status,
        IClock clock,
        ILoggerFactory loggerFactory,
        bool blocked)
    {
        _options = options;
        _attachment = attachment;
        _status = status;
        _clock = clock;
        _loggerFactory = loggerFactory;
        _log = loggerFactory.CreateLogger("Session");
        _blocked = blocked;
        _catalog = new CatalogResolver(loggerFactory.CreateLogger("Catalog"));

        // The supervisor already stamped its own version into the status; reusing it keeps one
        // reading of the sidecar version across status.json and the session_started event.
        // This is load bearing: Program.cs must write SidecarStatus.Initial before it constructs
        // a session, or the version reported here falls back to StatusWriter's own "0.0.0".
        _sidecarVersion = status.Current.SidecarVersion;

        var session = Guid.NewGuid().ToString("D");
        _writer = new EventWriter(
            options.OutDir,
            session,
            clock.UtcNow,
            () => _verified,
            clock,
            ex =>
            {
                _log.LogError(ex, "event write failed");
                _status.Update(s => s with { State = SidecarState.Error, LastError = ex.Message });
            },
            _log);

        _status.Update(s => s with
        {
            State = blocked ? SidecarState.VersionBlocked : SidecarState.Attached,
            MtgoVersion = attachment.MtgoVersion,
            SdkVersion = attachment.SdkVersion,
            AttachedAt = clock.UtcNow,
            CurrentFile = _writer.FileName,
            LastEventSeq = 0,
            LastError = null,
        });
    }

    public async Task RunUntilDetachedAsync(CancellationToken ct)
    {
        // RunContinuationsAsynchronously is not optional. With the default, completing this
        // source resumes the loop below inline on whichever thread signalled it, so the whole
        // teardown would run inside CancellationTokenSource.Cancel() in Program.cs, before the
        // 3 second hard-exit watchdog is even scheduled, or inside an SDK callback thread.
        var detached = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);

        // Three sources can end the session: the SDK's IsConnectedChanged, the remote process
        // exiting, and our own cancellation. TrySetResult makes all of them idempotent.
        _onDisconnected = () => Detach(detached);
        _attachment.Disconnected += _onDisconnected;
        _shutdownRegistration = ct.Register(() => detached.TrySetResult());

        // A disconnect raised before that subscription existed (for example while the version
        // gate was fetching) would otherwise be lost and the supervisor would hang.
        if (!_attachment.IsConnected)
        {
            detached.TrySetResult();
        }

        Emit(new PendingEvent(
            EventTypes.SessionStarted,
            null,
            null,
            new Dictionary<string, object?>
            {
                ["username"] = _attachment.Username,
                ["mtgo_version"] = _attachment.MtgoVersion,
                ["sdk_version"] = _attachment.SdkVersion,
                ["sidecar_version"] = _sidecarVersion,
            },
            null));

        // Subscribe before the first probe, not after it: the probe makes five catalog round
        // trips plus a username read, and a game that starts in that window would be missed.
        if (!_blocked)
        {
            Subscribe();
        }

        RunProbe(liveGame: null);

        var nextProbe = _clock.UtcNow + _options.ProbeInterval;
        while (!detached.Task.IsCompleted)
        {
            await Task.WhenAny(detached.Task, Task.Delay(s_statusInterval, CancellationToken.None));
            _status.Update(s => s with { LastEventSeq = _writer.LastSeq });

            // A blocked session records nothing, so the probe is the only thing that can tell
            // the supervisor the gate should be revisited.
            if (_blocked && _clock.UtcNow >= nextProbe)
            {
                RunProbe(liveGame: null);
                nextProbe = _clock.UtcNow + _options.ProbeInterval;
            }
        }

        // Set before teardown so a probe still running on the thread pool cannot repaint the
        // closing Waiting state to Attached or Degraded after this method has returned.
        _tearingDown = true;

        foreach (var match in _matches.Values)
        {
            match.AbandonAll();
        }

        // A write failure during this session set State to Error; that is the more useful thing
        // for the supervisor and for PHP to see, so teardown does not paint over it. The attach
        // time and the session file do go, under either state: the session is over and the file
        // will not grow again, so leaving them would show a live-looking session on /debug.
        _status.Update(s => s with
        {
            State = s.State == SidecarState.Error ? SidecarState.Error : SidecarState.Waiting,
            AttachedAt = null,
            CurrentFile = null,
            LastEventSeq = _writer.LastSeq,
        });
    }

    public async ValueTask DisposeAsync()
    {
        if (_onNewGame is not null)
        {
            GameProcessor.OnNewGame -= _onNewGame;
            _onNewGame = null;
        }

        if (_onMatchState is not null)
        {
            Match.MatchStateChanged -= _onMatchState;
            _onMatchState = null;
        }

        if (_onDeckSubmitted is not null)
        {
            Match.DeckForSideboardingChanged -= _onDeckSubmitted;
            _onDeckSubmitted = null;
        }

        if (_onDisconnected is not null)
        {
            _attachment.Disconnected -= _onDisconnected;
            _onDisconnected = null;
        }

        _shutdownRegistration.Dispose();

        foreach (var match in _matches.Values)
        {
            match.DisposeAll();
        }

        await _writer.CompleteAsync();
    }

    private void Subscribe()
    {
        // Subscribe before EnsureHookInitialized so no game that starts during setup is missed.
        _onNewGame = OnNewGame;
        GameProcessor.OnNewGame += _onNewGame;
        GameProcessor.EnsureHookInitialized();

        // EventHookProxy's += takes a System.Delegate, so these handlers have to be typed
        // delegate instances rather than lambdas written inline at the subscription.
        _onMatchState = OnMatchStateChanged;
        Match.MatchStateChanged += _onMatchState;

        _onDeckSubmitted = OnDeckForSideboardingChanged;
        Match.DeckForSideboardingChanged += _onDeckSubmitted;
    }

    /// <summary>Runs on an SDK callback thread; an escaping exception would take MTGO's thread with it.</summary>
    private void OnMatchStateChanged(Match match, MatchState state)
    {
        try
        {
            if (TrackedMatch(match) is { } tracker)
            {
                tracker.OnStateChanged(state);
            }
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "match state change handling failed");
        }
    }

    /// <inheritdoc cref="OnMatchStateChanged"/>
    private void OnDeckForSideboardingChanged(Match match, Deck deck)
    {
        try
        {
            if (TrackedMatch(match) is { } tracker)
            {
                tracker.OnLocalDeckSubmitted(_attachment.Username);
            }
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "sideboard submission handling failed");
        }
    }

    /// <summary>
    /// Both static Match hooks fire for every match the client models, not only the ones we
    /// record, and each delivery is a freshly built wrapper. Only a game creates a tracker, so
    /// an unknown match id here is a match we are not recording. Minting one would emit
    /// match_ended or sideboarding_started for a match PHP never saw start.
    /// </summary>
    private SdkMatchTracker? TrackedMatch(Match match)
    {
        var id = match.Id.ToString();
        if (_matches.TryGetValue(id, out var tracker))
        {
            return tracker;
        }

        _log.LogDebug("ignoring match hook for untracked match {MatchId}", id);

        return null;
    }

    private void OnNewGame(Game game)
    {
        SdkMatchTracker tracker;
        SdkGameRecorder recorder;
        try
        {
            // One IPC read, off the tick path.
            tracker = TrackerFor(game.Match);
        }
        catch (Exception ex)
        {
            _log.LogError(ex, "failed to resolve the match for a new game");

            return;
        }

        var number = tracker.ReserveGameNumber();
        try
        {
            var announced = false;
            SdkGameRecorder? built = null;
            built = new SdkGameRecorder(
                game,
                tracker.MatchId,
                number,
                emit: e =>
                {
                    Emit(e);

                    // The match needs the game's slot order so both agree on what `p` means.
                    // Core fills PlayerNames before it emits game_started, and every emit for a
                    // game is serialised by the recorder, so the flag needs no interlock.
                    if (!announced && e.Type == EventTypes.GameStarted && built is not null)
                    {
                        announced = true;
                        tracker.OnFirstGameStarted(built.PlayerNames);
                    }
                },
                onEnded: tracker.OnGameEnded,
                _catalog,
                _loggerFactory.CreateLogger("Game"));
            recorder = built;
        }
        catch (Exception ex)
        {
            // The constructor reads game.Id over IPC. Giving the number back keeps a game we
            // never recorded from shifting every later game_number in the match.
            tracker.ReleaseGameNumber();
            _log.LogError(ex, "failed to build a recorder for a new game");

            return;
        }

        // The number is committed here: from now on the game counts even if we cannot record it,
        // because it is still the match's next game as far as MTGO is concerned.
        tracker.Register(recorder);

        try
        {
            recorder.Start();
        }
        catch (Exception ex)
        {
            // A game we cannot subscribe to is skipped, not retried: the SDK subscriptions it did
            // manage to make have to come off or they leak for the life of the session.
            recorder.Dispose();
            _log.LogError(ex, "failed to start recording a game");

            return;
        }

        // OnNewGame runs inside the SDK's status hook mapper, on a SyncThread worker from a small
        // pool, before the game's own first message is routed. The probe does five catalog round
        // trips and walks every battlefield, so it goes to the thread pool. Events emitted before
        // it finishes carry the verified flag from the last completed probe, which is honest:
        // the probe event itself carries the new answer and PHP reads that.
        var probed = game;
        _ = Task.Run(() =>
        {
            try
            {
                RunProbe(probed);
            }
            catch (Exception ex)
            {
                _log.LogWarning(ex, "probe for a new game failed");
            }
        });
    }

    private SdkMatchTracker TrackerFor(Match match)
    {
        var id = match.Id.ToString();

        return _matches.GetOrAdd(id, key => new SdkMatchTracker(match, key, Emit, _loggerFactory.CreateLogger("Match")));
    }

    private void RunProbe(Game? liveGame)
    {
        var result = Probe.Run(_attachment, liveGame, _catalog);
        _verified = result.Passed;
        Emit(new PendingEvent(EventTypes.Probe, null, null, result.ToData(), null));

        // A blocked session stays version_blocked whatever the probe says, and a probe that
        // finishes after teardown must not touch the closing state.
        if (!_blocked && !_tearingDown)
        {
            _status.Update(s => s with { State = result.Passed ? SidecarState.Attached : SidecarState.Degraded });
        }

        _log.LogInformation(
            "probe passed={Passed} username={UsernameOk} cards={CardsOk} battlefield={BattlefieldOk}",
            result.Passed,
            result.UsernameOk,
            result.CardNamesOk,
            result.BattlefieldOk);
    }

    /// <summary>Runs on the SDK callback thread, so it must never throw.</summary>
    private void Detach(TaskCompletionSource detached)
    {
        try
        {
            detached.TrySetResult();
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "detach signalling failed");
        }
    }

    private void Emit(PendingEvent e) => _writer.Enqueue(e);
}
