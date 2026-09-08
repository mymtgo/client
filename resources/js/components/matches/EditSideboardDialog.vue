<script setup lang="ts">
import UpdateSideboardController from '@/actions/App/Http/Controllers/Games/UpdateSideboardController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { DeckCardOption, GameDetail } from '@/types/matches';
import { useForm } from '@inertiajs/vue3';
import { Minus, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Change = { mtgo_id: number; quantity: number; type: 'in' | 'out' };

const props = defineProps<{
    game: GameDetail;
    mains: DeckCardOption[];
    sideboard: DeckCardOption[];
}>();

const open = ref(false);
const outs = ref<Map<number, number>>(new Map());
const ins = ref<Map<number, number>>(new Map());

const form = useForm<{ changes: Change[] }>({ changes: [] });

const outTotal = computed(() => [...outs.value.values()].reduce((a, b) => a + b, 0));
const inTotal = computed(() => [...ins.value.values()].reduce((a, b) => a + b, 0));

function bump(direction: 'out' | 'in', card: DeckCardOption, delta: number): void {
    const map = direction === 'out' ? outs.value : ins.value;
    const next = Math.min(card.quantity, Math.max(0, (map.get(card.mtgoId) ?? 0) + delta));
    const copy = new Map(map);
    if (next === 0) copy.delete(card.mtgoId);
    else copy.set(card.mtgoId, next);
    if (direction === 'out') outs.value = copy;
    else ins.value = copy;
}

function openDialog(): void {
    form.clearErrors();
    outs.value = new Map(props.game.sideboardChanges.filter((c) => c.type === 'out').map((c) => [c.mtgoId, c.quantity]));
    ins.value = new Map(props.game.sideboardChanges.filter((c) => c.type === 'in').map((c) => [c.mtgoId, c.quantity]));
    open.value = true;
}

function submit(): void {
    form.changes = [
        ...[...outs.value.entries()].map(([mtgo_id, quantity]) => ({ mtgo_id, quantity, type: 'out' as const })),
        ...[...ins.value.entries()].map(([mtgo_id, quantity]) => ({ mtgo_id, quantity, type: 'in' as const })),
    ];
    form.submit(UpdateSideboardController({ game: props.game.id }), {
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
        <DialogContent class="flex max-h-[85vh] flex-col gap-4 overflow-hidden sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Game {{ game.number }} sideboard changes</DialogTitle>
                <DialogDescription>Take cards out of the maindeck and bring cards in from the sideboard.</DialogDescription>
            </DialogHeader>

            <div class="flex items-center gap-3 font-mono text-xs tabular-nums">
                <span class="text-destructive">−{{ outTotal }} out</span>
                <span class="text-success">+{{ inTotal }} in</span>
                <span v-if="outTotal !== inTotal" class="ml-auto text-muted-foreground italic">Totals differ, saved as entered</span>
            </div>

            <div class="grid min-h-0 flex-1 grid-cols-2 gap-3 overflow-hidden">
                <section class="flex min-h-0 flex-col gap-1.5">
                    <h3 class="text-[11px] font-semibold tracking-widest text-muted-foreground uppercase">Maindeck</h3>
                    <div class="flex flex-col gap-1 overflow-y-auto pr-1">
                        <div
                            v-for="card in mains"
                            :key="`out_${card.mtgoId}`"
                            class="flex items-center justify-between gap-2 rounded-md border px-2 py-1 text-xs"
                            :class="outs.get(card.mtgoId) ? 'border-destructive/50 bg-destructive/5' : ''"
                        >
                            <span class="truncate">
                                {{ card.name }} <span class="text-muted-foreground">×{{ card.quantity }}</span>
                            </span>
                            <div class="inline-flex shrink-0 items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="size-6"
                                    title="Put a copy back"
                                    :disabled="!outs.get(card.mtgoId)"
                                    @click="bump('out', card, -1)"
                                >
                                    <Plus :size="10" />
                                </Button>
                                <span class="w-5 text-center font-mono tabular-nums">{{ outs.get(card.mtgoId) ?? 0 }}</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="size-6"
                                    title="Take a copy out"
                                    :disabled="(outs.get(card.mtgoId) ?? 0) >= card.quantity"
                                    @click="bump('out', card, 1)"
                                >
                                    <Minus :size="10" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="flex min-h-0 flex-col gap-1.5">
                    <h3 class="text-[11px] font-semibold tracking-widest text-muted-foreground uppercase">Sideboard</h3>
                    <div class="flex flex-col gap-1 overflow-y-auto pr-1">
                        <div
                            v-for="card in sideboard"
                            :key="`in_${card.mtgoId}`"
                            class="flex items-center justify-between gap-2 rounded-md border px-2 py-1 text-xs"
                            :class="ins.get(card.mtgoId) ? 'border-success/50 bg-success/5' : ''"
                        >
                            <span class="truncate">
                                {{ card.name }} <span class="text-muted-foreground">×{{ card.quantity }}</span>
                            </span>
                            <div class="inline-flex shrink-0 items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="size-6"
                                    title="Leave a copy in the sideboard"
                                    :disabled="!ins.get(card.mtgoId)"
                                    @click="bump('in', card, -1)"
                                >
                                    <Minus :size="10" />
                                </Button>
                                <span class="w-5 text-center font-mono tabular-nums">{{ ins.get(card.mtgoId) ?? 0 }}</span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="size-6"
                                    title="Bring a copy in"
                                    :disabled="(ins.get(card.mtgoId) ?? 0) >= card.quantity"
                                    @click="bump('in', card, 1)"
                                >
                                    <Plus :size="10" />
                                </Button>
                            </div>
                        </div>
                        <p v-if="sideboard.length === 0" class="text-xs text-muted-foreground italic">This deck version has no sideboard.</p>
                    </div>
                </section>
            </div>

            <p v-if="form.errors.changes" class="text-xs text-destructive">{{ form.errors.changes }}</p>

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" @click="open = false">Cancel</Button>
                <Button type="button" :disabled="form.processing" @click="submit()">Save changes</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
