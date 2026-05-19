<script setup lang="ts">
import MatchupScreenshot from '@/components/matchups/MatchupScreenshot.vue';
import MatchupSpreadTable from '@/components/matchups/MatchupSpreadTable.vue';
import MatchupDrawer from '@/pages/decks/partials/MatchupDrawer.vue';
import { ContextMenuItem } from '@/components/ui/context-menu';
import MatchupDetailController from '@/actions/App/Http/Controllers/Decks/MatchupDetailController';
import { useScreenshot } from '@/composables/useScreenshot';
import { useToast } from '@/composables/useToast';
import { timeframeLabel } from '@/lib/timeframes';
import { nextTick, ref } from 'vue';
import type { MatchupDetail, MatchupSpread } from '@/types/decks';

const props = defineProps<{
    matchupSpread: MatchupSpread[];
    deckId: number;
    timeframe: string;
    version: number | null;
}>();

const selectedMatchup = ref<MatchupSpread | null>(null);

const screenshotMatchup = ref<MatchupSpread | null>(null);
const screenshotDetail = ref<MatchupDetail | null>(null);
const screenshotRef = ref<InstanceType<typeof MatchupScreenshot> | null>(null);

const { capture } = useScreenshot();
const { add: addToast } = useToast();

function onRowSelect(matchup: MatchupSpread) {
    selectedMatchup.value = matchup;
}

async function fetchMatchupDetail(archetypeId: number): Promise<MatchupDetail> {
    const params: Record<string, string> = {};
    if (props.timeframe !== 'alltime') params.timeframe = props.timeframe;
    if (props.version) params.version = String(props.version);

    const url = MatchupDetailController.url({ deck: props.deckId, archetype: archetypeId });
    const query = new URLSearchParams(params).toString();
    const response = await fetch(query ? `${url}?${query}` : url);
    if (!response.ok) {
        throw new Error(`MatchupDetail fetch failed: ${response.status}`);
    }
    return response.json();
}

async function copyScreenshot(matchup: MatchupSpread) {
    if (screenshotMatchup.value) return;

    let detail: MatchupDetail;
    try {
        detail = await fetchMatchupDetail(matchup.archetype_id);
    } catch (error) {
        console.error('[MatchupScreenshot] fetch failed:', error);
        addToast({ type: 'error', title: 'Screenshot failed', message: 'Could not load matchup data' });
        return;
    }

    screenshotMatchup.value = matchup;
    screenshotDetail.value = detail;

    try {
        await nextTick();
        const el = screenshotRef.value?.$el as HTMLElement | undefined;
        if (el) {
            await capture(el);
        }
    } finally {
        screenshotMatchup.value = null;
        screenshotDetail.value = null;
    }
}
</script>

<template>
    <MatchupSpreadTable :matchup-spread="matchupSpread" :timeframe="timeframe" @select="onRowSelect">
        <template #row-actions="{ row }">
            <ContextMenuItem @select="copyScreenshot(row)">Copy screenshot</ContextMenuItem>
        </template>
    </MatchupSpreadTable>

    <MatchupDrawer
        :deck-id="deckId"
        :matchup="selectedMatchup"
        :timeframe="timeframe"
        :version="version"
        @close="selectedMatchup = null"
    />

    <div
        v-if="screenshotMatchup && screenshotDetail"
        style="position: fixed; top: -9999px; left: -9999px; pointer-events: none;"
    >
        <MatchupScreenshot
            ref="screenshotRef"
            :matchup="screenshotMatchup"
            :detail="screenshotDetail"
            :timeframe-label="timeframeLabel(timeframe)"
        />
    </div>
</template>
