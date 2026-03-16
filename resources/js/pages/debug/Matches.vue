<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

const { add: toast } = useToast();

usePoll(1000);

type SelectOption = { label: string; value: string };

const props = defineProps<{
    matches: {
        data: Array<Record<string, unknown>>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    leagueOptions: SelectOption[];
    deckVersionOptions: SelectOption[];
    stateOptions: SelectOption[];
}>();

const flashState = reactive<Record<string, 'success' | 'error' | null>>({});

function flashCell(key: string, state: 'success' | 'error') {
    flashState[key] = state;
    setTimeout(() => (flashState[key] = null), 1000);
}

function saveField(matchId: number, field: string, value: unknown) {
    const key = `${matchId}-${field}`;
    router.patch(`/debug/matches/${matchId}`, { [field]: value }, {
        preserveScroll: true,
        onSuccess: () => {
            flashCell(key, 'success');
            toast({ type: 'success', title: 'Updated', message: `Match #${matchId} ${field} updated.`, duration: 2000 });
        },
        onError: () => flashCell(key, 'error'),
    });
}

function deleteMatch(id: number) {
    router.delete(`/debug/matches/${id}`, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Deleted', message: `Match #${id} soft-deleted.`, duration: 2000 }),
    });
}

function restoreMatch(id: number) {
    router.patch(`/debug/matches/${id}/restore`, {}, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Restored', message: `Match #${id} restored.`, duration: 2000 }),
    });
}

function forceDeleteMatch(id: number) {
    if (!confirm('Permanently delete this match and reset its log events? This cannot be undone.')) return;
    router.delete(`/debug/matches/${id}/force`, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Purged', message: `Match #${id} permanently deleted. Log events reset for reingestion.`, duration: 3000 }),
    });
}

const resetIdentifier = ref('');
const [resetting, startResetting] = useSpinGuard();

function resetMatch() {
    if (!resetIdentifier.value) return;
    const stop = startResetting();
    router.post('/debug/matches/reset', { identifier: resetIdentifier.value }, {
        preserveScroll: true,
        onSuccess: () => {
            toast({ type: 'success', title: 'Reset', message: `Match ${resetIdentifier.value} purged and events reset for reingestion.`, duration: 3000 });
            resetIdentifier.value = '';
        },
        onFinish: stop,
    });
}

const columns = [
    { key: 'id', label: 'ID', type: 'readonly' as const },
    { key: 'token', label: 'Token', type: 'text' as const },
    { key: 'mtgo_id', label: 'MTGO ID', type: 'text' as const },
    { key: 'league_id', label: 'League', type: 'select' as const, optionsKey: 'leagueOptions' as const, nullable: true },
    { key: 'deck_version_id', label: 'Deck Version', type: 'select' as const, optionsKey: 'deckVersionOptions' as const, nullable: true },
    { key: 'format', label: 'Format', type: 'text' as const },
    { key: 'match_type', label: 'Type', type: 'text' as const },
    { key: 'state', label: 'State', type: 'select' as const, optionsKey: 'stateOptions' as const },
    { key: 'games_won', label: 'Won', type: 'number' as const },
    { key: 'games_lost', label: 'Lost', type: 'number' as const },
    { key: 'started_at', label: 'Started', type: 'text' as const },
    { key: 'ended_at', label: 'Ended', type: 'text' as const },
    { key: 'submitted_at', label: 'Submitted', type: 'text' as const },
];

const optionsMap: Record<string, SelectOption[]> = {
    leagueOptions: props.leagueOptions,
    deckVersionOptions: props.deckVersionOptions,
    stateOptions: props.stateOptions,
};

const [processing, startProcessing] = useSpinGuard();
const [refreshing, startRefreshing] = useSpinGuard();

function processNow() {
    const stop = startProcessing();
    router.post('/debug/matches/process', {}, {
        preserveScroll: true,
        onSuccess: () => {
            toast({ type: 'success', title: 'Processed', message: 'Log events processed into matches.', duration: 2000 });
            setTimeout(() => refresh(), 1000);
        },
        onFinish: stop,
    });
}

function refresh() {
    const stop = startRefreshing();
    router.reload({ preserveScroll: true, onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Matches refreshed.', duration: 2000 }), onFinish: stop });
}
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <div class="mb-4 flex items-center gap-2">
                <Input
                    v-model="resetIdentifier"
                    type="text"
                    placeholder="Match ID or token..."
                    class="h-8 w-48 text-xs"
                    @keyup.enter="resetMatch"
                />
                <Button size="sm" variant="outline" class="h-8" :disabled="!resetIdentifier || resetting" @click="resetMatch">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': resetting }" />
                    Reset &amp; Rebuild
                </Button>
                <div class="flex-1" />
                <Button size="sm" class="h-8" :disabled="processing" @click="processNow">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': processing }" />
                    Process Now
                </Button>
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
                            v-for="match in matches.data"
                            :key="match.id as number"
                            :class="{ 'opacity-40 line-through': match.deleted_at }"
                        >
                            <EditableCell
                                v-for="col in columns"
                                :key="col.key"
                                :modelValue="match[col.key] as string | number | null"
                                :type="col.type"
                                :options="col.optionsKey ? optionsMap[col.optionsKey] : undefined"
                                :nullable="col.nullable"
                                :flash="flashState[`${match.id}-${col.key}`]"
                                @save="(val: unknown) => saveField(match.id as number, col.key, val)"
                            />
                            <td class="whitespace-nowrap px-2 py-1">
                                <template v-if="match.deleted_at">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="mr-1 h-7 text-xs"
                                        @click="restoreMatch(match.id as number)"
                                    >
                                        Restore
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 text-xs text-destructive"
                                        @click="forceDeleteMatch(match.id as number)"
                                    >
                                        Force Delete
                                    </Button>
                                </template>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 text-xs text-destructive"
                                    @click="deleteMatch(match.id as number)"
                                >
                                    Delete
                                </Button>
                            </td>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="matches.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in matches.links" :key="link.label">
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
