using MTGOSDK.API.Play.Games;
using MTGOSDK.API.Play.Games.Processors;
using MyMtgo.Sidecar.Core.Model;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// Projects one SDK <see cref="GameStateSnapshot"/> onto the source-neutral Core snapshot.
/// Every collection it returns is freshly allocated: the writer serialises asynchronously, so a
/// mapped snapshot must never share mutable state with the next tick or with the SDK.
/// </summary>
public static class SnapshotMapper
{
    public static GameSnapshot Map(GameStateSnapshot snapshot, CatalogResolver catalog)
    {
        var players = new List<PlayerState>();
        int? active = null;
        int? priority = null;

        foreach (var entry in snapshot.Players.OrderBy(kv => kv.Key))
        {
            var index = entry.Key;
            var player = entry.Value;
            if (player is null)
            {
                continue;
            }

            if (player.IsActivePlayer)
            {
                active = index;
            }

            if (player.HasPriority)
            {
                priority = index;
            }

            players.Add(new PlayerState(
                Index: index,
                Name: player.Name ?? string.Empty,
                Life: player.Life,
                HandCount: player.HandCount,
                LibraryCount: player.LibraryCount,
                IsActive: player.IsActivePlayer,
                HasPriority: player.HasPriority,
                ClockMs: (long)Math.Max(0d, player.ChessClock.TotalMilliseconds),
                Pool: MapPool(player.ManaPool)));
        }

        var cards = new Dictionary<int, CardState>(snapshot.Cards.Count);
        var withTarget = new List<int>();

        foreach (var entry in snapshot.Cards)
        {
            var card = entry.Value;
            if (card is null)
            {
                continue;
            }

            var zone = card.Zone?.Zone ?? CardZone.Invalid;
            if (zone is CardZone.Invalid or CardZone.Nowhere)
            {
                continue;
            }

            var attackTarget = FirstOrNull(card.AttackingOrders);
            var blockTarget = FirstOrNull(card.BlockingOrders);

            cards[entry.Key] = new CardState(
                Id: entry.Key,
                Name: card.Name ?? string.Empty,
                CatalogId: catalog.Resolve(card.CTN),
                Zone: zone.ToString(),
                OwnerIndex: card.OwnerIndex,
                ControllerIndex: card.ControllerIndex,
                Tapped: card.IsTapped,
                Attacking: card.IsAttacking,
                Blocking: card.IsBlocking,
                AttackTargetCard: TargetCardId(attackTarget, blockTarget),
                AttackTargetPlayer: card.IsAttacking && attackTarget is null
                    ? DefendingPlayer(players, card.ControllerIndex)
                    : null,
                Power: card.Power,
                Toughness: card.Toughness,
                Damage: card.Damage,
                Counters: MapCounters(card.Counters));

            if (cards[entry.Key].AttackTargetCard is not null)
            {
                withTarget.Add(entry.Key);
            }
        }

        // Second pass, because a target can appear anywhere in the iteration order: a target that
        // did not survive the Invalid/Nowhere filter must not reach the wire as a target_c
        // pointing at a card the stream never mentions.
        foreach (var key in withTarget)
        {
            var state = cards[key];
            if (state.AttackTargetCard is { } targetId && !cards.ContainsKey(targetId))
            {
                cards[key] = state with { AttackTargetCard = null };
            }
        }

        return new GameSnapshot(
            snapshot.TurnNumber,
            snapshot.CurrentPhase.ToString(),
            active,
            priority,
            players,
            cards);
    }

    /// <summary>
    /// A blocker points at what it blocks; an attacker points at what it attacks through
    /// (a planeswalker or an ordered blocker). Zero is the SDK's "no thing" sentinel.
    /// </summary>
    private static int? TargetCardId(GameCard? attackTarget, GameCard? blockTarget)
    {
        if (blockTarget is not null && blockTarget.Id != 0)
        {
            return blockTarget.Id;
        }

        if (attackTarget is not null && attackTarget.Id != 0)
        {
            return attackTarget.Id;
        }

        return null;
    }

    /// <summary>
    /// Two player games only: the attack target is whoever is not the controller. The SDK has no
    /// "attacking player X" member, so this is inferred. Null when it cannot be determined.
    /// </summary>
    private static int? DefendingPlayer(List<PlayerState> players, int controllerIndex)
    {
        if (players.Count != 2)
        {
            return null;
        }

        foreach (var player in players)
        {
            if (player.Index != controllerIndex)
            {
                return player.Index;
            }
        }

        return null;
    }

    /// <summary>GameCard.Counters is a bare enum sequence: repeats are the quantity.</summary>
    private static Dictionary<string, int> MapCounters(IEnumerable<CardCounter>? counters)
    {
        var result = new Dictionary<string, int>();
        if (counters is null)
        {
            return result;
        }

        foreach (var counter in counters)
        {
            var key = counter.ToString();
            result[key] = result.GetValueOrDefault(key) + 1;
        }

        return result;
    }

    private static Dictionary<string, int> MapPool(IEnumerable<Mana>? pool)
    {
        var result = new Dictionary<string, int>();
        if (pool is null)
        {
            return result;
        }

        foreach (var mana in pool)
        {
            if (mana is null || mana.Amount <= 0)
            {
                continue;
            }

            var key = mana.Color switch
            {
                MagicColors.White => "W",
                MagicColors.Blue => "U",
                MagicColors.Black => "B",
                MagicColors.Red => "R",
                MagicColors.Green => "G",
                _ => "C",
            };

            result[key] = result.GetValueOrDefault(key) + mana.Amount;
        }

        return result;
    }

    private static GameCard? FirstOrNull(IEnumerable<GameCard>? cards)
    {
        if (cards is null)
        {
            return null;
        }

        foreach (var card in cards)
        {
            if (card is not null)
            {
                return card;
            }
        }

        return null;
    }
}
