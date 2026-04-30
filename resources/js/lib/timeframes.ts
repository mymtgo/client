export type TimeframeKey = 'alltime' | 'year' | 'monthly' | 'biweekly' | 'week';

export const TIMEFRAME_OPTIONS: { value: TimeframeKey; label: string }[] = [
    { value: 'alltime', label: 'All time' },
    { value: 'year', label: 'This year' },
    { value: 'monthly', label: '30 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'week', label: '7 days' },
];

export function timeframeLabel(key: string): string {
    return TIMEFRAME_OPTIONS.find((o) => o.value === key)?.label ?? 'All time';
}
