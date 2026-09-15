<script setup lang="ts">
import BrowseFolderController from '@/actions/App/Http/Controllers/Settings/BrowseFolderController';
import UpdateDataPathController from '@/actions/App/Http/Controllers/Settings/UpdateDataPathController';
import UpdateLogPathController from '@/actions/App/Http/Controllers/Settings/UpdateLogPathController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import type { PathStatus } from '@/types/settings';
import { ref } from 'vue';

type PathKey = 'logPath' | 'dataPath';

const props = defineProps<{
    logPath: string;
    dataPath: string;
    logPathStatus: PathStatus;
    dataPathStatus: PathStatus;
}>();

const { processing, send } = useSettingsRequest();

const inputs = {
    logPath: ref(props.logPath),
    dataPath: ref(props.dataPath),
};

const fields: Array<{ key: PathKey; label: string; hint: string; url: () => string }> = [
    { key: 'logPath', label: 'Log File Directory', hint: 'Contains mtgo.log files', url: () => UpdateLogPathController.url() },
    { key: 'dataPath', label: 'Game Data Directory', hint: 'Contains Match_GameLog_* and deck XML files', url: () => UpdateDataPathController.url() },
];

function saved(key: PathKey): string {
    return key === 'logPath' ? props.logPath : props.dataPath;
}

function status(key: PathKey): PathStatus {
    return key === 'logPath' ? props.logPathStatus : props.dataPathStatus;
}

function save(key: PathKey) {
    const field = fields.find((f) => f.key === key)!;
    send(key, 'patch', field.url(), { path: inputs[key].value });
}

async function browse(key: PathKey) {
    processing.value = key;

    try {
        const response = await fetch(BrowseFolderController.url({ query: { default: inputs[key].value } }));
        const { path } = await response.json();

        if (path) {
            inputs[key].value = path;
            save(key);
        } else {
            processing.value = null;
        }
    } catch {
        processing.value = null;
    }
}
</script>

<template>
    <SettingsSection
        title="File Paths"
        description="Where to look for MTGO log files and game data. Defaults are set automatically for standard installs."
    >
        <template v-for="(field, index) in fields" :key="field.key">
            <Separator v-if="index > 0" />
            <div class="flex flex-col gap-2">
                <Label>{{ field.label }}</Label>
                <p class="text-sm text-muted-foreground">{{ field.hint }}</p>
                <div class="flex gap-2">
                    <Input v-model="inputs[field.key].value" @keydown.enter="save(field.key)" :disabled="processing === field.key" />
                    <Button variant="outline" :disabled="processing === field.key" @click="browse(field.key)">Browse</Button>
                    <Button
                        variant="outline"
                        :disabled="processing === field.key || inputs[field.key].value === saved(field.key)"
                        @click="save(field.key)"
                    >
                        <Spinner v-if="processing === field.key" />
                        {{ processing === field.key ? 'Saving...' : 'Save' }}
                    </Button>
                </div>
                <div v-if="saved(field.key)" class="flex items-center gap-2">
                    <div class="size-2 shrink-0 rounded-full" :class="status(field.key).valid ? 'bg-primary' : 'bg-destructive'" />
                    <span class="text-sm" :class="status(field.key).valid ? 'text-muted-foreground' : 'text-destructive'">
                        {{ status(field.key).message }}
                    </span>
                </div>
            </div>
        </template>
    </SettingsSection>
</template>
