<script setup lang="ts">
import LeaguesAvailableMatchesController from '@/actions/App/Http/Controllers/Leagues/AvailableMatchesController';
import LeaguesLinkMatchController from '@/actions/App/Http/Controllers/Leagues/LinkMatchController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { AvailableMatch } from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const open = ref(false);
const loading = ref(false);
const submitting = ref<number | null>(null);
const matches = ref<AvailableMatch[]>([]);
const leagueId = ref<number | null>(null);

async function openDialog(id: number) {
    leagueId.value = id;
    open.value = true;
    loading.value = true;
    matches.value = [];
    try {
        const res = await fetch(LeaguesAvailableMatchesController({ league: id }).url, {
            headers: { Accept: 'application/json' },
        });
        matches.value = await res.json();
    } finally {
        loading.value = false;
    }
}

function pick(matchId: number) {
    if (!leagueId.value) return;
    submitting.value = matchId;
    router.post(
        LeaguesLinkMatchController({ league: leagueId.value }).url,
        { match_id: matchId },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onFinish: () => {
                submitting.value = null;
            },
        },
    );
}

defineExpose({ open: openDialog });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Add a match</DialogTitle>
                <DialogDescription>Pick an unlinked match played with this deck.</DialogDescription>
            </DialogHeader>

            <div class="max-h-[60vh] overflow-y-auto">
                <div v-if="loading" class="space-y-2 p-2">
                    <div class="h-10 animate-pulse rounded bg-muted" />
                    <div class="h-10 animate-pulse rounded bg-muted" />
                    <div class="h-10 animate-pulse rounded bg-muted" />
                </div>

                <div v-else-if="!matches.length" class="p-6 text-center text-sm text-muted-foreground">
                    No unlinked matches for this deck.
                </div>

                <ul v-else class="divide-y divide-border">
                    <li v-for="m in matches" :key="m.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-muted/50 disabled:opacity-50"
                            :disabled="submitting !== null"
                            @click="pick(m.id)"
                        >
                            <div class="flex min-w-0 flex-col">
                                <span class="truncate text-sm font-medium">
                                    {{ m.opponentName ?? 'Unknown opponent' }}
                                </span>
                                <span class="truncate text-xs text-muted-foreground">
                                    {{ m.opponentArchetype ?? 'Unknown archetype' }} · {{ m.startedAtHuman }}
                                </span>
                            </div>
                            <div class="flex shrink-0 items-center gap-2 text-xs tabular-nums">
                                <span :class="m.result === 'W' ? 'text-success' : m.result === 'L' ? 'text-destructive' : 'text-muted-foreground'">
                                    {{ m.result ?? '—' }}
                                </span>
                                <span class="text-muted-foreground">{{ m.gameRecord }}</span>
                            </div>
                        </button>
                    </li>
                </ul>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="open = false">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
