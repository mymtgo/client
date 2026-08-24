<script setup lang="ts">
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const props = defineProps<{
    modelValue: string;
    showDrawOdds: boolean;
    showSideboard: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <!-- One section enabled: render its pane bare, no tab bar to choose from. -->
    <div v-if="!props.showDrawOdds || !props.showSideboard" class="min-h-0 flex-1 overflow-y-auto">
        <slot v-if="props.showDrawOdds" name="draw-odds" />
        <slot v-else-if="props.showSideboard" name="sideboard" />
        <p v-else class="p-3 text-center text-xs text-muted-foreground">
            Enable draw odds or the sideboard guide in Settings.
        </p>
    </div>

    <Tabs
        v-else
        :model-value="props.modelValue"
        class="flex min-h-0 flex-1 flex-col gap-0"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <TabsList class="w-full shrink-0 rounded-none border-b border-border" style="-webkit-app-region: no-drag">
            <TabsTrigger value="draw-odds" class="flex-1 text-xs" style="-webkit-app-region: no-drag">Draw odds</TabsTrigger>
            <TabsTrigger value="sideboard" class="flex-1 text-xs" style="-webkit-app-region: no-drag">Sideboarding</TabsTrigger>
        </TabsList>

        <TabsContent value="draw-odds" class="min-h-0 flex-1 overflow-y-auto">
            <slot name="draw-odds" />
        </TabsContent>
        <TabsContent value="sideboard" class="min-h-0 flex-1 overflow-y-auto">
            <slot name="sideboard" />
        </TabsContent>
    </Tabs>
</template>
