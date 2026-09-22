namespace MyMtgo.Sidecar.Core.Envelope;

/// <summary>Every event type name in the v1 vocabulary. Spec section "Event types".</summary>
public static class EventTypes
{
    public const string SessionStarted = "session_started";
    public const string Probe = "probe";
    public const string MatchStarted = "match_started";
    public const string MatchEnded = "match_ended";
    public const string SideboardingStarted = "sideboarding_started";
    public const string SideboardSubmitted = "sideboard_submitted";
    public const string GameStarted = "game_started";
    public const string GameEnded = "game_ended";
    public const string TurnStarted = "turn_started";
    public const string PhaseChanged = "phase_changed";
    public const string PriorityChanged = "priority_changed";
    public const string CardZoneChanged = "card_zone_changed";
    public const string CardTapped = "card_tapped";
    public const string CardUntapped = "card_untapped";
    public const string CardAttacking = "card_attacking";
    public const string CardBlocking = "card_blocking";
    public const string CardDamage = "card_damage";
    public const string CardPtChanged = "card_pt_changed";
    public const string CardCountersChanged = "card_counters_changed";
    public const string CardRevealed = "card_revealed";
    public const string LifeChanged = "life_changed";
    public const string ManaPoolChanged = "mana_pool_changed";
    public const string HandCountChanged = "hand_count_changed";
    public const string LibraryCountChanged = "library_count_changed";
    public const string ClockTick = "clock_tick";
    public const string Keyframe = "keyframe";

    public static readonly IReadOnlyList<string> All =
    [
        SessionStarted, Probe, MatchStarted, MatchEnded, SideboardingStarted, SideboardSubmitted,
        GameStarted, GameEnded, TurnStarted, PhaseChanged, PriorityChanged, CardZoneChanged,
        CardTapped, CardUntapped, CardAttacking, CardBlocking, CardDamage, CardPtChanged,
        CardCountersChanged, CardRevealed, LifeChanged, ManaPoolChanged, HandCountChanged,
        LibraryCountChanged, ClockTick, Keyframe,
    ];
}
