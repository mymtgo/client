<script setup lang="ts">
import TimeframeFilter from '@/components/TimeframeFilter.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import DashboardCustomiseSheet from '@/pages/partials/DashboardCustomiseSheet.vue';
import { COL_SPAN_CLASS, packRows, type WidgetInstance, type WidgetOptions } from '@/pages/partials/dashboardWidgets';
import WidgetHost from '@/pages/partials/WidgetHost.vue';
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, SlidersHorizontal } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    timeframe: string;
    hasMatches: boolean;
    layout: WidgetInstance[];
    widgetOptions?: WidgetOptions;
}>();

const cells = computed(() => packRows(props.layout));

const page = usePage();
const customising = ref(false);

/**
 * Widget payloads are dynamic prop names, so they are not declared above and
 * Vue would route them to $attrs. Read them straight from the page props.
 */
function widgetData(id: string): unknown {
    return (page.props as Record<string, unknown>)[`widget_${id}`];
}

function setTimeframe(value: string) {
    router.get('/', { timeframe: value }, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-4 p-3 lg:p-4">
        <div class="flex items-center justify-between gap-3">
            <TimeframeFilter :model-value="timeframe" @update:model-value="setTimeframe" />
            <Button variant="outline" size="sm" class="cursor-pointer gap-2" @click="customising = true">
                <SlidersHorizontal class="size-4" />
                Customise
            </Button>
        </div>

        <div v-if="!hasMatches" class="flex flex-col items-center gap-2 py-16 text-center">
            <LayoutDashboard class="size-10 text-muted-foreground/40" />
            <p class="font-medium">No match data yet</p>
            <p class="text-sm text-muted-foreground">Start the file watcher in Settings to begin tracking your MTGO matches.</p>
        </div>

        <div v-else-if="layout.length === 0" class="flex flex-col items-center gap-2 py-16 text-center">
            <LayoutDashboard class="size-10 text-muted-foreground/40" />
            <p class="font-medium">Your dashboard is empty</p>
            <p class="text-sm text-muted-foreground">Add some widgets with the Customise button.</p>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <div v-for="{ instance, columns } in cells" :key="instance.id" class="min-h-0" :class="COL_SPAN_CLASS[columns]">
                <Deferred :data="`widget_${instance.id}`">
                    <template #fallback>
                        <Skeleton class="h-48 rounded-xl bg-muted" />
                    </template>
                    <WidgetHost :instance="instance" :data="widgetData(instance.id)" />
                </Deferred>
            </div>
        </div>

        <DashboardCustomiseSheet v-model:open="customising" :layout="layout" :options="widgetOptions" />
    </div>
</template>
