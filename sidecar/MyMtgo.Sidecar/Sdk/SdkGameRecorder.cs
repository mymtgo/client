using Microsoft.Extensions.Logging;
using MTGOSDK.API.Play.Games;
using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Writer;
using GameEventArgs = MTGOSDK.API.Play.Games.Processors.EventArgs.GameEventArgs;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// Adapter between one SDK Game and one Core GameRecorder. All processor callbacks arrive on
/// SyncThread workers, so a lock serialises them into the recorder. Ticks are deduplicated by
/// snapshot Nonce: the SDK raises one event per changed card and per changed player for the
/// same tick, and they all carry the same snapshot.
/// </summary>
public sealed class SdkGameRecorder : IDisposable
{
    private static readonly TimeSpan s_resultGrace = TimeSpan.FromSeconds(3);

    private readonly Game _game;
    private readonly GameRecorder _recorder;
    private readonly CatalogResolver _catalog;
    private readonly Action<string?> _onEnded;
    private readonly ILogger _log;
    private readonly Lock _gate = new();
    private int _lastNonce = int.MinValue;
    private bool _hasNonce;
    private bool _disposed;

    public SdkGameRecorder(
        Game game,
        string matchId,
        int gameNumber,
        Action<PendingEvent> emit,
        Action<string?> onEnded,
        CatalogResolver catalog,
        ILogger log)
    {
        _game = game;
        _catalog = catalog;
        _onEnded = onEnded;
        _log = log;
        _recorder = new GameRecorder(game.Id.ToString(), matchId, gameNumber, emit, log);
    }

    public bool Ended => _recorder.Ended;

    /// <summary>
    /// Slot ordinals in this list are the `p` values in every event for this game. The Core
    /// recorder appends to its live list while the gate is held by a tick callback, so callers
    /// get a copy taken under the gate rather than a view that can tear or grow mid-enumeration.
    /// </summary>
    public IReadOnlyList<string> PlayerNames
    {
        get
        {
            lock (_gate)
            {
                return _recorder.PlayerNames.ToArray();
            }
        }
    }

    /// <summary>
    /// Subscribes to the processor pipeline and readies it. ReadyProcessor must come after every
    /// subscription or the drain loop never starts. Throws if the SDK refuses: the caller owns
    /// the decision to retry or skip the game.
    /// </summary>
    public void Start()
    {
        // OnCardChanged must be first: its factory registers CombatProcessor, and processors run
        // in subscription order. Subscribing OnZoneChanged first would let a tick whose opening
        // event is a zone change be mapped before combat cross-links have been refreshed, so
        // AttackingOrders and BlockingOrders would still hold the previous tick's targets.
        _game.OnCardChanged += e => OnTick(e);
        _game.OnZoneChanged += e => OnTick(e);
        _game.OnPlayerChanged += e => OnTick(e);
        _game.OnPromptChanged += e => OnTick(e);
        _game.OnGameResultsChanged += new Action<IList<GamePlayerResult>>(OnResults);
        _game.GameStatusChanged += new Action(OnStatus);
        _game.ReadyProcessor();
        _log.LogInformation("recording game {GameId}", _game.Id);
    }

    private void OnTick(GameEventArgs e)
    {
        try
        {
            lock (_gate)
            {
                if (_disposed || _recorder.Ended)
                {
                    return;
                }

                var nonce = e.Nonce;
                if (_hasNonce && nonce == _lastNonce)
                {
                    return;
                }

                var snapshot = e.Snapshot;
                if (snapshot is null)
                {
                    return;
                }

                // Commit the nonce only once the map has succeeded, so a transient remote read
                // failure lets the tick be retried on the next event carrying the same nonce.
                // Commit it before OnSnapshot, which may already have emitted when it throws.
                var mapped = SnapshotMapper.Map(snapshot, _catalog);
                _lastNonce = nonce;
                _hasNonce = true;

                _recorder.OnSnapshot(mapped);
            }
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "snapshot tick failed for game {GameId}", GameId());
        }
    }

    private void OnResults(IList<GamePlayerResult> results)
    {
        try
        {
            string? winner;

            lock (_gate)
            {
                if (_disposed || _recorder.Ended)
                {
                    return;
                }

                var inputs = new List<PlayerResultInput>();
                foreach (var result in results)
                {
                    if (result is null)
                    {
                        continue;
                    }

                    inputs.Add(new PlayerResultInput(
                        result.Player ?? string.Empty,
                        result.Result == GameResult.Win,
                        result.Clock == TimeSpan.Zero ? null : (long)Math.Max(0d, result.Clock.TotalMilliseconds)));
                }

                _recorder.OnResults(inputs);

                // The Core recorder drops results for a game that never started, leaving nothing
                // on the wire. Only report an end that the stream actually carries.
                if (!_recorder.Ended)
                {
                    _log.LogWarning("results arrived before game {GameId} started; ignoring", GameId());

                    return;
                }

                // Core resolved the winner when it wrote game_ended, so the name handed to the
                // match tracker is the one behind winner_p and nothing recomputes the rule here.
                winner = _recorder.WinnerName;
            }

            // Outside the gate: the callback takes Task 12's match lock, and holding the game
            // gate across it would publish an inverted lock order.
            _onEnded(winner);
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "game results handling failed for game {GameId}", GameId());
        }
    }

    private void OnStatus()
    {
        GameStatus status;
        try
        {
            status = _game.Status;
        }
        catch (Exception ex)
        {
            _log.LogDebug(ex, "status read failed");

            return;
        }

        if (status != GameStatus.Finished)
        {
            return;
        }

        // Results usually arrive within the same second. Give them a moment before falling back.
        _ = Task.Delay(s_resultGrace).ContinueWith(_ => FinishWithoutResults(), TaskScheduler.Default);
    }

    private void FinishWithoutResults()
    {
        try
        {
            lock (_gate)
            {
                if (_disposed || _recorder.Ended)
                {
                    return;
                }

                _recorder.OnFinishedWithoutResults();

                // Same latch as OnResults: a game that never started writes nothing, so it must
                // not be reported as ended either.
                if (!_recorder.Ended)
                {
                    _log.LogDebug("game {GameId} finished before it started; nothing to report", GameId());

                    return;
                }
            }

            _onEnded(null);
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "finish without results failed for game {GameId}", GameId());
        }
    }

    /// <summary>Connection lost: close the stream honestly without inventing a result.</summary>
    public void Abandon()
    {
        try
        {
            lock (_gate)
            {
                if (_disposed)
                {
                    return;
                }

                _recorder.OnAbandoned();
            }
        }
        catch (Exception ex)
        {
            _log.LogWarning(ex, "abandon failed for game {GameId}", GameId());
        }
    }

    public void Dispose()
    {
        lock (_gate)
        {
            if (_disposed)
            {
                return;
            }

            _disposed = true;
        }

        try
        {
            _game.ClearEvents();
        }
        catch (Exception ex)
        {
            _log.LogDebug(ex, "ClearEvents failed");
        }
    }

    /// <summary>Game.Id is a remote read and can throw once the game is gone; never fail a log call over it.</summary>
    private string GameId()
    {
        try
        {
            return _game.Id.ToString();
        }
        catch (Exception)
        {
            return "unknown";
        }
    }
}
