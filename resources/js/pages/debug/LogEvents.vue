<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import EditableCell from '@/components/debug/EditableCell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

type SelectOption = { label: string; value: string };

const props = defineProps<{
    logEvents: {
        data: Array<Record<string, unknown>>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    filters: { match_token: string; event_type: string };
    eventTypeOptions: SelectOption[];
}>();

const matchTokenFilter = ref(props.filters.match_token);
const eventTypeFilter = ref(props.filters.event_type);

function applyFilters() {
    router.get('/debug/log-events', {
        match_token: matchTokenFilter.value || undefined,
        event_type: eventTypeFilter.value || undefined,
    }, { preserveScroll: true });
}

function clearFilters() {
    matchTokenFilter.value = '';
    eventTypeFilter.value = '';
    router.get('/debug/log-events', {}, { preserveScroll: true });
}

const flashState = reactive<Record<string, 'success' | 'error' | null>>({});

function flashCell(key: string, state: 'success' | 'error') {
    flashState[key] = state;
    setTimeout(() => (flashState[key] = null), 1000);
}

function saveField(eventId: number, field: string, value: unknown) {
    const key = `${eventId}-${field}`;
    router.patch(`/debug/log-events/${eventId}`, { [field]: value }, {
        preserveScroll: true,
        onSuccess: () => flashCell(key, 'success'),
        onError: () => flashCell(key, 'error'),
    });
}

const columns = [
    { key: 'id', label: 'ID', type: 'readonly' as const },
    { key: 'file_path', label: 'File Path', type: 'text' as const },
    { key: 'byte_offset_start', label: 'Start', type: 'number' as const },
    { key: 'byte_offset_end', label: 'End', type: 'number' as const },
    { key: 'timestamp', label: 'Timestamp', type: 'text' as const },
    { key: 'level', label: 'Level', type: 'text' as const },
    { key: 'category', label: 'Category', type: 'text' as const },
    { key: 'context', label: 'Context', type: 'text' as const },
    { key: 'raw_text', label: 'Raw Text', type: 'text' as const },
    { key: 'ingested_at', label: 'Ingested', type: 'text' as const },
    { key: 'processed_at', label: 'Processed', type: 'text' as const },
    { key: 'match_token', label: 'Match Token', type: 'text' as const },
    { key: 'game_id', label: 'Game ID', type: 'text' as const },
    { key: 'match_id', label: 'Match ID', type: 'text' as const },
    { key: 'event_type', label: 'Event Type', type: 'text' as const },
    { key: 'logged_at', label: 'Logged At', type: 'text' as const },
];
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <!-- Filters -->
            <div class="mb-4 flex items-end gap-4">
                <div class="flex flex-col gap-1">
                    <Label class="text-xs">Match Token</Label>
                    <Input
                        v-model="matchTokenFilter"
                        class="h-8 w-64 text-xs"
                        placeholder="Filter by match token..."
                        @keydown.enter="applyFilters"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <Label class="text-xs">Event Type</Label>
                    <Select v-model="eventTypeFilter">
                        <SelectTrigger class="h-8 w-48 text-xs">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All types</SelectItem>
                            <SelectItem v-for="opt in eventTypeOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <Button size="sm" class="h-8" @click="applyFilters">Filter</Button>
                <Button size="sm" variant="outline" class="h-8" @click="clearFilters">Clear</Button>
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
                        <tr v-for="event in logEvents.data" :key="event.id as number">
                            <EditableCell
                                v-for="col in columns"
                                :key="col.key"
                                :modelValue="event[col.key] as string | number | null"
                                :type="col.type"
                                :flash="flashState[`${event.id}-${col.key}`]"
                                @save="(val: unknown) => saveField(event.id as number, col.key, val)"
                            />
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="logEvents.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in logEvents.links" :key="link.label">
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
