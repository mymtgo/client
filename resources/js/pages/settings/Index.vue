<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { router } from '@inertiajs/vue3';
import UpdateLogPathController from '@/actions/App/Http/Controllers/Settings/UpdateLogPathController';
import UpdateDataPathController from '@/actions/App/Http/Controllers/Settings/UpdateDataPathController';
import UpdateWatcherController from '@/actions/App/Http/Controllers/Settings/UpdateWatcherController';
import RunIngestController from '@/actions/App/Http/Controllers/Settings/RunIngestController';
import RunSyncController from '@/actions/App/Http/Controllers/Settings/RunSyncController';
import UpdateAnonymousStatsController from '@/actions/App/Http/Controllers/Settings/UpdateAnonymousStatsController';
import { useAppearance } from '@/composables/useAppearance';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

const props = defineProps<{
    logPath: string;
    dataPath: string;
    watcherActive: boolean;
    anonymousStats: boolean;
    dateFormat: 'DMY' | 'MDY';
    logPathStatus: { valid: boolean; fileCount: number; message: string };
    dataPathStatus: { valid: boolean; fileCount: number; message: string };
    lastIngestAt: string | null;
    lastSyncAt: string | null;
}>();

const logPathInput = ref(props.logPath);
const dataPathInput = ref(props.dataPath);

const pathsValid = computed(() => props.logPathStatus.valid && props.dataPathStatus.valid);

const errors = computed(() => usePage().props.errors as Record<string, string>);

const { appearance, updateAppearance } = useAppearance();

const opts = { preserveScroll: true };

function saveLogPath() {
    router.patch(UpdateLogPathController.url(), { path: logPathInput.value }, opts);
}

function saveDataPath() {
    router.patch(UpdateDataPathController.url(), { path: dataPathInput.value }, opts);
}

function toggleWatcher() {
    router.patch(UpdateWatcherController.url(), { active: !props.watcherActive }, opts);
}

function runIngest() {
    router.post(RunIngestController.url(), {}, opts);
}

function runSync() {
    router.post(RunSyncController.url(), {}, opts);
}

function toggleAnonymousStats(checked: boolean | 'indeterminate') {
    router.patch(UpdateAnonymousStatsController.url(), { enabled: checked === true }, opts);
}
</script>

<template>
    <AppLayout title="Settings">
        <div class="max-w-2xl divide-y">
            <!-- File Paths -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">File Paths</p>
                    <p class="text-sm text-muted-foreground">
                        Where to look for MTGO log files and game data. Defaults are set automatically for standard MTGO installs.
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Label>Log File Directory</Label>
                    <p class="text-sm text-muted-foreground">Contains <code>mtgo.log</code> files</p>
                    <div class="flex gap-2">
                        <Input v-model="logPathInput" @keydown.enter="saveLogPath" />
                        <Button variant="outline" @click="saveLogPath">Save</Button>
                    </div>
                    <div v-if="logPath" class="flex items-center gap-2">
                        <div class="size-2 shrink-0 rounded-full" :class="logPathStatus.valid ? 'bg-primary' : 'bg-destructive'" />
                        <span class="text-sm" :class="logPathStatus.valid ? 'text-muted-foreground' : 'text-destructive'">
                            {{ logPathStatus.message }}
                        </span>
                    </div>
                </div>

                <Separator />

                <div class="flex flex-col gap-2">
                    <Label>Game Data Directory</Label>
                    <p class="text-sm text-muted-foreground">Contains <code>Match_GameLog_*</code> and deck XML files</p>
                    <div class="flex gap-2">
                        <Input v-model="dataPathInput" @keydown.enter="saveDataPath" />
                        <Button variant="outline" @click="saveDataPath">Save</Button>
                    </div>
                    <div v-if="dataPath" class="flex items-center gap-2">
                        <div class="size-2 shrink-0 rounded-full" :class="dataPathStatus.valid ? 'bg-primary' : 'bg-destructive'" />
                        <span class="text-sm" :class="dataPathStatus.valid ? 'text-muted-foreground' : 'text-destructive'">
                            {{ dataPathStatus.message }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Watcher & Ingestion -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Watcher &amp; Ingestion</p>
                    <p class="text-sm text-muted-foreground">Control the file system watcher and manually trigger operations.</p>
                </div>

                <div class="flex items-center justify-between">
                    <div>
                        <Label>File Watcher</Label>
                        <p class="text-sm text-muted-foreground">Monitors log files and triggers ingestion automatically.</p>
                        <p v-if="!pathsValid" class="text-sm text-destructive">File paths must be valid to enable the watcher.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <Badge :variant="watcherActive && pathsValid ? 'default' : 'secondary'">
                            {{ watcherActive && pathsValid ? 'Running' : 'Stopped' }}
                        </Badge>
                        <Button variant="outline" size="sm" :disabled="!pathsValid" @click="toggleWatcher">
                            {{ watcherActive && pathsValid ? 'Stop' : 'Start' }}
                        </Button>
                    </div>
                </div>

                <Separator />

                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">Ingest Logs</p>
                        <p class="text-sm text-muted-foreground">
                            {{ lastIngestAt ? `Last run ${dayjs(lastIngestAt).fromNow()}` : 'Never run' }}
                        </p>
                        <p v-if="errors.ingest" class="text-sm text-destructive">{{ errors.ingest }}</p>
                    </div>
                    <Button variant="outline" size="sm" :disabled="!pathsValid" @click="runIngest">Run now</Button>
                </div>

                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">Sync Decks</p>
                        <p class="text-sm text-muted-foreground">
                            {{ lastSyncAt ? `Last run ${dayjs(lastSyncAt).fromNow()}` : 'Never run' }}
                        </p>
                        <p v-if="errors.sync" class="text-sm text-destructive">{{ errors.sync }}</p>
                    </div>
                    <Button variant="outline" size="sm" :disabled="!pathsValid" @click="runSync">Run now</Button>
                </div>
            </div>

            <!-- Display -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Display</p>
                    <p class="text-sm text-muted-foreground">Appearance preferences.</p>
                </div>

                <div class="flex items-center justify-between">
                    <div>
                        <Label>Theme</Label>
                        <p class="text-sm text-muted-foreground">Light or dark.</p>
                    </div>
                    <Select :model-value="appearance" @update:model-value="updateAppearance">
                        <SelectTrigger class="w-36">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="light">Light</SelectItem>
                            <SelectItem value="dark">Dark</SelectItem>
                            <SelectItem value="system">System</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Separator />

                <div class="flex items-center justify-between">
                    <div>
                        <Label>Date Format</Label>
                        <p class="text-sm text-muted-foreground">Detected from your system locale.</p>
                    </div>
                    <span class="text-sm text-muted-foreground">
                        {{ dateFormat === 'DMY' ? 'DD/MM/YYYY' : 'MM/DD/YYYY' }}
                    </span>
                </div>
            </div>

            <!-- Data & Privacy -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Data &amp; Privacy</p>
                    <p class="text-sm text-muted-foreground">Control what data is collected from your use of the app.</p>
                </div>
                <div class="flex items-start gap-3">
                    <Checkbox id="usage-tracking" :defaultValue="anonymousStats" @update:modelValue="toggleAnonymousStats" />
                    <div class="flex flex-col gap-1">
                        <Label for="usage-tracking">Send anonymous usage statistics</Label>
                        <p class="text-sm text-muted-foreground">
                            Helps improve the app. No personal data, match results, usernames, or deck contents are ever sent.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
