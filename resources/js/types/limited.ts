export const NO_VALUE = '—';

export type StateVariant = 'default' | 'success' | 'warning' | 'destructive' | 'secondary';

export type LimitedFiltersState = {
    set: string | null;
    kind: 'draft' | 'sealed' | null;
    timeframe: string;
};

export const TIMEFRAMES: { value: string; label: string }[] = [
    { value: 'alltime', label: 'All time' },
    { value: 'monthly', label: '30d' },
    { value: 'week', label: '7d' },
];

export function formatSeconds(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return NO_VALUE;
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

export function pickLabel(pack: number, pick: number): string {
    return `P${pack}p${pick}`;
}

export function timeLabel(iso: string | null): string {
    if (!iso) return NO_VALUE;
    const d = new Date(iso);
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export const COLOR_ORDER = ['W', 'U', 'B', 'R', 'G'] as const;
export type ManaColor = (typeof COLOR_ORDER)[number];

export const COLOR_NAMES: Record<ManaColor, string> = { W: 'White', U: 'Blue', B: 'Black', R: 'Red', G: 'Green' };

export const COLOR_CLASSES: Record<ManaColor | 'C', string> = {
    W: 'bg-[#e9e1c5]',
    U: 'bg-[#4a8fd0]',
    B: 'bg-[#8a7a99]',
    R: 'bg-[#d65a45]',
    G: 'bg-[#4c9a63]',
    C: 'bg-[#9ca3af]',
};

export type DraftHeader = {
    seatIndex: number | null;
    seatCount: number;
    boosterCatalogId: number | null;
    packSize: number;
    picksMade: number;
    picksExpected: number;
    startedAt: string | null;
    endedAt: string | null;
    durationMinutes: number | null;
    avgPickSeconds: number | null;
    avgMarginSeconds: number | null;
    indecisiveCount: number;
    fastestPack: number | null;
    slowestPack: number | null;
    colorsPicked: Partial<Record<ManaColor | 'C', number>>;
    state: string;
};

export type DraftSignal = { color: ManaColor; wheeled: number; seen_twice: number; score: number; share: number };

export type SeenWheelFact = {
    seen_count: number;
    first_seen_ordinal: number;
    wheeled: boolean;
    wheeled_to_me: boolean;
    picked_count: number;
    passed_count: number;
};

export type PickReservation = { catalogId: number; atSeconds: number | null };

/**
 * DraftPickData with its array props narrowed. The PHP-to-TypeScript
 * transformer widens every untyped PHP array to Array<any>, so the four list
 * props are restated here with their real element types.
 */
export type DraftPick = Omit<App.Data.Front.DraftPickData, 'available' | 'reservations' | 'wheeledIds' | 'takenIds'> & {
    available: number[];
    reservations: PickReservation[];
    wheeledIds: number[];
    takenIds: number[];
};

export type LimitedCards = Record<string, App.Data.Front.LimitedCardData>;

export type DraftReview = {
    header: DraftHeader;
    picks: DraftPick[];
    signals: DraftSignal[];
    seenWheel: Record<string, SeenWheelFact>;
    cards: LimitedCards;
};

export function cardFor(cards: LimitedCards, id: number): App.Data.Front.LimitedCardData {
    return (
        cards[String(id)] ?? {
            catalogId: id,
            name: `#${id}`,
            resolved: false,
            type: null,
            subType: null,
            rarity: null,
            colors: '',
            manaCost: null,
            cmc: null,
            image: null,
            artCrop: null,
            oracleId: null,
        }
    );
}

export type CrossDraftStat = {
    oracleId: string;
    drafts: number;
    timesTaken: number;
    avgOrdinal: number | null;
    timesPassed: number;
    timesWheeled: number;
    madeDeck: number;
};

export type CrossDraftStats = Record<string, CrossDraftStat>;

export function ordinalLabel(ordinal: number, packSize = 14): string {
    const pack = Math.floor((ordinal - 1) / packSize) + 1;
    const pick = ((ordinal - 1) % packSize) + 1;
    return pickLabel(pack, pick);
}

export function rarityAbbrev(rarity: string | null): string {
    switch (rarity) {
        case 'common':
            return 'c';
        case 'uncommon':
            return 'u';
        case 'rare':
            return 'r';
        case 'mythic':
            return 'm';
        default:
            return '';
    }
}

export type DeckDiffEntry = { catalogId: number; quantity: number };
export type DeckDiff = { added: DeckDiffEntry[]; removed: DeckDiffEntry[] };

export type DeckVersionRow = {
    index: number;
    signature: string;
    capturedAt: string | null;
    matchIds: number[];
    matchLabels: string[];
    main: number;
    side: number;
    colors: string;
    diffMain: DeckDiff;
    diffSide: DeckDiff;
    isCurrent: boolean;
    pool: { groups: PoolGroup[] };
    mainCards: DeckCardQty[];
    sideCards: DeckCardQty[];
};

export type DeckCardQty = { catalogId: number; quantity: number };

export type PoolStatus = 'main' | 'side' | 'pool' | 'cut';
export type PoolCard = { catalogId: number; quantity: number; status: PoolStatus; mainQty: number; sideQty: number };
export type PoolGroup = { key: string; label: string; count: number; cards: PoolCard[] };

export type GameBoardRow = { number: number; added: DeckDiffEntry[]; removed: DeckDiffEntry[]; note: string | null };
export type MatchBoardRow = { matchId: number; label: string; opponentName: string | null; result: 'W' | 'L' | null; games: GameBoardRow[] };

export type DeckEvolution = {
    summary: {
        drafted: number;
        mainSpells: number;
        basics: number;
        sideboard: number;
        versionCount: number;
        firstRegisteredAt: string | null;
        lastRegisteredAt: string | null;
    };
    versions: DeckVersionRow[];
    pool: { groups: PoolGroup[] };
    games: MatchBoardRow[];
    cards: LimitedCards;
};

export const POOL_STATUS_ORDER: PoolStatus[] = ['main', 'side', 'pool', 'cut'];

export const POOL_STATUS_LABELS: Record<PoolStatus, string> = { main: 'Main', side: 'Side', pool: 'Pool', cut: 'Cut' };

export function poolStatusVariant(status: PoolStatus): 'default' | 'destructive' | 'outline' {
    if (status === 'main') {
        return 'default';
    }
    return status === 'cut' ? 'destructive' : 'outline';
}

export type LimitedCardRow = {
    catalogId: number;
    oracleId: string | null;
    ordinals: number[];
    labels: string[];
    status: PoolStatus;
    gamesCast: number;
    castWon: number;
    castLost: number;
    winPctCast: number | null;
    seenCount: number;
    wheeled: boolean;
    priorTaken: number;
    priorAvgOrdinal: number | null;
    priorWheeled: number;
    priorDrafts: number;
};

export type LimitedCardTable = {
    rows: LimitedCardRow[];
    summary: { distinct: number; games: number; otherDrafts: number };
    cards: LimitedCards;
};
