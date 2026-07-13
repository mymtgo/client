<script setup lang="ts">
import FilterChip from '@/components/FilterChip.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import RecordChip from '@/components/RecordChip.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import StatTile from '@/components/StatTile.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import WinRateBar from '@/components/WinRateBar.vue';
import LeagueResultBadge from '@/components/leagues/LeagueResultBadge.vue';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Check } from 'lucide-vue-next';
import { ref } from 'vue';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';

const segmentValue = ref('overview');
const tabValue = ref('overview');
const filterValue = ref(true);
const timeframe = ref('alltime');
</script>

<template>
    <SinkSection id="mtgo" title="MTGO">
        <div>
            <h3 class="mb-2 text-sm font-medium text-muted-foreground">ManaSymbols</h3>
            <ManaSymbols symbols="W,U,B,R,G" />
        </div>

        <ResolvedNote tag="was three" note="Winrate tone — one rule: winrateTone(pct): ≥55 win · 45–55 muted · ≤45 loss. Bar fill and label always agree." />
        <div class="flex w-64 flex-col gap-2">
            <WinRateBar :winrate="25" />
            <WinRateBar :winrate="50" />
            <WinRateBar :winrate="75" />
        </div>

        <ResolvedNote
            tag="documented"
            note="Badge for categorical labels; Record chip (mono, outlined, semantic border) is the one sanctioned specialisation — W–L records only. Trophy 5–0 keeps the system's one sanctioned gradient."
        />
        <div class="flex flex-wrap items-center gap-3">
            <Badge>Aggro</Badge>
            <Badge variant="secondary">Modern</Badge>
            <Badge variant="outline">Untracked</Badge>
            <ResultBadge :won="true" show-text />
            <ResultBadge :won="false" show-text />
            <span class="w-3" />
            <RecordChip tone="perfect">5–0</RecordChip>
            <RecordChip tone="hot">4–1</RecordChip>
            <RecordChip>3–2</RecordChip>
            <RecordChip tone="cold">0–2</RecordChip>
            <RecordChip tone="live">2–1</RecordChip>
            <span class="w-3" />
            <LeagueResultBadge classification="TROPHY" :wins="5" :losses="0" :live-round="null" />
            <LeagueResultBadge classification="CASH" :wins="4" :losses="1" :live-round="null" />
            <LeagueResultBadge classification="FINISH" :wins="3" :losses="2" :live-round="null" />
            <LeagueResultBadge classification="BRICK" :wins="0" :losses="2" :live-round="null" />
            <LeagueResultBadge classification="LIVE" :wins="2" :losses="1" :live-round="3" />
        </div>

        <Card class="flex w-fit flex-row divide-x">
            <StatTile label="Current Streak" value="7W" tone="success" sub="Best: 9W / 3L" />
            <StatTile label="Match Win Rate" value="64%" :delta="3" />
            <StatTile label="Game Win Rate" value="58%" :delta="-2" />
            <StatTile label="OTP / OTD" value="61% / 48%" tone="muted" />
        </Card>

        <ResolvedNote
            tag="was three"
            note="Switching — two ways: Tabs for page-level content, Segmented for view toggles in a card. Toggle retired; the blue pill is now a filter chip."
        />
        <div class="flex flex-wrap items-start gap-9">
            <SegmentedControl
                v-model="segmentValue"
                :options="[
                    { label: 'Overview', value: 'overview' },
                    { label: 'Matches', value: 'matches' },
                ]"
            />
            <Tabs v-model="tabValue">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="matches">Matches</TabsTrigger>
                    <TabsTrigger value="games">Games</TabsTrigger>
                </TabsList>
            </Tabs>
            <FilterChip v-model="filterValue">
                <Check class="size-[13px]" />
                Practice games
            </FilterChip>
        </div>

        <div>
            <h3 class="mb-2 text-sm font-medium text-muted-foreground">TimeframeFilter</h3>
            <TimeframeFilter v-model="timeframe" />
        </div>
    </SinkSection>
</template>
