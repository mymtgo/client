<script setup lang="ts">
import { ref, watch } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// FAKE DATA — replace with props from backend
const username = ref('blisterguy');

const logDir = ref('C:\\Users\\Alec\\AppData\\Local\\Apps\\2.0\\mtgo\\Logs');
const logDirValid = ref(true);

const deckDir = ref('C:\\Users\\Alec\\AppData\\Local\\Apps\\2.0\\mtgo\\Decks');
const deckDirValid = ref(true);

const watcherActive = ref(true);
const lastIngestAt = ref('2026-02-18T09:41:00Z');
const lastSyncAt = ref('2026-02-18T09:40:00Z');

const theme = ref(document.documentElement.classList.contains('dark') ? 'dark' : 'light');
watch(theme, (val) => {
    document.documentElement.classList.toggle('dark', val === 'dark');
});

const dateFormat = ref('relative');
const statsPeriod = ref('all');

const usageTracking = ref(false);
</script>

<template>
    <AppLayout title="Settings">
        <div class="max-w-2xl divide-y">

            <!-- Identity -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Identity</p>
                    <p class="text-muted-foreground text-sm">Your MTGO username, used to determine which player is you when parsing match logs.</p>
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="username">MTGO Username</Label>
                    <div class="flex gap-2">
                        <Input id="username" v-model="username" placeholder="Enter your MTGO username" />
                        <Button variant="outline">Save</Button>
                    </div>
                </div>
            </div>

            <!-- File Paths -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">File Paths</p>
                    <p class="text-muted-foreground text-sm">Where to look for MTGO log files and deck XML files.</p>
                </div>
                <div class="flex flex-col gap-2">
                    <Label>Log File Directory</Label>
                    <p class="text-muted-foreground text-sm">Match log files (<code>Match_GameLog_*.dat</code>)</p>
                    <div class="flex gap-2">
                        <Input v-model="logDir" />
                        <Button variant="outline">Browse…</Button>
                    </div>
                    <p v-if="!logDirValid" class="text-destructive text-sm">Path not found or no log files detected.</p>
                    <p v-else class="text-muted-foreground text-sm">Path found</p>
                </div>
                <Separator />
                <div class="flex flex-col gap-2">
                    <Label>Deck XML Directory</Label>
                    <p class="text-muted-foreground text-sm">Deck XML files used for deck sync</p>
                    <div class="flex gap-2">
                        <Input v-model="deckDir" />
                        <Button variant="outline">Browse…</Button>
                    </div>
                    <p v-if="!deckDirValid" class="text-destructive text-sm">Path not found.</p>
                    <p v-else class="text-muted-foreground text-sm">Path found</p>
                </div>
            </div>

            <!-- Watcher & Ingestion -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Watcher &amp; Ingestion</p>
                    <p class="text-muted-foreground text-sm">Control the file system watcher and manually trigger log or deck sync operations.</p>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <Label>File Watcher</Label>
                        <p class="text-muted-foreground text-sm">Monitors log files and triggers ingestion automatically.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <Badge :variant="watcherActive ? 'default' : 'secondary'">
                            {{ watcherActive ? 'Running' : 'Stopped' }}
                        </Badge>
                        <Button variant="outline" size="sm" @click="watcherActive = !watcherActive">
                            {{ watcherActive ? 'Stop' : 'Start' }}
                        </Button>
                    </div>
                </div>
                <Separator />
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">Ingest Logs</p>
                        <p class="text-muted-foreground text-sm">Last run {{ dayjs(lastIngestAt).fromNow() }}</p>
                    </div>
                    <Button variant="outline" size="sm">Run now</Button>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">Sync Decks</p>
                        <p class="text-muted-foreground text-sm">Last run {{ dayjs(lastSyncAt).fromNow() }}</p>
                    </div>
                    <Button variant="outline" size="sm">Run now</Button>
                </div>
            </div>

            <!-- Display Preferences -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Display Preferences</p>
                    <p class="text-muted-foreground text-sm">Customise how the app looks and displays data.</p>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <Label>Theme</Label>
                        <p class="text-muted-foreground text-sm">Light or dark.</p>
                    </div>
                    <Select v-model="theme">
                        <SelectTrigger class="w-36">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="light">Light</SelectItem>
                            <SelectItem value="dark">Dark</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Separator />
                <div class="flex items-center justify-between">
                    <div>
                        <Label>Date Format</Label>
                        <p class="text-muted-foreground text-sm">How dates are shown throughout the app.</p>
                    </div>
                    <Select v-model="dateFormat">
                        <SelectTrigger class="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="relative">Relative (3 days ago)</SelectItem>
                            <SelectItem value="absolute">Absolute (12 Feb 2026)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Separator />
                <div class="flex items-center justify-between">
                    <div>
                        <Label>Default Stats Period</Label>
                        <p class="text-muted-foreground text-sm">Default time window for win rate calculations.</p>
                    </div>
                    <Select v-model="statsPeriod">
                        <SelectTrigger class="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All time</SelectItem>
                            <SelectItem value="90d">Last 90 days</SelectItem>
                            <SelectItem value="30d">Last 30 days</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Data & Privacy -->
            <div class="flex flex-col gap-4 p-4 lg:p-6">
                <div>
                    <p class="font-semibold">Data &amp; Privacy</p>
                    <p class="text-muted-foreground text-sm">Control what data is collected from your use of the app.</p>
                </div>
                <div class="flex items-start gap-3">
                    <Checkbox id="usage-tracking" v-model:checked="usageTracking" />
                    <div class="flex flex-col gap-1">
                        <Label for="usage-tracking">Send anonymous usage statistics</Label>
                        <p class="text-muted-foreground text-sm">
                            Helps improve the app. No personal data, match results, usernames, or deck contents are ever sent. Disabled by default.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
