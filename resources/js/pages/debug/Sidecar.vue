<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePoll } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';

usePoll(10000);

const props = defineProps<{
    status: null | {
        state: string;
        mtgo_version: string | null;
        sdk_version: string | null;
        sidecar_version: string | null;
        current_file: string | null;
        heartbeat: string | null;
        last_event_seq: number | null;
        last_error: string | null;
        stale: boolean;
    };
    settings: { enabled: boolean; available: boolean; tripped: boolean; authority: Record<string, boolean> };
    summary: Record<string, { agree: number; disagree: number; sidecar_incomplete: number; sidecar_degraded: number }>;
    recentEvents: Array<{
        id: number;
        seq: number;
        session: string;
        ts: string;
        type: string;
        game_mtgo_id: string | null;
        match_mtgo_id: string | null;
        verified: boolean;
        data: Record<string, unknown>;
    }>;
    diffs: Array<{
        id: number;
        field: string;
        chosen_source: string;
        log_value: unknown;
        sidecar_value: unknown;
        updated_at: string;
        match?: { mtgo_id: string } | null;
        game?: { mtgo_id: string } | null;
    }>;
}>();

const fieldLabels: Record<string, string> = {
    username: 'Username',
    on_play: 'On play',
    game_boundaries: 'Game boundaries',
    game_result: 'Game result',
    match_result: 'Match result',
};

function agreementPercent(row: { agree: number; disagree: number }): string {
    const total = row.agree + row.disagree;

    return total === 0 ? 'n/a' : `${Math.round((row.agree / total) * 100)}%`;
}

function truncate(value: string, length: number): string {
    return value.length > length ? `${value.slice(0, length)}…` : value;
}

function stringifyData(data: Record<string, unknown>): string {
    return truncate(JSON.stringify(data), 120);
}

function stringifyValue(value: unknown): string {
    return truncate(JSON.stringify(value), 120);
}
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <div class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Status</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="!status" class="text-sm text-muted-foreground">No status file found.</div>
                        <div v-else class="flex flex-col gap-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge>{{ status.state }}</Badge>
                                <Badge v-if="status.stale" variant="destructive">stale</Badge>
                                <Badge :variant="settings.enabled ? 'default' : 'outline'">{{ settings.enabled ? 'enabled' : 'disabled' }}</Badge>
                                <Badge :variant="settings.available ? 'default' : 'outline'">{{ settings.available ? 'available' : 'unavailable' }}</Badge>
                                <Badge v-if="settings.tripped" variant="destructive">tripped</Badge>
                            </div>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs text-muted-foreground sm:grid-cols-3">
                                <div>MTGO version: <span class="text-foreground">{{ status.mtgo_version ?? '-' }}</span></div>
                                <div>SDK version: <span class="text-foreground">{{ status.sdk_version ?? '-' }}</span></div>
                                <div>Sidecar version: <span class="text-foreground">{{ status.sidecar_version ?? '-' }}</span></div>
                                <div>Current file: <span class="text-foreground">{{ status.current_file ?? '-' }}</span></div>
                                <div>Heartbeat: <span class="text-foreground">{{ status.heartbeat ?? '-' }}</span></div>
                                <div>Last event seq: <span class="text-foreground">{{ status.last_event_seq ?? '-' }}</span></div>
                            </div>
                            <div v-if="status.last_error" class="text-xs text-red-400">{{ status.last_error }}</div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Authority</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="text-xs">Field</TableHead>
                                    <TableHead class="text-xs">Authority</TableHead>
                                    <TableHead class="text-xs">Agreement</TableHead>
                                    <TableHead class="text-xs">Agree</TableHead>
                                    <TableHead class="text-xs">Disagree</TableHead>
                                    <TableHead class="text-xs">Incomplete</TableHead>
                                    <TableHead class="text-xs">Degraded</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="field in Object.keys(summary)" :key="field">
                                    <TableCell class="text-xs">{{ fieldLabels[field] ?? field }}</TableCell>
                                    <TableCell class="text-xs">
                                        <Badge :variant="settings.authority[field] ? 'default' : 'outline'">
                                            {{ settings.authority[field] ? 'sidecar' : 'log' }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="text-xs">{{ agreementPercent(summary[field]) }}</TableCell>
                                    <TableCell class="text-xs">{{ summary[field].agree }}</TableCell>
                                    <TableCell class="text-xs">{{ summary[field].disagree }}</TableCell>
                                    <TableCell class="text-xs">{{ summary[field].sidecar_incomplete }}</TableCell>
                                    <TableCell class="text-xs">{{ summary[field].sidecar_degraded }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent events</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="recentEvents.length === 0" class="text-sm text-muted-foreground">No sidecar events recorded.</div>
                        <div v-else class="overflow-x-auto rounded-lg border border-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="text-xs">Seq</TableHead>
                                        <TableHead class="text-xs">Timestamp</TableHead>
                                        <TableHead class="text-xs">Type</TableHead>
                                        <TableHead class="text-xs">Game</TableHead>
                                        <TableHead class="text-xs">Verified</TableHead>
                                        <TableHead class="text-xs">Data</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="event in recentEvents" :key="event.id">
                                        <TableCell class="whitespace-nowrap text-xs">{{ event.seq }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ event.ts }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ event.type }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ event.game_mtgo_id ?? '-' }}</TableCell>
                                        <TableCell class="text-xs">
                                            <Check v-if="event.verified" class="h-3.5 w-3.5 text-emerald-500" />
                                            <X v-else class="h-3.5 w-3.5 text-red-400" />
                                        </TableCell>
                                        <TableCell class="max-w-[320px] truncate font-mono text-xs text-muted-foreground" :title="JSON.stringify(event.data)">
                                            {{ stringifyData(event.data) }}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Disagreements</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div v-if="diffs.length === 0" class="text-sm text-muted-foreground">No field diffs recorded.</div>
                        <div v-else class="overflow-x-auto rounded-lg border border-border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="text-xs">Match</TableHead>
                                        <TableHead class="text-xs">Game</TableHead>
                                        <TableHead class="text-xs">Field</TableHead>
                                        <TableHead class="text-xs">Chosen</TableHead>
                                        <TableHead class="text-xs">Log value</TableHead>
                                        <TableHead class="text-xs">Sidecar value</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="diff in diffs" :key="diff.id">
                                        <TableCell class="whitespace-nowrap text-xs">{{ diff.match?.mtgo_id ?? '-' }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ diff.game?.mtgo_id ?? '-' }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ fieldLabels[diff.field] ?? diff.field }}</TableCell>
                                        <TableCell class="whitespace-nowrap text-xs">{{ diff.chosen_source }}</TableCell>
                                        <TableCell class="max-w-[240px] truncate font-mono text-xs text-muted-foreground" :title="JSON.stringify(diff.log_value)">
                                            {{ stringifyValue(diff.log_value) }}
                                        </TableCell>
                                        <TableCell class="max-w-[240px] truncate font-mono text-xs text-muted-foreground" :title="JSON.stringify(diff.sidecar_value)">
                                            {{ stringifyValue(diff.sidecar_value) }}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
