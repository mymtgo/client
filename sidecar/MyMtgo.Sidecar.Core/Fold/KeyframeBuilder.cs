using MyMtgo.Sidecar.Core.Model;

namespace MyMtgo.Sidecar.Core.Fold;

public static class KeyframeBuilder
{
    /// <summary>Full snapshot payload. Key order is the fixture's; optional card fields appear only when non-default so a keyframe of lands stays small.</summary>
    public static Dictionary<string, object?> Build(GameSnapshot s, string trigger)
    {
        var players = new List<Dictionary<string, object?>>();
        for (var slot = 0; slot < s.Players.Count; slot++)
        {
            var p = s.Players[slot];
            players.Add(new()
            {
                ["p"] = slot,
                ["life"] = p.Life,
                ["hand"] = p.HandCount,
                ["library"] = p.LibraryCount,
                ["clock_ms"] = p.ClockMs,
                ["pool"] = p.Pool,
            });
        }

        var cards = new List<Dictionary<string, object?>>();
        foreach (var c in s.Cards.Values.OrderBy(c => c.Id))
        {
            var entry = new Dictionary<string, object?>
            {
                ["c"] = c.Id.ToString(),
                ["zone"] = c.Zone,
                ["owner_p"] = s.SlotOrNull(c.OwnerIndex),
                ["controller_p"] = s.SlotOrNull(c.ControllerIndex),
                ["catalog_id"] = c.CatalogId,
                ["tapped"] = c.Tapped,
                ["name"] = c.Name,
            };
            if (c.Power != 0 || c.Toughness != 0) { entry["power"] = c.Power; entry["toughness"] = c.Toughness; }
            if (c.Damage != 0) entry["damage"] = c.Damage;
            if (c.Counters.Count > 0) entry["counters"] = c.Counters;
            if (c.Attacking) entry["attacking"] = true;
            if (c.Blocking) entry["blocking"] = true;
            if (c.AttackTargetCard is not null) entry["attack_target_c"] = c.AttackTargetCard.ToString();
            if (c.AttackTargetPlayer is not null) entry["attack_target_p"] = s.SlotOrNull(c.AttackTargetPlayer);
            cards.Add(entry);
        }

        return new()
        {
            ["trigger"] = trigger,
            ["turn"] = s.Turn,
            ["phase"] = s.Phase,
            ["step"] = s.Phase is null ? null : s.Step,
            ["active_p"] = s.SlotOrNull(s.ActiveIndex),
            ["priority_p"] = s.SlotOrNull(s.PriorityIndex),
            ["players"] = players,
            ["cards"] = cards,
        };
    }
}
