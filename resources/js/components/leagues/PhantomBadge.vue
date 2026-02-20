<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Ghost } from 'lucide-vue-next';
</script>

<template>
    <TooltipProvider>
        <Tooltip>
            <TooltipTrigger as-child>
                <Badge variant="outline" class="text-muted-foreground shrink-0 cursor-default gap-1">
                    <Ghost class="size-3" />
                    Phantom
                </Badge>
            </TooltipTrigger>
            <TooltipContent class="max-w-64">
                <strong>Phantom leagues</strong> are your last 5 casual games grouped together — showing what your record would have been if they were a real league run. They don't count toward your stats.
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
