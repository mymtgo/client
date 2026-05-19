<script setup lang="ts">
import CardStatsController from '@/actions/App/Http/Controllers/Reports/CardStatsController';
import MatchesController from '@/actions/App/Http/Controllers/Reports/MatchesController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ReportArchetypeOption, ReportArchetypeStats, ReportFormatOption, ReportsCurrentPage } from '@/types/reports';
import { Link, router } from '@inertiajs/vue3';
import { BarChart3, Layers, Swords, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    currentPage: ReportsCurrentPage;
    archetypeOptions: ReportArchetypeOption[];
    formatOptions: ReportFormatOption[];
    selectedArchetype: number | null;
    selectedFormat: string | null;
    timeframe: string;
    archetypeStats: ReportArchetypeStats | null;
}>();

type NavItem = {
    key: ReportsCurrentPage;
    label: string;
    icon: LucideIcon;
    url: (q: { query: Record<string, string | number> }) => string;
};

const items: NavItem[] = [
    { key: 'matches', label: 'Matches', icon: Swords, url: (opts) => MatchesController.url(opts) },
    { key: 'card-stats', label: 'Card Stats', icon: BarChart3, url: (opts) => CardStatsController.url(opts) },
];

const query = computed(() => {
    const q: Record<string, string | number> = { timeframe: props.timeframe };
    if (props.selectedArchetype !== null) {
        q.archetype = props.selectedArchetype;
    }
    if (props.selectedFormat !== null) {
        q.format = props.selectedFormat;
    }
    return q;
});

const winrateColor = computed(() => {
    if (props.archetypeStats === null) return 'text-white/70';
    const wr = props.archetypeStats.matchWinrate;
    if (wr >= 55) return 'text-emerald-400';
    if (wr <= 50) return 'text-rose-400';
    return 'text-white/70';
});

const controller = computed(() => (props.currentPage === 'matches' ? MatchesController : CardStatsController));

function navigate(query: Record<string, string | number | null>) {
    const cleaned = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== null && v !== ''));
    router.get(controller.value.url(), cleaned, {
        preserveScroll: true,
        preserveState: true,
    });
}

function onArchetypeChange(raw: unknown) {
    const value = String(raw ?? '');
    const id = value === '' ? null : Number(value);
    navigate({ archetype: id, format: null, timeframe: props.timeframe });
}

function onFormatChange(raw: unknown) {
    const value = String(raw ?? '');
    const format = value === '' ? null : value;
    navigate({ archetype: props.selectedArchetype, format, timeframe: props.timeframe });
}
</script>

<template>
    <div class="flex h-full flex-col border-r border-black/80 bg-muted/20">
        <!-- Stats block -->
        <div class="flex flex-col gap-2 border-b border-black/80 px-3 py-3">
            <template v-if="archetypeStats">
                <div class="flex items-center gap-2">
                    <span class="flex items-center gap-1 rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium text-white/70">
                        <Layers class="size-3" />
                        {{ archetypeStats.deckCount }} decks
                    </span>
                </div>
                <div class="flex flex-col gap-0.5 px-0.5">
                    <span :class="['text-2xl leading-none font-bold tabular-nums', winrateColor]">
                        {{ archetypeStats.matchWinrate.toFixed(1) }}%
                    </span>
                    <span class="text-xs text-white/50">
                        {{ archetypeStats.matchWins }}W – {{ archetypeStats.matchLosses }}L
                        <template v-if="archetypeStats.matchDraws > 0"> – {{ archetypeStats.matchDraws }}D</template>
                    </span>
                </div>
            </template>
            <template v-else>
                <p class="text-xs text-muted-foreground/60">Pick an archetype and format to see stats.</p>
            </template>
        </div>
        <!-- Selector header -->
        <div class="flex flex-col gap-2 border-b border-black/60 px-3 py-3">
            <Select :model-value="selectedArchetype !== null ? String(selectedArchetype) : ''" @update:model-value="onArchetypeChange">
                <SelectTrigger class="h-9 w-full text-xs">
                    <SelectValue placeholder="Choose archetype" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="opt in archetypeOptions" :key="opt.id" :value="String(opt.id)">
                        {{ opt.name }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <Select :model-value="selectedFormat ?? ''" :disabled="selectedArchetype === null" @update:model-value="onFormatChange">
                <SelectTrigger class="h-9 w-full text-xs">
                    <SelectValue placeholder="Choose format" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="opt in formatOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>



        <!-- Navigation -->
        <nav class="flex flex-1 flex-col gap-0.5 border-t border-white/5 px-2 py-3">
            <Link
                v-for="item in items"
                :key="item.key"
                :href="item.url({ query: query })"
                prefetch="hover"
                cache-for="10s"
                class="flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors"
                :class="
                    currentPage === item.key
                        ? 'border border-black/50 bg-black/10 text-foreground shadow-inner shadow-black/50 outline outline-white/5'
                        : 'border border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'
                "
            >
                <component
                    :is="item.icon"
                    class="size-4 shrink-0 transition-[color,filter] duration-150"
                    :class="{ 'nav-icon-active': currentPage === item.key }"
                />
                <span>{{ item.label }}</span>
            </Link>
        </nav>
    </div>
</template>

<style scoped>
.nav-icon-active {
    color: #38bdf8;
    filter: drop-shadow(0 0 4px rgba(56, 189, 248, 0.7)) drop-shadow(0 0 8px rgba(56, 189, 248, 0.35));
}
</style>
