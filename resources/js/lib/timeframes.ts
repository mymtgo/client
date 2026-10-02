import { parseDate, type CalendarDate } from '@internationalized/date';

export type TimeframeKey = 'alltime' | 'year' | 'monthly' | 'biweekly' | 'week';

export const TIMEFRAME_OPTIONS: { value: TimeframeKey; label: string }[] = [
    { value: 'alltime', label: 'All time' },
    { value: 'year', label: 'This year' },
    { value: 'monthly', label: '30 days' },
    { value: 'biweekly', label: '2 weeks' },
    { value: 'week', label: '7 days' },
];

/** A custom range travels in the same `timeframe` param as the presets: `YYYY-MM-DD..YYYY-MM-DD`, both days inclusive. */
const CUSTOM_RANGE = /^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/;

export function customRange(timeframe: string): { start: CalendarDate; end: CalendarDate } | null {
    const match = CUSTOM_RANGE.exec(timeframe);
    if (!match) return null;

    try {
        return { start: parseDate(match[1]), end: parseDate(match[2]) };
    } catch {
        return null;
    }
}

export function customTimeframe(start: CalendarDate, end: CalendarDate): string {
    const [from, to] = start.compare(end) <= 0 ? [start, end] : [end, start];

    return `${from.toString()}..${to.toString()}`;
}

/** Days a custom range covers, both ends included; null for a preset. */
export function customRangeDays(timeframe: string): number | null {
    const range = customRange(timeframe);
    if (!range) return null;

    return Math.abs(range.end.compare(range.start)) + 1;
}

const dayFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });
const dayYearFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

/** "1 Sep to 15 Sep 2026", with the year on the start too when the range crosses one. */
export function customRangeLabel(timeframe: string): string | null {
    const range = customRange(timeframe);
    if (!range) return null;

    const start = range.start.toDate('UTC');
    const end = range.end.toDate('UTC');
    const startText = range.start.year === range.end.year ? dayFormat.format(start) : dayYearFormat.format(start);

    return range.start.compare(range.end) === 0 ? dayYearFormat.format(end) : `${startText} to ${dayYearFormat.format(end)}`;
}

export function timeframeLabel(key: string): string {
    return customRangeLabel(key) ?? TIMEFRAME_OPTIONS.find((o) => o.value === key)?.label ?? 'All time';
}
