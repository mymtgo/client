declare namespace App.Data.Front {
export type ArchetypeData = {
id: number;
name: string;
format: string | null;
colorIdentity: string | null;
decklistDownloadedAt: string | null;
hasDecklist: boolean;
manual: boolean;
isFallback: boolean;
mergedIntoId: number | null;
};
export type ArchetypeDeckData = {
id: number;
uuid: string;
seenCount: number;
lastSyncedAt: string | null;
cards: Array<App.Data.Front.CardData>;
facingWinrate: number | null;
wins: number;
losses: number;
};
export type ArchetypeDetailData = {
archetype: App.Data.Front.ArchetypeData;
decks: Array<App.Data.Front.ArchetypeDeckData>;
isStale: boolean;
mergedInto: App.Data.Front.ArchetypeData | null;
};
export type CardData = {
mtgoId: number | null;
name: string | null;
type: string | null;
identity: string | null;
image: string | null;
artCrop: string | null;
cmc: number | null;
quantity: number;
sideboard: boolean;
};
export type DeckData = {
id: number;
name: string;
originalName: string | null;
format: string;
matchesCount: number;
matchesWon: number;
matchesLost: number;
matchesDrawn: number;
winrate: number;
colorIdentity: string | null;
coverArt: string | null;
archetype: App.Data.Front.ArchetypeData | null;
lastPlayedAt: string | null;
lastPlayedAtHuman: string | null;
deletedAt: string | null;
matches: any;
identity: any;
cards: any;
};
export type DeckGroupData = {
archetype: App.Data.Front.ArchetypeData | null;
stats: App.Data.Front.DeckGroupStatsData;
decks: { [key: number]: App.Data.Front.DeckData };
};
export type DeckGroupStatsData = {
totalMatches: number;
totalWins: number;
winrate: number | null;
lastPlayedAt: string | null;
};
export type DeckWinrateData = {
wins: number;
games: number;
rate: number;
};
export type DrawOddsCardData = {
mtgoId: number | null;
name: string;
type: string;
identity: string | null;
image: string | null;
remaining: number;
total: number;
};
export type DrawOddsData = {
cards: { [key: number]: App.Data.Front.DrawOddsCardData };
librarySize: number;
liveLibraryCount: number;
};
export type ExternalCardStatsResponse = {
stats: { [key: number]: { [key: string]: any } };
archetypeWinrate: App.Data.Front.DeckWinrateData;
opponents: { [key: number]: App.Data.Front.ExternalOpponentData };
refreshedAt: string | null;
};
export type ExternalOpponentData = {
id: number;
uuid: string;
name: string;
};
export type GameData = {
id: number;
players: any | Array<any>;
timeline: any | Array<any>;
};
export type GameResultSummaryData = {
result: string;
onPlay: boolean | null;
};
export type GameTimelineData = {
timestamp: string;
content: Array<any>;
};
export type LeagueData = {
name: string;
startedAt: string;
format: string;
manual: boolean;
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
since: string;
startedAtFormatted: string;
matchTime: string | null;
notes: string | null;
deck: any | App.Data.Front.DeckData;
opponentArchetypes: any;
opponentName: any | string | null;
leagueName: any | string | null;
games: any | Array<any>;
gameResults: any | { [key: number]: App.Data.Front.GameResultSummaryData };
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
