<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/DashboardController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import MatchRecord from '@/components/MatchRecord.vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import WinRateBar from '@/components/WinRateBar.vue';
import { deckDragLabel, setDeckDragImage, writeDeckDrag } from '@/lib/deckDrag';
import { manaWash } from '@/lib/mana';
import { config, router } from '@inertiajs/vue3';
import { ChevronDown, GripVertical } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

/**
 * Decks without cover art still fill the art band, tinted by their colour
 * identity. Collapsing those tiles to a text block instead left the grid full
 * of holes wherever a synced deck had no art yet.
 */
const fallbackArtStyle = computed(() => ({ backgroundImage: manaWash(props.deck.colorIdentity) }));

// Not an Inertia <Link>: Link calls preventDefault on mousedown, which stops a
// native drag from ever starting. Navigation and hover prefetch are done by
// hand, the same way MatchesTable rows do it.
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

const drawerHovered = ref(false);

/**
 * The drawer holds the checkbox and the drag grip. It stays down while this
 * deck is ticked or any selection is active so a run of ticks is quick;
 * otherwise only the chevron tab shows until hovered.
 */
const drawerOpen = computed(() => drawerHovered.value || props.selected || props.selectionActive);

// Only the grip starts a drag. A draggable card body made an ordinary click
// too easy to turn into an accidental drag, and vice versa.
function onGripDragStart(event: DragEvent) {
    if (!event.dataTransfer) return;
    const ids = props.selected ? props.selectedIds : [props.deck.id];
    writeDeckDrag(event.dataTransfer, ids);
    setDeckDragImage(event.dataTransfer, deckDragLabel(ids.length, props.deck.name));
    emit('dragstart', props.deck.format);
}
</script>

<template>
    <div
        role="link"
        tabindex="0"
        class="group block h-full cursor-pointer transition-opacity outline-none"
        :class="!selectable && 'opacity-40'"
        @click="open"
        @keydown.enter="open"
        @mouseenter="startPrefetch"
        @mouseleave="cancelPrefetch"
    >
        <Card
            class="relative flex h-full flex-col gap-0 overflow-hidden py-0 transition-colors group-hover:border-white/10 group-hover:bg-black/25 group-focus-visible:border-ring"
            :class="selected && 'border-primary/60'"
        >
            <img
                v-if="deck.coverArt"
                :src="deck.coverArt"
                :alt="deck.name"
                class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top transition-transform duration-500 ease-out group-hover:scale-[1.04]"
                :class="deck.deletedAt ? 'grayscale' : ''"
            />
            <div v-else class="pointer-events-none absolute inset-0" :class="deck.deletedAt ? 'grayscale' : ''" :style="fallbackArtStyle" />

            <!--
                Explicit gradient scrim rather than a mask utility: masks do not
                render reliably in the Electron webview. Dark enough at the foot
                of the card to carry the stats text.
            -->
            <div class="pointer-events-none absolute inset-0 bg-linear-to-t from-black via-black/75 to-black/20" />

            <!--
                Pull-down drawer: a chevron tab on the top edge, hover it and
                the checkbox plus drag grip slide down. Stops click, mousedown
                and keydown so nothing in it opens the deck.
            -->
            <div
                v-if="selectable"
                class="absolute inset-x-0 top-0 z-10 flex justify-center"
                @click.stop
                @mousedown.stop
                @keydown.stop
                @mouseenter="drawerHovered = true"
                @mouseleave="drawerHovered = false"
            >
                <div
                    class="flex flex-col items-center transition-transform duration-200 ease-out"
                    :class="drawerOpen ? 'translate-y-0' : '-translate-y-[calc(100%-1.25rem)]'"
                >
                    <div
                        class="flex items-center gap-3 rounded-b-lg border border-t-0 border-white/10 bg-neutral-900 px-3 py-1.5 shadow shadow-black/50"
                    >
                        <Checkbox
                            :model-value="selected"
                            :aria-label="`Select ${deck.name}`"
                            :disabled="!selectable"
                            :title="selectable ? undefined : 'Only decks of one format can be selected together'"
                            class="size-4.5 border-white/60 bg-neutral-800 data-[state=unchecked]:hover:border-white"
                            @update:model-value="(value) => emit('update:selected', deck.id, value === true)"
                        />
                        <span
                            draggable="true"
                            class="cursor-grab text-white/70 transition-colors hover:text-white active:cursor-grabbing"
                            title="Drag onto an archetype"
                            @dragstart="onGripDragStart"
                            @dragend="emit('dragend')"
                        >
                            <GripVertical class="size-4" />
                        </span>
                    </div>
                    <div
                        class="flex h-5 w-10 items-center justify-center rounded-b-md border border-t-0 border-white/10 bg-neutral-900 text-white/60 shadow shadow-black/50"
                    >
                        <ChevronDown class="size-3.5 transition-transform duration-200" :class="drawerOpen && 'rotate-180'" />
                    </div>
                </div>
            </div>

            <!--
                Pinned to the card rather than sitting inline beside the name so
                a long name gets the full tile width.
            -->
            <ManaSymbols
                v-if="deck.colorIdentity"
                :symbols="deck.colorIdentity"
                class="absolute top-3 right-3 z-10 rounded-full border border-white/10 bg-black/60 px-2 py-1 shadow shadow-black/50 backdrop-blur-sm"
            />

            <!-- Spacer that gives the art band room to read as art. -->
            <div class="relative h-44 shrink-0" />

            <div class="relative flex flex-1 flex-col justify-end gap-3 p-4 [text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]">
                <span class="line-clamp-2 text-base leading-tight font-semibold">{{ deck.name }}</span>

                <div class="flex min-w-0 items-center gap-2 text-xs">
                    <Badge variant="outline" class="shrink-0 py-0 text-xs">{{ deck.format }}</Badge>
                    <Badge v-if="deck.deletedAt" variant="destructive" class="shrink-0 py-0 text-xs">Deleted</Badge>
                    <Badge v-if="deck.archetype" variant="secondary" class="min-w-0 shrink py-0 text-xs">
                        <span class="truncate">{{ deck.archetype.name }}</span>
                    </Badge>
                    <Badge v-else-if="!deck.deletedAt" variant="warning" class="shrink-0 py-0 text-xs">Unclassified</Badge>
                </div>

                <div class="flex flex-col gap-1.5">
                    <WinRateBar :winrate="deck.record.winrate" solid />
                    <div class="flex items-baseline justify-between gap-2 text-xs tabular-nums">
                        <span class="truncate text-muted-foreground">
                            {{ deck.record.total }} matches
                            <template v-if="!deck.deletedAt"> · {{ deck.lastPlayedAtHuman ?? 'never played' }}</template>
                        </span>
                        <MatchRecord :record="deck.record" />
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>
