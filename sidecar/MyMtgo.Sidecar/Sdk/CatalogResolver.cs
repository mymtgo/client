using System.Collections.Concurrent;
using Microsoft.Extensions.Logging;
using MTGOSDK.API.Collection;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>
/// CTN (card texture number) to catalog id. Every lookup is a remote dictionary read against
/// MTGO's heap, so a resolved id is cached for the life of the session. A miss is cached only for
/// <see cref="MissRetryAfter"/>: a permanent miss (a token, say) must not cost one round trip per
/// card per tick, but a transient failure (card data not ready yet) must not be remembered forever
/// either, since that would hold the probe's battlefield check false for a whole session.
/// </summary>
public sealed class CatalogResolver(ILogger log, TimeProvider? time = null)
{
    public static readonly TimeSpan MissRetryAfter = TimeSpan.FromSeconds(30);

    private readonly TimeProvider _time = time ?? TimeProvider.System;
    private readonly ConcurrentDictionary<int, int> _cache = new();
    private readonly ConcurrentDictionary<int, long> _missRetryAt = new();

    public int? Resolve(int ctn)
    {
        if (ctn <= 0)
        {
            return null;
        }

        if (_cache.TryGetValue(ctn, out var cached))
        {
            return cached;
        }

        var now = _time.GetTimestamp();
        if (_missRetryAt.TryGetValue(ctn, out var retryAt) && now < retryAt)
        {
            return null;
        }

        var resolved = Lookup(ctn);
        if (resolved is { } id)
        {
            _cache[ctn] = id;
            _missRetryAt.TryRemove(ctn, out _);
        }
        else
        {
            _missRetryAt[ctn] = now + (long)(MissRetryAfter.TotalSeconds * _time.TimestampFrequency);
        }

        return resolved;
    }

    private int? Lookup(int ctn)
    {
        try
        {
            var card = CollectionManager.GetCardByTextureId(ctn);
            if (card is null)
            {
                log.LogDebug("catalog lookup returned nothing for ctn {Ctn}", ctn);

                return null;
            }

            var id = card.Id;

            return id > 0 ? id : null;
        }
        catch (Exception ex)
        {
            log.LogDebug(ex, "catalog lookup failed for ctn {Ctn}", ctn);

            return null;
        }
    }
}
