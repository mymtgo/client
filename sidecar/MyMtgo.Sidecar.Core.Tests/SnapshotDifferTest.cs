using MyMtgo.Sidecar.Core.Envelope;
using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Model;

namespace MyMtgo.Sidecar.Core.Tests;

public class SnapshotDifferTest
{
    private const string G = "958291826";
    private const string M = "288955358";
    private static readonly PlayerState P0 = Snap.Player(0, "local.player", active: true, priority: true);
    private static readonly PlayerState P1 = Snap.Player(1, "Opp_Name");

    private static string Types(IEnumerable<Writer.PendingEvent> events) => string.Join(",", events.Select(e => e.Type));

    [Fact]
    public void Identical_snapshots_produce_nothing()
    {
        var s = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(445, "Hand"));
        Assert.Empty(SnapshotDiffer.Diff(s, s, G, M));
    }

    [Fact]
    public void Turn_change_emits_turn_end_clock_turn_started_turn_start_clock_then_keyframe()
    {
        var prev = Snap.Game(1, "Cleanup", 0, 0, [P0 with { ClockMs = 1_471_000 }, P1]);
        var curr = Snap.Game(2, "Untap", 1, 1, [P0 with { ClockMs = 1_471_000, IsActive = false, HasPriority = false }, P1 with { IsActive = true, HasPriority = true }]);
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);

        Assert.Equal("clock_tick,turn_started,clock_tick,keyframe,phase_changed,priority_changed", Types(ev));
        Assert.Equal("{\"p\":0,\"remaining_ms\":1471000,\"trigger\":\"turn_end\"}", Snap.Json(ev[0].Data));
        Assert.Equal("{\"turn\":2,\"active_p\":1}", Snap.Json(ev[1].Data));
        Assert.Equal("{\"p\":1,\"remaining_ms\":1500000,\"trigger\":\"turn_start\"}", Snap.Json(ev[2].Data));
        Assert.Equal("turn", ev[3].Data["trigger"]);
        Assert.Equal("{\"phase\":\"Beginning\",\"step\":\"Untap\"}", Snap.Json(ev[4].Data));
        Assert.Equal("{\"p\":1}", Snap.Json(ev[5].Data));
        Assert.All(ev, e => { Assert.Equal(G, e.Game); Assert.Equal(M, e.Match); });
    }

    [Fact]
    public void Priority_change_without_turn_change_emits_a_priority_clock_tick_for_the_loser()
    {
        var prev = Snap.Game(3, "PreCombatMain", 0, 0, [P0, P1]);
        var curr = Snap.Game(3, "PreCombatMain", 0, 1, [P0 with { HasPriority = false, ClockMs = 1_400_000 }, P1 with { HasPriority = true }]);
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal("priority_changed,clock_tick", Types(ev));
        Assert.Equal("{\"p\":0,\"remaining_ms\":1400000,\"trigger\":\"priority\"}", Snap.Json(ev[1].Data));
    }

    [Fact]
    public void Player_counters_emit_in_slot_order_with_spec_payloads()
    {
        var prev = Snap.Game(2, "Draw", 1, 1, [P0, P1]);
        var curr = Snap.Game(2, "Draw", 1, 1, [P0 with { Life = 18, HandCount = 6 }, P1 with { LibraryCount = 52, Pool = new Dictionary<string, int> { ["B"] = 1 } }]);
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal("life_changed,hand_count_changed,library_count_changed,mana_pool_changed", Types(ev));
        Assert.Equal("{\"p\":0,\"life\":18}", Snap.Json(ev[0].Data));
        Assert.Equal("{\"p\":0,\"count\":6}", Snap.Json(ev[1].Data));
        Assert.Equal("{\"p\":1,\"count\":52}", Snap.Json(ev[2].Data));
        Assert.Equal("{\"p\":1,\"pool\":{\"B\":1}}", Snap.Json(ev[3].Data));
    }

    [Fact]
    public void Pool_emptying_emits_an_empty_object()
    {
        var prev = Snap.Game(2, "Draw", 0, 0, [P0 with { Pool = new Dictionary<string, int> { ["B"] = 1 } }, P1]);
        var curr = Snap.Game(2, "Draw", 0, 0, [P0, P1]);
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal("{\"p\":0,\"pool\":{}}", Snap.Json(ev.Single().Data));
    }

    [Fact]
    public void Zone_change_matches_the_fixture_line_and_carries_thing_ref()
    {
        var prev = Snap.Game(1, "PreCombatMain", 0, 0, [P0, P1], Snap.Card(445, "Hand"));
        var curr = Snap.Game(1, "PreCombatMain", 0, 0, [P0, P1], Snap.Card(445, "Battlefield"));
        var ev = SnapshotDiffer.Diff(prev, curr, G, M).Single();
        Assert.Equal(EventTypes.CardZoneChanged, ev.Type);
        Assert.Equal("{\"c\":\"445\",\"from\":\"Hand\",\"to\":\"Battlefield\",\"owner_p\":0,\"controller_p\":0,\"name\":\"Swamp\",\"catalog_id\":132587}", Snap.Json(ev.Data));
        Assert.Equal("{\"thing\":445}", Snap.Json(ev.Ref!));
    }

    [Fact]
    public void New_and_vanished_cards_use_Nowhere()
    {
        var prev = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(1, "Hand"));
        var curr = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(2, "Battlefield"));
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal(2, ev.Count);
        Assert.Equal("Nowhere", ev[0].Data["from"]);
        Assert.Equal("2", ev[0].Data["c"]);
        Assert.Equal("Nowhere", ev[1].Data["to"]);
        Assert.Equal("1", ev[1].Data["c"]);
    }

    [Fact]
    public void Tap_untap_attack_block_damage_pt_and_counters_in_that_order_per_card()
    {
        var prev = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1],
            Snap.Card(446, "Battlefield", name: "Gixian Infiltrator", catalog: 39339, power: 2, toughness: 1),
            Snap.Card(447, "Battlefield", name: "Bear", catalog: 1, tapped: true));
        var curr = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1],
            Snap.Card(446, "Battlefield", name: "Gixian Infiltrator", catalog: 39339, tapped: true, attacking: true, targetPlayer: 1, power: 3, toughness: 2, damage: 1, counters: new() { ["PlusOnePlusOne"] = 1 }),
            Snap.Card(447, "Battlefield", name: "Bear", catalog: 1, tapped: false, blocking: true, targetCard: 446));
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal("card_tapped,card_attacking,card_damage,card_pt_changed,card_counters_changed,card_untapped,card_blocking", Types(ev));
        Assert.Equal("{\"c\":\"446\"}", Snap.Json(ev[0].Data));
        Assert.Equal("{\"c\":\"446\",\"target_p\":1}", Snap.Json(ev[1].Data));
        Assert.Equal("{\"c\":\"446\",\"damage\":1}", Snap.Json(ev[2].Data));
        Assert.Equal("{\"c\":\"446\",\"power\":3,\"toughness\":2}", Snap.Json(ev[3].Data));
        Assert.Equal("{\"c\":\"446\",\"counters\":{\"PlusOnePlusOne\":1}}", Snap.Json(ev[4].Data));
        Assert.Equal("{\"c\":\"447\"}", Snap.Json(ev[5].Data));
        Assert.Equal("{\"c\":\"447\",\"target_c\":\"446\"}", Snap.Json(ev[6].Data));
    }

    [Fact]
    public void Attacking_going_false_emits_nothing()
    {
        var prev = Snap.Game(4, "EndOfCombat", 0, 0, [P0, P1], Snap.Card(1, "Battlefield", attacking: true, targetPlayer: 1));
        var curr = Snap.Game(4, "EndOfCombat", 0, 0, [P0, P1], Snap.Card(1, "Battlefield"));
        Assert.Empty(SnapshotDiffer.Diff(prev, curr, G, M));
    }

    [Fact]
    public void Phase_change_into_pregame_is_not_emitted()
    {
        var prev = Snap.Game(0, "PreGame1", 0, 0, [P0, P1]);
        var curr = Snap.Game(0, "PreGame2", 0, 0, [P0, P1]);
        Assert.Empty(SnapshotDiffer.Diff(prev, curr, G, M));
    }

    [Fact]
    public void Diff_is_deterministic_for_dictionary_order()
    {
        var a = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(5, "Hand"), Snap.Card(3, "Hand"));
        var b = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(3, "Battlefield"), Snap.Card(5, "Battlefield"));
        var ev = SnapshotDiffer.Diff(a, b, G, M);
        Assert.Equal(["3", "5"], ev.Select(e => (string)e.Data["c"]!).ToList());
    }

    [Fact]
    public void A_card_that_first_appears_already_attacking_emits_zone_change_then_attack()
    {
        var prev = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1]);
        var curr = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1], Snap.Card(900, "Battlefield", name: "Token", catalog: null, tapped: true, attacking: true, targetPlayer: 1, power: 1, toughness: 1));
        var ev = SnapshotDiffer.Diff(prev, curr, G, M);
        Assert.Equal("card_zone_changed,card_tapped,card_attacking,card_pt_changed", Types(ev));
        Assert.Equal("{\"c\":\"900\",\"target_p\":1}", Snap.Json(ev[2].Data));
    }

    [Fact]
    public void Attack_target_player_outside_the_game_omits_the_key()
    {
        var prev = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1], Snap.Card(1, "Battlefield"));
        var curr = Snap.Game(4, "DeclareAttackers", 0, 0, [P0, P1], Snap.Card(1, "Battlefield", attacking: true, targetPlayer: 9));
        var ev = SnapshotDiffer.Diff(prev, curr, G, M).Single();
        Assert.Equal("{\"c\":\"1\"}", Snap.Json(ev.Data));
    }
}
