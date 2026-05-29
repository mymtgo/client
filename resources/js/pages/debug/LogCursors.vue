<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { RefreshCw, RotateCcw } from 'lucide-vue-next';

const { add: toast } = useToast();

usePoll(10000);

type LogCursor = {
    id: number;
    stuck_ticks: number;
    last_advance_at?: string | null;
    updated_at?: string | null;
    log_instance?: {
        id: number;
        file_path: string;
        local_username?: string | null;
        head_hash?: string | null;
        sealed_at?: string | null;
        seal_reason?: string | null;
    } | null;
    [key: string]: unknown;
};

const props = defineProps<{
    logCursors: {
        data: LogCursor[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
}>();

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'local_username', label: 'Username' },
    { key: 'file_path', label: 'File Path' },
    { key: 'byte_offset', label: 'Byte Offset' },
    { key: 'last_observed_size', label: 'Last Observed Size' },
    { key: 'stuck_ticks', label: 'Stuck Ticks' },
    { key: 'last_advance_at', label: 'Last Advance' },
    { key: 'head_hash', label: 'Head Hash' },
    { key: 'seal_status', label: 'Seal Status' },
    { key: 'updated_at', label: 'Updated At' },
    { key: 'actions', label: '' },
];

const [refreshing, startRefreshing] = useSpinGuard();

function refresh() {
    const stop = startRefreshing();
    router.reload({ preserveScroll: true, onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Log cursors refreshed.', duration: 2000 }), onFinish: stop });
}

function forceReset(id: number) {
    router.delete(`/debug/log-cursors/${id}`, {
        preserveScroll: true,
        onSuccess: () => toast({
            type: 'success',
            title: 'Cursor reset',
            message: `Cursor #${id} deleted — pipeline will re-ingest from byte 0 on next tick.`,
            duration: 3000,
        }),
    });
}
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <div class="mb-4 flex items-center justify-end gap-2">
                <Button size="sm" variant="outline" class="h-8" @click="refresh">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </Button>
            </div>
            <div class="overflow-x-auto rounded-lg border border-border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead v-for="col in columns" :key="col.key" class="whitespace-nowrap px-2 text-xs">
                                {{ col.label }}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <tr v-for="cursor in logCursors.data" :key="cursor.id">
                            <TableCell v-for="col in columns" :key="col.key" class="whitespace-nowrap px-2 py-1.5 text-xs">
                                <template v-if="col.key === 'file_path'">
                                    <span class="block max-w-[300px] truncate" :title="cursor.log_instance?.file_path ?? ''">
                                        {{ cursor.log_instance?.file_path ?? '—' }}
                                    </span>
                                </template>
                                <template v-else-if="col.key === 'local_username'">
                                    {{ cursor.log_instance?.local_username ?? '—' }}
                                </template>
                                <template v-else-if="col.key === 'head_hash'">
                                    <span class="font-mono text-muted-foreground">
                                        {{ cursor.log_instance?.head_hash ? cursor.log_instance.head_hash.substring(0, 8) : '—' }}
                                    </span>
                                </template>
                                <template v-else-if="col.key === 'seal_status'">
                                    <span v-if="cursor.log_instance?.sealed_at" class="text-muted-foreground" :title="cursor.log_instance.sealed_at">
                                        {{ cursor.log_instance.seal_reason ?? 'sealed' }}
                                    </span>
                                    <span v-else class="text-emerald-500">active</span>
                                </template>
                                <template v-else-if="col.key === 'actions'">
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        class="h-7 px-2 text-xs"
                                        title="Delete this cursor so the pipeline re-ingests its log file from the start."
                                        @click="forceReset(cursor.id)"
                                    >
                                        <RotateCcw class="mr-1 h-3 w-3" />
                                        Force Reset
                                    </Button>
                                </template>
                                <template v-else>
                                    {{ cursor[col.key] ?? '—' }}
                                </template>
                            </TableCell>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="logCursors.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in logCursors.links" :key="link.label">
                    <Button
                        v-if="link.url"
                        variant="outline"
                        size="sm"
                        class="h-7 text-xs"
                        :class="{ 'bg-primary/10 text-primary': link.active }"
                        @click="router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                    />
                    <span v-else class="px-2 text-xs text-muted-foreground" v-html="link.label" />
                </template>
            </div>
        </div>
    </div>
</template>
