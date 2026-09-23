/**
 * One replay frame: the Twitch snapshot shape plus the optional sidecar keys.
 * Log-sourced frames only carry Players/Cards with the basic counts, so every
 * key beyond those is optional and the viewer hides what is missing.
 */
export type ReplayPlayer = {
    Id: number;
    Name: string;
    IsLocal?: boolean;
    Life?: number;
    HandCount?: number;
    LibraryCount?: number;
    TimeLeft?: number;
    Pool?: Record<string, number> | [];
};

export type ReplayCard = {
    Id: number;
    CatalogID: number;
    Zone: string;
    Owner: number;
    Controller?: number;
    Tapped?: boolean;
    Power?: number;
    Toughness?: number;
    Counters?: Record<string, number>;
    Attacking?: number;
    Blocking?: number;
    Damage?: number;
    name?: string | null;
    type?: string | null;
    image?: string | null;
};

export type ReplayFrameContent = {
    Turn?: number;
    Phase?: string;
    Step?: string;
    ActivePlayer?: number;
    Priority?: number;
    Players: ReplayPlayer[];
    Cards: ReplayCard[];
};

export type ReplayFrame = {
    timestamp: string;
    content: ReplayFrameContent;
};

export type ReplayLogEntry = {
    timestamp: string;
    message: string;
};

export type ReplayTurn = {
    /** Sequential turn number across both players, 1-based. Null for the pre-game segment. */
    number: number | null;
    /** First frame index of the turn. */
    from: number;
    /** Exclusive end frame index. */
    to: number;
    /** Player Id whose turn it is, when known. */
    player: number | null;
};

export type ReplayLogItem = {
    key: number;
    kind: 'header' | 'line';
    timestamp: string;
    text: string;
    frame: number;
    player: number | null;
};

export type ReplayZone = 'Hand' | 'Graveyard' | 'Exile';

export type CardPreviewPosition = { left: number; top: number; width: number };

export type ZonePopoverPosition = { left: number; top?: number; bottom?: number; width: number; maxHeight: number };

export type ScrubTooltipView = { left: number; bottom: number; title: string; time: string; sub: string };

export type ScrubHover = { frame: number; clientX: number; trackTop: number };

/** One game of the match being replayed; `won` is from your side, null when unknown. */
export type ReplayMatchGame = { id: number; number: number; won: boolean | null };
