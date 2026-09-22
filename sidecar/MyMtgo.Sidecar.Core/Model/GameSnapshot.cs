namespace MyMtgo.Sidecar.Core.Model;

public sealed record PlayerState(
    int Index,
    string Name,
    int Life,
    int HandCount,
    int LibraryCount,
    bool IsActive,
    bool HasPriority,
    long ClockMs,
    IReadOnlyDictionary<string, int> Pool);

public sealed record CardState(
    int Id,
    string Name,
    int? CatalogId,
    string Zone,
    int OwnerIndex,
    int ControllerIndex,
    bool Tapped,
    bool Attacking,
    bool Blocking,
    int? AttackTargetCard,
    int? AttackTargetPlayer,
    int Power,
    int Toughness,
    int Damage,
    IReadOnlyDictionary<string, int> Counters);

/// <summary>
/// Source-neutral picture of one game tick. Players are ordered by ascending source index, and
/// that ordinal is the slot `p` used in every event for the game.
/// </summary>
public sealed record GameSnapshot(
    int Turn,
    string? Step,
    int? ActiveIndex,
    int? PriorityIndex,
    IReadOnlyList<PlayerState> Players,
    IReadOnlyDictionary<int, CardState> Cards)
{
    public string? Phase => Phases.GroupOf(Step);

    public int SlotOf(int playerIndex)
    {
        for (var i = 0; i < Players.Count; i++)
        {
            if (Players[i].Index == playerIndex) return i;
        }

        throw new KeyNotFoundException($"no player with source index {playerIndex}");
    }

    public int? SlotOrNull(int? playerIndex) =>
        playerIndex is null ? null : (Players.Any(p => p.Index == playerIndex) ? SlotOf(playerIndex.Value) : null);
}
