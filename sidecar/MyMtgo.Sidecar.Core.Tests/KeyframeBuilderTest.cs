using System.Text.Json;
using MyMtgo.Sidecar.Core.Fold;
using MyMtgo.Sidecar.Core.Model;

namespace MyMtgo.Sidecar.Core.Tests;

public static class Snap
{
    public static PlayerState Player(int index, string name, int life = 20, int hand = 7, int library = 53,
        bool active = false, bool priority = false, long clockMs = 1_500_000, Dictionary<string, int>? pool = null) =>
        new(index, name, life, hand, library, active, priority, clockMs, pool ?? new Dictionary<string, int>());

    public static CardState Card(int id, string zone, int owner = 0, int controller = 0, string name = "Swamp", int? catalog = 132587,
        bool tapped = false, bool attacking = false, bool blocking = false, int? targetCard = null, int? targetPlayer = null,
        int power = 0, int toughness = 0, int damage = 0, Dictionary<string, int>? counters = null) =>
        new(id, name, catalog, zone, owner, controller, tapped, attacking, blocking, targetCard, targetPlayer, power, toughness, damage, counters ?? new Dictionary<string, int>());

    public static GameSnapshot Game(int turn, string? step, int? active, int? priority, IEnumerable<PlayerState> players, params CardState[] cards) =>
        new(turn, step, active, priority, players.ToList(), cards.ToDictionary(c => c.Id));

    public static string Json(object o) => JsonSerializer.Serialize(o);
}

public class KeyframeBuilderTest
{
    private static readonly PlayerState P0 = Snap.Player(0, "local.player", active: true, priority: true);
    private static readonly PlayerState P1 = Snap.Player(1, "Opp_Name");

    [Fact]
    public void Pre_game_keyframe_matches_the_php_fixture_shape()
    {
        var s = Snap.Game(0, "PreGame1", 0, 0, [P0, P1]);
        var data = KeyframeBuilder.Build(s, "game_start");
        Assert.Equal(
            "{\"trigger\":\"game_start\",\"turn\":0,\"phase\":null,\"step\":null,\"active_p\":0,\"priority_p\":0,\"players\":[{\"p\":0,\"life\":20,\"hand\":7,\"library\":53,\"clock_ms\":1500000,\"pool\":{}},{\"p\":1,\"life\":20,\"hand\":7,\"library\":53,\"clock_ms\":1500000,\"pool\":{}}],\"cards\":[]}",
            Snap.Json(data));
    }

    [Fact]
    public void Card_entries_carry_zone_owner_controller_catalog_and_tapped_first()
    {
        var s = Snap.Game(2, "Untap", 1, 1, [P0, P1],
            Snap.Card(445, "Battlefield", tapped: false),
            Snap.Card(446, "Battlefield", name: "Gixian Infiltrator", catalog: 39339, power: 2, toughness: 1, damage: 1, counters: new() { ["PlusOnePlusOne"] = 2 }, attacking: true, targetPlayer: 1));
        var json = Snap.Json(KeyframeBuilder.Build(s, "turn"));
        Assert.StartsWith("{\"trigger\":\"turn\",\"turn\":2,\"phase\":\"Beginning\",\"step\":\"Untap\",\"active_p\":1,\"priority_p\":1,", json);
        Assert.Contains("{\"c\":\"445\",\"zone\":\"Battlefield\",\"owner_p\":0,\"controller_p\":0,\"catalog_id\":132587,\"tapped\":false,\"name\":\"Swamp\"}", json);
        Assert.Contains("{\"c\":\"446\",\"zone\":\"Battlefield\",\"owner_p\":0,\"controller_p\":0,\"catalog_id\":39339,\"tapped\":false,\"name\":\"Gixian Infiltrator\",\"power\":2,\"toughness\":1,\"damage\":1,\"counters\":{\"PlusOnePlusOne\":2},\"attacking\":true,\"attack_target_p\":1}", json);
    }

    [Fact]
    public void Cards_are_ordered_by_id_and_players_by_slot()
    {
        var s = Snap.Game(1, "Draw", 0, 0, [P0, P1], Snap.Card(900, "Hand"), Snap.Card(12, "Library"));
        var json = Snap.Json(KeyframeBuilder.Build(s, "reattach"));
        Assert.True(json.IndexOf("\"c\":\"12\"", StringComparison.Ordinal) < json.IndexOf("\"c\":\"900\"", StringComparison.Ordinal));
    }

    [Fact]
    public void Slot_maps_player_index_to_ordinal()
    {
        var s = Snap.Game(1, "Draw", 7, null, [Snap.Player(3, "a"), Snap.Player(7, "b")]);
        Assert.Equal(0, s.SlotOf(3));
        Assert.Equal(1, s.SlotOf(7));
        Assert.Null(s.SlotOrNull(null));
        Assert.Throws<KeyNotFoundException>(() => s.SlotOf(99));
        Assert.StartsWith("{\"trigger\":\"x\",\"turn\":1,\"phase\":\"Beginning\",\"step\":\"Draw\",\"active_p\":1,\"priority_p\":null,", Snap.Json(KeyframeBuilder.Build(s, "x")));
    }
}
