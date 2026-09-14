import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(urlToCheck: NonNullable<InertiaLinkProps['href']>, currentUrl: string) {
    return toUrl(urlToCheck) === currentUrl;
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

/**
 * Parse a `YYYY-MM-DD` day key as local midnight. `new Date('YYYY-MM-DD')`
 * parses as UTC midnight, which renders as the previous day anywhere west of UTC.
 */
export function parseLocalDate(dayKey: string): Date {
    const [year, month, day] = dayKey.split('-').map(Number);
    return new Date(year, month - 1, day);
}

/**
 * Escape text for interpolation into an HTML string (e.g. chart tooltip templates).
 */
export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
