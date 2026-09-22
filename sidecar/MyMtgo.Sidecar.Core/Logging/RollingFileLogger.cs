using System.Text;
using Microsoft.Extensions.Logging;

namespace MyMtgo.Sidecar.Core.Logging;

/// <summary>
/// Minimal rolling file sink: sidecar.log, rolled to sidecar.1.log, sidecar.2.log when it
/// passes maxBytes. Deliberately dependency free; the SDK takes an ILoggerFactory so this
/// provider serves both the SDK's logs and ours in one file.
/// </summary>
public sealed class RollingFileLoggerProvider(string path, long maxBytes = 5L * 1024 * 1024, int keep = 3) : ILoggerProvider
{
    private readonly object _gate = new();
    private readonly string _path = path;
    private readonly long _maxBytes = maxBytes;
    private readonly int _keep = keep;
    private readonly UTF8Encoding _utf8 = new(false);

    public ILogger CreateLogger(string categoryName) => new RollingFileLogger(this, categoryName);

    public void Dispose() { }

    internal void Write(string line)
    {
        lock (_gate)
        {
            // A diagnostics failure must never surface into an SDK callback thread.
            try
            {
                RollIfNeeded(_utf8.GetByteCount(line) + 1);
                File.AppendAllText(_path, line + "\n", _utf8);
            }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
    }

    private void RollIfNeeded(int incoming)
    {
        var info = new FileInfo(_path);
        if (!info.Exists || info.Length + incoming <= _maxBytes) return;

        var dir = Path.GetDirectoryName(_path)!;
        var stem = Path.GetFileNameWithoutExtension(_path);
        var ext = Path.GetExtension(_path);
        string Numbered(int n) => Path.Combine(dir, $"{stem}.{n}{ext}");

        var last = Numbered(_keep - 1);
        if (File.Exists(last)) File.Delete(last);
        for (var n = _keep - 2; n >= 1; n--)
        {
            if (File.Exists(Numbered(n))) File.Move(Numbered(n), Numbered(n + 1), overwrite: true);
        }
        File.Move(_path, Numbered(1), overwrite: true);
    }

    private sealed class RollingFileLogger(RollingFileLoggerProvider provider, string category) : ILogger
    {
        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;
        public bool IsEnabled(LogLevel logLevel) => logLevel >= LogLevel.Debug;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
        {
            if (!IsEnabled(logLevel)) return;
            var sb = new StringBuilder()
                .Append(DateTimeOffset.UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", System.Globalization.CultureInfo.InvariantCulture))
                .Append(" [").Append(Abbrev(logLevel)).Append("] ")
                .Append(category).Append(": ")
                .Append(formatter(state, exception));
            if (exception is not null) sb.Append('\n').Append(exception);
            provider.Write(sb.ToString());
        }

        private static string Abbrev(LogLevel l) => l switch
        {
            LogLevel.Trace => "TRC", LogLevel.Debug => "DBG", LogLevel.Information => "INF",
            LogLevel.Warning => "WRN", LogLevel.Error => "ERR", LogLevel.Critical => "CRT", _ => "???",
        };
    }
}
