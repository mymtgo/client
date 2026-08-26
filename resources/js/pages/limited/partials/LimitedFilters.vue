<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TIMEFRAMES, type LimitedFiltersState } from '@/types/limited';

const props = defineProps<{ filters: LimitedFiltersState; sets: string[] }>();
const emit = defineEmits<{ change: [next: LimitedFiltersState] }>();

const ALL = '__all__';

function update(patch: Partial<LimitedFiltersState>) {
    emit('change', { ...props.filters, ...patch });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Select :model-value="filters.set ?? ALL" @update:model-value="(value) => update({ set: value === ALL ? null : String(value) })">
            <SelectTrigger class="h-8 w-36" aria-label="Filter by set">
                <SelectValue placeholder="All sets" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem :value="ALL">All sets</SelectItem>
                <SelectItem v-for="set in sets" :key="set" :value="set">{{ set }}</SelectItem>
            </SelectContent>
        </Select>

        <Select
            :model-value="filters.kind ?? ALL"
            @update:model-value="(value) => update({ kind: value === ALL ? null : (String(value) as 'draft' | 'sealed') })"
        >
            <SelectTrigger class="h-8 w-40" aria-label="Filter by event kind">
                <SelectValue placeholder="Draft &amp; Sealed" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem :value="ALL">Draft &amp; Sealed</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="sealed">Sealed</SelectItem>
            </SelectContent>
        </Select>

        <SegmentedControl
            class="ml-auto"
            :model-value="filters.timeframe"
            :options="TIMEFRAMES"
            @update:model-value="(value) => update({ timeframe: value })"
        />
    </div>
</template>
