<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Archetypes/ShowController';
import CreateController from '@/actions/App/Http/Controllers/Archetypes/CreateController';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ManaSymbols from '@/components/ManaSymbols.vue';
import RefreshShowController from '@/actions/App/Http/Controllers/Archetypes/Refresh/ShowController';
import { useOfflineMode } from '@/composables/useOfflineMode';
import { Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus, RefreshCw } from 'lucide-vue-next';
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

const offlineMode = useOfflineMode();

const search = ref(props.filters.search);
const format = ref(props.filters.format);

let debounceTimer: ReturnType<typeof setTimeout>;

function applyFilters(params: Record<string, string> = {}) {
    router.get(
        window.location.pathname,
        {
            // Both keys go every time, empty included. Actions redirect without
            // a query string, so the server falls back to the last filters it
            // saw — omitting an empty one would read as "no opinion" and leave
            // the cleared filter in place.
            search: search.value,
            format: format.value,
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

        <div class="flex items-center justify-between border-b border-black/40 px-3 py-2">
            <Link
                :href="CreateController.url()"
                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-purple-400 transition-colors hover:bg-accent/50"
            >
                <Plus class="size-4" />
                Create New
            </Link>
            <Link
                v-if="!offlineMode"
                :href="RefreshShowController.url()"
                class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
                title="Refresh archetypes"
            >
                <RefreshCw class="size-4" />
            </Link>
            <button
                v-else
                type="button"
                disabled
                class="cursor-not-allowed rounded-md p-1.5 text-muted-foreground/40"
                title="Offline mode is enabled — turn it off in Settings to refresh archetypes."
            >
                <RefreshCw class="size-4" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            <Link
                v-for="archetype in archetypes.data"
                :key="archetype.id"
                :href="ShowController.url({ archetype: archetype.id })"
                :data="{ search, format }"
                class="flex items-center gap-2 border-b border-black/40 px-3 py-2.5 text-sm transition-colors hover:bg-accent/50"
                :class="{
                    'border-l-2 border-l-purple-500 bg-accent/30': selectedId === archetype.id,
                    'opacity-60': archetype.mergedIntoId,
                }"
                preserve-state
                preserve-scroll
                :only="['detail']"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-1.5">
                        <span class="truncate font-medium text-foreground">{{ archetype.name }}</span>
                        <span
                            v-if="archetype.mergedIntoId"
                            class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-muted-foreground"
                            title="Merged into another archetype"
                        >
                            Merged
                        </span>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span>{{ archetype.format }}</span>
                        <span>&middot;</span>
                        <ManaSymbols v-if="archetype.colorIdentity" :symbols="archetype.colorIdentity" class="inline-flex" />
                    </div>
                </div>
                <div
                    v-if="archetype.hasDecklist && !archetype.mergedIntoId"
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
