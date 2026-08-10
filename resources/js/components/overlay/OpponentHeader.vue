<script setup lang="ts">
import ArchetypeSelect from '@/components/overlay/ArchetypeSelect.vue';
import { computed } from 'vue';

const props = defineProps<{
    opponent: App.Data.Front.OverlayOpponentData | null;
    archetypes: App.Data.Front.ArchetypeData[];
    format: string | null;
}>();

const emit = defineEmits<{ select: [archetypeId: number] }>();

const headToHead = computed(() => {
    if (!props.opponent || props.opponent.previousMatches === 0) {
        return null;
    }

    return `${props.opponent.wins}–${props.opponent.losses} vs you`;
});
</script>

<template>
    <div class="flex flex-col gap-1.5 border-b border-border px-3 py-2" style="-webkit-app-region: drag">
        <template v-if="props.opponent">
            <div class="flex items-baseline justify-between gap-2">
                <span class="truncate text-sm font-semibold">{{ props.opponent.username }}</span>
                <span v-if="headToHead" class="shrink-0 text-xs text-muted-foreground">{{ headToHead }}</span>
            </div>

            <ArchetypeSelect
                :archetypes="props.archetypes"
                :format="props.format"
                :current-archetype-id="props.opponent.archetypeId"
                :current-archetype-name="props.opponent.archetypeName"
                @select="emit('select', $event)"
            />
        </template>

        <p v-else class="py-1 text-center text-xs text-muted-foreground">Waiting for opponent…</p>
    </div>
</template>
