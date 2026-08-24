<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import HelpPopover from '@/components/HelpPopover.vue';
import SupportPopover from '@/components/SupportPopover.vue';
import { useOfflineMode } from '@/composables/useOfflineMode';

const page = usePage();

const status = computed(() => page.props.status as {
    watcherRunning: boolean;
    lastIngestAt: string | null;
    lastIngestAtHuman: string | null;
});

const offlineMode = useOfflineMode();
</script>

<template>
    <footer class="flex h-7 shrink-0 items-center gap-4 border-t bg-muted/30 px-3 text-xs text-muted-foreground">
        <!-- Watcher status -->
        <div class="flex items-center gap-1.5">
            <div
                class="size-1.5 rounded-full"
                :class="status.watcherRunning ? 'bg-success' : 'bg-destructive'"
            />
            <span>{{ status.watcherRunning ? 'Watching' : 'Stopped' }}</span>
        </div>

        <template v-if="offlineMode">
            <div class="h-3 w-px bg-border" />
            <div class="flex items-center gap-1.5">
                <div class="size-1.5 rounded-full bg-warning" />
                <span>Offline mode</span>
            </div>
        </template>

        <div class="h-3 w-px bg-border" />

        <!-- Last ingestion -->
        <span v-if="status.lastIngestAtHuman">
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
