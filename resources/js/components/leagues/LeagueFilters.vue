<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { LeagueDeckOption, LeagueFiltersState } from '@/types/leagues';
import { Search } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    filters: LeagueFiltersState;
    formats: string[];
    decks: LeagueDeckOption[];
}>();

const emit = defineEmits<{ change: [LeagueFiltersState] }>();

const local = ref<LeagueFiltersState>({ ...props.filters });

const ALL_FORMATS = '__all_formats__';
const ALL_DECKS = '__all_decks__';

const formatModel = ref(local.value.format || ALL_FORMATS);
const deckModel = ref(local.value.deck === null ? ALL_DECKS : String(local.value.deck));

const chips = [
    { key: 'all', label: 'All' },
    { key: 'live', label: 'Live' },
    { key: 'trophies', label: 'Trophies' },
    { key: 'cash', label: '4-1+' },
    { key: 'finish', label: 'Finish' },
    { key: 'bricks', label: 'Bricks' },
];

function setChip(key: string) {
    local.value.state = key;
    emit('change', { ...local.value });
}

function emitNow() {
    emit('change', { ...local.value });
}

watch(formatModel, (next) => {
    local.value.format = next === ALL_FORMATS ? '' : next;
    emitNow();
});

watch(deckModel, (next) => {
    local.value.deck = next === ALL_DECKS ? null : Number(next);
    emitNow();
});

let qTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => local.value.q,
    (next) => {
        if (qTimer) clearTimeout(qTimer);
        qTimer = setTimeout(() => emit('change', { ...local.value, q: next }), 250);
    },
);

watch(
    () => local.value.sort,
    () => emitNow(),
);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap gap-1.5">
            <Button
                v-for="c in chips"
                :key="c.key"
                size="sm"
                :variant="local.state === c.key ? 'default' : 'outline'"
                @click="setChip(c.key)"
            >
                {{ c.label }}
            </Button>
        </div>

        <Select v-model="formatModel">
            <SelectTrigger class="h-8 w-32">
                <SelectValue placeholder="Format" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem :value="ALL_FORMATS">All formats</SelectItem>
                <SelectItem v-for="f in formats" :key="f" :value="f">{{ f }}</SelectItem>
            </SelectContent>
        </Select>

        <Select v-model="deckModel">
            <SelectTrigger class="h-8 w-40">
                <SelectValue placeholder="Deck" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem :value="ALL_DECKS">All decks</SelectItem>
                <SelectItem v-for="d in decks" :key="d.id" :value="String(d.id)">
                    {{ d.name }}
                </SelectItem>
            </SelectContent>
        </Select>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <div class="relative">
                <Search class="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="local.q"
                    placeholder="Search opponent or archetype"
                    aria-label="Search opponent or archetype"
                    class="h-8 w-full pl-7 sm:w-64"
                />
            </div>
            <Select v-model="local.sort">
                <SelectTrigger class="h-8 w-32">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="newest">Newest</SelectItem>
                    <SelectItem value="oldest">Oldest</SelectItem>
                    <SelectItem value="best">Best</SelectItem>
                    <SelectItem value="worst">Worst</SelectItem>
                </SelectContent>
            </Select>
        </div>
    </div>
</template>
