<script setup lang="ts">
defineProps<{
    entry: {
        type: string;
        turn: number | null;
        actor: string | null;
        result?: string;
        description: string;
    };
}>();
</script>

<template>
    <!-- End of game marker -->
    <div v-if="entry.type === 'end'" class="mt-2 flex items-center gap-2 pt-2 border-t">
        <div
            class="h-3 w-3 shrink-0 rounded-full"
            :class="entry.result === 'W' ? 'bg-primary' : 'bg-destructive'"
        />
        <span class="text-sm font-medium">{{ entry.description }}</span>
    </div>

    <!-- Standard event row -->
    <div v-else class="flex items-start gap-2 py-1">
        <div class="mt-1.5 shrink-0">
            <!-- Mulligan: amber dot -->
            <div v-if="entry.type === 'mulligan'" class="h-2 w-2 rounded-full bg-muted-foreground" />
            <!-- Play event: muted dot, local vs opponent tinted differently -->
            <div
                v-else
                class="h-2 w-2 rounded-full"
                :class="entry.actor === 'local' ? 'bg-primary/60' : 'bg-muted-foreground/40'"
            />
        </div>
        <div class="text-sm leading-snug">
            <span v-if="entry.turn" class="text-muted-foreground mr-1 text-xs">T{{ entry.turn }}</span>
            {{ entry.description }}
        </div>
    </div>
</template>
