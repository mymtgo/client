<script setup lang="ts">
import StoreNoteController from '@/actions/App/Http/Controllers/Decks/SideboardGuides/StoreNoteController';
import DestroyNoteController from '@/actions/App/Http/Controllers/Overlay/DestroyNoteController';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { router } from '@inertiajs/vue3';
import { ChevronDown, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Notes for this matchup. Each note posts on Add so it carries its own
 * timestamp; card selections elsewhere on the page are saved separately.
 */
const props = defineProps<{
    deckId: number;
    guideId: number;
    current: App.Data.Front.ArchetypeNoteData[];
    other: App.Data.Front.ArchetypeNoteData[];
}>();

const body = ref('');
const saving = ref(false);

function add(): void {
    if (!body.value.trim() || saving.value) return;

    saving.value = true;
    router.post(
        StoreNoteController.url({ deck: props.deckId, sideboardGuide: props.guideId }),
        { body: body.value },
        {
            preserveScroll: true,
            only: ['notes', 'guide'],
            onSuccess: () => (body.value = ''),
            onFinish: () => (saving.value = false),
        },
    );
}

function remove(id: number): void {
    router.delete(DestroyNoteController.url({ note: id }), { preserveScroll: true, only: ['notes', 'guide'] });
}

function stamp(value: string): string {
    return new Date(value).toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <h3 class="text-sm font-semibold">Notes</h3>

        <div class="flex flex-col gap-1.5">
            <textarea
                v-model="body"
                rows="3"
                placeholder="What did you learn this time?"
                class="w-full resize-none rounded-md border border-border bg-background px-2 py-1.5 text-sm"
            />
            <Button size="sm" class="self-end" :disabled="saving || !body.trim()" @click="add">Add note</Button>
        </div>

        <p v-if="current.length === 0" class="text-xs text-muted-foreground">No notes for this matchup yet.</p>

        <article v-for="note in current" :key="note.id" class="flex items-start justify-between gap-2 rounded-md border border-border px-3 py-2">
            <div class="flex min-w-0 flex-col gap-1">
                <p class="text-sm whitespace-pre-wrap">{{ note.body }}</p>
                <time class="text-[10px] text-muted-foreground tabular-nums">{{ stamp(note.createdAt) }}</time>
            </div>
            <Button variant="ghost" size="icon" class="size-6 shrink-0" aria-label="Delete note" @click="remove(note.id)">
                <Trash2 class="size-3" />
            </Button>
        </article>

        <Collapsible v-if="other.length">
            <CollapsibleTrigger class="flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-foreground">
                <ChevronDown class="size-3" />
                Other decks ({{ other.length }})
            </CollapsibleTrigger>
            <CollapsibleContent class="flex flex-col gap-1.5 pt-2">
                <article v-for="note in other" :key="note.id" class="rounded-md border border-border/60 px-3 py-2">
                    <p class="text-sm whitespace-pre-wrap">{{ note.body }}</p>
                    <p class="text-[10px] text-muted-foreground">{{ note.deckName }} · {{ stamp(note.createdAt) }}</p>
                </article>
            </CollapsibleContent>
        </Collapsible>
    </section>
</template>
