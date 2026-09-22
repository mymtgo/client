using System.Text;
using System.Text.Json;
using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Status;

public sealed record SidecarStatus(
    int Pid,
    SidecarState State,
    string? MtgoVersion,
    string SdkVersion,
    string SidecarVersion,
    DateTimeOffset? AttachedAt,
    long LastEventSeq,
    string? CurrentFile,
    DateTimeOffset Heartbeat,
    string? LastError)
{
    public static SidecarStatus Initial(int pid, string sdkVersion, string sidecarVersion, DateTimeOffset now) =>
        new(pid, SidecarState.Waiting, null, sdkVersion, sidecarVersion, null, 0, null, now, null);

    public string ToJson()
    {
        using var stream = new MemoryStream();
        using (var w = new Utf8JsonWriter(stream))
        {
            w.WriteStartObject();
            w.WriteNumber("pid", Pid);
            w.WriteString("state", SidecarStateNames.ToWire(State));
            if (MtgoVersion is null) { w.WriteNull("mtgo_version"); } else { w.WriteString("mtgo_version", MtgoVersion); }
            w.WriteString("sdk_version", SdkVersion);
            w.WriteString("sidecar_version", SidecarVersion);
            if (AttachedAt is null) { w.WriteNull("attached_at"); } else { w.WriteString("attached_at", Timestamps.Format(AttachedAt.Value)); }
            w.WriteNumber("last_event_seq", LastEventSeq);
            if (CurrentFile is null) { w.WriteNull("current_file"); } else { w.WriteString("current_file", CurrentFile); }
            w.WriteString("heartbeat", Timestamps.Format(Heartbeat));
            if (LastError is null) { w.WriteNull("last_error"); } else { w.WriteString("last_error", LastError); }
            w.WriteEndObject();
        }

        return Encoding.UTF8.GetString(stream.ToArray());
    }
}
