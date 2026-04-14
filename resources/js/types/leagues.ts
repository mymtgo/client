export type LeagueGameResult = {
    result: 'W' | 'L';
    onPlay: boolean | null;
};

export type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    gameResults: LeagueGameResult[];
    startedAt: string;
    startedAtHuman: string;
};

export type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: { id: number; name: string; colorIdentity?: string | null; coverArt?: string | null; coverArtBase64?: string | null } | null;
    versionLabel?: string | null;
    startedAt: string;
    startedAtHuman: string | null;
    results: ('W' | 'L' | null)[];
    phantom: boolean;
    state: 'active' | 'complete' | 'partial';
    matches: LeagueMatch[];
};
