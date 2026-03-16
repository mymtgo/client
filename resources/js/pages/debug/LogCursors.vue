<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';

const { add: toast } = useToast();

usePoll(1000);

const props = defineProps<{
    logCursors: {
        data: Array<Record<string, unknown>>;
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
    { key: 'file_size', label: 'File Size' },
    { key: 'file_mtime', label: 'File Mtime' },
    { key: 'head_hash', label: 'Head Hash' },
    { key: 'updated_at', label: 'Updated At' },
];

const [refreshing, startRefreshing] = useSpinGuard();

function refresh() {
    const stop = startRefreshing();
    router.reload({ preserveScroll: true, onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Log cursors refreshed.', duration: 2000 }), onFinish: stop });
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
                        <tr v-for="cursor in logCursors.data" :key="cursor.id as number">
                            <TableCell v-for="col in columns" :key="col.key" class="whitespace-nowrap px-2 py-1.5 text-xs">
                                <template v-if="col.key === 'file_path'">
                                    <span class="max-w-[300px] truncate block" :title="cursor[col.key] as string">
                                        {{ cursor[col.key] }}
                                    </span>
                                </template>
                                <template v-else-if="col.key === 'head_hash'">
                                    <span class="font-mono text-muted-foreground">
                                        {{ cursor[col.key] ? (cursor[col.key] as string).substring(0, 8) : '—' }}
                                    </span>
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
