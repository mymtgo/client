<script setup lang="ts">
import DeleteOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/DeleteOverlayBackgroundController';
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import UploadOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/UploadOverlayBackgroundController';
import type { OverlayState, OverlayStatus } from '@/components/leagues/LeagueOverlayCard.vue';
import LeagueOverlayCard from '@/components/leagues/LeagueOverlayCard.vue';
import { sampleOverlayState } from '@/components/leagues/overlaySamples';
import SegmentedControl from '@/components/SegmentedControl.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    enabled: boolean;
    backgroundUrl: string | null;
    artwork: 'deck' | 'none' | 'custom';
    size: 'full' | 'compact';
    deckArtUrl: string | null;
    deck: OverlayState['deck'];
}>();

const { processing, send } = useSettingsRequest();

const errors = computed(() => usePage().props.errors as Record<string, string>);

function setEnabled(val: boolean) {
    send('leagueWindow', 'post', UpdateOverlaySettingsController.url(), { league_window: val });
}

const backgroundInput = ref<HTMLInputElement | null>(null);

function pickBackground() {
    backgroundInput.value?.click();
}

function uploadBackground(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('image', file);

    processing.value = 'background';
    router.post(UploadOverlayBackgroundController.url(), formData, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            processing.value = null;
            if (backgroundInput.value) {
                backgroundInput.value.value = '';
            }
        },
    });
}

function removeBackground() {
    processing.value = 'background';
    router.delete(DeleteOverlayBackgroundController.url(), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = null;
        },
    });
}

function setArtwork(value: string) {
    send('artwork', 'post', UpdateOverlaySettingsController.url(), { overlay_artwork: value });
}

function setSize(value: string) {
    send('size', 'post', UpdateOverlaySettingsController.url(), { overlay_size: value });
}

const previewStatus = ref<OverlayStatus>('in_game');

const previewArt = computed(() => {
    if (props.artwork === 'none') return null;
    if (props.artwork === 'custom') return props.backgroundUrl ?? props.deckArtUrl;
    return props.deckArtUrl;
});

const previewState = computed(() => sampleOverlayState(previewStatus.value, { art: previewArt.value, size: props.size, deck: props.deck }));

const artworkOptions = [
    { value: 'deck', label: 'Use deck cover' },
    { value: 'none', label: 'No artwork' },
    { value: 'custom', label: 'Custom artwork' },
];
const sizeOptions = [
    { value: 'full', label: 'Full' },
    { value: 'compact', label: 'Compact' },
];
const previewOptions: Array<{ value: OverlayStatus; label: string }> = [
    { value: 'in_game', label: 'In game' },
    { value: 'sideboarding', label: 'Sideboarding' },
    { value: 'waiting', label: 'Waiting' },
    { value: 'complete', label: 'Complete' },
    { value: 'trophied', label: 'Trophied' },
    { value: 'dropped', label: 'Dropped' },
    { value: 'idle', label: 'Idle' },
];
</script>

<template>
    <SettingsSection title="League progress window" description="A small always-on-top window with your current league run.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Show league window</Label>
                <p class="text-sm text-muted-foreground">Opens automatically while a league run is active.</p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="setEnabled" :disabled="processing === 'leagueWindow'" />
        </div>

        <div class="flex flex-col items-center gap-3">
            <div :class="size === 'compact' ? 'h-[58px] w-[240px]' : 'h-[100px] w-[300px]'">
                <LeagueOverlayCard :state="previewState" />
            </div>
            <div class="flex flex-wrap justify-center">
                <SegmentedControl v-model="previewStatus" :options="previewOptions" />
            </div>
        </div>

        <div class="flex items-center justify-between gap-3">
            <Label>Artwork</Label>
            <SegmentedControl
                :model-value="artwork"
                :options="artworkOptions"
                :disabled="processing === 'artwork'"
                @update:model-value="setArtwork"
            />
        </div>

        <div class="flex items-center justify-between gap-3">
            <Label>Size</Label>
            <SegmentedControl :model-value="size" :options="sizeOptions" :disabled="processing === 'size'" @update:model-value="setSize" />
        </div>

        <div v-if="artwork === 'custom'" class="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <Label>Custom background</Label>
                    <p class="text-sm text-muted-foreground">
                        Upload an image (e.g. channel art, brand logo). It replaces the deck art everywhere. Recommended: 900×300, max 5MB.
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button type="button" variant="outline" size="sm" :disabled="processing === 'background'" @click="pickBackground">
                        {{ backgroundUrl ? 'Replace' : 'Upload' }}
                    </Button>
                    <Button
                        v-if="backgroundUrl"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="processing === 'background'"
                        @click="removeBackground"
                    >
                        Remove
                    </Button>
                </div>
            </div>
            <input ref="backgroundInput" type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" @change="uploadBackground" />
            <p v-if="errors['image']" class="text-sm text-destructive">{{ errors['image'] }}</p>
        </div>
    </SettingsSection>
</template>
