<script setup lang="ts">
import BrowseFolderController from '@/actions/App/Http/Controllers/Settings/BrowseFolderController';
import RunSubmitMatchesController from '@/actions/App/Http/Controllers/Settings/RunSubmitMatchesController';
import UpdateAccountTrackingController from '@/actions/App/Http/Controllers/Settings/UpdateAccountTrackingController';
import UpdateDataPathController from '@/actions/App/Http/Controllers/Settings/UpdateDataPathController';
import UpdateHidePhantomController from '@/actions/App/Http/Controllers/Settings/UpdateHidePhantomController';
import UpdateLogPathController from '@/actions/App/Http/Controllers/Settings/UpdateLogPathController';
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import UpdateShareStatsController from '@/actions/App/Http/Controllers/Settings/UpdateShareStatsController';
import UpdateDebugModeController from '@/actions/App/Http/Controllers/Settings/UpdateDebugModeController';
import UpdateTimezoneController from '@/actions/App/Http/Controllers/Settings/UpdateTimezoneController';
import UpdateWatcherController from '@/actions/App/Http/Controllers/Settings/UpdateWatcherController';
import type { LeagueData } from '@/components/leagues/LeagueTracker.vue';
import LeagueTracker from '@/components/leagues/LeagueTracker.vue';
import type { OpponentData } from '@/components/leagues/OpponentScout.vue';
import OpponentScout from '@/components/leagues/OpponentScout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { ChevronsUpDown, Check } from 'lucide-vue-next';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    logPath: string;
    dataPath: string;
    watcherActive: boolean;
    shareStats: boolean;
    logPathStatus: { valid: boolean; fileCount: number; message: string };
    dataPathStatus: { valid: boolean; fileCount: number; message: string };
    hidePhantomLeagues: boolean;
    pendingMatches: Array<{ id: number; format: string; outcome: string | null; started_at: string }>;
    accounts: Array<{ id: number; username: string; tracked: boolean; active: boolean }>;
    timezone: string;
    detectedTimezone: string;
    leagueWindowEnabled: boolean;
    opponentWindowEnabled: boolean;
    deckWindowEnabled: boolean;
    debugMode: boolean;
    appVersion: string;
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

function toggleHidePhantom(val: boolean) {
    withProcessing('hidePhantom', 'patch', UpdateHidePhantomController.url(), { enabled: val });
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

function setOpponentWindowEnabled(val: boolean) {
    withProcessing('opponentWindow', 'post', UpdateOverlaySettingsController.url(), { opponent_window: val });
}

function setDeckWindowEnabled(val: boolean) {
    withProcessing('deckWindow', 'post', UpdateOverlaySettingsController.url(), { deck_window: val });
}

function toggleDebugMode(val: boolean) {
    withProcessing('debugMode', 'patch', UpdateDebugModeController.url(), { enabled: val });
}

function updateTimezone(val: string) {
    withProcessing('timezone', 'patch', UpdateTimezoneController.url(), { timezone: val });
}

const allTimezones = computed(() =>
    Intl.supportedValuesOf('timeZone').map((tz) => ({ value: tz, label: tz.replace(/_/g, ' ') })),
);

const timezoneOpen = ref(false);

const sampleLeague: LeagueData = {
    id: 0,
    name: 'Friendly League',
    format: 'Modern',
    phantom: false,
    wins: 3,
    losses: 1,
    totalMatches: 4,
    deckName: 'Mono Green Tron',
    hasActiveMatch: true,
    games: [
        { won: true, ended: true },
        { won: false, ended: true },
        { won: null, ended: false },
    ],
};

const sampleOpponent: OpponentData = {
    username: 'Opponent123',
    previousMatches: 2,
    wins: 1,
    losses: 1,
    lastArchetype: 'Azorius Control',
    lastArchetypeColors: 'W,U',
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

            <!-- Timezone -->
            <Card>
                <CardHeader>
                    <CardTitle>Timezone</CardTitle>
                    <CardDescription>
                        Set your timezone so match timestamps are accurate. Detected: {{ detectedTimezone }}.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Popover v-model:open="timezoneOpen">
                        <PopoverTrigger as-child>
                            <Button
                                variant="outline"
                                role="combobox"
                                :aria-expanded="timezoneOpen"
                                class="w-full justify-between"
                                :disabled="processing === 'timezone'"
                            >
                                {{ props.timezone.replace(/_/g, ' ') }}
                                <ChevronsUpDown class="ml-2 size-4 shrink-0 opacity-50" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-[--reka-popover-trigger-width] p-0">
                            <Command>
                                <CommandInput placeholder="Search timezone..." />
                                <CommandList>
                                    <CommandEmpty>No timezone found.</CommandEmpty>
                                    <CommandGroup>
                                        <CommandItem
                                            v-for="tz in allTimezones"
                                            :key="tz.value"
                                            :value="tz.label"
                                            @select="() => { updateTimezone(tz.value); timezoneOpen = false; }"
                                        >
                                            <Check :class="cn('mr-2 size-4', props.timezone === tz.value ? 'opacity-100' : 'opacity-0')" />
                                            {{ tz.label }}
                                        </CommandItem>
                                    </CommandGroup>
                                </CommandList>
                            </Command>
                        </PopoverContent>
                    </Popover>
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

                    <Separator />

                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Opponent scouting window</Label>
                            <p class="text-sm text-muted-foreground">
                                Show opponent history and last known archetype in a separate window during matches.
                            </p>
                        </div>
                        <Switch
                            :modelValue="props.opponentWindowEnabled"
                            @update:modelValue="setOpponentWindowEnabled"
                            :disabled="processing === 'opponentWindow'"
                        />
                    </div>

                    <div class="mx-auto w-64 overflow-hidden rounded-md border border-border">
                        <OpponentScout :opponent="sampleOpponent" />
                    </div>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Deck list window</Label>
                            <p class="text-sm text-muted-foreground">
                                Show your most recently used deck in a separate window.
                            </p>
                        </div>
                        <Switch
                            :modelValue="props.deckWindowEnabled"
                            @update:modelValue="setDeckWindowEnabled"
                            :disabled="processing === 'deckWindow'"
                        />
                    </div>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Hide phantom leagues</Label>
                            <p class="text-sm text-muted-foreground">Exclude phantom league runs from the Leagues page and dashboard stats.</p>
                        </div>
                        <Switch
                            :modelValue="props.hidePhantomLeagues"
                            @update:modelValue="toggleHidePhantom"
                            :disabled="processing === 'hidePhantom'"
                        />
                    </div>
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
                    <template v-if="props.debugMode">
                        <Separator class="my-4" />
                        <div class="flex items-center justify-between">
                            <div>
                                <Label>Simulate update</Label>
                                <p class="text-sm text-muted-foreground">Triggers a fake update banner for testing.</p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                @click="router.post(SimulateUpdateController.url(), {}, { preserveScroll: true })"
                            >
                                Simulate
                            </Button>
                        </div>
                    </template>
                </CardContent>
            </Card>

            <p class="text-xs text-muted-foreground">mymtgo v{{ appVersion }}</p>
        </div>
    </div>
</template>
