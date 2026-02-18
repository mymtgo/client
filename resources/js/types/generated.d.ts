declare namespace App.Data.Front {
export type ArchetypeData = {
id: number;
name: string;
format: string;
colorIdentity: string;
};
export type CardData = {
mtgoId: number | null;
name: string | null;
type: string | null;
identity: string | null;
image: string | null;
quantity: number;
sideboard: boolean;
};
export type DeckData = {
id: number;
name: string;
format: string;
matchesCount: number;
matchesWon: number;
matchesLost: number;
winrate: number;
matches: any;
identity: any;
cards: any;
};
export type GameData = {
id: number;
players: any | Array<any>;
timeline: any | Array<any>;
};
export type GameTimelineData = {
timestamp: string;
content: Array<any>;
};
export type LeagueData = {
name: string;
startedAt: any;
phantom: boolean;
format: string;
matches: Array<any>;
};
export type MatchArchetypeData = {
confidence: number;
archetype: any | App.Data.Front.ArchetypeData;
};
export type MatchData = {
id: number;
format: string;
matchType: string;
leagueGame: boolean;
gamesWon: number;
gamesLost: number;
result: string;
startedAt: string;
matchTime: string;
deck: any | App.Data.Front.DeckData;
opponentArchetypes: any;
opponentName: any | string | null;
games: any | Array<any>;
};
export type MatchDeckData = {
deck: any | App.Data.Front.DeckData;
};
export type PlayerData = {
id: number;
username: string;
isLocal: boolean;
onPlay: boolean;
startingHandSize: number;
deck: Array<any>;
};
}
