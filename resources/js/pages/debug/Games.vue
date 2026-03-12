<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

type SelectOption = { label: string; value: string };

const props = defineProps<{
    games: {
        data: Array<Record<string, unknown>>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    matchOptions: SelectOption[];
    wonOptions: SelectOption[];
}>();

const cellRefs = ref<Record<string, InstanceType<typeof EditableCell>>>({});

function saveField(gameId: number, field: string, value: unknown) {
    router.patch(`/debug/games/${gameId}`, { [field]: value }, {
        preserveScroll: true,
        onSuccess: () => cellRefs.value[`${gameId}-${field}`]?.flashCell('success'),
        onError: () => cellRefs.value[`${gameId}-${field}`]?.flashCell('error'),
    });
}

const columns = [
    { key: 'id', label: 'ID', type: 'readonly' as const },
    { key: 'match_id', label: 'Match', type: 'select' as const, optionsKey: 'matchOptions' as const },
    { key: 'mtgo_id', label: 'MTGO ID', type: 'text' as const },
    { key: 'won', label: 'Won', type: 'select' as const, optionsKey: 'wonOptions' as const },
    { key: 'started_at', label: 'Started', type: 'text' as const },
    { key: 'ended_at', label: 'Ended', type: 'text' as const },
];

const optionsMap: Record<string, SelectOption[]> = {
    matchOptions: props.matchOptions,
    wonOptions: props.wonOptions,
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
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <tr v-for="game in games.data" :key="game.id as number">
                            <EditableCell
                                v-for="col in columns"
                                :key="col.key"
                                :ref="(el: any) => { if (el) cellRefs[`${game.id}-${col.key}`] = el }"
                                :modelValue="game[col.key] as string | number | null"
                                :type="col.type"
                                :options="col.optionsKey ? optionsMap[col.optionsKey] : undefined"
                                :nullable="col.nullable"
                                @save="(val: unknown) => saveField(game.id as number, col.key, val)"
                            />
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="games.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in games.links" :key="link.label">
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
