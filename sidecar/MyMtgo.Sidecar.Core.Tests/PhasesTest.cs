using MyMtgo.Sidecar.Core.Model;

namespace MyMtgo.Sidecar.Core.Tests;

public class PhasesTest
{
    [Theory]
    [InlineData("Untap", "Beginning")]
    [InlineData("Upkeep", "Beginning")]
    [InlineData("Draw", "Beginning")]
    [InlineData("PreCombatMain", "PreCombatMain")]
    [InlineData("BeginCombat", "Combat")]
    [InlineData("DeclareAttackers", "Combat")]
    [InlineData("DeclareBlockers", "Combat")]
    [InlineData("CombatDamage", "Combat")]
    [InlineData("EndOfCombat", "Combat")]
    [InlineData("PostCombatMain", "PostCombatMain")]
    [InlineData("EndOfTurn", "Ending")]
    [InlineData("Cleanup", "Ending")]
    [InlineData("PreGame1", null)]
    [InlineData("PreGame2", null)]
    [InlineData("Invalid", null)]
    [InlineData(null, null)]
    public void Groups_sdk_phase_names(string? step, string? group) => Assert.Equal(group, Phases.GroupOf(step));
}
