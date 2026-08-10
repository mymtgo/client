<script setup lang="ts">
import BrowseFolderController from '@/actions/App/Http/Controllers/Settings/BrowseFolderController';
import DeleteOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/DeleteOverlayBackgroundController';
import RunSubmitMatchesController from '@/actions/App/Http/Controllers/Settings/RunSubmitMatchesController';
import UploadOverlayBackgroundController from '@/actions/App/Http/Controllers/Settings/UploadOverlayBackgroundController';
import UpdateAccountTrackingController from '@/actions/App/Http/Controllers/Settings/UpdateAccountTrackingController';
import UpdateDataPathController from '@/actions/App/Http/Controllers/Settings/UpdateDataPathController';
import UpdateLogPathController from '@/actions/App/Http/Controllers/Settings/UpdateLogPathController';
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import UpdateShareStatsController from '@/actions/App/Http/Controllers/Settings/UpdateShareStatsController';
import UpdateDebugModeController from '@/actions/App/Http/Controllers/Settings/UpdateDebugModeController';
import UpdateLocalImagesController from '@/actions/App/Http/Controllers/Settings/UpdateLocalImagesController';
import UpdateWatcherController from '@/actions/App/Http/Controllers/Settings/UpdateWatcherController';
import UpdateAutostartController from '@/actions/App/Http/Controllers/Settings/UpdateAutostartController';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import ApiStatusCard from '@/components/settings/ApiStatusCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    logPath: string;
    dataPath: string;
    watcherActive: boolean;
    shareStats: boolean;
    logPathStatus: { valid: boolean; fileCount: number; message: string };
    dataPathStatus: { valid: boolean; fileCount: number; message: string };
    pendingMatches: Array<{ id: number; format: string; outcome: string | null; started_at: string }>;
    accounts: Array<{ id: number; username: string; tracked: boolean; active: boolean }>;
leagueWindowEnabled: boolean;
    gameOverlayEnabled: boolean;
    overlayShowOpponent: boolean;
    overlayShowDrawOdds: boolean;
    overlayShowSideboard: boolean;
    overlayBackgroundUrl: string | null;
    debugMode: boolean;
    localImages: boolean;
    localImagesSize: string;
    appVersion: string;
    autostartEnabled: boolean;
    trayAvailable: boolean;
}>();

const logPathInput = ref(props.logPath);
const dataPathInput = ref(props.dataPath);

const pathsValid = computed(() => props.logPathStatus.valid && props.dataPathStatus.valid);

const errors = computed(() => usePage().props.errors as Record<string, string>);

const processing = ref<string | null>(null);

function withProcessing(key: string, method: 'patch' | 'post', url: string, data: Record<string, unknown> = {}) {
    processing.value = key;
    router[method](url, data, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = null;
        },
    });
}

function saveLogPath() {
    withProcessing('logPath', 'patch', UpdateLogPathController.url(), { path: logPathInput.value });
}

function saveDataPath() {
    withProcessing('dataPath', 'patch', UpdateDataPathController.url(), { path: dataPathInput.value });
}

async function browseFolder(key: 'logPath' | 'dataPath') {
    const currentPath = key === 'logPath' ? logPathInput.value : dataPathInput.value;
    const updateUrl = key === 'logPath' ? UpdateLogPathController.url() : UpdateDataPathController.url();
    const inputRef = key === 'logPath' ? logPathInput : dataPathInput;

    processing.value = key;

    try {
        const response = await fetch(BrowseFolderController.url({ query: { default: currentPath } }));
        const { path } = await response.json();

        if (path) {
            inputRef.value = path;
            withProcessing(key, 'patch', updateUrl, { path });
        } else {
            processing.value = null;
        }
    } catch {
        processing.value = null;
    }
}

function toggleWatcher() {
    withProcessing('watcher', 'patch', UpdateWatcherController.url(), { active: !props.watcherActive });
}

function toggleShareStats(val: boolean) {
    withProcessing('shareStats', 'patch', UpdateShareStatsController.url(), { enabled: val });
}

function toggleAutostart(val: boolean) {
    withProcessing('autostart', 'patch', UpdateAutostartController.url(), { enabled: val });
}

