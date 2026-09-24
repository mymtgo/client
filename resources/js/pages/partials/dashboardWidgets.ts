import DashboardArchetypeStats from '@/pages/partials/DashboardArchetypeStats.vue';
import DashboardDecks from '@/pages/partials/DashboardDecks.vue';
import DashboardDeckStats from '@/pages/partials/DashboardDeckStats.vue';
import DashboardFormatStats from '@/pages/partials/DashboardFormatStats.vue';
import DashboardKpiStrip from '@/pages/partials/DashboardKpiStrip.vue';
import DashboardLeagueResults from '@/pages/partials/DashboardLeagueResults.vue';
import DashboardLimitedLeague from '@/pages/partials/DashboardLimitedLeague.vue';
import DashboardLimitedPicks from '@/pages/partials/DashboardLimitedPicks.vue';
import DashboardMatchupSpread from '@/pages/partials/DashboardMatchupSpread.vue';
import DashboardRecentMatches from '@/pages/partials/DashboardRecentMatches.vue';
import DashboardRollingForm from '@/pages/partials/DashboardRollingForm.vue';
import DashboardSessionRecap from '@/pages/partials/DashboardSessionRecap.vue';
import type { Component } from 'vue';

export type WidgetKey =
    | 'kpi_strip'
    | 'league_results'
    | 'rolling_form'
    | 'last_session'
    | 'deck_performance'
    | 'matchup_spread'
    | 'recent_matches'
    | 'deck_stats'
    | 'archetype_stats'
    | 'limited_league'
    | 'limited_picks'
    | 'format_stats';

export type WidgetSpan = 3 | 4 | 6 | 12;

export type WidgetConfigValue = string | number | boolean | string[] | null;
export type WidgetConfig = Record<string, WidgetConfigValue>;

export type WidgetInstance = {
    id: string;
    type: WidgetKey;
    label: string;
    span: WidgetSpan;
    maxColumns: number;
    config: WidgetConfig;
};

export type LayoutRow = Pick<WidgetInstance, 'id' | 'type' | 'config'>;

export type WidgetOptions = {
    decks: { id: number; name: string; format: string; coverArt: string | null }[];
    archetypes: { id: number; name: string; format: string }[];
    sets: { code: string; name: string }[];
    formats: { value: string; label: string }[];
};

/** Mirrors the PHP registry. Keep in sync when adding a widget. */
export const WIDGET_CATALOG: Record<WidgetKey, { label: string; span: WidgetSpan; allowsMultiple: boolean; defaultConfig: WidgetConfig }> = {
    kpi_strip: { label: 'Overview', span: 12, allowsMultiple: false, defaultConfig: {} },
    league_results: { label: 'League results', span: 4, allowsMultiple: false, defaultConfig: { format: null } },
    rolling_form: { label: 'Rolling form', span: 4, allowsMultiple: false, defaultConfig: {} },
    last_session: { label: 'Last session', span: 4, allowsMultiple: false, defaultConfig: {} },
    deck_performance: { label: 'Deck performance', span: 6, allowsMultiple: false, defaultConfig: {} },
    matchup_spread: { label: 'Top matchups', span: 6, allowsMultiple: false, defaultConfig: {} },
    recent_matches: { label: 'Recent matches', span: 12, allowsMultiple: false, defaultConfig: {} },
    deck_stats: { label: 'Deck stats', span: 3, allowsMultiple: true, defaultConfig: { deck_id: null } },
    archetype_stats: { label: 'Archetype stats', span: 3, allowsMultiple: true, defaultConfig: { archetype_id: null } },
    limited_league: { label: 'Last limited league', span: 4, allowsMultiple: false, defaultConfig: {} },
    limited_picks: { label: 'Top limited picks', span: 4, allowsMultiple: false, defaultConfig: { set_code: null } },
    format_stats: { label: 'Format win rates', span: 12, allowsMultiple: false, defaultConfig: { formats: [] } },
};

export const WIDGET_KEYS = Object.keys(WIDGET_CATALOG) as WidgetKey[];

export const DEFAULT_LAYOUT: LayoutRow[] = [
    { id: 'default-kpi-strip', type: 'kpi_strip', config: {} },
    { id: 'default-league-results', type: 'league_results', config: {} },
    { id: 'default-rolling-form', type: 'rolling_form', config: {} },
    { id: 'default-last-session', type: 'last_session', config: {} },
    { id: 'default-deck-performance', type: 'deck_performance', config: {} },
    { id: 'default-matchup-spread', type: 'matchup_spread', config: {} },
    { id: 'default-recent-matches', type: 'recent_matches', config: {} },
];

