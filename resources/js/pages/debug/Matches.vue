<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

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
        onSuccess: () => flashCell(key, 'success'),
        onError: () => flashCell(key, 'error'),
    });
}

function deleteMatch(id: number) {
    router.delete(`/debug/matches/${id}`, { preserveScroll: true });
}

function restoreMatch(id: number) {
    router.patch(`/debug/matches/${id}/restore`, {}, { preserveScroll: true });
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
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
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
                            <td class="px-2 py-1">
                                <Button
                                    v-if="match.deleted_at"
                                    variant="outline"
                                    size="sm"
                                    class="h-7 text-xs"
                                    @click="restoreMatch(match.id as number)"
                                >
                                    Restore
                                </Button>
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
