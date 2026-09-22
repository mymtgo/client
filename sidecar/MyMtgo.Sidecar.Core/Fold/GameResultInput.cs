namespace MyMtgo.Sidecar.Core.Fold;

/// <summary>What the adapter extracts from the SDK's GamePlayerResult list. Name-keyed because the SDK's result type carries a player name, not an index.</summary>
public sealed record PlayerResultInput(string Name, bool Won, long? ClockMs);
