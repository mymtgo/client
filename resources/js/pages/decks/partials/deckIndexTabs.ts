import ArchetypeCardStatsController from '@/actions/App/Http/Controllers/Decks/Archetypes/CardStatsController';
import ArchetypeMatchesController from '@/actions/App/Http/Controllers/Decks/Archetypes/MatchesController';
import ArchetypeMatchupsController from '@/actions/App/Http/Controllers/Decks/Archetypes/MatchupsController';
import IndexController from '@/actions/App/Http/Controllers/Decks/IndexController';
import type { DeckIndexTab } from '@/types/decks';

export const DECK_INDEX_TABS: { key: DeckIndexTab; label: string }[] = [
    { key: 'decks', label: 'Decks' },
    { key: 'matches', label: 'Matches' },
    { key: 'matchups', label: 'Matchups' },
    { key: 'card-stats', label: 'Card Stats' },
];

/**
 * URL for one tab of the archetype view. The format filter travels with every
 * tab so the sidebar keeps its scope; the timeframe only matters to the stats
 * tabs, and `alltime` is the default so it is left off the query.
 */
export function deckIndexTabUrl(tab: DeckIndexTab, archetypeId: number, format: string, timeframe = 'alltime'): string {
    if (tab === 'decks') {
        return IndexController.url({ query: { archetype: archetypeId, format: format || undefined } });
    }

    const query = { format: format || undefined, timeframe: timeframe !== 'alltime' ? timeframe : undefined };
    const controller = {
        matches: ArchetypeMatchesController,
        matchups: ArchetypeMatchupsController,
        'card-stats': ArchetypeCardStatsController,
    }[tab];

    return controller.url({ archetype: archetypeId }, { query });
}
