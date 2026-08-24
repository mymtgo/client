<script setup lang="ts">
import IndexController from '@/actions/App/Http/Controllers/Archetypes/IndexController';
import ApplyController from '@/actions/App/Http/Controllers/Archetypes/Refresh/ApplyController';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import SuccessorSelect, { type SuccessorOption } from '@/pages/archetypes/partials/SuccessorSelect.vue';
import { useOfflineMode } from '@/composables/useOfflineMode';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, RefreshCw } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';

interface Removal {
    id: number;
    name: string;
    format: string | null;
    match_count: number;
    suggested_id: number | null;
    suggested_uuid: string | null;
}

const props = defineProps<{
    added: number;
    updated: number;
    removals: Removal[];
    removed_without_matches: number;
    matches_affected: number;
    options: SuccessorOption[];
}>();

const removals = computed<Removal[]>(() => [...props.removals].sort((a, b) => b.match_count - a.match_count));

// Suggested successors are preselected; the user overrides per row. Incoming
// API archetypes (no local id yet) are referenced by their uuid string.
const mappings = reactive<Record<number, number | string | null>>(
    Object.fromEntries(props.removals.map((removal) => [removal.id, removal.suggested_id ?? removal.suggested_uuid])),
);

const offlineMode = useOfflineMode();

const applying = ref(false);

const totalRemoved = computed(() => props.removals.length + props.removed_without_matches);
const hasChanges = computed(() => props.added > 0 || props.updated > 0 || totalRemoved.value > 0);

const remapCount = computed(() => Object.values(mappings).filter((successorId) => successorId !== null).length);
const redetectMatchCount = computed(() =>
    props.removals.filter((removal) => mappings[removal.id] === null).reduce((sum, removal) => sum + removal.match_count, 0),
);

function clearAll(): void {
    for (const removal of props.removals) {
        mappings[removal.id] = null;
    }
}

function restoreSuggestions(): void {
    for (const removal of props.removals) {
        mappings[removal.id] = removal.suggested_id ?? removal.suggested_uuid;
    }
}

function apply(): void {
    if (applying.value || offlineMode.value) return;

    applying.value = true;
    router.post(
        ApplyController.url(),
        { mappings: { ...mappings } },
        {
            onFinish: () => {
                applying.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 overflow-y-auto p-6">
        <div>
            <Link
                :href="IndexController.url()"
                class="mb-3 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
                <ArrowLeft class="size-3.5" />
                Back to archetypes
            </Link>
            <h1 class="text-lg font-bold text-foreground">Refresh Archetypes</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Syncs the archetype list with the server. Archetypes you created yourself are never touched.
            </p>
        </div>

        <p v-if="!hasChanges" class="rounded-md border border-black/40 p-4 text-sm text-muted-foreground">Everything is already up to date.</p>

        <template v-else>
            <div class="grid grid-cols-3 gap-3 text-sm">
                <div class="rounded-md border border-black/40 p-3">
                    <p class="text-xs text-muted-foreground uppercase">New</p>
                    <p class="mt-1 text-lg font-bold">{{ added }}</p>
                </div>
                <div class="rounded-md border border-black/40 p-3">
                    <p class="text-xs text-muted-foreground uppercase">Updated</p>
                    <p class="mt-1 text-lg font-bold">{{ updated }}</p>
                </div>
                <div class="rounded-md border border-black/40 p-3">
                    <p class="text-xs text-muted-foreground uppercase">Removed</p>
                    <p class="mt-1 text-lg font-bold">{{ totalRemoved }}</p>
                </div>
            </div>

            <div v-if="removals.length > 0" class="flex flex-col gap-2">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-muted-foreground">
                        These removed archetypes have matches assigned. Reassign each to its renamed successor, or delete it and re-detect its
                        matches.
                    </p>
                    <div class="flex shrink-0 gap-2">
                        <Button variant="outline" size="sm" @click="restoreSuggestions"> Restore suggestions </Button>
                        <Button variant="outline" size="sm" @click="clearAll"> Re-detect all </Button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-md border border-black/40">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Archetype</TableHead>
                                <TableHead class="w-24">Format</TableHead>
                                <TableHead class="w-20 text-right">Matches</TableHead>
                                <TableHead class="w-64">Reassign to</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="removal in removals" :key="removal.id">
                                <TableCell class="max-w-0 truncate font-medium">
                                    {{ removal.name }}
                                </TableCell>
                                <TableCell class="text-xs text-muted-foreground uppercase">
                                    {{ removal.format }}
                                </TableCell>
                                <TableCell class="text-right tabular-nums">
                                    {{ removal.match_count }}
                                </TableCell>
                                <TableCell>
                                    <SuccessorSelect v-model="mappings[removal.id]" :options="options" :format="removal.format" />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>

            <p v-if="removed_without_matches > 0" class="text-sm text-muted-foreground">
                {{ removed_without_matches }} other {{ removed_without_matches === 1 ? 'archetype' : 'archetypes' }} with no matches assigned will be
                removed.
            </p>

            <div class="flex items-center justify-between gap-3 border-t border-black/40 pt-4">
                <p class="text-sm text-muted-foreground">
                    <template v-if="remapCount > 0">
                        {{ remapCount }} {{ remapCount === 1 ? 'archetype' : 'archetypes' }}
                        will be reassigned.
                    </template>
                    <template v-if="redetectMatchCount > 0">
                        {{ redetectMatchCount }}
                        {{ redetectMatchCount === 1 ? 'match' : 'matches' }} will be re-detected.
                    </template>
                </p>
                <div class="flex shrink-0 gap-2">
                    <Button variant="outline" :disabled="applying" as-child>
                        <Link :href="IndexController.url()">Cancel</Link>
                    </Button>
                    <Button
                        :disabled="applying || offlineMode"
                        :title="offlineMode ? 'Offline mode is enabled — turn it off in Settings to refresh archetypes.' : undefined"
                        @click="apply"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': applying }" />
                        {{ applying ? 'Refreshing…' : 'Apply refresh' }}
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
