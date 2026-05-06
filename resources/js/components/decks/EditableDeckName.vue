<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { Pencil, RotateCcw } from 'lucide-vue-next';
import UpdateNameController from '@/actions/App/Http/Controllers/Decks/UpdateNameController';

const props = defineProps<{
    deckId: number;
    name: string;
    originalName?: string | null;
    deletedAt?: string | null;
}>();

const isEditing = ref(false);
const draft = ref(props.name);
const saving = ref(false);
const inputEl = ref<HTMLInputElement | null>(null);

const isCustomName = computed(() => Boolean(props.originalName));
const isReadonly = computed(() => Boolean(props.deletedAt));
const readonlyTitle = 'Deck deleted on MTGO — read-only';

async function startEditing() {
    if (isReadonly.value) {
        return;
    }
    draft.value = props.name;
    isEditing.value = true;
    await nextTick();
    inputEl.value?.focus();
    inputEl.value?.select();
}

function cancel() {
    draft.value = props.name;
    isEditing.value = false;
}

function commit() {
    const next = draft.value.trim();

    if (!next || next === props.name) {
        cancel();
        return;
    }

    saving.value = true;
    router.patch(
        UpdateNameController.url({ deck: props.deckId }),
        { name: next },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                saving.value = false;
                isEditing.value = false;
            },
            onError: () => {
                draft.value = props.name;
            },
        },
    );
}

function revert() {
    if (!props.originalName || saving.value || isReadonly.value) {
        return;
    }

    saving.value = true;
    router.patch(
        UpdateNameController.url({ deck: props.deckId }),
        { name: props.originalName },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="group flex w-full items-center gap-1.5">
        <template v-if="isEditing">
            <input
                ref="inputEl"
                v-model="draft"
                type="text"
                maxlength="255"
                :disabled="saving"
                class="min-w-0 flex-1 truncate rounded-sm border border-white/20 bg-black/40 px-1.5 py-0.5 text-base leading-tight font-semibold outline-none focus:border-sky-400/70 focus:ring-1 focus:ring-sky-400/30"
                @keydown.enter.prevent="commit"
                @keydown.esc.prevent="cancel"
                @blur="commit"
            />
        </template>
        <template v-else>
            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-1.5 truncate text-left disabled:cursor-default"
                :disabled="isReadonly"
                :title="isReadonly ? readonlyTitle : isCustomName ? `Custom name (was: ${originalName})` : 'Click to rename'"
                @click="startEditing"
            >
                <h2 class="truncate text-base leading-tight font-semibold">{{ name }}</h2>
                <Pencil v-if="!isReadonly" class="size-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-60" />
            </button>
            <button
                v-if="isCustomName"
                type="button"
                :disabled="saving || isReadonly"
                :title="isReadonly ? readonlyTitle : `Revert to '${originalName}'`"
                class="shrink-0 rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover:opacity-100 disabled:cursor-default disabled:opacity-0"
                @click.stop="revert"
            >
                <RotateCcw class="size-3" />
            </button>
        </template>
    </div>
</template>
