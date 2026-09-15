<script setup lang="ts">
import DeleteOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/DeleteOverlayBackgroundController';
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import UploadOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/UploadOverlayBackgroundController';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
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

const sampleLeague = computed<LeagueData>(() => ({
    id: 0,
    name: 'Friendly League',
    format: 'Modern',
    wins: 3,
    losses: 1,
    totalMatches: 4,
    deckId: null,
    deckName: 'Mono Green Tron',
    backgroundUrl: props.backgroundUrl,
    hasActiveMatch: true,
    games: [
        { won: true, ended: true },
        { won: false, ended: true },
        { won: null, ended: false },
    ],
}));
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

        <div class="mx-auto w-64 overflow-hidden rounded-md border border-border">
            <LeagueTracker :league="sampleLeague" />
        </div>

        <div class="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <Label>Custom background</Label>
                    <p class="text-sm text-muted-foreground">
                        Upload an image (e.g. channel art, brand logo). Falls back to your deck cover when empty. Recommended: ~1200×400, max 5MB.
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
