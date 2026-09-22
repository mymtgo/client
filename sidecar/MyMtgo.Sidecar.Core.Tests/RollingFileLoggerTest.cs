using Microsoft.Extensions.Logging;
using MyMtgo.Sidecar.Core.Logging;

namespace MyMtgo.Sidecar.Core.Tests;

public class RollingFileLoggerTest : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "sidecar-log-" + Guid.NewGuid().ToString("N"));
    public RollingFileLoggerTest() => Directory.CreateDirectory(_dir);
    public void Dispose() => Directory.Delete(_dir, recursive: true);

    [Fact]
    public void Writes_level_category_and_message_on_one_line()
    {
        var path = Path.Combine(_dir, "sidecar.log");
        using (var provider = new RollingFileLoggerProvider(path))
        {
            var logger = provider.CreateLogger("Attach");
            logger.LogInformation("attached to MTGO {Version}", "1.2.3");
        }
        var line = File.ReadAllLines(path).Single();
        Assert.Matches(@"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z \[INF\] Attach: attached to MTGO 1\.2\.3$", line);
    }

    [Fact]
    public void Rolls_at_max_bytes_and_keeps_three_files()
    {
        var path = Path.Combine(_dir, "sidecar.log");
        using (var provider = new RollingFileLoggerProvider(path, maxBytes: 500, keep: 3))
        {
            var logger = provider.CreateLogger("T");
            for (var i = 0; i < 200; i++) logger.LogWarning("line {I} padding padding padding padding", i);
        }
        // Deviation from brief: method-group `Select(Path.GetFileName)` loses the
        // NotNullIfNotNull nullability flow and fails CS8604 under TreatWarningsAsErrors
        // at the Path.Combine call below. Calling it via a lambda keeps the assertion
        // intent identical while preserving non-null inference.
        var files = Directory.GetFiles(_dir).Select(p => Path.GetFileName(p)).Order().ToList();
        Assert.Equal(["sidecar.1.log", "sidecar.2.log", "sidecar.log"], files);
        Assert.All(files, f => Assert.InRange(new FileInfo(Path.Combine(_dir, f)).Length, 1, 700));
    }

    [Fact]
    public void Exceptions_are_appended_on_following_lines()
    {
        var path = Path.Combine(_dir, "sidecar.log");
        using (var provider = new RollingFileLoggerProvider(path))
        {
            provider.CreateLogger("T").LogError(new InvalidOperationException("nope"), "failed");
        }
        var text = File.ReadAllText(path);
        Assert.Contains("[ERR] T: failed", text);
        Assert.Contains("InvalidOperationException: nope", text);
    }
}