function submitPendingMatches() {
    withProcessing('submitMatches', 'post', RunSubmitMatchesController.url());
}

function toggleAccountTracking(username: string, tracked: boolean) {
    withProcessing(`account-${username}`, 'patch', UpdateAccountTrackingController.url(), { username, tracked });
}

function setLeagueWindowEnabled(val: boolean) {
    withProcessing('leagueWindow', 'post', UpdateOverlaySettingsController.url(), { league_window: val });
}

function setGameOverlayEnabled(val: boolean) {
    withProcessing('gameOverlay', 'post', UpdateOverlaySettingsController.url(), { game_overlay: val });
}

function setOverlaySection(key: 'opponent' | 'draw_odds' | 'sideboard', val: boolean) {
    withProcessing(`overlay-${key}`, 'post', UpdateOverlaySettingsController.url(), {
        [`overlay_show_${key}`]: val,
    });
}

function toggleDebugMode(val: boolean) {
    withProcessing('debugMode', 'patch', UpdateDebugModeController.url(), { enabled: val });
}

function toggleLocalImages(val: boolean) {
    withProcessing('localImages', 'patch', UpdateLocalImagesController.url(), { enabled: val });
}

const overlayBackgroundInput = ref<HTMLInputElement | null>(null);

function pickOverlayBackground() {
    overlayBackgroundInput.value?.click();
}

function uploadOverlayBackground(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('image', file);

    processing.value = 'overlayBackground';
    router.post(UploadOverlayBackgroundController.url(), formData, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            processing.value = null;
            if (overlayBackgroundInput.value) {
                overlayBackgroundInput.value.value = '';
            }
        },
    });
}

function removeOverlayBackground() {
    processing.value = 'overlayBackground';
    router.delete(DeleteOverlayBackgroundController.url(), {
        preserveScroll: true,
        onFinish: () => {
            processing.value = null;
        },
    });
}

const sampleLeague: LeagueData = {
    id: 0,
    name: 'Friendly League',
    format: 'Modern',
    wins: 3,
    losses: 1,
    totalMatches: 4,
    deckId: null,
    deckName: 'Mono Green Tron',
    backgroundUrl: props.overlayBackgroundUrl,
    hasActiveMatch: true,
    games: [
        { won: true, ended: true },
        { won: false, ended: true },
        { won: null, ended: false },
    ],
};
</script>

