<script setup lang="ts">
import StateBadge from '@/components/limited/StateBadge.vue';
import DraftController from '@/actions/App/Http/Controllers/Limited/DraftController';
import DeckController from '@/actions/App/Http/Controllers/Limited/DeckController';
import MatchesController from '@/actions/App/Http/Controllers/Limited/MatchesController';
import CardsController from '@/actions/App/Http/Controllers/Limited/CardsController';
import { Link } from '@inertiajs/vue3';
import { BookOpen, Calendar, Layers2, List, Swords } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ event: App.Data.Front.LimitedEventData; currentPage: string }>();

const navItems = computed(() => [
    { key: 'draft', label: 'Draft', icon: BookOpen, href: DraftController.url({ league: props.event.id }) },
    { key: 'deck', label: 'Deck', icon: Layers2, href: DeckController.url({ league: props.event.id }) },
    { key: 'matches', label: 'Matches', icon: Swords, href: MatchesController.url({ league: props.event.id }) },
    { key: 'cards', label: 'Cards', icon: List, href: CardsController.url({ league: props.event.id }) },
]);

const recordClass = computed(() => {
    if (props.event.wins + props.event.losses === 0) return 'text-muted-foreground';
    return props.event.wins >= props.event.losses ? 'text-emerald-400' : 'text-rose-400';
});
</script>

<template>
    <div class="flex h-full flex-col border-r border-black/80 bg-muted/20">
        <div class="relative h-24 overflow-hidden border-b border-black/60">
            <img v-if="event.coverArt" :src="event.coverArt" :alt="event.title" class="pointer-events-none absolute inset-0 h-full w-full object-cover object-top opacity-50" />
            <div class="relative flex h-full flex-col items-start justify-center gap-1 px-4" :class="event.coverArt ? '[text-shadow:_0_1px_4px_rgb(0_0_0_/_80%)]' : ''">
                <span class="text-sm font-semibold leading-tight">{{ event.title }}</span>
                <span class="text-xs text-muted-foreground">{{ event.subtitle }}</span>
            </div>
        </div>
        <div class="flex flex-col gap-2 border-b border-black/80 px-3 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="event.setCode" class="rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium">{{ event.setCode }}</span>
                <span class="rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium capitalize">{{ event.kind }}</span>
                <span class="rounded-md border border-black/70 bg-background px-2.5 py-1 text-xs font-medium tabular-nums" :class="recordClass">{{ event.wins }}-{{ event.losses }}</span>
            </div>
            <div class="inline-flex items-center gap-1 text-xs text-muted-foreground"><Calendar class="size-3" />{{ event.startedAtHuman }}</div>
            <div class="flex flex-wrap items-center gap-2">
                <StateBadge :label="event.state" :variant="event.stateVariant" />
                <span class="text-xs text-muted-foreground tabular-nums">{{ event.picksMade }}/{{ event.picksExpected }} picks</span>
            </div>
        </div>
        <nav class="flex flex-1 flex-col gap-0.5 border-t border-white/5 px-2 py-3">
            <Link
                v-for="item in navItems"
                :key="item.key"
                :href="item.href"
                prefetch
                preserve-state
                class="flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors"
                :class="currentPage === item.key
                    ? 'border border-black/50 bg-black/10 text-foreground shadow-inner shadow-black/50 outline outline-white/5'
                    : 'border border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground'"
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
