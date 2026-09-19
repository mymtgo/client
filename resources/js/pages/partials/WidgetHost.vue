<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { widgetComponents, widgetProps, type WidgetInstance } from '@/pages/partials/dashboardWidgets';
import { AlertTriangle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    instance: WidgetInstance;
    data: unknown;
}>();

const component = computed(() => widgetComponents[props.instance.type]);
const failed = computed(() => typeof props.data === 'object' && props.data !== null && (props.data as { error?: boolean }).error === true);
const boundProps = computed(() => widgetProps[props.instance.type](props.data, props.instance.config));
</script>

<template>
    <Card v-if="failed" class="h-full">
        <CardContent class="flex h-full items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
            <AlertTriangle class="size-4" />
            <span>Couldn't load {{ instance.label.toLowerCase() }}</span>
        </CardContent>
    </Card>
    <component :is="component" v-else class="h-full" v-bind="boundProps" />
</template>