export const widgetComponents: Record<WidgetKey, Component> = {
    kpi_strip: DashboardKpiStrip,
    league_results: DashboardLeagueResults,
    rolling_form: DashboardRollingForm,
    last_session: DashboardSessionRecap,
    deck_performance: DashboardDecks,
    matchup_spread: DashboardMatchupSpread,
    recent_matches: DashboardRecentMatches,
    deck_stats: DashboardDeckStats,
    archetype_stats: DashboardArchetypeStats,
    limited_league: DashboardLimitedLeague,
    limited_picks: DashboardLimitedPicks,
    format_stats: DashboardFormatStats,
};

/**
 * Existing partials keep their own prop names; new partials take `data` and
 * `config`. This maps a widget's resolved payload onto whichever shape the
 * component expects, with the same fallbacks the old page used.
 */
export const widgetProps: Record<WidgetKey, (data: unknown, config: WidgetConfig) => Record<string, unknown>> = {
    kpi_strip: (data) => ({ ...(data as Record<string, unknown>) }),
    league_results: (data) => ({ leagueDistribution: data ?? { buckets: {}, trophies: 0, dropped: 0, total: 0, formatLabel: null } }),
    rolling_form: (data) => ({ rollingForm: data ?? { results: [], winrate: 0, allTimeWinrate: 0, delta: 0 } }),
    last_session: (data) => ({ lastSession: data ?? null }),
    deck_performance: (data) => ({ deckStats: data ?? [] }),
    matchup_spread: (data) => ({ matchupSpread: data ?? [] }),
    recent_matches: (data) => ({ matches: data ?? [] }),
    deck_stats: (data, config) => ({ data: data ?? null, config }),
    archetype_stats: (data, config) => ({ data: data ?? null, config }),
    limited_league: (data, config) => ({ data: data ?? null, config }),
    limited_picks: (data, config) => ({ data: data ?? null, config }),
    format_stats: (data, config) => ({ data: data ?? [], config }),
};

const GRID_COLUMNS = 12;

/** Static so Tailwind sees every class. Keys are 12-column spans. */
export const COL_SPAN_CLASS: Record<number, string> = {
    1: 'lg:col-span-1',
    2: 'lg:col-span-2',
    3: 'lg:col-span-3',
    4: 'lg:col-span-4',
    5: 'lg:col-span-5',
    6: 'lg:col-span-6',
    7: 'lg:col-span-7',
    8: 'lg:col-span-8',
    9: 'lg:col-span-9',
    10: 'lg:col-span-10',
    11: 'lg:col-span-11',
    12: 'lg:col-span-12',
};

/**
 * Pack widgets into rows in order, then stretch each row towards the full
 * width so rows rarely end with a hole. A widget's preferred span (of 12) is
 * the starting size; spare columns are handed out one at a time, in order,
 * to widgets still under their maximum. A lone small card therefore stops at
 * its maximum instead of ballooning to full width, and any hole left is the
 * user's cue to reorder.
 */
export function packRows(layout: WidgetInstance[]): { instance: WidgetInstance; columns: number }[] {
    const rows: WidgetInstance[][] = [];
    let current: WidgetInstance[] = [];
    let used = 0;

    for (const instance of layout) {
        const base = instance.span;
        if (used + base > GRID_COLUMNS && current.length > 0) {
            rows.push(current);
            current = [];
            used = 0;
        }
        current.push(instance);
        used += base;
    }
    if (current.length > 0) rows.push(current);

    return rows.flatMap((row) => {
        const columns = row.map((i) => i.span);
        let spare = GRID_COLUMNS - columns.reduce((a, b) => a + b, 0);
        let grew = true;
        while (spare > 0 && grew) {
            grew = false;
            for (let idx = 0; idx < row.length && spare > 0; idx += 1) {
                if (columns[idx] < row[idx].maxColumns) {
                    columns[idx] += 1;
                    spare -= 1;
                    grew = true;
                }
            }
        }
        return row.map((instance, idx) => ({ instance, columns: columns[idx] }));
    });
}
