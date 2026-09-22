using System.Net;
using Microsoft.Extensions.Logging.Abstractions;
using MyMtgo.Sidecar.Core.Gate;

namespace MyMtgo.Sidecar.Core.Tests;

public sealed class StubHandler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
{
    public int Calls { get; private set; }
    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        Calls++;
        return Task.FromResult(respond(request));
    }
}

public class VersionGateTest : IDisposable
{
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "sidecar-gate-" + Guid.NewGuid().ToString("N"));
    private readonly FakeClock _clock = new(new DateTimeOffset(2026, 9, 21, 12, 0, 0, TimeSpan.Zero));
    public VersionGateTest() => Directory.CreateDirectory(_dir);
    public void Dispose() => Directory.Delete(_dir, recursive: true);

    private static HttpResponseMessage Json(string body) => new(HttpStatusCode.OK) { Content = new StringContent(body, System.Text.Encoding.UTF8, "application/json") };
    private VersionGate Gate(StubHandler? h) => new(_dir, h, _clock, NullLogger.Instance);

    [Fact]
    public async Task No_config_url_allows_without_touching_the_network()
    {
        var h = new StubHandler(_ => throw new InvalidOperationException("must not be called"));
        var d = await Gate(h).DecideAsync(null, "1.2.3.4", CancellationToken.None);
        Assert.True(d.Allowed);
        Assert.Equal("no_config", d.Source);
        Assert.Equal(0, h.Calls);
        d = await Gate(h).DecideAsync("", "1.2.3.4", CancellationToken.None);
        Assert.True(d.Allowed);
    }

    [Fact]
    public async Task Fetched_list_blocks_unknown_versions_and_allows_listed_ones()
    {
        var h = new StubHandler(_ => Json("{\"authority_flags\":{\"username\":true},\"known_good_mtgo_versions\":[\"1.2.3.4\",\"1.2.3.5\"]}"));
        var g = Gate(h);
        var allowed = await g.DecideAsync("https://api.test/sidecar/config", "1.2.3.5", CancellationToken.None);
        Assert.True(allowed.Allowed);
        Assert.Equal("fetched", allowed.Source);
        var blocked = await g.DecideAsync("https://api.test/sidecar/config", "9.9.9.9", CancellationToken.None);
        Assert.False(blocked.Allowed);
        Assert.Equal("cache", blocked.Source);
        Assert.Equal(1, h.Calls);
    }

    [Fact]
    public async Task Empty_list_means_no_opinion_and_allows()
    {
        var h = new StubHandler(_ => Json("{\"known_good_mtgo_versions\":[]}"));
        Assert.True((await Gate(h).DecideAsync("https://api.test/c", "any", CancellationToken.None)).Allowed);
    }

    [Fact]
    public async Task Successful_fetch_writes_cache_and_cache_is_used_within_ttl()
    {
        var h = new StubHandler(_ => Json("{\"known_good_mtgo_versions\":[\"1.0\"]}"));
        await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        Assert.True(File.Exists(Path.Combine(_dir, "known-good-cache.json")));

        _clock.Advance(TimeSpan.FromHours(5));
        var d = await Gate(h).DecideAsync("https://api.test/c", "2.0", CancellationToken.None);
        Assert.Equal(1, h.Calls);
        Assert.False(d.Allowed);
        Assert.Equal("cache", d.Source);
    }

    [Fact]
    public async Task Cache_older_than_ttl_triggers_a_refetch()
    {
        var h = new StubHandler(_ => Json("{\"known_good_mtgo_versions\":[\"1.0\"]}"));
        await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        _clock.Advance(TimeSpan.FromHours(7));
        await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        Assert.Equal(2, h.Calls);
    }

    [Fact]
    public async Task Fetch_failure_falls_back_to_a_stale_cache()
    {
        var ok = new StubHandler(_ => Json("{\"known_good_mtgo_versions\":[\"1.0\"]}"));
        await Gate(ok).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        _clock.Advance(TimeSpan.FromDays(3));
        var down = new StubHandler(_ => new HttpResponseMessage(HttpStatusCode.ServiceUnavailable));
        var d = await Gate(down).DecideAsync("https://api.test/c", "2.0", CancellationToken.None);
        Assert.False(d.Allowed);
        Assert.Equal("cache", d.Source);
    }

    [Theory]
    [InlineData("not json at all")]
    [InlineData("{\"known_good_mtgo_versions\":\"1.0\"}")]
    [InlineData("{\"something_else\":[]}")]
    public async Task Malformed_body_is_treated_as_no_response(string body)
    {
        var h = new StubHandler(_ => Json(body));
        var d = await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        Assert.True(d.Allowed);
        Assert.Equal("no_data", d.Source);
        Assert.False(File.Exists(Path.Combine(_dir, "known-good-cache.json")));
    }

    [Fact]
    public async Task Network_exception_with_no_cache_allows()
    {
        var h = new StubHandler(_ => throw new HttpRequestException("dns"));
        var d = await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        Assert.True(d.Allowed);
        Assert.Equal("no_data", d.Source);
    }

    [Fact]
    public void A_cache_write_to_a_directory_that_does_not_exist_logs_instead_of_throwing()
    {
        var missing = Path.Combine(_dir, "no", "such", "place");
        var cache = new KnownGoodCache(_clock.UtcNow, ["1.0"]);

        cache.Save(missing);

        Assert.False(File.Exists(Path.Combine(missing, KnownGoodCache.FileName)));
    }

    [Fact]
    public async Task A_cache_write_failure_does_not_abort_the_decision()
    {
        // The gate's whole policy is to degrade toward allow. An unwritable cache directory
        // costs a refetch next time; it must never take the attach down with it.
        var unwritable = Path.Combine(_dir, "no", "such", "place");
        var h = new StubHandler(_ => Json("{\"known_good_mtgo_versions\":[\"1.0\"]}"));
        var gate = new VersionGate(unwritable, h, _clock, NullLogger.Instance);

        var d = await gate.DecideAsync("https://api.test/c", "1.0", CancellationToken.None);

        Assert.True(d.Allowed);
        Assert.Equal("fetched", d.Source);
        Assert.Equal(1, h.Calls);
    }

    [Fact]
    public async Task Cache_file_with_null_versions_is_ignored()
    {
        File.WriteAllText(Path.Combine(_dir, "known-good-cache.json"), "{\"fetched_at\":\"2026-09-21T11:00:00+00:00\",\"versions\":null}");
        var h = new StubHandler(_ => throw new HttpRequestException("down"));
        var d = await Gate(h).DecideAsync("https://api.test/c", "1.0", CancellationToken.None);
        Assert.True(d.Allowed);
        Assert.Equal("no_data", d.Source);
    }
}
