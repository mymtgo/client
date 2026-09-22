namespace MyMtgo.Sidecar.Core.Model;

/// <summary>The SDK collapses phase and step into one GamePhase enum. The vocabulary carries both: step is the SDK name, phase is the coarse group.</summary>
public static class Phases
{
    public static string? GroupOf(string? step) => step switch
    {
        "Untap" or "Upkeep" or "Draw" => "Beginning",
        "PreCombatMain" => "PreCombatMain",
        "BeginCombat" or "DeclareAttackers" or "DeclareBlockers" or "CombatDamage" or "EndOfCombat" => "Combat",
        "PostCombatMain" => "PostCombatMain",
        "EndOfTurn" or "Cleanup" => "Ending",
        _ => null,
    };
}
