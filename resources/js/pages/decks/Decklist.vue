<script setup lang="ts">
import ExportDekController from '@/actions/App/Http/Controllers/Decks/ExportDekController';
import ScreenshotDataController from '@/actions/App/Http/Controllers/Decks/ScreenshotDataController';
import AppLayout from '@/AppLayout.vue';
import CurveAndColours from '@/components/decks/CurveAndColours.vue';
import DeckScreenshot from '@/components/decks/DeckScreenshot.vue';
import { useScreenshot } from '@/composables/useScreenshot';
import { useToast } from '@/composables/useToast';
import DeckViewLayout from '@/layouts/DeckViewLayout.vue';
import DeckList from '@/pages/decks/partials/DeckList.vue';
import HypergeometricCalculator from '@/pages/decks/partials/HypergeometricCalculator.vue';
import type { VersionStats } from '@/types/decks';
import { Camera, Download, Loader2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

defineOptions({ layout: [AppLayout, DeckViewLayout] });

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    versions: VersionStats[];
    currentVersionId: number | null;
    trophies: number;
    currentPage: string;
    maindeck: Record<string, App.Data.Front.CardData[]>;
    sideboard: App.Data.Front.CardData[];
}>();

const decklistOrgUrl = computed(() => {
    const mainCards = Object.values(props.maindeck)
        .flat()
        .map((c) => `${c.quantity} ${c.name}`)
        .join('\n');
    const sideCards = props.sideboard.map((c) => `${c.quantity} ${c.name}`).join('\n');
    const params = new URLSearchParams({
        deckmain: mainCards,
        deckside: sideCards,
        eventformat: props.deck.format,
    });
    return `https://decklist.org/?${params.toString()}`;
});

const screenshotRef = ref<InstanceType<typeof DeckScreenshot> | null>(null);
const showScreenshot = ref(false);
const screenshotData = ref<Record<string, any> | null>(null);
const screenshotLoading = ref(false);
const exporting = ref(false);
const { capture } = useScreenshot();
const { add: addToast } = useToast();

async function downloadDek() {
    if (exporting.value) return;
    exporting.value = true;

    try {
        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        const response = await fetch(ExportDekController.url(props.deck.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            addToast({ type: 'error', title: 'Export failed', message: 'Could not save deck file' });
            return;
        }

        const result = await response.json();
        if (result.success) {
            addToast({ type: 'success', title: 'Saved deck file', message: result.path });
        } else if (!result.cancelled) {
            addToast({ type: 'error', title: 'Export failed', message: result.message ?? 'Could not save deck file' });
        }
    } catch (e) {
        addToast({ type: 'error', title: 'Export failed', message: 'Could not save deck file' });
    } finally {
        exporting.value = false;
    }
}

async function copyDeckScreenshot() {
    if (screenshotLoading.value) return;
    screenshotLoading.value = true;

    try {
        const response = await fetch(ScreenshotDataController.url(props.deck.id));
        if (!response.ok) {
            addToast({ type: 'error', title: 'Screenshot failed', message: 'Could not load deck data' });
            return;
        }
        screenshotData.value = await response.json();
        showScreenshot.value = true;
        await nextTick();

        const el = screenshotRef.value?.$el as HTMLElement | undefined;
        if (el) {
            await capture(el);
        }
    } finally {
        showScreenshot.value = false;
        screenshotData.value = null;
        screenshotLoading.value = false;
    }
}
</script>

<template>
    <div class="p-3 lg:p-4">
        <div class="mb-4 flex items-center justify-end gap-2">
            <button
                :disabled="screenshotLoading"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                @click="copyDeckScreenshot"
            >
                <Loader2 v-if="screenshotLoading" class="size-4 animate-spin" />
                <Camera v-else class="size-4" />
                {{ screenshotLoading ? 'Generating...' : 'Share Deck' }}
            </button>
            <button
                :disabled="exporting"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
                @click="downloadDek"
            >
                <Loader2 v-if="exporting" class="size-4 animate-spin" />
                <Download v-else class="size-4" />
                {{ exporting ? 'Saving…' : 'Download .dek' }}
            </button>
            <a
                :href="decklistOrgUrl"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                Deck Registration
            </a>
        </div>
        <div class="grid grid-cols-4 gap-4">
            <div class="col-span-3">
                <DeckList :maindeck="maindeck" :sideboard="sideboard" />
            </div>
            <div class="col-span-1 flex flex-col gap-4">
                <CurveAndColours :cards="Object.values(maindeck).flat()" scope="maindeck, nonland" />

                <!-- Hypergeometric Draw Odds -->
                <HypergeometricCalculator :maindeck="maindeck" />
            </div>
        </div>
        <!-- Off-screen screenshot capture -->
        <div v-if="showScreenshot && screenshotData" style="position: fixed; top: -9999px; left: -9999px; pointer-events: none">
            <DeckScreenshot
                ref="screenshotRef"
                :name="screenshotData.name"
                :format="screenshotData.format"
                :color-identity="screenshotData.colorIdentity"
                :match-record="screenshotData.matchRecord"
                :cover-art-base64="screenshotData.coverArtBase64"
                :non-land-cards="screenshotData.nonLandCards"
                :land-cards="screenshotData.landCards"
                :sideboard-cards="screenshotData.sideboardCards"
                :cmc-distribution="screenshotData.cmcDistribution"
                :type-distribution="screenshotData.typeDistribution"
            />
        </div>
    </div>
</template>
