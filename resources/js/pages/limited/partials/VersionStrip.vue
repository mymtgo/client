<script setup lang="ts">
import ManaPips from '@/components/limited/ManaPips.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { timeLabel, type DeckVersionRow } from '@/types/limited';
import { computed } from 'vue';

/**
 * Which registered build the whole page is showing. Sits above the zones
 * because it drives every card below it, not just one.
 */
const props = defineProps<{ versions: DeckVersionRow[]; selected: number | null }>();
const emit = defineEmits<{ select: [index: number] }>();

const options = computed(() => props.versions.map((version) => ({ value: String(version.index), label: `v${version.index}` })));
const active = computed(() => props.versions.find((version) => version.index === props.selected) ?? null);
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-black/60 bg-card px-4 py-2 text-xs">
        <SegmentedControl
            v-if="versions.length > 1"
            :model-value="String(selected ?? '')"
            :options="options"
            @update:model-value="(value) => emit('select', Number(value))"
        />
        <template v-if="active">
            <span class="font-medium">Registered build v{{ active.index }}<template v-if="active.isCurrent"> · current</template></span>
            <span class="text-muted-foreground">{{ active.matchLabels.join(', ') || 'unplayed' }}</span>
            <span class="text-muted-foreground">{{ timeLabel(active.capturedAt) }}</span>
            <span class="text-muted-foreground tabular-nums">{{ active.main }} main · {{ active.side }} side</span>
            <ManaPips :colors="active.colors" size="md" />
        </template>
        <span v-else class="text-muted-foreground">No registered deck yet.</span>
    </div>
</template>
