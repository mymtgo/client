<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { reactive } from 'vue';

const { add: toast } = useToast();

usePoll(1000);

type SelectOption = { label: string; value: string };

const props = defineProps<{
    leagues: {
        data: Array<Record<string, unknown>>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    stateOptions: SelectOption[];
    deckVersionOptions: SelectOption[];
}>();

const flashState = reactive<Record<string, 'success' | 'error' | null>>({});

function flashCell(key: string, state: 'success' | 'error') {
    flashState[key] = state;
    setTimeout(() => (flashState[key] = null), 1000);
}

function saveField(leagueId: number, field: string, value: unknown) {
    const key = `${leagueId}-${field}`;
    router.patch(`/debug/leagues/${leagueId}`, { [field]: value }, {
        preserveScroll: true,
        onSuccess: () => {
            flashCell(key, 'success');
            toast({ type: 'success', title: 'Updated', message: `League #${leagueId} ${field} updated.`, duration: 2000 });
        },
        onError: () => flashCell(key, 'error'),
    });
}

function deleteLeague(id: number) {
    router.delete(`/debug/leagues/${id}`, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Deleted', message: `League #${id} soft-deleted.`, duration: 2000 }),
    });
}

function restoreLeague(id: number) {
    router.patch(`/debug/leagues/${id}/restore`, {}, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Restored', message: `League #${id} restored.`, duration: 2000 }),
    });
}

const columns = [
    { key: 'id', label: 'ID', type: 'readonly' as const },
    { key: 'token', label: 'Token', type: 'text' as const },
    { key: 'event_id', label: 'Event ID', type: 'number' as const },
    { key: 'name', label: 'Name', type: 'text' as const },
    { key: 'format', label: 'Format', type: 'text' as const },
    { key: 'state', label: 'State', type: 'select' as const, optionsKey: 'stateOptions' as const },
    { key: 'phantom', label: 'Phantom', type: 'switch' as const },
    { key: 'deck_change_detected', label: 'Deck Change', type: 'switch' as const },
    { key: 'deck_version_id', label: 'Deck Version', type: 'select' as const, optionsKey: 'deckVersionOptions' as const, nullable: true },
    { key: 'started_at', label: 'Started', type: 'readonly' as const },
    { key: 'joined_at', label: 'Joined', type: 'readonly' as const },
];

const optionsMap: Record<string, SelectOption[]> = {
    stateOptions: props.stateOptions,
    deckVersionOptions: props.deckVersionOptions,
};

const [refreshing, startRefreshing] = useSpinGuard();

function refresh() {
    const stop = startRefreshing();
    router.reload({ preserveScroll: true, onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Leagues refreshed.', duration: 2000 }), onFinish: stop });
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
                            <TableHead class="px-2 text-xs">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <tr
                            v-for="league in leagues.data"
                            :key="league.id as number"
                            :class="{ 'opacity-40 line-through': league.deleted_at }"
                        >
                            <EditableCell
                                v-for="col in columns"
                                :key="col.key"
                                :modelValue="league[col.key] as string | number | null"
                                :type="col.type"
                                :options="col.optionsKey ? optionsMap[col.optionsKey] : undefined"
                                :nullable="col.nullable"
                                :flash="flashState[`${league.id}-${col.key}`]"
                                @save="(val: unknown) => saveField(league.id as number, col.key, val)"
                            />
                            <td class="px-2 py-1">
                                <Button
                                    v-if="league.deleted_at"
                                    variant="outline"
                                    size="sm"
                                    class="h-7 text-xs"
                                    @click="restoreLeague(league.id as number)"
                                >
                                    Restore
                                </Button>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 text-xs text-destructive"
                                    @click="deleteLeague(league.id as number)"
                                >
                                    Delete
                                </Button>
                            </td>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="leagues.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in leagues.links" :key="link.label">
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
