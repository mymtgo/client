namespace MyMtgo.Sidecar.Core.Writer;

/// <summary>What producers hand to the writer. Seq, ts and verified are stamped by the writer, not the producer.</summary>
public sealed record PendingEvent(
    string Type,
    string? Game,
    string? Match,
    IReadOnlyDictionary<string, object?> Data,
    IReadOnlyDictionary<string, object?>? Ref);
