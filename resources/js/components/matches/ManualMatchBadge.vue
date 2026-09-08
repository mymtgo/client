<script setup lang="ts">
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
</script>

<template>
    <TooltipProvider>
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    class="inline-flex shrink-0 items-center rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                    aria-label="Manual match"
                    >M</span
                >
            </TooltipTrigger>
            <TooltipContent side="top">Manual match</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
