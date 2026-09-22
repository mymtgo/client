using MTGOSDK.API.Collection;
using MTGOSDK.API.Play.Games;

namespace MyMtgo.Sidecar.Sdk;

/// <summary>Outcome of one probe run. Shape is the `probe` event's data payload, in key order.</summary>
public sealed record ProbeResult(bool Passed, string? Username, bool UsernameOk, bool CardNamesOk, bool BattlefieldOk)
{
    public Dictionary<string, object?> ToData() => new()
    {
        ["passed"] = Passed,
        ["username"] = Username,
        ["checks"] = new Dictionary<string, object?>
        {
            ["username"] = UsernameOk,
            ["card_names"] = CardNamesOk,
            ["battlefield_cards"] = BattlefieldOk,
        },
    };
}

/// <summary>
/// Cheap read-only assertions that the SDK surface we depend on still behaves. A failed probe
/// does not stop the session; it marks the stream unverified so PHP can discount it.
/// </summary>
public static class Probe
{
    private static readonly string[] s_basics = ["Plains", "Island", "Swamp", "Mountain", "Forest"];

    public static ProbeResult Run(SdkAttachment attachment, Game? liveGame, CatalogResolver catalog)
    {
        var username = attachment.Username;
        var usernameOk = !string.IsNullOrEmpty(username);

        var cardNamesOk = true;
        foreach (var name in s_basics)
        {
            if (!Try(() =>
            {
                var card = CollectionManager.GetCard(name);

                return card is not null && card.Id > 0 && string.Equals(card.Name, name, StringComparison.Ordinal);
            }))
            {
                cardNamesOk = false;

                break;
            }
        }

        var battlefieldOk = liveGame is null || Try(() => SharedBattlefieldOk(liveGame, catalog) && PlayerBattlefieldsOk(liveGame, catalog));

        return new ProbeResult(usernameOk && cardNamesOk && battlefieldOk, username, usernameOk, cardNamesOk, battlefieldOk);
    }

    /// <summary>
    /// Battlefield is modelled per player in MTGO, so the shared zone collection normally has no
    /// Battlefield key at all and the remote indexer throws across IPC before the SDK's own
    /// KeyNotFoundException can be raised. "Cannot get the zone" is therefore not a failure of
    /// anything we depend on; only a zone we can reach whose cards do not read is.
    /// </summary>
    private static bool SharedBattlefieldOk(Game game, CatalogResolver catalog)
    {
        GameZone? zone;
        try
        {
            zone = game.GetGameZone(CardZone.Battlefield);
        }
        catch (Exception)
        {
            return true;
        }

        return zone is null || CardsAreReadable(zone, catalog);
    }

    /// <inheritdoc cref="SharedBattlefieldOk"/>
    private static bool PlayerBattlefieldsOk(Game game, CatalogResolver catalog)
    {
        foreach (var player in game.Players)
        {
            GameZone? zone;
            try
            {
                zone = game.GetGameZone(player, CardZone.Battlefield);
            }
            catch (Exception)
            {
                // No battlefield zone object materialised for this player yet.
                continue;
            }

            if (zone is not null && !CardsAreReadable(zone, catalog))
            {
                return false;
            }
        }

        return true;
    }

    private static bool CardsAreReadable(GameZone zone, CatalogResolver catalog)
    {
        foreach (var card in zone.Cards)
        {
            if (card is null)
            {
                continue;
            }

            if (string.IsNullOrEmpty(card.Name) || catalog.Resolve(card.CTN) is null)
            {
                return false;
            }
        }

        return true;
    }

    /// <summary>Any exception from a check is a failed check, never a failed session.</summary>
    private static bool Try(Func<bool> check)
    {
        try
        {
            return check();
        }
        catch (Exception)
        {
            return false;
        }
    }
}
