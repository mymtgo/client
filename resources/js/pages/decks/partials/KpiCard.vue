<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    label: string;
    /** Right-aligned note saying what the figures are drawn from, e.g. "89 of 102 games". */
    scope?: string | null;
}>();
</script>

<template>
    <Card class="gap-0 py-0">
        <CardContent class="flex flex-col gap-3 p-4">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-xs font-medium tracking-wider text-muted-foreground uppercase">{{ label }}</span>
                <span v-if="scope" class="shrink-0 text-xs text-muted-foreground">{{ scope }}</span>
            </div>
            <slot />
        </CardContent>
    </Card>
</template>
