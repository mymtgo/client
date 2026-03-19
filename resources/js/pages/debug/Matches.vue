<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { EllipsisVertical, RefreshCw, Trash2, RotateCcw } from 'lucide-vue-next';
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

// Delete confirmation dialog
const deleteDialogOpen = ref(false);
const matchToDelete = ref<{ id: number; token: string } | null>(null);

function confirmDelete(match: Record<string, unknown>) {
    matchToDelete.value = { id: match.id as number, token: match.token as string };
    deleteDialogOpen.value = true;
}

function executeDelete() {
    if (!matchToDelete.value) return;
    const { id } = matchToDelete.value;
    router.delete(`/debug/matches/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast({ type: 'success', title: 'Deleted', message: `Match #${id} permanently deleted.`, duration: 2000 });
            deleteDialogOpen.value = false;
            matchToDelete.value = null;
        },
    });
}

function reprocessMatch(id: number) {
    router.post('/debug/matches/reset', { identifier: String(id) }, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Voided', message: `Match #${id} voided.`, duration: 2000 }),
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
                            <TableHead class="w-10 px-2 text-xs" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <tr
                            v-for="match in matches.data"
                            :key="match.id as number"
                            :class="{ 'opacity-40': match.state === 'voided' }"
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
                            <td class="px-2 py-1">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-7 w-7">
                                            <EllipsisVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            v-if="match.state === 'voided'"
                                            @click="saveField(match.id as number, 'state', 'complete')"
                                        >
                                            <RotateCcw class="h-4 w-4" />
                                            Restore
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="match.state !== 'voided'"
                                            @click="reprocessMatch(match.id as number)"
                                        >
                                            <RotateCcw class="h-4 w-4" />
                                            Void
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            class="text-destructive"
                                            @click="confirmDelete(match)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
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

        <!-- Delete confirmation dialog -->
        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete Match</DialogTitle>
                    <DialogDescription>
                        This will permanently delete match
                        <span class="font-mono font-semibold">#{{ matchToDelete?.id }}</span>
                        and all associated data (games, archetypes, timelines, log events).
                        This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleteDialogOpen = false">Cancel</Button>
                    <Button variant="destructive" @click="executeDelete">Delete permanently</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
