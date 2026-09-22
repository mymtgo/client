using System.Text.Json;
using Microsoft.Extensions.Logging;
using MyMtgo.Sidecar.Core.Envelope;

namespace MyMtgo.Sidecar.Core.Gate;

public sealed record GateDecision(bool Allowed, string Source);

/// <summary>
/// Remote known-good MTGO version list. A blocked version puts the sidecar into
/// version_blocked where only session_started and probe are emitted. Every failure path
/// here degrades toward "allow": the PHP side has its own gates and a sidecar that never
/// captures anything is the worse outcome.
/// </summary>
public sealed class VersionGate(string cacheDirectory, HttpMessageHandler? handler, IClock clock, ILogger logger)
{
    public static readonly TimeSpan Ttl = TimeSpan.FromHours(6);
    private static readonly TimeSpan FetchTimeout = TimeSpan.FromSeconds(10);

    public async Task<GateDecision> DecideAsync(string? configUrl, string mtgoVersion, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(configUrl))
        {
            return new(true, "no_config");
        }

        var cache = KnownGoodCache.Load(cacheDirectory);
        if (cache is not null && clock.UtcNow - cache.FetchedAt < Ttl)
        {
            return Decide(cache.Versions, mtgoVersion, "cache");
        }

        var fetched = await FetchAsync(configUrl, ct);
        if (fetched is not null)
        {
            new KnownGoodCache(clock.UtcNow, fetched).Save(cacheDirectory, logger);
            return Decide(fetched, mtgoVersion, "fetched");
        }

        if (cache is not null)
        {
            return Decide(cache.Versions, mtgoVersion, "cache");
        }

        return new(true, "no_data");
    }

    private static GateDecision Decide(IReadOnlyList<string> versions, string mtgoVersion, string source) =>
        new(versions.Count == 0 || versions.Contains(mtgoVersion, StringComparer.Ordinal), source);

    private async Task<IReadOnlyList<string>?> FetchAsync(string url, CancellationToken ct)
    {
        try
        {
            using var http = handler is null ? new HttpClient() : new HttpClient(handler, disposeHandler: false);
            http.Timeout = FetchTimeout;
            using var response = await http.GetAsync(url, ct);
            if (!response.IsSuccessStatusCode)
            {
                logger.LogWarning("config fetch returned {Status}", (int)response.StatusCode);
                return null;
            }

            using var doc = JsonDocument.Parse(await response.Content.ReadAsStringAsync(ct));
            if (!doc.RootElement.TryGetProperty("known_good_mtgo_versions", out var arr) || arr.ValueKind != JsonValueKind.Array)
            {
                logger.LogWarning("config body lacks a known_good_mtgo_versions array");
                return null;
            }

            var list = new List<string>();
            foreach (var el in arr.EnumerateArray())
            {
                if (el.ValueKind == JsonValueKind.String)
                {
                    list.Add(el.GetString()!);
                }
            }
            return list;
        }
        catch (Exception ex) when (ex is HttpRequestException or JsonException or TaskCanceledException or IOException)
        {
            logger.LogWarning(ex, "config fetch failed");
            return null;
        }
    }
}
