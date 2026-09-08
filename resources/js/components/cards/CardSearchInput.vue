<script setup lang="ts">
import SearchController from '@/actions/App/Http/Controllers/Cards/SearchController';
import { Input } from '@/components/ui/input';
import type { SearchCard } from '@/types/matches';
import { ref, watch } from 'vue';

const emit = defineEmits<{ select: [card: SearchCard] }>();

const query = ref('');
const results = ref<SearchCard[]>([]);
const highlighted = ref(0);
const loading = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let requestId = 0;

async function search(term: string): Promise<void> {
    const id = ++requestId;
    loading.value = true;
    try {
        const response = await fetch(SearchController.url({ query: { q: term } }), { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = (await response.json()) as SearchCard[];
        if (id === requestId) {
            results.value = data;
            highlighted.value = 0;
        }
    } finally {
        if (id === requestId) loading.value = false;
    }
}

watch(query, (value) => {
    if (timer) clearTimeout(timer);
    const term = value.trim();
    if (term.length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(() => search(term), 250);
});

function choose(card: SearchCard): void {
    emit('select', card);
    query.value = '';
    results.value = [];
}

function onKeydown(event: KeyboardEvent): void {
    if (results.value.length === 0) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = (highlighted.value - 1 + results.value.length) % results.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        choose(results.value[highlighted.value]);
    } else if (event.key === 'Escape') {
        results.value = [];
    }
}
</script>

<template>
    <div class="relative">
        <Input v-model="query" placeholder="Search cards by name" autocomplete="off" @keydown="onKeydown" />
        <ul
            v-if="results.length || (query.trim().length >= 2 && !loading)"
            class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-md border bg-popover p-1 text-xs shadow-md"
            role="listbox"
        >
            <li
                v-for="(card, i) in results"
                :key="card.mtgoId"
                role="option"
                :aria-selected="i === highlighted"
                class="flex cursor-pointer items-center justify-between gap-2 rounded px-2 py-1"
                :class="i === highlighted ? 'bg-accent text-accent-foreground' : ''"
                @mousemove="highlighted = i"
                @mousedown.prevent="choose(card)"
            >
                <span class="truncate">{{ card.name }}</span>
                <span class="shrink-0 text-[10px] text-muted-foreground">{{ card.type }}</span>
            </li>
            <li v-if="results.length === 0" class="px-2 py-1 text-muted-foreground italic">No cards found</li>
        </ul>
    </div>
</template>
