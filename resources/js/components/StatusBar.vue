<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import HelpPopover from '@/components/HelpPopover.vue';
import SupportPopover from '@/components/SupportPopover.vue';

const page = usePage();

/** Shared `status` prop not wired on 1.x — tolerate undefined for this idle specimen. */
const status = computed(() => (page.props.status ?? null) as {
    watcherRunning: boolean;
    lastIngestAt: string | null;
    lastIngestAtHuman: string | null;
} | null);
</script>

<template>
    <footer class="flex h-7 shrink-0 items-center gap-4 border-t bg-muted/30 px-3 text-xs text-muted-foreground">
        <!-- Watcher status -->
        <div class="flex items-center gap-1.5">
            <div
                class="size-1.5 rounded-full"
                :class="status?.watcherRunning ? 'bg-success' : 'bg-destructive'"
            />
            <span>{{ status?.watcherRunning ? 'Watching' : 'Stopped' }}</span>
        </div>

        <div class="h-3 w-px bg-border" />

        <!-- Last ingestion -->
        <span v-if="status?.lastIngestAtHuman">
            Last ingestion {{ status.lastIngestAtHuman }}
        </span>
        <span v-else>Never ingested</span>

        <!-- Spacer -->
        <div class="flex-1" />

        <SupportPopover />

        <div class="h-3 w-px bg-border" />

        <HelpPopover />
    </footer>
</template>
