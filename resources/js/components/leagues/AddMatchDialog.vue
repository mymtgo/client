<script setup lang="ts">
import LeaguesAvailableMatchesController from '@/actions/App/Http/Controllers/Leagues/AvailableMatchesController';
import LeaguesLinkMatchController from '@/actions/App/Http/Controllers/Leagues/LinkMatchController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { AvailableMatch } from '@/types/leagues';
import { router } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const open = ref(false);
const loading = ref(false);
const submitting = ref(false);
const matches = ref<AvailableMatch[]>([]);
const leagueId = ref<number | null>(null);
const remainingSlots = ref(5);
const selected = ref<Set<number>>(new Set());

const selectedCount = computed(() => selected.value.size);
const canSubmit = computed(() => !submitting.value && selectedCount.value > 0 && selectedCount.value <= remainingSlots.value);

async function openDialog(id: number, currentMatchCount: number) {
    leagueId.value = id;
    remainingSlots.value = Math.max(0, 5 - currentMatchCount);
    selected.value = new Set();
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

function toggle(id: number) {
    if (selected.value.has(id)) {
        selected.value.delete(id);
        selected.value = new Set(selected.value);
        return;
    }
    if (selectedCount.value >= remainingSlots.value) {
        return;
    }
    selected.value.add(id);
    selected.value = new Set(selected.value);
}

function submit() {
    if (!leagueId.value || !canSubmit.value) return;
    submitting.value = true;
    router.post(
        LeaguesLinkMatchController({ league: leagueId.value }).url,
        { match_ids: Array.from(selected.value) },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onFinish: () => {
                submitting.value = false;
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
                <DialogTitle>Add matches</DialogTitle>
                <DialogDescription>
                    Select up to {{ remainingSlots }} unlinked match{{ remainingSlots === 1 ? '' : 'es' }} played with this deck.
                </DialogDescription>
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
                            class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted/50 disabled:opacity-50 disabled:hover:bg-transparent"
                            :disabled="submitting || (!selected.has(m.id) && selectedCount >= remainingSlots)"
                            @click="toggle(m.id)"
                        >
                            <div
                                class="flex size-5 shrink-0 items-center justify-center rounded border"
                                :class="selected.has(m.id) ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card'"
                            >
                                <Check v-if="selected.has(m.id)" class="size-3.5" />
                            </div>
                            <div class="flex min-w-0 flex-1 flex-col">
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

            <DialogFooter class="flex items-center justify-between gap-2 sm:justify-between">
                <p class="text-xs text-muted-foreground">{{ selectedCount }} of {{ remainingSlots }} selected</p>
                <div class="flex gap-2">
                    <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                    <Button type="button" :disabled="!canSubmit" @click="submit">
                        Add {{ selectedCount > 0 ? selectedCount : '' }} match{{ selectedCount === 1 ? '' : 'es' }}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
