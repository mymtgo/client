# Game Replay View Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the game replay snapshot to mirror MTGO's battlefield layout with proper zones, and improve the timeline scrubber to show event tick marks.

**Architecture:** Rewrite `GameReplaySnapshot.vue` with an MTGO-style vertical layout: opponent info bar, opponent battlefield, stack (conditional), player battlefield, player info bar, player hand. Extract a reusable `PlayerInfoBar` sub-component for the player stats + expandable graveyard/exile. Modify `GameReplayTimeline.vue` to always show tick marks.

**Tech Stack:** Vue 3, Tailwind CSS v4, shadcn-vue Collapsible, Lucide icons, lodash `filter`/`find`

**Design doc:** `docs/plans/2026-03-02-game-replay-design.md`

---

### Task 1: Rewrite GameReplaySnapshot — board layout

**Files:**
- Modify: `resources/js/pages/games/partials/GameReplaySnapshot.vue`

**Context:** The current file is a rough WIP with unstyled divs, no zone labels, and a raw JSON dump. It already has the correct computed properties for filtering cards by zone. The new version keeps the same data logic but completely replaces the template with an MTGO-style layout.

The `content` field on each event has:
- `content.Players` — array of `{ Id, Name, Life, HandCount, LibraryCount, IsLocal }`
- `content.Cards` — array of `{ Id, CatalogID, Zone, Owner, Controller, image }`

Zones: `"Hand"`, `"Battlefield"`, `"Stack"`, `"Graveyard"`, `"Exile"`, `"Library"`

**Step 1: Rewrite the component**

