<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Check, ChevronsUpDown, RotateCcw } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

export interface SuccessorOption {
    id: number | string;
    name: string;
    format: string | null;
    incoming?: boolean;
}

const props = defineProps<{
    options: SuccessorOption[];
    modelValue: number | string | null;
    format: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | string | null];
}>();

const open = ref(false);
const search = ref('');
const searchInput = ref<InstanceType<typeof Input> | null>(null);

const selected = computed<SuccessorOption | null>(() => props.options.find((option) => option.id === props.modelValue) ?? null);

const filtered = computed<SuccessorOption[]>(() => {
    const term = search.value.trim().toLowerCase();
    const matches = term === '' ? props.options : props.options.filter((option) => option.name.toLowerCase().includes(term));

    // Same-format archetypes are the realistic successors — surface them first.
    return [...matches].sort((a, b) => {
        const aSame = a.format === props.format ? 0 : 1;
        const bSame = b.format === props.format ? 0 : 1;
        return aSame - bSame || a.name.localeCompare(b.name);
    });
});

function onOpenChange(next: boolean): void {
    open.value = next;
    if (next) {
        search.value = '';
        nextTick(() => (searchInput.value?.$el as HTMLInputElement | undefined)?.focus());
    }
}

function select(option: SuccessorOption | null): void {
    emit('update:modelValue', option?.id ?? null);
    open.value = false;
}
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <PopoverTrigger
            class="flex w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-1.5 text-left text-sm transition-colors hover:bg-accent/50"
        >
            <span v-if="selected" class="truncate">{{ selected.name }}</span>
            <span v-else class="flex items-center gap-1.5 text-muted-foreground">
                <RotateCcw class="size-3.5 shrink-0" />
                Delete &amp; re-detect
            </span>
            <ChevronsUpDown class="size-3.5 shrink-0 text-muted-foreground" />
        </PopoverTrigger>

        <PopoverContent class="w-72 p-0" align="start">
            <div class="border-b p-1">
                <Input ref="searchInput" v-model="search" placeholder="Search archetypes..." aria-label="Search archetypes" class="h-8" />
            </div>

            <div class="max-h-64 overflow-y-auto p-1">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-muted-foreground italic hover:bg-accent/50"
                    @click="select(null)"
                >
                    <Check v-if="modelValue === null" class="size-3.5 shrink-0" />
                    <span v-else class="size-3.5 shrink-0" />
                    Delete &amp; re-detect matches
                </button>

                <p v-if="filtered.length === 0" class="px-2 py-1.5 text-sm text-muted-foreground">No archetypes found.</p>

                <button
                    v-for="option in filtered"
                    :key="option.id"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent/50"
                    @click="select(option)"
                >
                    <Check v-if="option.id === modelValue" class="size-3.5 shrink-0" />
                    <span v-else class="size-3.5 shrink-0" />
                    <span class="min-w-0 flex-1 truncate">{{ option.name }}</span>
                    <span v-if="option.incoming" class="shrink-0 rounded-sm bg-accent px-1 text-[10px] text-muted-foreground uppercase">new</span>
                    <span v-if="option.format !== format" class="shrink-0 text-xs text-muted-foreground uppercase">
                        {{ option.format }}
                    </span>
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
