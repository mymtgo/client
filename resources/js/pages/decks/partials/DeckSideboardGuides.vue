<script setup lang="ts">
import DestroyController from '@/actions/App/Http/Controllers/Decks/SideboardGuides/DestroyController';
import EditController from '@/actions/App/Http/Controllers/Decks/SideboardGuides/EditController';
import CreateSideboardGuideDialog from '@/components/decks/CreateSideboardGuideDialog.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Link, router } from '@inertiajs/vue3';
import { BookOpen, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    deck: App.Data.Front.DeckData;
    guides: App.Data.Front.SideboardGuideSummaryData[];
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const createDialog = ref<InstanceType<typeof CreateSideboardGuideDialog> | null>(null);
const pendingDelete = ref<App.Data.Front.SideboardGuideSummaryData | null>(null);
const deleting = ref(false);

function confirmDelete(): void {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;
    router.delete(DestroyController.url({ deck: props.deck.id, sideboardGuide: pendingDelete.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            pendingDelete.value = null;
        },
    });
}

function updated(value: string): string {
    return new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

function planSummary(guide: App.Data.Front.SideboardGuideSummaryData): string {
    if (guide.cardsIn === 0 && guide.cardsOut === 0) return 'No cards planned';
    return `+${guide.cardsIn} in / -${guide.cardsOut} out`;
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <header class="flex items-center justify-between gap-3">
            <div class="flex flex-col gap-0.5">
                <h2 class="text-sm font-semibold">Sideboard guides</h2>
                <p class="text-xs text-muted-foreground">Your own plan and notes for each matchup, shown in the game overlay when you face it.</p>
            </div>
            <Button size="sm" @click="createDialog?.open()">
                <Plus class="size-4" />
                Create guide
            </Button>
        </header>

        <Empty v-if="guides.length === 0" class="border border-dashed border-border py-10">
            <BookOpen class="size-6 text-muted-foreground" />
            <EmptyDescription>No sideboard guides yet. Create one for an archetype you keep running into.</EmptyDescription>
            <Button size="sm" variant="outline" @click="createDialog?.open()">Create guide</Button>
        </Empty>

        <div v-else class="overflow-hidden rounded-md border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/30 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Archetype</th>
                        <th class="px-3 py-2 text-left">Plan</th>
                        <th class="px-3 py-2 text-right">Matches</th>
                        <th class="px-3 py-2 text-right">Match %</th>
                        <th class="px-3 py-2 text-right">Game %</th>
                        <th class="px-3 py-2 text-right">Notes</th>
                        <th class="px-3 py-2 text-right">Updated</th>
                        <th class="w-10 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="guide in guides" :key="guide.id" class="group hover:bg-muted/40">
                        <td class="px-3 py-2">
                            <Link
                                :href="EditController.url({ deck: deck.id, sideboardGuide: guide.id })"
                                class="flex items-center gap-2 font-medium hover:underline"
                            >
                                <ManaSymbols :symbols="guide.archetypeColorIdentity" />
                                {{ guide.archetypeName }}
                            </Link>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground tabular-nums">{{ planSummary(guide) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <template v-if="guide.matches > 0">{{ guide.matchRecord }}</template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <template v-if="guide.matchWinrate !== null">{{ guide.matchWinrate }}%</template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <template v-if="guide.gameWinrate !== null">{{ guide.gameWinrate }}%</template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ guide.notesCount }}</td>
                        <td class="px-3 py-2 text-right text-muted-foreground tabular-nums">{{ updated(guide.updatedAt) }}</td>
                        <td class="px-2 py-2 text-right">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                                :aria-label="`Delete guide for ${guide.archetypeName}`"
                                @click="pendingDelete = guide"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <CreateSideboardGuideDialog ref="createDialog" :deck-id="deck.id" :format="deck.format" :archetypes="archetypes" />

        <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete this guide?</DialogTitle>
                    <DialogDescription>
                        Deleting removes the plan and all notes for {{ pendingDelete?.archetypeName }}. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" :disabled="deleting" @click="pendingDelete = null">Cancel</Button>
                    <Button variant="destructive" :disabled="deleting" @click="confirmDelete">Delete</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
