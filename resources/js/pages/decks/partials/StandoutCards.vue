<script setup lang="ts">
type StandoutCard = {
    name: string;
    image: string | null;
    stat: string;
} | null;

defineProps<{
    topPerformer: StandoutCard;
    mostCast: StandoutCard;
    mostSeen: StandoutCard;
    mostPlayedLand: StandoutCard;
    mostSidedIn: StandoutCard;
    mostSidedOut: StandoutCard;
}>();

const cards = [
    { key: 'topPerformer', title: 'Top Performer', emptyText: 'Not enough data' },
    { key: 'mostCast', title: 'Most Cast', emptyText: 'Not enough data' },
    { key: 'mostSeen', title: 'Most Seen', emptyText: 'Not enough data' },
    { key: 'mostPlayedLand', title: 'Most Played Land', emptyText: 'Not enough data' },
    { key: 'mostSidedIn', title: 'Most Sided In', emptyText: 'No sideboard data' },
    { key: 'mostSidedOut', title: 'Most Sided Out', emptyText: 'No sideboard data' },
] as const;
</script>

<template>
    <div class="grid grid-cols-6 gap-4">
        <div
            v-for="meta in cards"
            :key="meta.key"
            class="flex flex-col overflow-hidden rounded-lg border border-border bg-card"
        >
            <template v-if="$props[meta.key]">
                <p class="px-3 pt-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ meta.title }}</p>
                <img
                    v-if="$props[meta.key]!.image"
                    :src="$props[meta.key]!.image!"
                    :alt="$props[meta.key]!.name"
                    class="mx-3 mt-2 rounded-[14px]"
                />
                <div class="flex flex-col px-3 pt-2 pb-3">
                    <span class="truncate text-sm font-medium">{{ $props[meta.key]!.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ $props[meta.key]!.stat }}</span>
                </div>
            </template>
            <div v-else class="flex flex-col justify-center p-3">
                <span class="text-xs tracking-wide text-muted-foreground uppercase">{{ meta.title }}</span>
                <span class="mt-1 text-sm text-muted-foreground">{{ meta.emptyText }}</span>
            </div>
        </div>
    </div>
</template>
