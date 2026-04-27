<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface MatchOption {
    id: number;
    opponent_username: string;
    started_at: string | null;
}

const props = defineProps<{
    matches: MatchOption[];
    format: string;
    modelValue: number | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
}>();

const open = ref(false);
const search = ref('');
const reloading = ref(false);
let debounce: ReturnType<typeof setTimeout> | null = null;

function reload(searchValue: string) {
    if (!props.format) return;
    reloading.value = true;
    router.reload({
        only: ['matches'],
        data: {
            format: props.format,
            search: '',
            match_search: searchValue,
        },
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            reloading.value = false;
        },
    });
}

watch(search, (val) => {
    if (debounce) clearTimeout(debounce);
    debounce = setTimeout(() => reload(val), 300);
});

watch(
    () => props.format,
    () => {
        emit('update:modelValue', null);
        search.value = '';
        if (props.format) {
            reload('');
        }
    },
);

const selected = computed(() => props.matches.find((m) => m.id === props.modelValue) ?? null);

function pick(match: MatchOption) {
    emit('update:modelValue', match.id);
    open.value = false;
}

function clear() {
    emit('update:modelValue', null);
    search.value = '';
    reload('');
}

function relativeTime(iso: string | null): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    const diffMs = Date.now() - then;
    const minutes = Math.floor(diffMs / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    const weeks = Math.floor(days / 7);
    if (weeks < 4) return `${weeks}w ago`;
    const months = Math.floor(days / 30);
    if (months < 12) return `${months}mo ago`;
    const years = Math.floor(days / 365);
    return `${years}y ago`;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :disabled="!format"
                class="w-full justify-between"
            >
                <span v-if="selected" class="flex min-w-0 items-center gap-2">
                    <span class="truncate">{{ selected.opponent_username }}</span>
                    <span class="text-xs text-muted-foreground">{{ relativeTime(selected.started_at) }}</span>
                </span>
                <span v-else class="text-muted-foreground">
                    {{ format ? 'Pick a match' : 'Select a format first' }}
                </span>
                <ChevronsUpDown class="ml-2 size-4 shrink-0 opacity-50" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-96 p-0" align="start">
            <div class="flex h-9 items-center gap-2 border-b px-3">
                <Search class="size-4 shrink-0 opacity-50" />
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search opponent name..."
                    class="placeholder:text-muted-foreground flex h-9 w-full bg-transparent py-2 text-sm outline-none"
                />
                <Spinner v-if="reloading" class="size-3.5" />
            </div>

            <div class="max-h-64 overflow-y-auto p-1">
                <div v-if="!matches.length" class="px-3 py-6 text-center text-sm text-muted-foreground">
                    {{ search ? 'No matches found.' : 'No matches available.' }}
                </div>

                <button
                    v-for="match in matches"
                    :key="match.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                    @click="pick(match)"
                >
                    <span class="flex min-w-0 items-center gap-2">
                        <Check
                            class="size-4 shrink-0"
                            :class="selected?.id === match.id ? 'opacity-100' : 'opacity-0'"
                        />
                        <span class="truncate">{{ match.opponent_username }}</span>
                    </span>
                    <span class="shrink-0 text-xs text-muted-foreground">{{ relativeTime(match.started_at) }}</span>
                </button>

                <button
                    v-if="selected"
                    type="button"
                    class="mt-1 w-full border-t border-border px-2 py-1.5 text-left text-xs text-muted-foreground hover:text-foreground"
                    @click="clear"
                >
                    Clear selection
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
