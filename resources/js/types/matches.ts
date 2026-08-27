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
    mulliganedHands: { name: string; image: string | null }[][];
    keptHand: { name: string; image: string | null; bottomed: boolean }[];
    sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
    opponentCardsSeen: {
        name: string;
        image: string | null;
        type: string | null;
        identity: string | null;
        quantity: number;
    }[];
};
