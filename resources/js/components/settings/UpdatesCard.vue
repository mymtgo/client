<script setup lang="ts">
import { Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import CheckForUpdatesController from '@/actions/App/Http/Controllers/Settings/CheckForUpdatesController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useInstallUpdate } from '@/composables/useInstallUpdate';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import { useUpdateStatus } from '@/composables/useUpdateStatus';

const props = withDefaults(defineProps<{ whatsNewUrl?: string | null }>(), { whatsNewUrl: null });

const update = useUpdateStatus();
const { installing, install } = useInstallUpdate();
const { processing, send } = useSettingsRequest();

const label = computed(() => {
    if (!update.value.active) {
        return 'Updates are only checked in the installed app';
    }

    switch (update.value.status) {
        case 'checking':
            return 'Checking for updates...';
        case 'downloading':
            return `Downloading v${update.value.available}...`;
        case 'ready':
            return `v${update.value.available} is ready to install`;
        case 'error':
            return 'Could not check for updates';
        default:
            return "You're up to date";
    }
});

const checkedAgo = computed(() => {
    if (!update.value.checkedAt) {
        return null;
    }

    const minutes = Math.round((Date.now() - new Date(update.value.checkedAt).getTime()) / 60000);

    if (minutes < 1) {
        return 'Last checked just now';
    }

    return minutes < 60 ? `Last checked ${minutes} min ago` : `Last checked ${Math.round(minutes / 60)} h ago`;
});

const dotClass = computed(() => {
    switch (update.value.status) {
        case 'ready':
            return 'bg-primary';
        case 'checking':
        case 'downloading':
            return 'animate-pulse bg-warning';
        case 'error':
            return 'bg-destructive';
        default:
            return 'bg-success';
    }
});

// Backstop for the Native event listeners: poll while something is in flight.
const { start, stop } = usePoll(2000, { only: ['update'] }, { autoStart: false });
const inFlight = computed(() => update.value.status === 'checking' || update.value.status === 'downloading');
watch(inFlight, (poll) => (poll ? start() : stop()), { immediate: true });

function check() {
    send('check', 'post', CheckForUpdatesController.url());
}
</script>

<template>
    <SettingsSection title="Updates" :description="`mymtgo v${update.current}`">
        <div class="flex items-center justify-between gap-4">
            <div class="flex flex-col gap-1">
                <Label>App version</Label>
                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="size-2 rounded-full" :class="dotClass" />
                    <span>{{ label }}</span>
                </div>
                <p v-if="update.status === 'error' && update.error" class="text-xs text-muted-foreground">{{ update.error }}</p>
                <p v-if="checkedAgo" class="text-xs text-muted-foreground">{{ checkedAgo }}</p>
            </div>
            <div class="flex items-center gap-3">
                <Link v-if="props.whatsNewUrl" :href="props.whatsNewUrl" class="cursor-pointer text-sm text-muted-foreground hover:text-foreground">
                    What's new
                </Link>
                <Button v-if="update.status === 'ready'" size="sm" class="cursor-pointer" :disabled="installing" @click="install">
                    Install & restart
                </Button>
                <Button
                    v-else
                    variant="outline"
                    size="sm"
                    class="cursor-pointer"
                    :disabled="!update.active || processing === 'check' || inFlight"
                    @click="check"
                >
                    Check for updates
                </Button>
            </div>
        </div>
    </SettingsSection>
</template>
