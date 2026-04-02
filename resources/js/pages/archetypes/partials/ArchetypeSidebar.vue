<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Archetypes/ShowController';
import CreateController from '@/actions/App/Http/Controllers/Archetypes/CreateController';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const props = defineProps<{
    archetypes: {
        data: App.Data.Front.ArchetypeData[];
        current_page: number;
        last_page: number;
    };
    formats: Record<string, string>;
    filters: {
        format: string;
        search: string;
    };
    selectedId?: number;
}>();

const search = ref(props.filters.search);
const format = ref(props.filters.format);
let debounceTimer: ReturnType<typeof setTimeout>;

function applyFilters(params: Record<string, string> = {}) {
    router.get(
        window.location.pathname,
        {
            search: search.value || undefined,
            format: format.value || undefined,
            ...params,
        },
        { preserveState: true, preserveScroll: true },
    );
}

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => applyFilters({ page: '1' }), 300);
});

function onFormatChange() {
    applyFilters({ page: '1' });
}

function goToPage(page: number) {
    applyFilters({ page: String(page) });
}
</script>

<template>
    <aside class="flex h-full w-full flex-col border-r border-black/60">
        <div class="flex gap-2 border-b border-black/60 p-3">
            <Input v-model="search" placeholder="Search..." class="basis-2/3 text-sm" />
            <Select :modelValue="format || '__all__'" @update:modelValue="(val: string) => { format = val === '__all__' ? '' : val; onFormatChange(); }">
                <SelectTrigger class="basis-1/3">
                    <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="__all__">All</SelectItem>
                    <SelectItem v-for="(label, value) in formats" :key="value" :value="value as string">
                        {{ label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="border-b border-black/40 px-3 py-2">
            <Link
                :href="CreateController.url()"
                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-purple-400 transition-colors hover:bg-accent/50"
            >
                <Plus class="size-4" />
                Create New
            </Link>
        </div>

        <div class="flex-1 overflow-y-auto">
            <Link
                v-for="archetype in archetypes.data"
                :key="archetype.id"
                :href="ShowController.url({ archetype: archetype.id })"
                :data="{ search: search || undefined, format: format || undefined }"
                class="flex items-center gap-2 border-b border-black/40 px-3 py-2.5 text-sm transition-colors hover:bg-accent/50"
                :class="{
                    'border-l-2 border-l-purple-500 bg-accent/30': selectedId === archetype.id,
                }"
                preserve-state
                preserve-scroll
                :only="['detail']"
            >
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium text-foreground">{{ archetype.name }}</div>
                    <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span>{{ archetype.format }}</span>
                        <span>&middot;</span>
                        <ManaSymbols v-if="archetype.colorIdentity" :symbols="archetype.colorIdentity" class="inline-flex" />
                    </div>
                </div>
                <div
                    v-if="archetype.hasDecklist"
                    class="size-2 shrink-0 rounded-full bg-green-500"
                    title="Decklist downloaded"
                />
            </Link>
        </div>

        <div v-if="archetypes.last_page > 1" class="flex items-center justify-between border-t border-black/60 px-3 py-2">
            <button
                :disabled="archetypes.current_page <= 1"
                class="rounded p-1 hover:bg-accent/50 disabled:opacity-30"
                @click="goToPage(archetypes.current_page - 1)"
            >
                <ChevronLeft class="size-4" />
            </button>
            <span class="text-xs text-muted-foreground">
                {{ archetypes.current_page }} / {{ archetypes.last_page }}
            </span>
            <button
                :disabled="archetypes.current_page >= archetypes.last_page"
                class="rounded p-1 hover:bg-accent/50 disabled:opacity-30"
                @click="goToPage(archetypes.current_page + 1)"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>
    </aside>
</template>
