export type TournamentMatch = {
    id: number;
    state: string;
    result: 'W' | 'L' | null;
    opponentName: string | null;
    opponentArchetype: string | null;
    opponentArchetypeId: number | null;
    gameResults: Array<{ result: 'W' | 'L'; onPlay: boolean | null }>;
    startedAt: string;
    startedAtHuman: string;
    durationSeconds: number | null;
    roundNumber: number | null;
    notes: string | null;
};

export type TournamentRun = {
    id: number;
    name: string;
    format: string;
    mtgo_event_id: number;
    startedAt: string | null;
    startedAtHuman: string | null;
    deck: {
        id: number;
        name: string;
        colorIdentity?: string | null;
        coverArt?: string | null;
        coverArtBase64?: string | null;
    } | null;
    versionLabel: string | null;
    results: Array<'W' | 'L'>;
    matches: TournamentMatch[];
    avgMatchSeconds: number | null;
    topOpponentArchetype: string | null;
    gameWins: number;
    gameLosses: number;
    onPlayRecord: { wins: number; losses: number };
    onDrawRecord: { wins: number; losses: number };
    topMatchups: Array<{ archetype: string; wins: number; losses: number }>;
    matches_count: number;
    inProgressCount: number;
    wins: number;
    losses: number;
    name_synthesized: boolean;
};
