<script setup lang="ts">
import MatchShowController from '@/actions/App/Http/Controllers/Matches/ShowController';
import ResultBadge from '@/components/matches/ResultBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import LeagueScreenshot from '@/components/leagues/LeagueScreenshot.vue';
import { useScreenshot } from '@/composables/useScreenshot';
import { Badge } from '@/components/ui/badge';
import { Camera } from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import type { LeagueRun } from '@/types/leagues';

defineProps<{
    league: LeagueRun;
}>();

const screenshotRef = ref<InstanceType<typeof LeagueScreenshot> | null>(null);
const showScreenshot = ref(false);
const { capture, capturing } = useScreenshot();

async function copyScreenshot() {
    showScreenshot.value = true;
    await nextTick();
    const el = screenshotRef.value?.$el as HTMLElement | undefined;
    if (el) {
        await capture(el);
    }
    showScreenshot.value = false;
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="p-4">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Latest League</p>
                <div class="flex items-center gap-2">
                    <Badge variant="outline" class="text-xs">{{ league.format }}</Badge>
                    <span class="text-sm font-semibold tabular-nums">
                        {{ league.results.filter((r) => r === 'W').length }}-{{ league.results.filter((r) => r === 'L').length }}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-6 shrink-0"
                        :disabled="capturing"
                        @click.stop="copyScreenshot"
                    >
                        <Camera class="size-3.5" />
                    </Button>
                </div>
            </div>
            <div class="flex flex-col gap-1">
                <div
                    v-for="match in league.matches"
                    :key="match.id"
                    class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted/40"
                    @click="router.visit(MatchShowController({ id: match.id }).url)"
                >
                    <ResultBadge :won="match.result === 'W'" />
                    <span class="shrink-0 font-medium">{{ match.opponentName ?? '—' }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{ match.opponentArchetype ?? 'Unknown' }}</span>
                    <div class="ml-auto flex shrink-0 items-center gap-1">
                        <div
                            v-for="(game, i) in match.gameResults"
                            :key="i"
                            class="size-2 rounded-full"
                            :class="game.result === 'W' ? 'bg-success' : 'bg-destructive'"
                        />
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
    <div v-if="showScreenshot" style="position: fixed; top: -9999px; left: -9999px; pointer-events: none;">
        <LeagueScreenshot ref="screenshotRef" :league="league" />
    </div>
</template>
