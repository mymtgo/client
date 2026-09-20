<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import DeckSyncBadge from '@/components/decks/DeckSyncBadge.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchRecord from '@/components/MatchRecord.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import WinRateBar from '@/components/WinRateBar.vue';
import { deckDragLabel, setDeckDragImage, writeDeckDrag } from '@/lib/deckDrag';
import { config, router } from '@inertiajs/vue3';
import { GripVertical } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        deck: App.Data.Front.DeckData;
        selected?: boolean;
        selectionActive?: boolean;
        selectedIds?: number[];
        /** False when a selection of another format is active; the checkbox is then disabled. */
        selectable?: boolean;
    }>(),
    { selected: false, selectionActive: false, selectedIds: () => [], selectable: true },
);

const emit = defineEmits<{
    'update:selected': [deckId: number, value: boolean];
    dragstart: [format: string];
    dragend: [];
}>();

const url = computed(() => ShowController({ deck: props.deck.id }).url);

let prefetchTimer: ReturnType<typeof setTimeout> | undefined;
function startPrefetch() {
    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(() => router.prefetch(url.value, {}, { cacheFor: '10s' }), config.get('prefetch.hoverDelay'));
}
function cancelPrefetch() {
    clearTimeout(prefetchTimer);
}
function open() {
    router.visit(url.value);
}

// Only the grip starts a drag, same as DeckCard.
function onGripDragStart(event: DragEvent) {
    if (!event.dataTransfer) return;
    const ids = props.selected ? props.selectedIds : [props.deck.id];
    writeDeckDrag(event.dataTransfer, ids);
    setDeckDragImage(event.dataTransfer, deckDragLabel(ids.length, props.deck.name));
    emit('dragstart', props.deck.format);
}
</script>

<!--
    The dense counterpart to DeckCard: cover art stays a dimmed backdrop behind
    the stats rather than getting a tile of its own, so a card is one text block
    tall. Selected by the deck listing's card size setting. A div rather than a
    Link for the same drag reason as DeckCard.
-->
<template>
    <div
        role="link"
        tabindex="0"
        class="group block cursor-pointer transition-opacity outline-none"
        :class="!selectable && 'opacity-40'"
        @click="open"
        @keydown.enter="open"
        @mouseenter="startPrefetch"
        @mouseleave="cancelPrefetch"
    >
        <Card
            class="relative overflow-hidden transition-colors group-focus-visible:border-ring hover:bg-black/20"
            :class="selected && 'border-primary/60'"
        >
            <img
                v-if="deck.coverArt"
                :src="deck.coverArt"
                :alt="deck.name"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top opacity-50"
                :class="deck.deletedAt ? 'grayscale' : ''"
            />
            <CardContent class="relative flex flex-col gap-3" :class="deck.coverArt ? '[text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]' : ''">
                <!--
                    Badges left, hover controls right, then the name on its own
                    line so long deck and archetype names stop fighting for width.
                -->
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2 text-xs">
                            <DeckSyncBadge :enabled="deck.cloudSyncEnabled" class="shrink-0" />
                            <ManaSymbols v-if="deck.colorIdentity" :symbols="deck.colorIdentity" class="shrink-0" />
                            <Badge variant="outline" class="shrink-0 py-0 text-xs">{{ deck.format }}</Badge>
                            <Badge v-if="deck.deletedAt" variant="destructive" class="shrink-0 py-0 text-xs">Deleted</Badge>
                            <Badge v-if="deck.archetype" variant="secondary" class="min-w-0 shrink py-0 text-xs">
                                <span class="truncate">{{ deck.archetype.name }}</span>
                            </Badge>
                            <Badge v-else-if="!deck.deletedAt" variant="warning" class="shrink-0 py-0 text-xs">Unclassified</Badge>
                        </div>
                        <div
                            v-if="selectable"
                            class="flex shrink-0 items-center gap-1.5 transition-opacity"
                            :class="selected || selectionActive ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100'"
                            @click.stop
                            @mousedown.stop
                            @keydown.stop
                        >
                            <Checkbox
                                :model-value="selected"
                                :aria-label="`Select ${deck.name}`"
                                :disabled="!selectable"
                                :title="selectable ? undefined : 'Only decks of one format can be selected together'"
                                class="size-4.5 shrink-0 border-white/60 bg-neutral-800 data-[state=unchecked]:hover:border-white"
                                @update:model-value="(value) => emit('update:selected', deck.id, value === true)"
                            />
                            <span
                                draggable="true"
                                class="shrink-0 cursor-grab text-white/60 hover:text-white active:cursor-grabbing"
                                title="Drag onto an archetype"
                                @dragstart="onGripDragStart"
                                @dragend="emit('dragend')"
                            >
                                <GripVertical class="size-4" />
                            </span>
                        </div>
                    </div>
                    <span class="truncate leading-tight font-semibold">{{ deck.name }}</span>
                </div>

                <div class="flex items-end justify-between gap-4">
                    <div class="flex flex-1 flex-col gap-1">
                        <span class="text-xs text-muted-foreground">win rate</span>
                        <WinRateBar :winrate="deck.record.winrate" :solid="!!deck.coverArt" />
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-medium tabular-nums">
                            {{ deck.record.total }} matches
                            <span v-if="!deck.deletedAt" class="text-muted-foreground"> · {{ deck.lastPlayedAtHuman ?? 'never' }}</span>
                        </div>
                        <MatchRecord :record="deck.record" />
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
