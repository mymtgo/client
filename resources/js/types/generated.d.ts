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
export type ArchetypeNoteData = {
id: number;
body: string;
deckName: string;
createdAt: string;
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
export type DraftNotesData = {
draftId: number;
leagueId: number | null;
state: string;
ordinal: number | null;
label: string | null;
cardsInPack: number | null;
deadlineAt: string | null;
pickedCatalogId: number | null;
pickedName: string | null;
note: string | null;
};
export type DraftPickData = {
ordinal: number;
packNumber: number;
pickNumber: number;
label: string;
packId: number | null;
direction: number | null;
available: Array<any>;
pickedCatalogId: number | null;
reservations: Array<any>;
elapsedSeconds: number | null;
marginSeconds: number | null;
indecisive: boolean;
shownAt: string | null;
deadlineAt: string | null;
pickedAt: string | null;
note: string | null;
noteSavedAt: string | null;
wheelReturnOrdinal: number | null;
wheeledIds: Array<any>;
takenIds: Array<any>;
};
export type DrawOddsCardData = {
mtgoId: number | null;
name: string;
type: string;
identity: string | null;
image: string | null;
artCrop: string | null;
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
export type LimitedCardData = {
catalogId: number;
name: string;
resolved: boolean;
type: string | null;
subType: string | null;
rarity: string | null;
colors: string;
manaCost: string | null;
cmc: number | null;
image: string | null;
artCrop: string | null;
oracleId: string | null;
};
export type LimitedEventData = {
id: number;
draftId: number | null;
title: string;
subtitle: string;
setCode: string | null;
setName: string | null;
kind: string;
state: string;
stateVariant: string;
startedAt: string | null;
startedAtHuman: string | null;
wins: number;
losses: number;
picksMade: number;
picksExpected: number;
deckRegistered: boolean;
deckId: number | null;
coverArt: string | null;
seatIndex: number | null;
seatCount: number;
boosterCatalogId: number | null;
draftState: string;
};
export type LimitedIndexKpisData = {
events: number;
drafts: number;
unlinked: number;
matchWinPct: number | null;
matchWins: number;
matchLosses: number;
avgWins: number | null;
avgLosses: number | null;
completedRuns: number;
mostDraftedSet: string | null;
mostDraftedCount: number;
avgPickSeconds: number | null;
indecisionPct: number | null;
};
export type LimitedIndexRowData = {
leagueId: number | null;
draftId: number | null;
title: string;
setCode: string | null;
kind: string;
state: string;
stateVariant: string;
startedAt: string | null;
startedAtHuman: string | null;
wins: number;
losses: number;
results: Array<any>;
picksMade: number;
picksExpected: number;
deckRegistered: boolean;
versionCount: number;
avgPickSeconds: number | null;
opponents: Array<any>;
note: string | null;
linked: boolean;
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
export type OverlayOpponentData = {
username: string;
previousMatches: number;
wins: number;
losses: number;
archetypeId: number | null;
archetypeName: string | null;
archetypeColors: string | null;
source: string;
manual: boolean;
};
export type PlayerData = {
id: number;
username: string;
isLocal: boolean;
onPlay: boolean;
startingHandSize: number;
deck: Array<any>;
};
export type RevealedCardData = {
mtgoId: number | null;
name: string;
type: string;
identity: string | null;
image: string | null;
artCrop: string | null;
quantity: number;
};
export type SideboardCardData = {
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
};
export type SideboardGuideData = {
sidedIn: Array<any>;
sidedOut: Array<any>;
postboardGames: number;
postboardRecord: string;
};
export type SidedOutCardData = {
oracleId: string;
name: string;
type: string | null;
image: string | null;
artCrop: string | null;
sidedOutGames: number;
communitySidedOut: number | null;
communityGames: number | null;
communityRate: number | null;
};
}
