export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

declare global {
    interface Window {
        Native?: {
            on: (event: string, callback: (payload: unknown, event: string) => void) => void;
        };
    }
}
