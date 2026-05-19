export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type {
    ReportsCurrentPage,
    ReportArchetypeOption,
    ReportFormatOption,
    ReportsSharedProps,
} from './reports';

declare global {
    interface Window {
        Native?: {
            on: (event: string, callback: (payload: unknown, event: string) => void) => void;
        };
    }
}
