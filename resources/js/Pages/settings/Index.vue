<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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

const theme = ref<'light' | 'dark' | 'system'>('dark');
const dateFormat = ref<'relative' | 'absolute'>('relative');
const statsPeriod = ref<'all' | '90d' | '30d'>('all');

const usageTracking = ref(false);

// Settings row layout helper — just used in template
</script>

<template>
    <AppLayout title="Settings">
        <div class="flex flex-col gap-6 p-4 lg:p-6 max-w-2xl">

            <!-- Identity -->
            <Card>
                <CardHeader>
                    <CardTitle>Identity</CardTitle>
                    <CardDescription>Your MTGO username, used to determine which player is you when parsing match logs.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="username">MTGO Username</Label>
                        <div class="flex gap-2">
                            <Input id="username" v-model="username" placeholder="Enter your MTGO username" class="max-w-xs" />
                            <Button variant="outline">Save</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- File Paths -->
            <Card>
                <CardHeader>
                    <CardTitle>File Paths</CardTitle>
                    <CardDescription>Where to look for MTGO log files and deck XML files. MTGO may store these in different locations depending on your installation.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-5">
                    <div class="flex flex-col gap-1.5">
                        <Label>Log File Directory</Label>
                        <p class="text-muted-foreground text-xs">MTGO match log files (<code class="font-mono">Match_GameLog_*.dat</code>)</p>
                        <div class="flex gap-2">
                            <Input v-model="logDir" class="font-mono text-xs" />
                            <Button variant="outline" size="sm">Browse…</Button>
                        </div>
                        <p v-if="!logDirValid" class="text-destructive text-xs">Path not found or no log files detected.</p>
                        <p v-else class="text-muted-foreground text-xs">✓ Path found</p>
                    </div>

                    <Separator />

                    <div class="flex flex-col gap-1.5">
                        <Label>Deck XML Directory</Label>
                        <p class="text-muted-foreground text-xs">MTGO deck XML files used for deck sync</p>
                        <div class="flex gap-2">
                            <Input v-model="deckDir" class="font-mono text-xs" />
                            <Button variant="outline" size="sm">Browse…</Button>
                        </div>
                        <p v-if="!deckDirValid" class="text-destructive text-xs">Path not found.</p>
                        <p v-else class="text-muted-foreground text-xs">✓ Path found</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Watcher & Ingestion -->
            <Card>
                <CardHeader>
                    <CardTitle>Watcher &amp; Ingestion</CardTitle>
                    <CardDescription>Control the file system watcher and manually trigger log or deck sync operations.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-5">
                    <!-- Watcher status -->
                    <div class="flex items-center justify-between">
                        <div class="flex flex-col gap-0.5">
                            <Label>File Watcher</Label>
                            <p class="text-muted-foreground text-xs">Monitors log files for changes and triggers ingestion automatically.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Badge :class="watcherActive ? 'bg-win text-win-foreground border-transparent' : 'border-transparent bg-muted text-muted-foreground'">
                                {{ watcherActive ? 'Running' : 'Stopped' }}
                            </Badge>
                            <Button
                                :variant="watcherActive ? 'outline' : 'default'"
                                size="sm"
                                @click="watcherActive = !watcherActive"
                            >
                                {{ watcherActive ? 'Stop' : 'Start' }}
                            </Button>
                        </div>
                    </div>

                    <Separator />

                    <!-- Manual triggers -->
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-medium">Ingest Logs</span>
                                <span class="text-muted-foreground text-xs">Last run {{ dayjs(lastIngestAt).fromNow() }}</span>
                            </div>
                            <Button variant="outline" size="sm">Run now</Button>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-medium">Sync Decks</span>
                                <span class="text-muted-foreground text-xs">Last run {{ dayjs(lastSyncAt).fromNow() }}</span>
                            </div>
                            <Button variant="outline" size="sm">Run now</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Display Preferences -->
            <Card>
                <CardHeader>
                    <CardTitle>Display Preferences</CardTitle>
                    <CardDescription>Customise how the app looks and displays data.</CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-5">
                    <div class="flex items-center justify-between">
                        <div class="flex flex-col gap-0.5">
                            <Label>Theme</Label>
                            <p class="text-muted-foreground text-xs">Light, dark, or follow your system setting.</p>
                        </div>
                        <div class="flex rounded-md border overflow-hidden">
                            <Button
                                v-for="opt in [{ value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark' }, { value: 'system', label: 'System' }]"
                                :key="opt.value"
                                size="sm"
                                :variant="theme === opt.value ? 'default' : 'ghost'"
                                class="rounded-none border-0"
                                @click="theme = opt.value as typeof theme"
                            >{{ opt.label }}</Button>
                        </div>
                    </div>

                    <Separator />

                    <div class="flex items-center justify-between">
                        <div class="flex flex-col gap-0.5">
                            <Label>Date Format</Label>
                            <p class="text-muted-foreground text-xs">How dates are shown throughout the app.</p>
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
                        <div class="flex flex-col gap-0.5">
                            <Label>Default Stats Period</Label>
                            <p class="text-muted-foreground text-xs">Time window used for win rate calculations on the dashboard and deck views.</p>
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
                </CardContent>
            </Card>

            <!-- Data & Privacy -->
            <Card>
                <CardHeader>
                    <CardTitle>Data &amp; Privacy</CardTitle>
                    <CardDescription>Control what data is collected from your use of the app.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="flex items-start gap-3">
                        <Checkbox id="usage-tracking" v-model:checked="usageTracking" class="mt-0.5" />
                        <div class="flex flex-col gap-0.5">
                            <Label for="usage-tracking" class="cursor-pointer">Send anonymous usage statistics</Label>
                            <p class="text-muted-foreground text-xs">
                                Helps improve the app. No personal data, match results, usernames, or deck contents are ever sent. Disabled by default.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

        </div>
    </AppLayout>
</template>
