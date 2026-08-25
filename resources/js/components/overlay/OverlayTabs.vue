<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { computed } from 'vue';

const props = defineProps<{
    modelValue: string;
    showDrawOdds: boolean;
    showSideboard: boolean;
    showReveals: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const sections = computed(() =>
    [
        { value: 'draw-odds', label: 'Draw odds', enabled: props.showDrawOdds },
        { value: 'reveals', label: 'Revealed', enabled: props.showReveals },
        { value: 'sideboard', label: 'Sideboarding', enabled: props.showSideboard },
    ].filter((section) => section.enabled),
);
</script>

<template>
    <!-- One section enabled (or none): render its pane bare, no tab bar to choose from. -->
    <div v-if="sections.length <= 1" class="min-h-0 flex-1 overflow-y-auto">
        <slot v-if="sections[0]" :name="sections[0].value" />
        <p v-else class="p-3 text-center text-xs text-muted-foreground">
            Enable draw odds, revealed cards, or the sideboard guide in Settings.
        </p>
    </div>

    <Tabs
        v-else
        :model-value="props.modelValue"
        class="flex min-h-0 flex-1 flex-col gap-0"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <TabsList class="w-full shrink-0 rounded-none border-b border-border" style="-webkit-app-region: no-drag">
            <TabsTrigger
                v-for="section in sections"
                :key="section.value"
                :value="section.value"
                class="flex-1 text-xs"
                style="-webkit-app-region: no-drag"
            >
                {{ section.label }}
            </TabsTrigger>
        </TabsList>

        <TabsContent v-for="section in sections" :key="section.value" :value="section.value" class="min-h-0 flex-1 overflow-y-auto">
            <slot :name="section.value" />
        </TabsContent>
    </Tabs>
</template>
