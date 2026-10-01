<script setup lang="ts">
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import { usePoll } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    enabled: boolean;
    url: string | null;
    size: 'full' | 'compact';
    obsSizes: Record<'full' | 'compact', [number, number]>;
    lastPublishedAt: string | null;
    error: string | null;
    linked: boolean;
}>();

const { processing, send } = useSettingsRequest();
const copied = ref<'url' | 'size' | null>(null);
const now = ref(Date.now());

useIntervalFn(() => (now.value = Date.now()), 5000);

/* Pushes happen in the background, so refresh the "last published" time while publishing. */
const { start, stop } = usePoll(15000, { only: ['overlayLastPublishedAt', 'overlayPublishError'] }, { autoStart: props.enabled });
watch(
    () => props.enabled,
    (on) => (on ? start() : stop()),
);

function setEnabled(val: boolean) {
    send('overlayPublish', 'post', UpdateOverlaySettingsController.url(), { overlay_publish: val });
}

/** The URL to paste into OBS, carrying the size chosen above. */
const obsUrl = computed(() => (props.url && props.size === 'compact' ? `${props.url}?size=compact` : props.url));

/** OBS Browser Source width x height for the chosen card size. */
const obsSize = computed(() => props.obsSizes[props.size]);

async function copy(what: 'url' | 'size') {
    const text = what === 'url' ? obsUrl.value : `${obsSize.value[0]}x${obsSize.value[1]}`;
    if (!text) return;
    await navigator.clipboard.writeText(text);
    copied.value = what;
    setTimeout(() => (copied.value = null), 1500);
}

/** Why OBS may be blank, in words the streamer can act on. */
const ERRORS: Record<string, string> = {
    not_claimed: 'Your MTGO account is not claimed on mymtgo.com yet, so the overlay cannot publish.',
    rejected: 'mymtgo.com refused the overlay data. Try a different artwork setting.',
    unreachable: 'Could not reach mymtgo.com. Retrying every 30 seconds.',
    no_login_id: 'Your MTGO account is not identified yet. Play a match, then it will publish.',
};
const errorMessage = computed(() => (props.error ? (ERRORS[props.error] ?? null) : null));

const lastPublished = computed(() => {
    if (!props.lastPublishedAt) return 'Not published yet';
    const seconds = Math.max(0, Math.round((now.value - Date.parse(props.lastPublishedAt)) / 1000));
    return seconds < 60 ? `Last published ${seconds}s ago` : `Last published ${Math.round(seconds / 60)}m ago`;
});
</script>

<template>
    <SettingsSection
        title="Stream overlay (OBS)"
        description="Show your league card in OBS as a Browser Source. Works with the league window closed."
    >
        <div class="flex items-center justify-between">
            <div>
                <Label>Publish live overlay</Label>
                <p class="text-sm text-muted-foreground">
                    {{ linked ? 'Anyone with the link can see your current league.' : 'Link your account in Sync settings to publish.' }}
                </p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="setEnabled" :disabled="!linked || processing === 'overlayPublish'" />
        </div>

        <div v-if="enabled && url" class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1.5 text-xs">{{ obsUrl }}</code>
                <Button size="sm" variant="outline" class="cursor-pointer" @click="copy('url')">{{ copied === 'url' ? 'Copied' : 'Copy' }}</Button>
            </div>
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs text-muted-foreground">
                    In OBS, set this Browser Source to <span class="font-medium text-foreground">{{ obsSize[0] }} × {{ obsSize[1] }}</span> (width ×
                    height).
                </p>
                <Button size="sm" variant="outline" class="shrink-0 cursor-pointer" @click="copy('size')">
                    {{ copied === 'size' ? 'Copied' : `Copy size (${obsSize[0]}×${obsSize[1]})` }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">{{ lastPublished }}.</p>
            <p v-if="errorMessage" class="text-xs text-destructive">{{ errorMessage }}</p>
        </div>
        <p v-else-if="enabled && !url" class="text-xs text-muted-foreground">Sign in to MTGO to get your overlay URL.</p>
    </SettingsSection>
</template>