Full replacement for `resources/js/pages/games/partials/GameReplaySnapshot.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue';
import type { GameTimelineEvent } from './GameReplay.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronRight, Heart, BookOpen, Hand } from 'lucide-vue-next';
import { filter, find } from 'lodash';

const props = defineProps<{
    event: GameTimelineEvent | null;
}>();

const player = computed(() => find(props.event?.content.Players, (p: any) => p.IsLocal));
const opponent = computed(() => find(props.event?.content.Players, (p: any) => !p.IsLocal));

const cards = computed(() => props.event?.content.Cards || []);

const playerBattlefield = computed(() => filter(cards.value, (c) => c.Zone === 'Battlefield' && c.Controller == player.value?.Id));
const opponentBattlefield = computed(() => filter(cards.value, (c) => c.Zone === 'Battlefield' && c.Controller != player.value?.Id));
const stack = computed(() => filter(cards.value, (c) => c.Zone === 'Stack'));
const playerHand = computed(() => filter(cards.value, (c) => c.Zone === 'Hand' && c.Owner == player.value?.Id));
const playerGraveyard = computed(() => filter(cards.value, (c) => c.Zone === 'Graveyard' && c.Owner == player.value?.Id));
const opponentGraveyard = computed(() => filter(cards.value, (c) => c.Zone === 'Graveyard' && c.Owner != player.value?.Id));
const playerExile = computed(() => filter(cards.value, (c) => c.Zone === 'Exile' && c.Owner == player.value?.Id));
const opponentExile = computed(() => filter(cards.value, (c) => c.Zone === 'Exile' && c.Owner != player.value?.Id));

const oppGraveyardOpen = ref(false);
const oppExileOpen = ref(false);
const playerGraveyardOpen = ref(false);
const playerExileOpen = ref(false);
</script>

<template>
    <div v-if="!event" class="border border-dashed rounded-lg p-8 text-center text-muted-foreground">
        No event selected.
    </div>

    <div v-else class="flex flex-col rounded-lg border bg-card overflow-hidden">
        <!-- Opponent info bar -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b bg-muted/50 px-3 py-2">
            <span class="text-sm font-semibold">{{ opponent?.Name ?? 'Opponent' }}</span>
            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"><Heart :size="12" class="text-destructive" /> {{ opponent?.Life ?? 0 }}</span>
                <span class="flex items-center gap-1"><Hand :size="12" /> {{ opponent?.HandCount ?? 0 }}</span>
                <span class="flex items-center gap-1"><BookOpen :size="12" /> {{ opponent?.LibraryCount ?? 0 }}</span>
            </div>
            <div class="flex items-center gap-2">
                <Collapsible v-if="opponentGraveyard.length" v-model:open="oppGraveyardOpen">
                    <CollapsibleTrigger class="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground">
                        <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': oppGraveyardOpen }" />
                        Graveyard ({{ opponentGraveyard.length }})
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div class="flex flex-wrap gap-0.5 pt-1">
                            <img v-for="card in opponentGraveyard" :key="card.Id" :src="card.image" :alt="'card'" class="w-14 rounded" />
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                <Collapsible v-if="opponentExile.length" v-model:open="oppExileOpen">
                    <CollapsibleTrigger class="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground">
                        <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': oppExileOpen }" />
                        Exile ({{ opponentExile.length }})
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div class="flex flex-wrap gap-0.5 pt-1">
                            <img v-for="card in opponentExile" :key="card.Id" :src="card.image" :alt="'card'" class="w-14 rounded" />
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </div>

        <!-- Opponent battlefield -->
        <div class="min-h-20 border-b px-3 py-2">
            <div v-if="opponentBattlefield.length" class="flex flex-wrap gap-1">
                <img v-for="card in opponentBattlefield" :key="card.Id" :src="card.image" :alt="'card'" class="w-16 rounded-[7px]" />
            </div>
            <div v-else class="flex h-16 items-center justify-center text-xs text-muted-foreground">
                No permanents
            </div>
        </div>

        <!-- Stack (only when cards exist) -->
        <div v-if="stack.length" class="flex items-center gap-2 border-b border-dashed bg-muted/30 px-3 py-2">
            <span class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Stack</span>
            <div class="flex flex-wrap gap-1">
                <img v-for="card in stack" :key="card.Id" :src="card.image" :alt="'card'" class="w-16 rounded-[7px]" />
            </div>
        </div>

        <!-- Player battlefield -->
        <div class="min-h-20 border-b px-3 py-2">
            <div v-if="playerBattlefield.length" class="flex flex-wrap gap-1">
                <img v-for="card in playerBattlefield" :key="card.Id" :src="card.image" :alt="'card'" class="w-16 rounded-[7px]" />
            </div>
            <div v-else class="flex h-16 items-center justify-center text-xs text-muted-foreground">
                No permanents
            </div>
        </div>

        <!-- Player info bar -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b bg-muted/50 px-3 py-2">
            <span class="text-sm font-semibold">{{ player?.Name ?? 'You' }}</span>
            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"><Heart :size="12" class="text-destructive" /> {{ player?.Life ?? 0 }}</span>
                <span class="flex items-center gap-1"><Hand :size="12" /> {{ player?.HandCount ?? 0 }}</span>
                <span class="flex items-center gap-1"><BookOpen :size="12" /> {{ player?.LibraryCount ?? 0 }}</span>
            </div>
            <div class="flex items-center gap-2">
                <Collapsible v-if="playerGraveyard.length" v-model:open="playerGraveyardOpen">
                    <CollapsibleTrigger class="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground">
                        <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': playerGraveyardOpen }" />
                        Graveyard ({{ playerGraveyard.length }})
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div class="flex flex-wrap gap-0.5 pt-1">
                            <img v-for="card in playerGraveyard" :key="card.Id" :src="card.image" :alt="'card'" class="w-14 rounded" />
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                <Collapsible v-if="playerExile.length" v-model:open="playerExileOpen">
                    <CollapsibleTrigger class="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground">
                        <ChevronRight class="size-3 transition-transform" :class="{ 'rotate-90': playerExileOpen }" />
                        Exile ({{ playerExile.length }})
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div class="flex flex-wrap gap-0.5 pt-1">
                            <img v-for="card in playerExile" :key="card.Id" :src="card.image" :alt="'card'" class="w-14 rounded" />
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </div>

        <!-- Player hand -->
        <div class="px-3 py-2">
            <div v-if="playerHand.length" class="flex flex-wrap gap-1">
                <img v-for="card in playerHand" :key="card.Id" :src="card.image" :alt="'card'" class="w-16 rounded-[7px]" />
            </div>
            <div v-else class="flex h-12 items-center justify-center text-xs text-muted-foreground">
                Empty hand
            </div>
        </div>
    </div>
</template>
```

Key changes from current file:
- Removed raw `contentJson` dump
- Removed `map` import from lodash-es (unused)
- Removed `MtgoCard` import (unused in new template)
- Added Collapsible imports for graveyard/exile
- Added Lucide icons (Heart, BookOpen, Hand)
- Added exile zone computeds (`playerExile`, `opponentExile`)
- Proper MTGO-style vertical layout: opponent info → opponent battlefield → stack → player battlefield → player info → player hand
- Graveyard and exile as expandable collapsible badges in info bars
- Stack only shown when cards exist on it
- Compact card sizing (w-16 for battlefield/stack/hand, w-14 for graveyard/exile)
- Empty states for battlefields and hand

**Step 2: Verify build**

Run: `npx vite build 2>&1 | tail -5`
Expected: Build succeeds

**Step 3: Commit**

```bash
git add resources/js/pages/games/partials/GameReplaySnapshot.vue
git commit -m "feat: redesign game replay with MTGO-style board layout"
```

---

### Task 2: Improve timeline tick marks

