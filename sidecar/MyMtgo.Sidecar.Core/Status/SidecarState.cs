namespace MyMtgo.Sidecar.Core.Status;

public enum SidecarState { Waiting, Attached, Degraded, VersionBlocked, Error }

public static class SidecarStateNames
{
    public static string ToWire(SidecarState state) => state switch
    {
        SidecarState.Waiting => "waiting",
        SidecarState.Attached => "attached",
        SidecarState.Degraded => "degraded",
        SidecarState.VersionBlocked => "version_blocked",
        SidecarState.Error => "error",
        _ => throw new ArgumentOutOfRangeException(nameof(state), state, null),
    };
}
