<script setup lang="ts">
import BrowseFolderController from '@/actions/App/Http/Controllers/Settings/BrowseFolderController';
import ResetCardImagesPathController from '@/actions/App/Http/Controllers/Settings/ResetCardImagesPathController';
import UpdateCardImagesPathController from '@/actions/App/Http/Controllers/Settings/UpdateCardImagesPathController';
import UpdateLocalImagesController from '@/actions/App/Http/Controllers/Settings/UpdateLocalImagesController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import type { CardImagesFolder } from '@/types/settings';
import { usePage, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps<{
    enabled: boolean;
    usage: string;
    folder: CardImagesFolder;
}>();

const { processing, send } = useSettingsRequest();

const page = usePage();
const folderError = computed(() => (page.props.errors as Record<string, string>)?.cardImagesPath ?? props.folder.error);
const busy = computed(() => props.folder.moving || processing.value === 'folder');

// The move runs on the downloads queue; poll until it reports back.
const { start, stop } = usePoll(2000, { only: ['cardImages', 'localImagesSize'] }, { autoStart: false });
watch(
    () => props.folder.moving,
    (moving) => (moving ? start() : stop()),
    { immediate: true },
);

function toggle(val: boolean) {
    send('localImages', 'patch', UpdateLocalImagesController.url(), { enabled: val });
}

async function browse() {
    processing.value = 'folder';

    try {
        const response = await fetch(BrowseFolderController.url({ query: { default: props.folder.path } }));
        const { path } = await response.json();

        if (path) {
            send('folder', 'patch', UpdateCardImagesPathController.url(), { path });
        } else {
            processing.value = null;
        }
    } catch {
        processing.value = null;
    }
}

function reset() {
    send('folder', 'delete', ResetCardImagesPathController.url());
}
</script>

<template>
    <SettingsSection title="Card images" description="Manage how card imagery is stored on your machine.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Download card images locally</Label>
                <p class="text-sm text-muted-foreground">
                    Save card imagery to your machine for speed and offline use. This will increase disk usage.
                </p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="toggle" :disabled="processing === 'localImages'" />
        </div>
        <p class="text-sm text-muted-foreground">Current usage: {{ usage }}</p>

        <div class="flex flex-col gap-2">
            <Label>Image folder</Label>
            <p class="text-sm text-muted-foreground">
                Store images on another drive if your system drive is short on space. Existing images are moved across.
            </p>
            <div class="flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded-md border border-border bg-muted/40 px-3 py-2 text-sm" :title="folder.path">
                    {{ folder.path }}
                </code>
                <Button variant="outline" class="shrink-0 cursor-pointer" :disabled="busy" @click="browse">
                    <Spinner v-if="busy" />
                    {{ folder.moving ? 'Moving...' : 'Change' }}
                </Button>
                <Button v-if="!folder.isDefault" variant="ghost" class="shrink-0 cursor-pointer" :disabled="busy" @click="reset">
                    Reset to default
                </Button>
            </div>
            <div v-if="folder.missing" class="flex items-center gap-2">
                <div class="size-2 shrink-0 rounded-full bg-destructive" />
                <span class="text-sm text-destructive"
                    >Image folder not found. Downloaded images will not show until it is back, and new downloads are paused.</span
                >
            </div>
            <p v-if="folderError" class="text-sm text-destructive">{{ folderError }}</p>
        </div>
    </SettingsSection>
</template>
