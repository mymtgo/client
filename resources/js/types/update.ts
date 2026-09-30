export type UpdateState = 'up_to_date' | 'checking' | 'downloading' | 'ready' | 'error';

export type UpdateStatus = {
    current: string;
    available: string | null;
    status: UpdateState;
    checkedAt: string | null;
    error: string | null;
    active: boolean;
};