**Files:**
- Modify: `resources/js/pages/games/partials/GameReplayTimeline.vue`

**Context:** The current timeline has two modes: individual markers when <= 100 events, and a plain line for larger timelines. The markers exist but are only 1px wide (`w-1`) and blend into the background. We need to:
1. Always show markers (remove the `MAX_VISIBLE_MARKERS` threshold — for very large timelines we can thin them)
2. Make markers more visible (taller, slightly wider)
3. Better color contrast between past/current/future

**Step 1: Rewrite the component**

Full replacement for `resources/js/pages/games/partials/GameReplayTimeline.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import type { GameTimelineEvent } from './GameReplay.vue'

const props = defineProps<{
    events: GameTimelineEvent[]
    currentIndex: number
}>()

const emit = defineEmits<{
    seek: [index: number]
}>()

const trackRef = ref<HTMLElement | null>(null)

const markerPositions = computed(() => {
    if (props.events.length <= 1) return [50]
    return props.events.map((_, i) => (i / (props.events.length - 1)) * 100)
})

const playheadPosition = computed(() => {
    if (props.events.length <= 1) return 50
    return (props.currentIndex / (props.events.length - 1)) * 100
})

// For very large timelines, sample markers to avoid rendering thousands of DOM elements
const MAX_MARKERS = 200
const sampledIndices = computed(() => {
    if (props.events.length <= MAX_MARKERS) {
        return props.events.map((_, i) => i)
    }
    const step = props.events.length / MAX_MARKERS
    const indices: number[] = []
    for (let i = 0; i < MAX_MARKERS; i++) {
        indices.push(Math.round(i * step))
    }
    // Always include current index
    if (!indices.includes(props.currentIndex)) {
        indices.push(props.currentIndex)
    }
    return indices.sort((a, b) => a - b)
})

function handleTrackClick(event: MouseEvent) {
    if (!trackRef.value) return
    const rect = trackRef.value.getBoundingClientRect()
    const x = event.clientX - rect.left
    const percent = x / rect.width
    const targetIndex = Math.round(percent * (props.events.length - 1))
    const clampedIndex = Math.max(0, Math.min(targetIndex, props.events.length - 1))
    emit('seek', clampedIndex)
}
</script>

<template>
    <div class="border bg-card rounded-lg p-3">
        <!-- Timeline track -->
        <div
            ref="trackRef"
            class="relative h-6 cursor-pointer rounded bg-muted"
            @click="handleTrackClick"
        >
            <!-- Event tick marks -->
            <button
                v-for="index in sampledIndices"
                :key="index"
                class="absolute top-1 bottom-1 w-0.5 -translate-x-1/2 rounded-full transition-colors"
                :class="[
                    index === currentIndex
                        ? 'bg-primary w-1'
                        : index < currentIndex
                          ? 'bg-primary/50'
                          : 'bg-muted-foreground/25'
                ]"
                :style="{ left: `${markerPositions[index]}%` }"
                :title="events[index]?.timestamp"
                @click.stop="emit('seek', index)"
            />

            <!-- Playhead -->
            <div
                class="absolute top-0 bottom-0 w-1 -translate-x-1/2 rounded bg-primary shadow-md ring-2 ring-primary/30 transition-[left] duration-100"
                :style="{ left: `${playheadPosition}%` }"
            />
        </div>

        <!-- Timestamp labels -->
        <div class="mt-1 flex justify-between text-xs text-muted-foreground">
            <span>{{ events[0]?.timestamp ?? '' }}</span>
            <span v-if="events.length > 1">{{ events[events.length - 1]?.timestamp ?? '' }}</span>
        </div>
    </div>
</template>
```

Key changes:
- Removed `showAllMarkers` / `MAX_VISIBLE_MARKERS` toggle — always show markers
- Added `sampledIndices` for very large timelines (> 200 events) — samples evenly, always includes current index
- Markers are now `top-1 bottom-1` (fill more of the track height) instead of `top-1 h-6`
- Current marker is wider (`w-1`) vs others (`w-0.5`) for emphasis
- Better color contrast: current = `bg-primary`, past = `bg-primary/50`, future = `bg-muted-foreground/25`
- Playhead has a ring glow (`ring-2 ring-primary/30`) for better visibility
- Track height reduced from `h-8` to `h-6`
- Removed `handleMarkerClick` (the button click handler + `.stop` handles it directly)
- Added `rounded-lg` to container for consistency

**Step 2: Verify build**

Run: `npx vite build 2>&1 | tail -5`
Expected: Build succeeds

**Step 3: Commit**

```bash
git add resources/js/pages/games/partials/GameReplayTimeline.vue
git commit -m "feat: improve timeline with always-visible event tick marks"
```
