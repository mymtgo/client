# Match Page Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the match show page with clear visual hierarchy, add back-link navigation to detail pages, and slim down per-game sections.

**Architecture:** Create a reusable `BackLink` component for detail page navigation. Restructure the match summary to lead with the result. Replace flat game sections with collapsible groups using the existing shadcn-vue Collapsible component. Remove cards played/seen sections (replay covers this).

**Tech Stack:** Vue 3, Tailwind CSS v4, shadcn-vue Collapsible (`@/components/ui/collapsible`), Lucide icons, Wayfinder routes, Inertia Link

**Design doc:** `docs/plans/2026-03-02-match-page-redesign-design.md`

---

### Task 1: Create BackLink component

**Files:**
- Create: `resources/js/components/BackLink.vue`

**Step 1: Create the component**

A simple reusable component that shows a left chevron + linked text.

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

defineProps<{
    href: string;
    label: string;
}>();
</script>

<template>
    <Link :href="href" class="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground">
        <ChevronLeft class="size-4" />
        {{ label }}
    </Link>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/BackLink.vue
git commit -m "feat: add BackLink component for detail page navigation"
```

---

### Task 2: Redesign match summary (matches/Show.vue)

**Files:**
- Modify: `resources/js/pages/matches/Show.vue`

**Context:** The current match summary card crams everything into a two-column layout with no clear hierarchy. The redesign leads with the result, makes the opponent the primary heading, and pushes metadata to a secondary line.

**Step 1: Rewrite the template**

The full new file content for `resources/js/pages/matches/Show.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue';
import AppLayout from '@/AppLayout.vue';
import BackLink from '@/components/BackLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import SetArchetypeDialog from '@/components/matches/SetArchetypeDialog.vue';
import MatchGame from '@/pages/matches/partials/MatchGame.vue';
import DeckShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { PencilIcon } from 'lucide-vue-next';
import dayjs from 'dayjs';

type GameDetail = {
    id: number;
    number: number;
    won: boolean;
    onThePlay: boolean;
    duration: string | null;
    turns: number | null;
    localMulligans: number;
    opponentMulligans: number;
    mulliganedHands: { name: string; image: string | null }[][];
    keptHand: { name: string; image: string | null; bottomed: boolean }[];
    sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
};

const props = defineProps<{
    match: App.Data.Front.MatchData;
    games: GameDetail[];
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const archetypeDialog = ref<InstanceType<typeof SetArchetypeDialog> | null>(null);

const isWin = computed(() => props.match.gamesWon > props.match.gamesLost);
const deck = computed(() => props.match.deck as App.Data.Front.DeckData | null);
const opponentArchetype = computed(() => {
    const archetypes = props.match.opponentArchetypes as App.Data.Front.MatchArchetypeData[] | null;
    return archetypes?.[0] ?? null;
});
</script>

<template>
    <SetArchetypeDialog ref="archetypeDialog" :archetypes="archetypes" />

    <AppLayout :title="`vs ${match.opponentName ?? 'Unknown'}`">
        <div class="flex flex-col gap-6 p-3 lg:p-4">
            <!-- Back link -->
            <BackLink
                v-if="deck"
                :href="DeckShowController({ deck: deck.id }).url"
                :label="deck.name"
            />

            <!-- Match header -->
            <div class="flex flex-col gap-2">
                <!-- Result + opponent -->
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3">
                        <ResultBadge :won="isWin" :showText="true" />
                        <span class="text-lg font-semibold tabular-nums">
                            {{ match.gamesWon }}–{{ match.gamesLost }}
                        </span>
                    </div>
                    <div class="h-6 w-px bg-border" />
                    <h1 class="text-lg font-semibold">vs {{ match.opponentName ?? 'Unknown' }}</h1>
                </div>

                <!-- Archetype -->
                <div class="flex items-center gap-1.5">
                    <template v-if="opponentArchetype?.archetype">
                        <span class="text-sm">{{ opponentArchetype.archetype.name }}</span>
                        <ManaSymbols :symbols="opponentArchetype.archetype.colorIdentity" />
                    </template>
                    <span v-else class="text-sm text-muted-foreground">Unknown archetype</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-5 w-5 text-muted-foreground"
                        @click="archetypeDialog?.openForMatch(match.id, match.format)"
                    >
                        <PencilIcon :size="11" />
                    </Button>
                </div>

                <!-- Metadata line -->
                <p class="text-sm text-muted-foreground">
                    <template v-if="deck">{{ deck.name }} · </template>
                    {{ match.format }}
                    · {{ dayjs(match.startedAt).format('MMM D, YYYY [at] h:mma') }}
                    · {{ match.matchTime }}
                    <template v-if="match.leagueName"> · {{ match.leagueName }}</template>
                </p>
            </div>

            <!-- Per-game sections -->
            <div class="flex flex-col gap-4">
                <MatchGame
                    v-for="game in games"
                    :key="game.id"
                    :game="game"
                    :opponent-name="(match.opponentName as string) ?? 'Opponent'"
                />
            </div>
        </div>
    </AppLayout>
</template>
```

Key changes from the current file:
- Added `BackLink` import and usage (links back to deck)
- Removed `Card`/`CardContent` wrapper around the match summary — it's now a clean header area
- Result-first layout: ResultBadge + score + separator + opponent name on one line
- Archetype on its own line
- Single metadata line with deck, format, date, duration, league
- Removed `router` import (no longer using `router.visit` — deck link is in BackLink now)
- Removed `localCardsPlayed` and `opponentCardsSeen` from the `GameDetail` type (no longer used)

**Step 2: Verify build**

Run: `npx vite build 2>&1 | tail -5`
Expected: Build succeeds

**Step 3: Commit**

```bash
git add resources/js/pages/matches/Show.vue
git commit -m "feat: redesign match summary with result-first layout and back link"
```

---

### Task 3: Redesign MatchGame with collapsible sections

**Files:**
- Modify: `resources/js/pages/matches/partials/MatchGame.vue`

**Context:** The current MatchGame component shows everything flat: kept hand, mulliganed hands, sideboard, cards played, opponent cards seen — all at the same visual level. The redesign:
- Keeps the game header always visible
- Makes subsections collapsible (kept hand open by default, others collapsed)
- Removes "Cards Played" and "Opponent Cards Seen" sections entirely
- Wires up the View Replay button to link to the game show page
- Removes the stray `{{ game.id }}` debug text on line 55

**Step 1: Rewrite the component**

Full new content for `resources/js/pages/matches/partials/MatchGame.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SwordsIcon, ChevronRight, Play } from 'lucide-vue-next';
import GameShowController from '@/actions/App/Http/Controllers/Games/ShowController';

