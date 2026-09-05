/**
 * Spatie serialises `SideboardGuideData.sidedIn` / `sidedOut` as `Array<any>`,
 * so the card shapes are declared here. Keep in step with
 * `App\Data\Front\SideboardCardData` and `SidedOutCardData`.
 */
export type GuideInCard = {
    oracleId: string;
    name: string;
    type: string | null;
    colorIdentity: string | null;
    image: string | null;
    artCrop: string | null;
    quantity: number;
    sidedInGames: number;
    wins: number;
    losses: number;
    winrate: number | null;
    communitySidedIn: number | null;
    communityGames: number | null;
    communityRate: number | null;
    plannedQuantity: number | null;
    stale: boolean;
};

export type GuideOutCard = {
    oracleId: string;
    name: string;
    type: string | null;
    image: string | null;
    artCrop: string | null;
    quantity: number;
    sidedOutGames: number;
    communitySidedOut: number | null;
    communityGames: number | null;
    communityRate: number | null;
    plannedQuantity: number | null;
    stale: boolean;
};

export type GuideDirection = 'in' | 'out';

export type GuideCardInput = {
    oracle_id: string;
    direction: GuideDirection;
    quantity: number;
};
