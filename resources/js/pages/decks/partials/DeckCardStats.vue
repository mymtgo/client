<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { ContextMenuItem } from '@/components/ui/context-menu';
import CardStatScreenshot from '@/components/cards/CardStatScreenshot.vue';
import CardStatsView from '@/components/cards/CardStatsView.vue';
import ExternalStatsToggle from '@/components/cards/ExternalStatsToggle.vue';
import TrustSliderPopover from '@/components/cards/TrustSliderPopover.vue';
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import ImageBase64Controller from '@/actions/App/Http/Controllers/Cards/ImageBase64Controller';
import RegenerateCardStatsController from '@/actions/App/Http/Controllers/Decks/RegenerateCardStatsController';
import CardStatsController from '@/actions/App/Http/Controllers/Decks/CardStatsController';
import { useScreenshot } from '@/composables/useScreenshot';
import { useToast } from '@/composables/useToast';
import { useTrustSetting } from '@/composables/useTrustSetting';
import { timeframeLabel } from '@/lib/timeframes';
import { Skeleton } from '@/components/ui/skeleton';
import { loadCardStatsVisibility, type CardStatsPerspective } from '@/pages/decks/partials/cardStatsColumns';
import type { CardStatsPayload, DeckCardStat } from '@/types/decks';
import { Deferred, router } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { CircleHelp, RefreshCw } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    deckId: number;
    deckArchetypeId: number | null;
    timeframe?: string;
    deletedAt?: string | null;
    cardStats?: CardStatsPayload;
}>();

const isReadonly = computed(() => Boolean(props.deletedAt));
const readonlyTitle = 'Deck deleted on MTGO — read-only';

const stats = computed(() => props.cardStats?.stats ?? []);
const archetypes = computed(() => props.cardStats?.archetypes ?? []);
const perspective = computed<CardStatsPerspective>(() => props.cardStats?.perspective ?? 'mine');
const deckWinrate = computed(() => props.cardStats?.deckWinrate ?? { wins: 0, games: 0, rate: 0.5 });
const initialTrust = computed(() => props.cardStats?.trust ?? 50);
const source = computed<'local' | 'external'>(() => props.cardStats?.source ?? 'local');
const refreshedAt = computed<string | null>(() => props.cardStats?.refreshedAt ?? null);
const externalError = computed<false | 'unavailable' | 'offline'>(() => props.cardStats?.externalError ?? false);
const archetypeMissing = computed<boolean>(() => props.deckArchetypeId === null);

const toggleLoading = ref(false);

// ── Trust + shrinkage ────────────────────────────────────────────────────────

const trust = useTrustSetting(initialTrust.value);

const trustMax = computed<number>(() => {
    const games = deckWinrate.value.games;
    if (!Number.isFinite(games) || games <= 0) return 200;
    return Math.max(200, Math.ceil((games * 2) / 50) * 50);
});

// ── Freshness badge ──────────────────────────────────────────────────────────

const isExternal = computed<boolean>(() => source.value === 'external');
const refreshedAgo = computed<string | null>(() => {
    if (!refreshedAt.value) return null;
    return useTimeAgo(new Date(refreshedAt.value)).value;
});

// ── External error toast ─────────────────────────────────────────────────────

const { add: toast } = useToast();
watch(() => externalError.value, (reason) => {
    if (!reason) return;

    toast(reason === 'offline'
        ? {
            type: 'warning',
            title: 'Offline mode',
            message: 'Community stats are unavailable while offline mode is enabled.',
        }
        : {
            type: 'error',
            title: 'Community stats unavailable',
            message: "Couldn't reach the community stats service. Try again later.",
        });
});

// ── Timeframe ────────────────────────────────────────────────────────────────

function setTimeframe(value: string): void {
    const query: Record<string, string> = {};
    if (value !== 'alltime') query.timeframe = value;
    router.get(CardStatsController.url({ deck: props.deckId }), query, { preserveScroll: true });
}

const regenerateOpen = ref(false);
const regenerating = ref(false);

function regenerateCardStats() {
    regenerating.value = true;
    router.post(
        RegenerateCardStatsController.url({ deck: props.deckId }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                regenerateOpen.value = false;
                const flash = (page.props.flash as Record<string, unknown> | undefined) ?? {};
                const count = flash.cardStatsRegenerated;
                if (typeof count === 'number') {
                    toast({
                        type: 'success',
                        title: 'Card stats regenerating',
                        message: count === 0
                            ? 'No matches needed regeneration.'
                            : `Queued regeneration for ${count} match${count === 1 ? '' : 'es'}. Stats will refresh shortly.`,
                    });
                }
            },
            onError: () => {
                toast({
                    type: 'error',
                    title: 'Failed',
                    message: 'Could not regenerate card stats.',
                });
            },
            onFinish: () => {
                regenerating.value = false;
            },
        },
    );
}