const props = defineProps<{
    game: {
        id: number;
        number: number;
        won: boolean;
        onThePlay: boolean;
        duration: string | null;
        turns: number | null;
        localMulligans: number;
        opponentMulligans: number;
        mulliganedHands: { name: string; image: string | null }[][];
        keptHand: { name: string; image: string | null; bottomed: boolean }[];
        sideboardChanges: { name: string; image: string | null; quantity: number; type: 'in' | 'out' }[];
    };
    opponentName: string;
}>();

const handOpen = ref(true);
const mulligansOpen = ref(false);
const sideboardOpen = ref(false);
</script>

<template>
    <Card class="overflow-hidden">
        <!-- Game header -->
        <CardHeader class="flex flex-row flex-wrap items-center gap-3 bg-muted px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="font-semibold">Game {{ game.number }}</span>
                <ResultBadge :won="game.won" />
            </div>
            <div class="flex items-center gap-3 text-sm text-muted-foreground">
                <span class="flex items-center gap-1">
                    <SwordsIcon :size="13" />
                    {{ game.onThePlay ? 'On the play' : 'On the draw' }}
                </span>
                <span v-if="game.duration">{{ game.duration }}</span>
                <span v-if="game.turns !== null">{{ game.turns }} turns</span>
            </div>
            <div class="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                <span v-if="game.localMulligans > 0">You mulliganed {{ game.localMulligans }}×</span>
                <span v-if="game.opponentMulligans > 0">{{ opponentName }} mulliganed {{ game.opponentMulligans }}×</span>
            </div>
        </CardHeader>

        <CardContent class="flex flex-col gap-2 pt-4">
            <!-- Kept hand (open by default) -->
            <Collapsible v-model:open="handOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3.5 transition-transform" :class="{ 'rotate-90': handOpen }" />
                    {{ game.localMulligans > 0 ? `Kept Hand (mulligan to ${7 - game.localMulligans})` : 'Opening Hand' }}
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div class="grid grid-cols-8 gap-1 px-2 pt-2">
                        <div v-for="(card, i) in game.keptHand" :key="`kept_${i}`" class="relative shrink-0">
                            <div
                                class="overflow-hidden rounded-[10px] border-2 shadow-sm"
                                :class="card.bottomed ? 'border-destructive' : 'border-transparent'"
                            >
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                    <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                                </div>
                            </div>
                            <div
                                v-if="card.bottomed"
                                class="absolute right-0 bottom-0 left-0 rounded-b-lg bg-destructive/85 py-0.5 text-center text-xs font-medium text-destructive-foreground"
                            >
                                Bottomed
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <!-- Mulliganed hands (collapsed by default, only if mulligans happened) -->
            <Collapsible v-if="game.mulliganedHands.length" v-model:open="mulligansOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3.5 transition-transform" :class="{ 'rotate-90': mulligansOpen }" />
                    Mulliganed Hands ({{ game.mulliganedHands.length }})
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div v-for="(hand, hi) in game.mulliganedHands" :key="`mull_${hi}`" class="px-2 pt-2">
                        <p v-if="game.mulliganedHands.length > 1" class="mb-1.5 text-xs text-muted-foreground">Hand {{ hi + 1 }}</p>
                        <div class="grid grid-cols-8 gap-1">
                            <div v-for="(card, ci) in hand" :key="`mull_${hi}_${ci}`" class="shrink-0 overflow-hidden border-2 border-transparent">
                                <img v-if="card.image" :src="card.image" :alt="card.name" class="h-full w-full object-cover" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                    <span class="text-xs leading-tight text-muted-foreground">{{ card.name }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <!-- Sideboard changes (collapsed by default, only games 2+) -->
            <Collapsible v-if="game.number > 1" v-model:open="sideboardOpen">
                <CollapsibleTrigger class="flex w-full items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase transition-colors hover:bg-muted">
                    <ChevronRight class="size-3.5 transition-transform" :class="{ 'rotate-90': sideboardOpen }" />
                    Sideboard Changes
                    <span v-if="game.sideboardChanges.length" class="normal-case">({{ game.sideboardChanges.length }})</span>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div v-if="game.sideboardChanges.length" class="grid grid-cols-8 gap-1 px-2 pt-2">
                        <div
                            v-for="change in game.sideboardChanges"
                            :key="`${change.type}_${change.name}`"
                            class="relative overflow-hidden border-2"
                            :class="change.type === 'in' ? 'border-success' : 'border-destructive'"
                        >
                            <img v-if="change.image" :src="change.image" :alt="change.name" class="h-full w-full object-cover" />
                            <div v-else class="flex h-full w-full items-center justify-center bg-muted p-1.5 text-center">
                                <span class="text-xs leading-tight text-muted-foreground">{{ change.name }}</span>
                            </div>
                            <div
                                class="absolute right-0 bottom-0 left-0 py-0.5 text-center text-xs font-bold"
                                :class="change.type === 'in' ? 'bg-success/85 text-success-foreground' : 'bg-destructive/85 text-destructive-foreground'"
                            >
                                {{ change.type === 'in' ? '+' : '−' }}{{ change.quantity }}
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-2 pt-2 text-sm text-muted-foreground">No changes</p>
                </CollapsibleContent>
            </Collapsible>

            <!-- View Replay -->
            <div class="flex justify-end pt-2">
                <Button variant="outline" size="sm" as-child>
                    <Link :href="GameShowController(game.id).url" class="inline-flex items-center gap-1.5">
                        <Play :size="13" />
                        View Replay
                    </Link>
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
```

Key changes from current:
- Added `Collapsible`/`CollapsibleContent`/`CollapsibleTrigger` imports
- Added `GameShowController` import and wired up View Replay button as a real link
- Added `ChevronRight` and `Play` icons
- Removed `HoverCard` imports (no longer used)
- Removed `DeckListCard` import (no longer used)
- Kept hand section is collapsible (open by default via `handOpen = ref(true)`)
- Mulliganed hands section is collapsible (collapsed, only shown if mulligans happened)
- Sideboard section is collapsible (collapsed, only for games 2+)
- Removed "Cards Played" section entirely
- Removed "Opponent Cards Seen" section entirely
- Removed stray `{{ game.id }}` from kept hand label
- Mulligan info moved to header right side instead of inline with other stats

**Step 2: Verify build**

Run: `npx vite build 2>&1 | tail -5`
Expected: Build succeeds

**Step 3: Commit**

```bash
git add resources/js/pages/matches/partials/MatchGame.vue
git commit -m "feat: redesign game sections with collapsible layout, wire up replay link"
```

---

### Task 4: Add back link to games/Show page

**Files:**
- Modify: `resources/js/pages/games/Show.vue`

**Context:** The game replay page currently has no way to navigate back to the match. Add a BackLink pointing to the match show page.

**Step 1: Update the component**

Full new content for `resources/js/pages/games/Show.vue`:

```vue
<script setup lang="ts">
import GameReplay from './partials/GameReplay.vue';
import AppLayout from '@/AppLayout.vue';
import BackLink from '@/components/BackLink.vue';
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';

defineProps<{
    game: App.Data.Front.GameData & { match_id: number };
    timeline: App.Data.Front.GameTimelineData[];
}>();
</script>

<template>
    <AppLayout title="Game Replay">
        <div class="flex flex-col gap-4 p-3 lg:p-4">
            <BackLink
                :href="MatchShowController(game.match_id).url"
                label="Back to match"
            />
            <h1 class="text-2xl font-bold tracking-tight">Game Replay</h1>
            <GameReplay :timeline="timeline" />
        </div>
    </AppLayout>
</template>
```

Changes:
- Added `BackLink` and `MatchShowController` imports
- Added `BackLink` component above the title, linking back to the match

**Step 2: Verify build**

Run: `npx vite build 2>&1 | tail -5`
Expected: Build succeeds

**Step 3: Commit**

```bash
git add resources/js/pages/games/Show.vue
git commit -m "feat: add back link to game replay page"
```
