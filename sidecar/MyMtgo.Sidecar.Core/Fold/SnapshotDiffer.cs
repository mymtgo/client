using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Model;
using MyMtgo.Sidecar.Core.Writer;

namespace MyMtgo.Sidecar.Core.Fold;

/// <summary>
/// Turns two consecutive snapshots of one game into vocabulary events. Pure and deterministic:
/// same inputs, same list, same order. The order is part of the wire contract because PHP
/// folds by seq.
/// </summary>
public static class SnapshotDiffer
{
    private const string Nowhere = "Nowhere";

    public static IReadOnlyList<PendingEvent> Diff(GameSnapshot prev, GameSnapshot curr, string gameId, string matchId)
    {
        var events = new List<PendingEvent>();
        PendingEvent Ev(string type, Dictionary<string, object?> data, Dictionary<string, object?>? @ref = null) => new(type, gameId, matchId, data, @ref);

        var turnChanged = curr.Turn > prev.Turn;
        if (turnChanged)
        {
            if (prev.ActiveIndex is { } prevActive && prev.SlotOrNull(prevActive) is { } prevSlot)
            {
                events.Add(Ev(EventTypes.ClockTick, Clock(prevSlot, prev.Players[prevSlot].ClockMs, "turn_end")));
            }

            events.Add(Ev(EventTypes.TurnStarted, new() { ["turn"] = curr.Turn, ["active_p"] = curr.SlotOrNull(curr.ActiveIndex) }));

            if (curr.ActiveIndex is { } newActive && curr.SlotOrNull(newActive) is { } newSlot)
            {
                events.Add(Ev(EventTypes.ClockTick, Clock(newSlot, curr.Players[newSlot].ClockMs, "turn_start")));
            }

            events.Add(Ev(EventTypes.Keyframe, KeyframeBuilder.Build(curr, "turn")));
        }

        if (curr.Step != prev.Step && curr.Phase is not null)
        {
            events.Add(Ev(EventTypes.PhaseChanged, new() { ["phase"] = curr.Phase, ["step"] = curr.Step }));
        }

        if (curr.PriorityIndex != prev.PriorityIndex && curr.SlotOrNull(curr.PriorityIndex) is { } prioritySlot)
        {
            events.Add(Ev(EventTypes.PriorityChanged, new() { ["p"] = prioritySlot }));
            if (!turnChanged && curr.SlotOrNull(prev.PriorityIndex) is { } loserSlot)
            {
                events.Add(Ev(EventTypes.ClockTick, Clock(loserSlot, curr.Players[loserSlot].ClockMs, "priority")));
            }
        }

        for (var slot = 0; slot < curr.Players.Count; slot++)
        {
            var c = curr.Players[slot];
            var p = prev.Players.FirstOrDefault(x => x.Index == c.Index);
            if (p is null)
            {
                continue;
            }

            if (c.Life != p.Life)
            {
                events.Add(Ev(EventTypes.LifeChanged, new() { ["p"] = slot, ["life"] = c.Life }));
            }

            if (c.HandCount != p.HandCount)
            {
                events.Add(Ev(EventTypes.HandCountChanged, new() { ["p"] = slot, ["count"] = c.HandCount }));
            }

            if (c.LibraryCount != p.LibraryCount)
            {
                events.Add(Ev(EventTypes.LibraryCountChanged, new() { ["p"] = slot, ["count"] = c.LibraryCount }));
            }

            if (!SameMap(c.Pool, p.Pool))
            {
                events.Add(Ev(EventTypes.ManaPoolChanged, new() { ["p"] = slot, ["pool"] = c.Pool }));
            }
        }

        foreach (var card in curr.Cards.Values.OrderBy(x => x.Id))
        {
            prev.Cards.TryGetValue(card.Id, out var before);
            var thingRef = new Dictionary<string, object?> { ["thing"] = card.Id };
            var handle = card.Id.ToString();

            if (before is null || before.Zone != card.Zone)
            {
                events.Add(Ev(EventTypes.CardZoneChanged, ZoneChange(curr, card, before?.Zone ?? Nowhere, card.Zone), thingRef));
            }

            // A card whose first appearance is already tapped, attacking or blocking (a token made
            // attacking, a reattach) must still show those edges: diff it against a blank baseline.
            before ??= Blank(card);

            if (card.Tapped != before.Tapped)
            {
                events.Add(Ev(card.Tapped ? EventTypes.CardTapped : EventTypes.CardUntapped, new() { ["c"] = handle }, thingRef));
            }

            if (card.Attacking && !before.Attacking)
            {
                var data = new Dictionary<string, object?> { ["c"] = handle };
                if (card.AttackTargetCard is not null)
                {
                    data["target_c"] = card.AttackTargetCard.ToString();
                }

                if (card.AttackTargetPlayer is not null)
                {
                    if (curr.SlotOrNull(card.AttackTargetPlayer) is { } targetSlot)
                    {
                        data["target_p"] = targetSlot;
                    }
                }

                events.Add(Ev(EventTypes.CardAttacking, data, thingRef));
            }

            if (card.Blocking && !before.Blocking)
            {
                var data = new Dictionary<string, object?> { ["c"] = handle };
                if (card.AttackTargetCard is not null)
                {
                    data["target_c"] = card.AttackTargetCard.ToString();
                }

                events.Add(Ev(EventTypes.CardBlocking, data, thingRef));
            }

            if (card.Damage != before.Damage)
            {
                events.Add(Ev(EventTypes.CardDamage, new() { ["c"] = handle, ["damage"] = card.Damage }, thingRef));
            }

            if (card.Power != before.Power || card.Toughness != before.Toughness)
            {
                events.Add(Ev(EventTypes.CardPtChanged, new() { ["c"] = handle, ["power"] = card.Power, ["toughness"] = card.Toughness }, thingRef));
            }

            if (!SameMap(card.Counters, before.Counters))
            {
                events.Add(Ev(EventTypes.CardCountersChanged, new() { ["c"] = handle, ["counters"] = card.Counters }, thingRef));
            }
        }

        foreach (var gone in prev.Cards.Values.Where(x => !curr.Cards.ContainsKey(x.Id)).OrderBy(x => x.Id))
        {
            events.Add(Ev(EventTypes.CardZoneChanged, ZoneChange(curr, gone, gone.Zone, Nowhere), new() { ["thing"] = gone.Id }));
        }

        return events;
    }


    /// <summary>Baseline for a card with no previous state: same identity and zone, nothing set.</summary>
    private static CardState Blank(CardState card) =>
        card with { Tapped = false, Attacking = false, Blocking = false, AttackTargetCard = null, AttackTargetPlayer = null, Power = 0, Toughness = 0, Damage = 0, Counters = new Dictionary<string, int>() };

    private static Dictionary<string, object?> Clock(int slot, long remainingMs, string trigger) =>
        new() { ["p"] = slot, ["remaining_ms"] = remainingMs, ["trigger"] = trigger };

    private static Dictionary<string, object?> ZoneChange(GameSnapshot s, CardState card, string from, string to) => new()
    {
        ["c"] = card.Id.ToString(),
        ["from"] = from,
        ["to"] = to,
        ["owner_p"] = s.SlotOrNull(card.OwnerIndex),
        ["controller_p"] = s.SlotOrNull(card.ControllerIndex),
        ["name"] = card.Name,
        ["catalog_id"] = card.CatalogId,
    };

    private static bool SameMap(IReadOnlyDictionary<string, int> a, IReadOnlyDictionary<string, int> b) =>
        a.Count == b.Count && a.All(kv => b.TryGetValue(kv.Key, out var v) && v == kv.Value);
}
