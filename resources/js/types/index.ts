export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type { ReportArchetypeOption } from './reports';

declare global {
    interface Window {
        Native?: {
            on: (event: string, callback: (payload: unknown, event: string) => void) => void;
        };
    }
}
