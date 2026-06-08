<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

interface Props {
    source: 'local' | 'external';
    archetypeMissing: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:loading': [loading: boolean];
}>();

const optimisticChecked = ref<boolean | null>(null);

const checked = computed<boolean>(() =>
    optimisticChecked.value ?? props.source === 'external',
);

watch(
    () => props.source,
    () => {
        optimisticChecked.value = null;
    },
);

function onToggle(next: boolean): void {
    if (props.archetypeMissing) {
        return;
    }
    optimisticChecked.value = next;
    emit('update:loading', true);
    router.reload({
        data: { card_stats_source: next ? 'external' : undefined },
        only: ['cardStats'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            optimisticChecked.value = null;
            emit('update:loading', false);
        },
    });
}
</script>

<template>
    <TooltipProvider>
        <Tooltip>
            <TooltipTrigger as-child>
                <div class="inline-flex items-center gap-2">
                    <Switch
                        :modelValue="checked"
                        :disabled="archetypeMissing"
                        @update:modelValue="onToggle"
                    />
                    <span class="select-none text-xs text-muted-foreground">Community stats</span>
                </div>
            </TooltipTrigger>
            <TooltipContent v-if="archetypeMissing">
                Set an archetype on this deck to enable community stats.
            </TooltipContent>
            <TooltipContent v-else>
                Show aggregated stats from all reporting players for this archetype.
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