function handleFilterChange(params: {
    archetype?: string;
    playDraw?: string;
    board?: string;
    perspective?: string;
}) {
    const data: Record<string, string> = {
        card_stats_archetype: params.archetype ?? '',
        card_stats_play_draw: params.playDraw ?? '',
        card_stats_board: params.board ?? '',
        card_stats_perspective: params.perspective ?? '',
    };
    router.reload({
        only: ['cardStats'],
        data,
        preserveScroll: true,
        preserveState: true,
    });
}

// ── Screenshot ───────────────────────────────────────────────────────────────

const cardStatsViewRef = ref<InstanceType<typeof CardStatsView> | null>(null);

const screenshotStat = ref<DeckCardStat | null>(null);
const screenshotImageDataUrl = ref<string | null>(null);
const screenshotRef = ref<InstanceType<typeof CardStatScreenshot> | null>(null);

const { capture } = useScreenshot();

const selectedArchetypeRecord = computed(() => {
    const archetypeValue = cardStatsViewRef.value?.selectedArchetype ?? '__all__';
    if (archetypeValue === '__all__') return null;
    return archetypes.value.find((a) => String(a.id) === archetypeValue) ?? null;
});

const archetypeName = computed<string | null>(() => selectedArchetypeRecord.value?.name ?? null);
const archetypeColorIdentity = computed<string | null>(() => selectedArchetypeRecord.value?.colorIdentity ?? null);

const boardLabel = computed<string | null>(() => {
    const boardValue = cardStatsViewRef.value?.selectedBoard ?? '__all__';
    if (boardValue === 'preboard') return 'Game 1';
    if (boardValue === 'postboard') return 'Postboard';
    return null;
});

const playDrawLabel = computed<string | null>(() => {
    const playDrawValue = cardStatsViewRef.value?.selectedPlayDraw ?? '__all__';
    if (playDrawValue === 'play') return 'On the play';
    if (playDrawValue === 'draw') return 'On the draw';
    return null;
});

const screenshotTimeframeLabel = computed<string>(() => timeframeLabel(props.timeframe ?? 'alltime'));

const screenshotVisibleColumns = computed(() => cardStatsViewRef.value?.visibleColumns ?? loadCardStatsVisibility());

async function fetchImageDataUrl(oracleId: string): Promise<string | null> {
    try {
        const response = await fetch(ImageBase64Controller.url({ oracleId }));
        if (!response.ok) return null;
        const data = (await response.json()) as { dataUrl?: string | null };
        return data.dataUrl ?? null;
    } catch {
        return null;
    }
}

