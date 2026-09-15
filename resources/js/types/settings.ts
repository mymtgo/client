export type SettingsCurrentPage = 'general' | 'overlays' | 'storage' | 'privacy' | 'advanced';

export type PathStatus = {
    valid: boolean;
    fileCount: number;
    message: string;
};

export type SettingsAccount = {
    id: number;
    username: string;
    tracked: boolean;
    active: boolean;
};

export type PendingMatch = {
    id: number;
    format: string;
    outcome: string | null;
    started_at: string;
};
