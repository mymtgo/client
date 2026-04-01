<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useSpinGuard } from '@/composables/useSpinGuard';
import { useToast } from '@/composables/useToast';
import { router, usePoll } from '@inertiajs/vue3';
import { Download, RefreshCw } from 'lucide-vue-next';
import { ref } from 'vue';

const { add: toast } = useToast();

usePoll(1000);

const props = defineProps<{
    cards: {
        data: Array<Record<string, unknown>>;
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    filters: { search: string; status: string };
    missingCount: number;
    missingArtCount: number;
    totalCount: number;
}>();

const searchFilter = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const [populating, startPopulating] = useSpinGuard();
const [refreshing, startRefreshing] = useSpinGuard();

function applyFilters() {
    router.get('/debug/cards', {
        search: searchFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveScroll: true, preserveState: true });
}

function clearFilters() {
    searchFilter.value = '';
    statusFilter.value = '';
    router.get('/debug/cards', {}, { preserveScroll: true, preserveState: true });
}

function populateNow() {
    const stop = startPopulating();
    router.post('/debug/cards/populate', {}, {
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Populated', message: 'Missing card data fetched.', duration: 2000 }),
        onFinish: stop,
    });
}

function refresh() {
    const stop = startRefreshing();
    router.reload({
        preserveScroll: true,
        onSuccess: () => toast({ type: 'success', title: 'Refreshed', message: 'Cards refreshed.', duration: 2000 }),
        onFinish: stop,
    });
}

function isMissing(card: Record<string, unknown>): boolean {
    return !card.name || !card.scryfall_id || !card.image;
}

const columns = [
    { key: 'id', label: 'ID' },
    { key: 'mtgo_id', label: 'MTGO ID' },
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Type' },
    { key: 'rarity', label: 'Rarity' },
    { key: 'color_identity', label: 'Colors' },
    { key: 'oracle_id', label: 'Oracle ID' },
    { key: 'scryfall_id', label: 'Scryfall ID' },
    { key: 'image', label: 'Image' },
    { key: 'art_crop', label: 'Artwork' },
];
</script>

<template>
    <div class="flex flex-1 flex-col overflow-hidden">
        <DebugNav />
        <div class="flex-1 overflow-auto p-4">
            <!-- Stats + filters -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="text-xs text-muted-foreground">
                    {{ totalCount }} cards &middot;
                    <span :class="missingCount > 0 ? 'text-amber-400' : 'text-success'">
                        {{ missingCount }} missing data
                    </span>
                    &middot;
                    <span :class="missingArtCount > 0 ? 'text-amber-400' : 'text-success'">
                        {{ missingArtCount }} missing artwork
                    </span>
                </span>

                <div class="flex-1" />

                <Input
                    v-model="searchFilter"
                    placeholder="Search name, MTGO ID, oracle ID..."
                    class="h-8 w-64 text-xs"
                    @keyup.enter="applyFilters"
                />

                <Select :modelValue="statusFilter || '__all__'" @update:modelValue="(val: string) => { statusFilter = val === '__all__' ? '' : val; applyFilters(); }">
                    <SelectTrigger class="h-8 w-36 text-xs">
                        <SelectValue placeholder="All cards" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__all__">All cards</SelectItem>
                        <SelectItem value="missing">Missing data</SelectItem>
                        <SelectItem value="missing_art">Missing artwork</SelectItem>
                        <SelectItem value="complete">Complete</SelectItem>
                    </SelectContent>
                </Select>

                <Button size="sm" variant="outline" class="h-8" @click="applyFilters">Filter</Button>
                <Button size="sm" variant="outline" class="h-8" @click="clearFilters">Clear</Button>

                <Button size="sm" class="h-8" :disabled="populating || (missingCount === 0 && missingArtCount === 0)" @click="populateNow">
                    <Download class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-bounce': populating }" />
                    Fetch Missing ({{ missingCount + missingArtCount }})
                </Button>

                <Button size="sm" variant="outline" class="h-8" @click="refresh">
                    <RefreshCw class="mr-1.5 h-3.5 w-3.5" :class="{ 'animate-spin': refreshing }" />
                    Refresh
                </Button>
            </div>

            <!-- Table -->
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
                        <tr
                            v-for="card in cards.data"
                            :key="card.id as number"
                            :class="{ 'bg-amber-500/5': isMissing(card) }"
                        >
                            <TableCell v-for="col in columns" :key="col.key" class="whitespace-nowrap px-2 py-1.5 text-xs">
                                <template v-if="col.key === 'art_crop' || col.key === 'image'">
                                    <span v-if="card[col.key]" class="text-success">Yes</span>
                                    <span v-else class="text-destructive">No</span>
                                </template>
                                <template v-else-if="col.key === 'scryfall_id' || col.key === 'oracle_id'">
                                    <span v-if="card[col.key]" class="max-w-[120px] truncate block font-mono text-muted-foreground" :title="card[col.key] as string">
                                        {{ (card[col.key] as string).substring(0, 8) }}...
                                    </span>
                                    <span v-else class="text-destructive">—</span>
                                </template>
                                <template v-else>
                                    <span v-if="card[col.key] != null">{{ card[col.key] }}</span>
                                    <span v-else class="text-destructive">—</span>
                                </template>
                            </TableCell>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <!-- Pagination -->
            <div v-if="cards.last_page > 1" class="mt-4 flex items-center justify-center gap-1">
                <template v-for="link in cards.links" :key="link.label">
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
