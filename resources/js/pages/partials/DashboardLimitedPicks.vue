<script setup lang="ts">
import CardHoverPreview from '@/components/cards/CardHoverPreview.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { WidgetConfig } from '@/pages/partials/dashboardWidgets';

type LimitedPicks = {
    setCode: string | null;
    setName: string | null;
    picks: { catalogId: string; name: string | null; image: string | null; count: number }[];
};

defineProps<{ data: LimitedPicks | null; config: WidgetConfig }>();
</script>

<template>
    <Card class="h-full">
        <CardHeader class="pb-2">
            <div class="flex items-baseline justify-between gap-2">
                <CardTitle class="text-sm font-medium tracking-wide text-muted-foreground uppercase">Top picks</CardTitle>
                <span v-if="data?.setCode" class="truncate text-xs text-muted-foreground/60">{{ data.setName ?? data.setCode }}</span>
            </div>
        </CardHeader>
        <CardContent>
            <div v-if="!data || data.picks.length === 0" class="py-6 text-center text-sm text-muted-foreground">No drafts for this set</div>
            <ul v-else class="flex flex-col">
                <li v-for="pick in data.picks" :key="pick.catalogId" class="flex items-center justify-between gap-2 py-1 text-sm">
                    <CardHoverPreview :image="pick.image" :name="pick.name ?? pick.catalogId">
                        <span class="cursor-default truncate">{{ pick.name ?? `Card #${pick.catalogId}` }}</span>
                    </CardHoverPreview>
                    <span class="shrink-0 text-muted-foreground tabular-nums">{{ pick.count }}</span>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
