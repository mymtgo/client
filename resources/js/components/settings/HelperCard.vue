<script setup lang="ts">
import RetrySidecarDownloadController from '@/actions/App/Http/Controllers/Settings/RetrySidecarDownloadController';
import UpdateSidecarEnabledController from '@/actions/App/Http/Controllers/Settings/UpdateSidecarEnabledController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import type { HelperStatus } from '@/types/settings';
import { usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    helper: HelperStatus;
}>();

const { processing, send } = useSettingsRequest();

const enabled = computed(() => props.helper.state !== 'off');

const label = computed(() => {
    switch (props.helper.state) {
        case 'running':
            return 'Running';
        case 'starting':
            return 'Starting';
        case 'downloading':
            return props.helper.progress === null ? 'Downloading' : `Downloading, ${props.helper.progress}%`;
        case 'tripped':
            return 'Stopped after repeated crashes';
        case 'failed':
            return 'Download failed';
        case 'offline':
            return 'Waiting for offline mode to be turned off';
        default:
            return 'Off';
    }
});

const detail = computed(() => {
    if (props.helper.state === 'tripped') {
        return 'Restart the app to try again.';
    }

    if (props.helper.state !== 'failed') {
        return null;
    }

    switch (props.helper.error) {
        case 'checksum':
            return 'The downloaded file did not match the expected version.';
        case 'quarantined':
            return 'Removed by antivirus after download.';
        default:
            return 'Could not reach GitHub.';
    }
});

const dotClass = computed(() => {
    switch (props.helper.state) {
        case 'running':
            return 'bg-success';
        case 'starting':
        case 'downloading':
            return 'animate-pulse bg-warning';
        case 'failed':
        case 'tripped':
            return 'bg-destructive';
        default:
            return 'bg-muted-foreground';
    }
});

const { start, stop } = usePoll(2000, { only: ['helper'] }, { autoStart: false });

// A network failure may only be the job waiting out its retry backoff, so
// keep polling there too; otherwise a later successful retry never shows.
const shouldPoll = computed(
    () =>
        props.helper.state === 'downloading' ||
        props.helper.state === 'starting' ||
        (props.helper.state === 'failed' && props.helper.error === 'network'),
);

watch(shouldPoll, (poll) => (poll ? start() : stop()), { immediate: true });

function toggle(value: boolean) {
    send('enabled', 'patch', UpdateSidecarEnabledController.url(), { enabled: value });
}

function retry() {
    send('retry', 'post', RetrySidecarDownloadController.url());
}
</script>

<template>
    <SettingsSection
        title="MTGO helper"
        description="Reads game state straight from the MTGO client for richer replays. Downloaded automatically on first run (about 68 MB)."
    >
        <div class="flex items-center justify-between gap-4">
            <div class="flex flex-col gap-1">
                <Label>Enable the helper</Label>
                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                    <span class="size-2 rounded-full" :class="dotClass" />
                    <span>{{ label }}</span>
                </div>
                <p v-if="detail" class="text-xs text-muted-foreground">{{ detail }}</p>
            </div>
            <div class="flex items-center gap-3">
                <Button
                    v-if="helper.state === 'failed'"
                    variant="outline"
                    size="sm"
                    class="cursor-pointer"
                    :disabled="processing === 'retry'"
                    @click="retry"
                >
                    Retry
                </Button>
                <Switch class="cursor-pointer" :modelValue="enabled" @update:modelValue="toggle" :disabled="processing === 'enabled'" />
            </div>
        </div>
    </SettingsSection>
</template>
