namespace MyMtgo.Sidecar.Core.Envelope;

public interface IClock
{
    DateTimeOffset UtcNow { get; }
}

public sealed class SystemClock : IClock
{
    public static readonly SystemClock Instance = new();
    public DateTimeOffset UtcNow => DateTimeOffset.UtcNow;
}

public static class Timestamps
{
    /// <summary>UTC, millisecond precision, Z suffix. Matches what PHP's Carbon parses on the other side.</summary>
    public static string Format(DateTimeOffset value) =>
        value.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", System.Globalization.CultureInfo.InvariantCulture);
}
