export type ReportsCurrentPage = 'matches' | 'card-stats';

export type ReportArchetypeOption = {
    id: number;
    name: string;
    colorIdentity: string | null;
};

export type ReportFormatOption = {
    value: string;
    label: string;
};

export type ReportArchetypeStats = {
    deckCount: number;
    matchRecord: App.Data.Front.MatchRecordData;
    formatLabel: string;
    archetypeName: string;
    colorIdentity: string | null;
};

export type ReportsSharedProps = {
    archetypeOptions: ReportArchetypeOption[];
    formatOptions: ReportFormatOption[];
    selectedArchetype: number | null;
    selectedFormat: string | null;
    timeframe: string;
    currentPage: ReportsCurrentPage;
    matchCount: number;
    archetypeStats: ReportArchetypeStats | null;
};
