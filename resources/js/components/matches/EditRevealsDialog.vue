<script setup lang="ts">
import UpdateRevealsController from '@/actions/App/Http/Controllers/Games/UpdateRevealsController';
import CardSearchInput from '@/components/cards/CardSearchInput.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { GameDetail, ManualEditingData, SearchCard } from '@/types/matches';
import { useForm } from '@inertiajs/vue3';
import { Minus, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Row = { mtgo_id: number; quantity: number; name: string };

const props = defineProps<{
    game: GameDetail;
    games: GameDetail[];
    archetypeDecklist: ManualEditingData['archetypeDecklist'];
}>();

const open = ref(false);
const rows = ref<Row[]>([]);

const form = useForm<{ cards: { mtgo_id: number; quantity: number }[] }>({ cards: [] });

const copySources = computed(() => props.games.filter((g) => g.id !== props.game.id && g.opponentCardsSeen.length > 0));

const archetypeCards = computed(() => {
    const list = props.archetypeDecklist ?? [];
    return [...list.filter((c) => !c.sideboard), ...list.filter((c) => c.sideboard)];
});

const firstError = computed(() => Object.values(form.errors)[0] ?? null);

function has(mtgoId: number): boolean {
    return rows.value.some((r) => r.mtgo_id === mtgoId);
}

function addCard(card: { mtgoId: number; name: string }): void {
    const existing = rows.value.find((r) => r.mtgo_id === card.mtgoId);
    if (existing) {
        existing.quantity = Math.min(20, existing.quantity + 1);
        return;
    }
    rows.value = [...rows.value, { mtgo_id: card.mtgoId, quantity: 1, name: card.name }];
}

function onSearchSelect(card: SearchCard): void {
    addCard({ mtgoId: card.mtgoId, name: card.name });
}

function bump(row: Row, delta: number): void {
    row.quantity = Math.min(20, Math.max(1, row.quantity + delta));
}

function remove(row: Row): void {
    rows.value = rows.value.filter((r) => r !== row);
}

function copyFrom(gameId: unknown): void {
    const source = props.games.find((g) => String(g.id) === String(gameId));
    if (!source) return;
    rows.value = source.opponentCardsSeen.map((c) => ({ mtgo_id: c.mtgoId, quantity: c.quantity, name: c.name }));
}

function openDialog(): void {
    form.clearErrors();
    rows.value = props.game.opponentCardsSeen.map((c) => ({ mtgo_id: c.mtgoId, quantity: c.quantity, name: c.name }));
    open.value = true;
}

function submit(): void {
    form.cards = rows.value.map(({ mtgo_id, quantity }) => ({ mtgo_id, quantity }));
    form.submit(UpdateRevealsController({ game: props.game.id }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

defineExpose({ open: openDialog });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[85vh] flex-col gap-4 overflow-hidden sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Game {{ game.number }} revealed cards</DialogTitle>
                <DialogDescription>Cards the opponent showed you this game.</DialogDescription>
            </DialogHeader>

            <div v-if="copySources.length" class="flex items-center gap-2 text-xs">
                <span class="text-muted-foreground">Copy from</span>
                <Select @update:model-value="copyFrom($event)">
                    <SelectTrigger class="h-8 w-44 text-xs">
                        <SelectValue placeholder="Another game" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="g in copySources" :key="g.id" :value="String(g.id)" class="text-xs">
                            Game {{ g.number }} ({{ g.opponentCardsSeen.length }} cards)
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="archetypeCards.length" class="flex flex-col gap-1.5">
                <h3 class="text-[11px] font-semibold tracking-widest text-muted-foreground uppercase">Expected for this archetype</h3>
                <div class="flex max-h-32 flex-wrap gap-1 overflow-y-auto">
                    <button
                        v-for="card in archetypeCards"
                        :key="`arch_${card.mtgoId}_${card.sideboard}`"
                        type="button"
                        class="rounded-full border px-2 py-0.5 text-[11px] transition hover:bg-muted"
                        :class="[has(card.mtgoId) ? 'border-primary/50 bg-primary/10' : '', card.sideboard ? 'border-dashed' : '']"
                        @click="addCard(card)"
                    >
                        {{ card.name }}
                    </button>
                </div>
            </div>

            <CardSearchInput @select="onSearchSelect" />

            <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
                <div v-for="row in rows" :key="row.mtgo_id" class="flex items-center justify-between gap-2 rounded-md border px-2 py-1 text-xs">
                    <span class="truncate">{{ row.name }}</span>
                    <div class="inline-flex shrink-0 items-center gap-1">
                        <Button type="button" variant="outline" size="icon-sm" class="size-6" :disabled="row.quantity <= 1" @click="bump(row, -1)">
                            <Minus :size="10" />
                        </Button>
                        <span class="w-5 text-center font-mono tabular-nums">{{ row.quantity }}</span>
                        <Button type="button" variant="outline" size="icon-sm" class="size-6" :disabled="row.quantity >= 20" @click="bump(row, 1)">
                            <Plus :size="10" />
                        </Button>
                        <Button type="button" variant="ghost" size="icon-sm" class="size-6" @click="remove(row)">
                            <X :size="10" />
                        </Button>
                    </div>
                </div>
                <p v-if="rows.length === 0" class="py-4 text-center text-xs text-muted-foreground italic">No cards yet.</p>
            </div>

            <p v-if="firstError" class="text-xs text-destructive">{{ firstError }}</p>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                <Button type="button" :disabled="form.processing" @click="submit()">Save cards</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
