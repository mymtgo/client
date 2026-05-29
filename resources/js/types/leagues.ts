export type LeagueGameResult = {
    result: 'W' | 'L';
    onPlay: boolean | null;
};

export type LeagueMatch = {
    id: number;
    result: 'W' | 'L';
    opponentName: string | null;
    opponentArchetype: string | null;
    opponentArchetypeId: number | null;
    gameResults: LeagueGameResult[];
    startedAt: string;
    startedAtHuman: string;
    durationSeconds: number | null;
    notes: string | null;
};

export type LeagueClassification = 'TROPHY' | 'CASH' | 'FINISH' | 'BRICK' | 'LIVE' | 'EMPTY';

export type LeagueTimeOfDay = 'morning' | 'afternoon' | 'evening' | 'night';

export type LeagueRecord = { wins: number; losses: number };

export type LeagueMatchupSummary = {
    archetype: string;
    wins: number;
    losses: number;
};

export type LeagueDeck = {
    id: number;
    name: string;
    colorIdentity?: string | null;
    coverArt?: string | null;
    coverArtBase64?: string | null;
};

export type LeagueRun = {
    id: number;
    name: string;
    format: string;
    deck: LeagueDeck | null;
    versionLabel?: string | null;
    startedAt: string;
    startedAtHuman: string | null;
    droppedAt: string | null;
    droppedAtHuman: string | null;
    results: ('W' | 'L' | null)[];
    state: 'active' | 'complete' | 'partial' | 'dropped';
    manual: boolean;
    notes: string | null;
    classification: LeagueClassification;
    liveRound: number | null;
    avgMatchSeconds: number | null;
    timeOfDay: LeagueTimeOfDay | null;
    topOpponentArchetype: string | null;
    gameWins: number;
    gameLosses: number;
    onPlayRecord: LeagueRecord;
    onDrawRecord: LeagueRecord;
    topMatchups: LeagueMatchupSummary[];
    tixDelta: number | null;
    matches: LeagueMatch[];
};

export type LeagueKpis = {
    runs: { total: number; completed: number; live: number; decks: number };
    trophies: number;
    trophyRate: number | null;
    cashRate: number | null;
    avgFinish: number | null;
    topMatchup: { archetype: string; wins: number; losses: number; count: number } | null;
};

export type LeagueDeckOption = { id: number; name: string };

export type LeagueFiltersState = {
    format: string;
    state: string;
    deck: number | null;
    q: string;
    sort: string;
};

export type ManualLeagueDeckOption = {
    id: number;
    name: string;
    format: string;
};

export type AvailableMatch = {
    id: number;
    startedAt: string;
    startedAtHuman: string;
    endedAt: string | null;
    result: 'W' | 'L' | 'D' | null;
    opponentName: string | null;
    opponentArchetype: string | null;
    gameRecord: string;
};
