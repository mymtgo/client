/** Per-game detail as built by BuildMatchGameData for the match detail view. */
export type GameDetail = {
    id: number;
    number: number;
    won: boolean;
    onThePlay: boolean;
    duration: string | null;
    turns: number | null;
    localMulligans: number;
    opponentMulligans: number;
    mulliganedHands: { mtgoId: number; name: string; image: string | null }[][];
    keptHand: { mtgoId: number; name: string; image: string | null; bottomed: boolean }[];
    sideboardChanges: { mtgoId: number; name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
    opponentCardsSeen: {
        mtgoId: number;
        name: string;
        image: string | null;
        type: string | null;
        identity: string | null;
        quantity: number;
    }[];
};

/** A registered deck card the manual match dialogs can pick from. */
export type DeckCardOption = {
    mtgoId: number;
    name: string;
    image: string | null;
    quantity: number;
};

/** Options for the manual match edit dialogs, null for tracked matches. */
export type ManualEditingData = {
    deck: {
        mains: DeckCardOption[];
        sideboard: DeckCardOption[];
    };
    archetypeDecklist: (DeckCardOption & { sideboard: boolean })[] | null;
};

/** A row from the local card search endpoint. */
export type SearchCard = {
    mtgoId: number;
    oracleId: string | null;
    name: string;
    image: string | null;
    type: string | null;
};