async function copyScreenshot(stat: DeckCardStat) {
    if (screenshotStat.value) return;
    if (stat.oracleId) {
        screenshotImageDataUrl.value = await fetchImageDataUrl(stat.oracleId);
    } else {
        screenshotImageDataUrl.value = null;
    }
    screenshotStat.value = stat;
    try {
        await nextTick();
        const el = screenshotRef.value?.$el as HTMLElement | undefined;
        if (el) {
            const img = el.querySelector('img');
            if (img && !img.complete) {
                await new Promise<void>((resolve) => {
                    img.addEventListener('load', () => resolve(), { once: true });
                    img.addEventListener('error', () => resolve(), { once: true });
                });
            }
            if (img && typeof img.decode === 'function') {
                await img.decode().catch(() => undefined);
            }
            await capture(el);
        }
    } finally {
        screenshotStat.value = null;
        screenshotImageDataUrl.value = null;
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <TimeframeFilter :model-value="props.timeframe ?? 'alltime'" @update:model-value="setTimeframe" />

            <div class="flex flex-wrap items-center gap-2">
                <div
                    v-if="isExternal && refreshedAgo"
                    class="select-none text-xs text-muted-foreground"
                    title="Last refresh of community aggregates"
                >
                    Community · Updated {{ refreshedAgo }}
                </div>

                <TrustSliderPopover
                    :model-value="trust.value.value"
                    :max="trustMax"
                    @update:model-value="trust.setValue"
                    @reset="trust.reset"
                />

                <ExternalStatsToggle
                    :source="source"
                    :archetype-missing="archetypeMissing"
                    @update:loading="toggleLoading = $event"
                />

                <Button
                    variant="ghost"
                    size="sm"
                    class="bevel py-4 gap-1.5 border border-black/60 px-2.5 text-xs text-muted-foreground"
                    :disabled="regenerating || isReadonly"
                    :title="isReadonly ? readonlyTitle : undefined"
                    @click="regenerateOpen = true"
                >
                    <RefreshCw class="size-3.5" :class="regenerating ? 'animate-spin' : ''" />
                    <span class="hidden lg:inline">Regenerate</span>
                </Button>

                <Sheet>
                    <SheetTrigger as-child>
                        <Button variant="ghost" size="sm" class="bevel py-4 gap-1.5 border border-black/60 px-2.5 text-xs text-muted-foreground">
                            <CircleHelp class="size-3.5" />
                            <span class="hidden lg:inline">Help</span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="overflow-y-auto sm:max-w-md">
                        <SheetHeader>
                            <SheetTitle>Understanding Card Stats</SheetTitle>
                            <SheetDescription>How to read the metrics on this page and what they mean for your deck.</SheetDescription>
                        </SheetHeader>
                        <div class="flex flex-col gap-6 px-4 pb-6">
                            <section>
                                <h3 class="mb-1 text-sm font-semibold">How Win Rates Are Calculated</h3>
                                <p class="text-sm text-muted-foreground">
                                    Win % columns are <span class="font-medium">adjusted for sample size</span>. A card with only a few games
                                    gets pulled toward your deck's overall win rate, so a lucky 2-0 doesn't show as a misleading 100%. The more
                                    games a card has, the closer its displayed value is to the raw win rate. Hover any win % to see the raw
                                    numbers behind it.
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    The <span class="font-medium">Trust</span> slider controls how many games a card needs before its own
                                    results outweigh the deck baseline. Set it to 0 to see raw win rates; raise it to be more skeptical of
                                    small samples.
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Cards with no recorded games for a column show <span class="font-mono">-</span> and sort below cards with
                                    data.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> a card that went 2-0 shows around
                                    <span class="font-mono">52%</span> rather than <span class="font-mono">100%</span> when your deck wins 48%
                                    overall &mdash; two games isn't enough evidence to stray far from the baseline.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Kept %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of games where this card appeared in your opening hand and was kept (not mulliganed away). The
                                    number in brackets is the raw count of games kept.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">23% (12)</span> means the card was kept in
                                    12 out of ~52 possible games.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Kept Win %</h3>
                                <p class="text-sm text-muted-foreground">
                                    Your win rate in games where this card was kept in your opening hand. The number in brackets is the sample
                                    size (total games where the card was kept).
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">38% (8)</span> means you won 38% of the 8
                                    games where this card was kept. A high Kept Win % suggests the card is strong in openers.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Cast %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of games where this card was actually cast (put on the stack). The number in brackets is the
                                    raw count of games where the card was cast.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">27% (14)</span> means you cast the card in
                                    14 games. A low Cast % on a mainboard card may indicate it's hard to cast or frequently sided out.
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    If a card was cast via an alternative cost or casting method (flashback, madness, evoke, warp, free, dash, escape, ...), the breakdown appears below the cast count.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Cast Win %</h3>
                                <p class="text-sm text-muted-foreground">
                                    Your win rate in games where this card was cast. The number in brackets is the sample size. This shows
                                    correlation, not causation &mdash; a low Cast Win % doesn't necessarily mean the card is bad; you might only
                                    cast it when behind.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">75% (4)</span> means you won 3 out of 4
                                    games where the card was cast. Look for cards with decent sample sizes (5+) to draw meaningful conclusions.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Played %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of games where a land card was played (put onto the battlefield from hand). This is the land equivalent of Cast % &mdash; lands are "played", not "cast". The number in brackets is the raw count.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Kicked</h3>
                                <p class="text-sm text-muted-foreground">
                                    The total number of times this card was cast with kicker across all games. Compare with total casts to see how often you have enough mana to kick it.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Activated</h3>
                                <p class="text-sm text-muted-foreground">
                                    The total number of times this card's activated ability was used across all games. High activation counts indicate the card is sticking on the battlefield and generating value.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Pregame %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of games where this card triggered before Turn 1 &mdash; either revealed from your opening hand (Chancellor cycle, Devourer of Destiny) or put onto the battlefield pre-game (Gemstone Caverns, Leylines). The number in brackets is the raw count.
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    When a card has both revealed and played triggers recorded, hover the cell for the breakdown.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Pregame Win %</h3>
                                <p class="text-sm text-muted-foreground">
                                    Your win rate in games where this card triggered pre-game. Compare against Kept Win % to see whether the opening-hand effect actually correlates with winning &mdash; a Leyline that&rsquo;s 75% Pregame Win but 45% Cast Win tells you the card is mostly a dead draw unless you open on it.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Seen %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of games where this card left your library &mdash; whether drawn naturally, tutored, milled, or
                                    exiled. Any card that appeared in your hand, on the battlefield, in the graveyard, in exile, or on the stack
                                    counts as "seen." The number in brackets is the raw count of games seen.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">54% (28)</span> means the card was seen in
                                    28 games. Seen % is usually higher than Cast % since it includes cards drawn but never cast.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Seen Win %</h3>
                                <p class="text-sm text-muted-foreground">
                                    Your win rate in games where this card was seen (drawn or otherwise left the library). The number in brackets
                                    is the sample size.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">40% (10)</span> means you won 4 out of 10
                                    games where this card was seen. Compare Seen Win % with Cast Win % &mdash; a big gap may indicate the card is
                                    only good when you can actually cast it.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">SB Out %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of postboard games (games 2 and 3) where this card was sided out of your deck. The number in
                                    brackets is the count of games it was removed. Only applies to postboard games.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">50% (10)</span> means the card was sided out
                                    in 10 postboard games. A high SB Out % on a mainboard card may suggest it's a frequent sideboard cut in your
                                    meta.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">SB In %</h3>
                                <p class="text-sm text-muted-foreground">
                                    The percentage of postboard games (games 2 and 3) where this card was sided into your deck from the sideboard.
                                    The number in brackets is the count of games it was brought in. Only applies to postboard games.
                                </p>
                                <div class="mt-2 rounded-md bg-muted px-3 py-2 text-xs">
                                    <span class="font-medium">Example:</span> <span class="font-mono">75% (6)</span> means the card was sided in
                                    for 6 postboard games. A high SB In % on a sideboard card shows it's frequently relevant in your meta.
                                </div>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Games</h3>
                                <p class="text-sm text-muted-foreground">
                                    The total number of games played with this card in the deck. This is the denominator for most percentage
                                    calculations. Cards with low game counts will have less reliable statistics.
                                </p>
                            </section>

                            <section>
                                <h3 class="mb-1 text-sm font-semibold">Reading Win Rate Colors</h3>
                                <p class="text-sm text-muted-foreground">
                                    Win rate values are color-coded: <span class="font-medium text-success">green</span> for win rates above 55%,
                                    <span class="font-medium text-destructive">red</span> for win rates below 45%, and neutral for values in
                                    between. These thresholds help you quickly spot over- and under-performers.
                                </p>
                            </section>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </div>

        <Deferred data="cardStats">
            <template #fallback>
                <Card class="gap-0 overflow-hidden p-0">
                    <CardContent class="flex flex-col gap-2 px-4 py-4">
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-full" />
                        <Skeleton class="h-8 w-3/4" />
                    </CardContent>
                </Card>
            </template>

            <CardStatsView
                ref="cardStatsViewRef"
                :stats="stats"
                :archetypes="archetypes"
                :perspective="perspective"
                :deck-winrate-rate="deckWinrate.rate"
                :source="source"
                :trust-value="trust.value.value"
                :loading="toggleLoading"
                @filter-change="handleFilterChange"
            >
                <template #row-actions="{ stat }">
                    <ContextMenuItem @click="copyScreenshot(stat)">Copy screenshot</ContextMenuItem>
                </template>
            </CardStatsView>
        </Deferred>
    </div>

    <div
        v-if="screenshotStat"
        style="position: fixed; top: -9999px; left: -9999px; pointer-events: none;"
    >
        <CardStatScreenshot
            ref="screenshotRef"
            :stat="screenshotStat"
            :visible-columns="screenshotVisibleColumns"
            :timeframe-label="screenshotTimeframeLabel"
            :archetype-name="archetypeName"
            :archetype-color-identity="archetypeColorIdentity"
            :board-label="boardLabel"
            :play-draw-label="playDrawLabel"
            :image-data-url="screenshotImageDataUrl"
        />
    </div>

    <Dialog v-model:open="regenerateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Regenerate card stats?</DialogTitle>
                <DialogDescription>
                    This will recompute card stats for every game across all matches in this deck.
                    Existing card stat rows will be replaced. Live matches process in the background and
                    may take a moment to finish.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2 sm:gap-0">
                <Button variant="outline" :disabled="regenerating" @click="regenerateOpen = false">Cancel</Button>
                <Button :disabled="regenerating" @click="regenerateCardStats">
                    <Spinner v-if="regenerating" class="mr-2 size-4" />
                    {{ regenerating ? 'Queuing...' : 'Regenerate' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
