export type VersionStats = {
    id: number | null;
    label: string;
    isCurrent: boolean;
    dateLabel: string | null;
    matchesWon: number;
    matchesLost: number;
    gamesWon: number;
    gamesLost: number;
    matchWinrate: number;
    gameWinrate: number;
    gamesOtpWon: number;
    gamesOtpLost: number;
    otpRate: number;
    gamesOtdWon: number;
    gamesOtdLost: number;
    otdRate: number;
};

export type MatchupSpread = {
    archetype_id: number;
    name: string;
    color_identity: string | null;
    match_winrate: number;
    game_winrate: number;
    otp_winrate: number;
    avg_turns: number | null;
    matches: number;
    match_record: string;
    game_record: string;
    match_wins: number;
    match_losses: number;
    games_won: number;
    games_lost: number;
    total_games: number;
};

export type PerGameWinrate = {
    gameNumber: number;
    winrate: number;
    record: string;
    wins: number;
    losses: number;
};

export type MatchupDetail = {
    matchWinrate: number;
    gameWinrate: number;
    matchRecord: string;
    gameRecord: string;
    matches: number;
    perGameWinrates: PerGameWinrate[];
    otpWinrate: number;
    otpRecord: string;
    otdWinrate: number;
    otdRecord: string;
    avgTurns: number | null;
    avgMulligans: number | null;
    onPlayRate: number;
    bestCards: MatchupCardStat[];
    worstCards: MatchupCardStat[];
    matchHistory: MatchupHistoryEntry[];
};

export type MatchupCardStat = {
    oracleId: string;
    name: string;
    image: string | null;
    gamesKept: number;
    keptWon: number;
    keptLost: number;
    keptWinrate: number;
};

export type MatchupHistoryEntry = {
    id: number;
    date: string;
    dateFormatted: string;
    isLeague: boolean;
    leagueName: string | null;
    opponentName: string | null;
    score: string;
    outcome: 'win' | 'loss' | 'draw' | 'unknown';
    gameResults: (boolean | null)[];
};

export type VersionDecklist = {
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
};
