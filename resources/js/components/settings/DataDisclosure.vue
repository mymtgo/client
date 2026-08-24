<script setup lang="ts">
/**
 * Plain-language account of what the app sends and receives over the
 * network, and what offline mode changes. Rendered directly beneath the
 * offline mode toggle in Settings so the tradeoff is visible before anyone
 * flips it.
 */
</script>

<template>
    <div class="flex flex-col gap-3 rounded-md border border-border bg-muted/30 p-3">
        <p class="text-xs font-semibold text-foreground">What data is sent</p>

        <div class="max-h-64 overflow-y-auto pr-1">
            <div class="flex flex-col gap-3 text-xs text-muted-foreground">
                <section class="flex flex-col gap-1">
                    <p class="font-medium text-foreground">Sent when offline mode is off</p>
                    <p>
                        After each match, the app sends your MTGO username, the match result, the format and
                        a timestamp, your decklist and its detected archetype, and any league or tournament
                        token and round number. For every game it also sends turn counts, timings and your
                        opening hand: the exact cards you kept, how many hands you mulliganed, whether you
                        were on the play, and your opponent's mulligan count.
                    </p>
                    <p>
                        Your opponent's decklist goes with that scouting data. This is another player's
                        data rather than yours, and it is submitted so the community archetype database
                        stays current. To look up your current opponent's known archetype mid match, the app
                        also sends their MTGO username to the API, so this feature sends a lookup as well as
                        receiving one.
                    </p>
                    <p>
                        Tournament observations are sent for any MTGO tournament event your client syncs,
                        including events you only browse in the lobby rather than enter. Most of them carry
                        a player roster for that event, containing other players' MTGO usernames, numeric
                        login IDs and avatar IDs. Round by round they also carry everyone's standings (rank
                        and points), elimination status, and the round's match pairings, including a match ID
                        and token for every game in the round, exactly as MTGO broadcasts them rather than
                        only the games you played. These are MTGO's own messages passed through unfiltered,
                        so they can contain more than the list above, including a human readable event name
                        that MTGO puts in the same raw message. As with the opponent decklist, this is other
                        players' data rather than yours. The event name the app displays is looked up
                        separately from mymtgo's own tournament records, not read back from what you sent.
                    </p>
                    <p>
                        A closing report is sent when an event ends, carrying one further MTGO login ID. We
                        could not confirm from the client alone whether that ID always identifies another
                        competitor or sometimes your own account, so treat it with the same caution as the
                        rest of this section.
                    </p>
                    <p>
                        Per game, per card statistics are sent together with the archetype they were observed
                        against. When your deck's archetype cannot be confidently detected on your own
                        machine, its decklist is sent to the API for archetype estimation.
                    </p>
                </section>

                <section class="flex flex-col gap-1">
                    <p class="font-medium text-foreground">Sent always, even in offline mode</p>
                    <p>
                        MTGO card identifiers, looked up against the API to get card names, mana costs and an
                        image link so your decks keep rendering. No match, result or player data is attached
                        to these lookups.
                    </p>
                    <p>
                        Every request above, card lookups included, carries a generated device identifier.
                        The app periodically exchanges it for a short lived API key that authenticates this
                        installation, and that exchange keeps happening while offline mode is on because card
                        lookups depend on it.
                    </p>
                </section>

                <section class="flex flex-col gap-1">
                    <p class="font-medium text-foreground">Not affected by offline mode</p>
                    <p>
                        App updates. These download from GitHub through the built in updater and are never
                        blocked by offline mode. Turning it on does not pin your version or stop updates
                        installing.
                    </p>
                    <p>
                        Error reporting. Crash and error reports, including recent app logs, database queries
                        and outgoing network requests as context, go to our error tracking service whatever
                        the toggle is set to. We keep this on unconditionally so that crashes can still be
                        fixed for privacy conscious users.
                    </p>
                    <p>
                        Card images. Once a card's image link is known, the app loads the image itself from
                        Scryfall's servers rather than ours, every time it is shown. Scryfall is a third
                        party, and it sees your IP address and, in near real time, which cards you are
                        looking at. This is independent of offline mode and outside our control. Turning on
                        "Download card images locally" in Settings avoids it after the first load by caching
                        images on your machine instead.
                    </p>
                </section>

                <section class="flex flex-col gap-1">
                    <p class="font-medium text-foreground">What offline mode gives up</p>
                    <p>
                        Community card stats, opponent archetype scouting, archetype catalog updates and
                        tournament names all rely on the traffic above, so expect them to go stale or become
                        unavailable while offline mode is on.
                    </p>
                </section>
            </div>
        </div>

        <p class="text-xs text-foreground">
            Leaving offline mode switched off means you agree to your data being sent as described above.
        </p>

        <p class="text-xs text-muted-foreground/70 italic">Last updated: 24 August 2026</p>
    </div>
</template>
