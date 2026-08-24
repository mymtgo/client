<script setup lang="ts">
import DestroyNoteController from '@/actions/App/Http/Controllers/Overlay/DestroyNoteController';
import StoreNoteController from '@/actions/App/Http/Controllers/Overlay/StoreNoteController';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    current: App.Data.Front.ArchetypeNoteData[];
    other: App.Data.Front.ArchetypeNoteData[];
    disabled?: boolean;
}>();

const body = ref('');
const saving = ref(false);

/** Grow the composer with its content instead of scrolling a two-line box. */
function autoSize(event: Event): void {
    const el = event.target as HTMLTextAreaElement;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
}

function add(): void {
    if (!body.value.trim() || saving.value) {
        return;
    }

    saving.value = true;

    router.post(
        StoreNoteController.url(),
        { body: body.value },
        {
            preserveScroll: true,
            onSuccess: () => (body.value = ''),
            onFinish: () => (saving.value = false),
        },
    );
}

function remove(id: number): void {
    router.delete(DestroyNoteController.url({ note: id }), { preserveScroll: true });
}

function stamp(value: string): string {
    return new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <section class="flex flex-col gap-2">
        <div class="flex flex-col gap-1.5">
            <textarea
                v-model="body"
                :disabled="props.disabled"
                rows="2"
                placeholder="Add a note for this matchup…"
                class="w-full resize-none overflow-hidden rounded-md border border-border bg-background px-2 py-1.5 text-xs disabled:opacity-50"
                style="-webkit-app-region: no-drag"
                @input="autoSize"
            />
            <Button
                size="sm"
                class="self-end"
                style="-webkit-app-region: no-drag"
                :disabled="props.disabled || saving || !body.trim()"
                @click="add"
            >
                Add note
            </Button>
        </div>

        <div v-if="props.current.length" class="flex flex-col gap-1.5">
            <h3 class="text-xs font-semibold text-muted-foreground">Notes for this deck</h3>
            <article
                v-for="note in props.current"
                :key="note.id"
                class="flex items-start justify-between gap-2 rounded-md border border-border px-2 py-1.5"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <p class="text-xs whitespace-pre-wrap">{{ note.body }}</p>
                    <span class="text-[10px] text-muted-foreground">{{ stamp(note.createdAt) }}</span>
                </div>
                <button
                    type="button"
                    class="shrink-0 text-muted-foreground hover:text-destructive"
                    style="-webkit-app-region: no-drag"
                    @click="remove(note.id)"
                >
                    <Trash2 class="size-3" />
                </button>
            </article>
        </div>

        <div v-if="props.other.length" class="flex flex-col gap-1.5">
            <h3 class="text-xs font-semibold text-muted-foreground">Other notes</h3>
            <article
                v-for="note in props.other"
                :key="note.id"
                class="flex flex-col gap-0.5 rounded-md border border-dashed border-border px-2 py-1.5"
            >
                <p class="text-xs whitespace-pre-wrap">{{ note.body }}</p>
                <span class="text-[10px] text-muted-foreground">{{ note.deckName }} · {{ stamp(note.createdAt) }}</span>
            </article>
        </div>
    </section>
</template>
