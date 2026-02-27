<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

const page = usePage();

const status = computed(() => page.props.status as {
    watcherRunning: boolean;
    lastIngestAt: string | null;
    pendingMatchCount: number;
});
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

        <div class="h-3 w-px bg-border" />

        <!-- Last ingestion -->
        <span v-if="status.lastIngestAt">
            Last ingestion {{ dayjs(status.lastIngestAt).fromNow() }}
        </span>
        <span v-else>Never ingested</span>

        <!-- Spacer -->
        <div class="flex-1" />

        <!-- Pending matches -->
        <span v-if="status.pendingMatchCount > 0">
            {{ status.pendingMatchCount }} match{{ status.pendingMatchCount === 1 ? '' : 'es' }} pending
        </span>
    </footer>
</template>
