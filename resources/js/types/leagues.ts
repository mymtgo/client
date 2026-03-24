export type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    games: string;
    startedAt: string;
};

export type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string; colorIdentity?: string | null } | null;
    versionLabel?: string | null;
    startedAt: string;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};
