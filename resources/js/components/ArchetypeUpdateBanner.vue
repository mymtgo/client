<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Archetypes/Refresh/ShowController';
import { Button } from '@/components/ui/button';
import { useOfflineMode } from '@/composables/useOfflineMode';
import { Link, usePage } from '@inertiajs/vue3';
import { RefreshCw, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const STORAGE_KEY = 'archetype_update_dismissed';

const page = usePage<{
    archetypeUpdate?: { available: boolean; version: string | null };
}>();

const offlineMode = useOfflineMode();

function readDismissed(): string | null {
    try {
        return sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

const dismissedVersion = ref<string | null>(readDismissed());

// Dismissal is keyed on the remote version, so a later server change
// surfaces the banner again even within the same session.
const visible = computed(() => {
    const update = page.props.archetypeUpdate;

    return Boolean(update?.available) && !offlineMode.value && dismissedVersion.value !== update?.version;
});

function dismiss() {
    const version = page.props.archetypeUpdate?.version ?? null;
    dismissedVersion.value = version;

    try {
        if (version !== null) {
            sessionStorage.setItem(STORAGE_KEY, version);
        }
    } catch {
        // sessionStorage unavailable: banner simply reappears on next load.
    }
}
</script>

<template>
    <div v-if="visible" class="flex h-9 shrink-0 items-center justify-between gap-4 border-b border-black/80 bg-primary/15 px-4 text-sm">
        <div class="flex items-center gap-2">
            <RefreshCw :size="14" class="text-primary" />
            <span>Your archetypes are out of date. Sync them to pick up the latest changes.</span>
        </div>
        <div class="flex items-center gap-1">
            <Button as-child size="sm">
                <Link :href="ShowController.url()">Review and sync</Link>
            </Button>
            <Button variant="ghost" size="icon-sm" aria-label="Dismiss" @click="dismiss">
                <X :size="14" />
            </Button>
        </div>
    </div>
</template>