<template>
    <div class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-3xl space-y-4 p-6">
            <!-- Accounts -->
            <Card v-if="accounts.length">
                <CardHeader>
                    <CardTitle>Accounts</CardTitle>
                    <CardDescription>Toggle tracking to control which accounts record match data.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div v-for="account in accounts" :key="account.id" class="flex items-center justify-between">
                        <div>
                            <Label>
                                {{ account.username }}
                                <Badge v-if="account.active" variant="default" class="ml-1 text-xs">Active</Badge>
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                {{ account.tracked ? 'Recording matches' : 'Not recording matches' }}
                            </p>
                        </div>
                        <Switch
                            :modelValue="account.tracked"
                            @update:modelValue="(val: boolean) => toggleAccountTracking(account.username, val)"
                            :disabled="processing === `account-${account.username}`"
                        />
                    </div>
                </CardContent>
            </Card>

            <!-- Gameplay Settings -->
            <Card>
                <CardHeader>
                    <CardTitle>Gameplay Settings</CardTitle>
                    <CardDescription>League overlay and display preferences.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <Label>League progress window</Label>
                            <p class="text-sm text-muted-foreground">Show a small always-on-top window with your current league run.</p>
                        </div>
                        <Switch :modelValue="props.leagueWindowEnabled" @update:modelValue="setLeagueWindowEnabled" />
                    </div>

                    <div class="mx-auto w-64 overflow-hidden rounded-md border border-border">
                        <LeagueTracker :league="sampleLeague" />
                    </div>

                    <div class="flex flex-col gap-2 rounded-md border border-border bg-muted/30 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <Label>Custom overlay background</Label>
                                <p class="text-sm text-muted-foreground">
                                    Upload an image (e.g. channel art, brand logo). Falls back to your deck cover when empty. Recommended: ~1200×400, max 5MB.
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <Button type="button" variant="outline" size="sm" :disabled="processing === 'overlayBackground'" @click="pickOverlayBackground">
                                    {{ props.overlayBackgroundUrl ? 'Replace' : 'Upload' }}
                                </Button>
                                <Button
                                    v-if="props.overlayBackgroundUrl"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing === 'overlayBackground'"
                                    @click="removeOverlayBackground"
                                >
                                    Remove
                                </Button>
                            </div>
                        </div>
                        <input
                            ref="overlayBackgroundInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            class="hidden"
                            @change="uploadOverlayBackground"
                        />
                        <p v-if="errors['image']" class="text-sm text-destructive">{{ errors['image'] }}</p>
                    </div>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Game overlay</Label>
                            <p class="text-sm text-muted-foreground">
                                Show a floating panel during matches with your opponent's archetype, live draw odds,
                                and your sideboard guide.
                            </p>
                        </div>
                        <Switch
                            :modelValue="props.gameOverlayEnabled"
                            @update:modelValue="setGameOverlayEnabled"
                            :disabled="processing === 'gameOverlay'"
                        />
                    </div>

                    <div class="flex flex-col gap-3 pl-6">
                        <div class="flex items-center justify-between">
                            <Label :class="props.gameOverlayEnabled ? '' : 'text-muted-foreground'">
                                Show opponent scout
                            </Label>
                            <Switch
                                :modelValue="props.overlayShowOpponent"
                                @update:modelValue="(val: boolean) => setOverlaySection('opponent', val)"
                                :disabled="!props.gameOverlayEnabled || processing === 'overlay-opponent'"
                            />
                        </div>

                        <div class="flex items-center justify-between">
                            <Label :class="props.gameOverlayEnabled ? '' : 'text-muted-foreground'">
                                Show draw odds
                            </Label>
                            <Switch
                                :modelValue="props.overlayShowDrawOdds"
                                @update:modelValue="(val: boolean) => setOverlaySection('draw_odds', val)"
                                :disabled="!props.gameOverlayEnabled || processing === 'overlay-draw_odds'"
                            />
                        </div>

                        <div class="flex items-center justify-between">
                            <Label :class="props.gameOverlayEnabled ? '' : 'text-muted-foreground'">
                                Show sideboard guide
                            </Label>
                            <Switch
                                :modelValue="props.overlayShowSideboard"
                                @update:modelValue="(val: boolean) => setOverlaySection('sideboard', val)"
                                :disabled="!props.gameOverlayEnabled || processing === 'overlay-sideboard'"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Storage -->
            <Card>
                <CardHeader>
                    <CardTitle>Storage</CardTitle>
                    <CardDescription>Manage how card imagery and data is stored on your machine.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Download card images locally</Label>
                            <p class="text-sm text-muted-foreground">
                                Save card imagery to your machine for speed and offline use. This will increase disk usage.
                            </p>
                        </div>
                        <Switch
                            :modelValue="props.localImages"
                            @update:modelValue="toggleLocalImages"
                            :disabled="processing === 'localImages'"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">Current usage: {{ props.localImagesSize }}</p>
                </CardContent>
            </Card>

            <!-- File Paths -->
            <Card>
                <CardHeader>
                    <CardTitle>File Paths</CardTitle>
                    <CardDescription
                        >Where to look for MTGO log files and game data. Defaults are set automatically for standard installs.</CardDescription
                    >
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col gap-2">
                        <Label>Log File Directory</Label>
                        <p class="text-sm text-muted-foreground">Contains <code>mtgo.log</code> files</p>
                        <div class="flex gap-2">
                            <Input v-model="logPathInput" @keydown.enter="saveLogPath" :disabled="processing === 'logPath'" />
                            <Button variant="outline" :disabled="processing === 'logPath'" @click="browseFolder('logPath')">Browse</Button>
                            <Button variant="outline" :disabled="processing === 'logPath' || logPathInput === logPath" @click="saveLogPath">
                                <Spinner v-if="processing === 'logPath'" />
                                {{ processing === 'logPath' ? 'Saving...' : 'Save' }}
                            </Button>
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
                            <Input v-model="dataPathInput" @keydown.enter="saveDataPath" :disabled="processing === 'dataPath'" />
                            <Button variant="outline" :disabled="processing === 'dataPath'" @click="browseFolder('dataPath')">Browse</Button>
                            <Button variant="outline" :disabled="processing === 'dataPath' || dataPathInput === dataPath" @click="saveDataPath">
                                <Spinner v-if="processing === 'dataPath'" />
                                {{ processing === 'dataPath' ? 'Saving...' : 'Save' }}
                            </Button>
                        </div>
                        <div v-if="dataPath" class="flex items-center gap-2">
                            <div class="size-2 shrink-0 rounded-full" :class="dataPathStatus.valid ? 'bg-primary' : 'bg-destructive'" />
                            <span class="text-sm" :class="dataPathStatus.valid ? 'text-muted-foreground' : 'text-destructive'">
                                {{ dataPathStatus.message }}
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Watcher -->
            <Card>
                <CardHeader>
                    <CardTitle>Watcher</CardTitle>
                    <CardDescription>Control the file system watcher that monitors log files and triggers ingestion.</CardDescription>
                </CardHeader>
                <CardContent>
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
                            <Button variant="outline" size="sm" :disabled="!pathsValid || processing === 'watcher'" @click="toggleWatcher">
                                <Spinner v-if="processing === 'watcher'" />
                                {{ processing === 'watcher' ? 'Processing...' : watcherActive && pathsValid ? 'Stop' : 'Start' }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Background -->
            <Card>
                <CardHeader>
                    <CardTitle>Background</CardTitle>
                    <CardDescription>Keep mymtgo running in the system tray and launch automatically when you sign in.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Launch at login</Label>
                            <p class="text-sm text-muted-foreground">Start mymtgo automatically when your computer signs in.</p>
                        </div>
                        <Switch :modelValue="props.autostartEnabled" @update:modelValue="toggleAutostart" :disabled="processing === 'autostart'" />
                    </div>
                    <Separator />
                    <div>
                        <Label>Closing the window</Label>
                        <p v-if="props.trayAvailable" class="text-sm text-muted-foreground">
                            Closing the main window leaves mymtgo running in the system tray. Match ingestion continues in the background — open it again from the tray icon.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            Your operating system doesn't support a system tray for this app. Closing the main window will quit mymtgo and stop match ingestion.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- API Status -->
            <ApiStatusCard />

            <!-- Data & Privacy -->
            <Card>
                <CardHeader>
                    <CardTitle>Data &amp; Privacy</CardTitle>
                    <CardDescription>Control what data is collected from your use of the app.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Share match stats</Label>
                            <p class="text-sm text-muted-foreground">
                                Contribute match data to the community. Your deck, archetype, result, and format are submitted after each match.
                            </p>
                        </div>
                        <Switch :modelValue="props.shareStats" @update:modelValue="toggleShareStats" :disabled="processing === 'shareStats'" />
                    </div>
                    <div v-if="props.shareStats" class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">{{ pendingMatches.length }} matches pending.</p>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="processing === 'submitMatches' || !pendingMatches.length"
                            @click="submitPendingMatches"
                        >
                            <Spinner v-if="processing === 'submitMatches'" />
                            {{
                                processing === 'submitMatches'
                                    ? 'Submitting...'
                                    : `Submit ${pendingMatches.length} match${pendingMatches.length === 1 ? '' : 'es'}`
                            }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Debug Mode -->
            <Card>
                <CardHeader>
                    <CardTitle>Debug Mode</CardTitle>
                    <CardDescription>This will add more menu items and editing capabilities, use at your own risk.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Enable debug mode</Label>
                            <p class="text-sm text-muted-foreground">Access raw database tables for matches, games, and log events.</p>
                        </div>
                        <Switch
                            :modelValue="props.debugMode"
                            @update:modelValue="toggleDebugMode"
                            :disabled="processing === 'debugMode'"
                        />
                    </div>
                </CardContent>
            </Card>

            <p class="text-xs text-muted-foreground">mymtgo v{{ appVersion }}</p>
        </div>
    </div>
</template>
