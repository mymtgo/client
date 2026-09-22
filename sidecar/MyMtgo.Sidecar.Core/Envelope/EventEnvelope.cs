using System.Text;
using System.Text.Json;

namespace MyMtgo.Sidecar.Core.Envelope;

public sealed record EventEnvelope(
    string Session,
    DateTimeOffset SessionStartedAt,
    long Seq,
    DateTimeOffset Ts,
    string Type,
    string? Game,
    string? Match,
    bool Verified,
    IReadOnlyDictionary<string, object?> Data,
    IReadOnlyDictionary<string, object?>? Ref)
{
    public const int SchemaMajor = 1;

    private static readonly JsonSerializerOptions PayloadOptions = new()
    {
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.Never,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };

    /// <summary>One NDJSON line, newline terminated. Key order is fixed by hand so the wire format never depends on serializer defaults.</summary>
    public string ToJsonLine()
    {
        using var stream = new MemoryStream();
        using (var w = new Utf8JsonWriter(stream, new JsonWriterOptions { Encoder = PayloadOptions.Encoder }))
        {
            w.WriteStartObject();
            w.WriteNumber("v", SchemaMajor);
            w.WriteString("session", Session);
            w.WriteString("session_started_at", Timestamps.Format(SessionStartedAt));
            w.WriteNumber("seq", Seq);
            w.WriteString("ts", Timestamps.Format(Ts));
            w.WriteString("type", Type);
            WriteNullableString(w, "game", Game);
            WriteNullableString(w, "match", Match);
            w.WriteBoolean("verified", Verified);
            w.WritePropertyName("data");
            JsonSerializer.Serialize(w, Data, PayloadOptions);
            w.WritePropertyName("ref");
            if (Ref is null) w.WriteNullValue(); else JsonSerializer.Serialize(w, Ref, PayloadOptions);
            w.WriteEndObject();
        }

        return Encoding.UTF8.GetString(stream.ToArray()) + "\n";
    }

    private static void WriteNullableString(Utf8JsonWriter w, string name, string? value)
    {
        if (value is null) w.WriteNull(name); else w.WriteString(name, value);
    }
}
