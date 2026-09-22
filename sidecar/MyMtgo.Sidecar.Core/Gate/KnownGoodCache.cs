using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;

namespace MyMtgo.Sidecar.Core.Gate;

public sealed record KnownGoodCache(
    [property: JsonPropertyName("fetched_at")] DateTimeOffset FetchedAt,
    [property: JsonPropertyName("versions")] IReadOnlyList<string> Versions)
{
    public const string FileName = "known-good-cache.json";

    public static KnownGoodCache? Load(string directory)
    {
        var path = Path.Combine(directory, FileName);
        if (!File.Exists(path))
        {
            return null;
        }

        try
        {
            var cache = JsonSerializer.Deserialize<KnownGoodCache>(File.ReadAllText(path));
            // A hand-edited or truncated file with a null list is treated as no cache at all.
            return cache is { Versions: not null } ? cache : null;
        }
        catch (JsonException)
        {
            return null;
        }
    }

    /// <summary>
    /// Writes the cache, or logs and carries on. A cache we cannot write costs one refetch on
    /// the next attach; letting the failure out of here would abort the attach itself, which
    /// contradicts this class's policy that every failure path degrades toward allow.
    /// </summary>
    public void Save(string directory, ILogger? logger = null)
    {
        var path = Path.Combine(directory, FileName);
        var tmp = path + ".tmp";
        try
        {
            File.WriteAllText(tmp, JsonSerializer.Serialize(this));
            File.Move(tmp, path, overwrite: true);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            logger?.LogWarning(ex, "could not write {FileName} to {Directory}", FileName, directory);
        }
    }
}
