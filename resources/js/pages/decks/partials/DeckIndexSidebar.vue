<script setup lang="ts">
import ArchetypePicker from '@/components/archetypes/ArchetypePicker.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { archetypeFormatKey } from '@/composables/useArchetypeSplit';
import { hasDeckDrag, readDeckDrag } from '@/lib/deckDrag';
import { cn } from '@/lib/utils';
import { Plus, Search, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    search: string;
    format: string;
    archetype: string;
    formatOptions: App.Data.Front.DeckFormatOptionData[];
    archetypeOptions: App.Data.Front.DeckArchetypeOptionData[];
    unclassifiedCount: number;
    archetypes: App.Data.Front.ArchetypeData[] | undefined;
    compactCards: boolean;
    showArchived: boolean;
    selectedIds: number[];
    /** Deck id to display format for every deck on the current page, so a picker can be scoped to the decks it will assign. */
    deckFormats: Record<number, string>;
    /** Display format of the deck(s) being dragged, null when no drag is in flight. */
    dragFormat: string | null;
}>();

const emit = defineEmits<{
    'update:search': [value: string];
    'update:format': [value: string];
    'update:archetype': [value: string];
    'update:compactCards': [value: boolean];
    'update:showArchived': [value: boolean];
    assign: [deckIds: number[], archetypeId: number | null];
}>();

const totalDecks = computed(() => props.formatOptions.reduce((sum, option) => sum + option.count, 0));
const selectedCount = computed(() => props.selectedIds.length);

// Select rejects an empty-string value, so "All" needs a sentinel that maps back to ''.
const ALL_FORMATS = '__all__';

function onFormatChange(value: unknown) {
    const next = String(value ?? '');
    emit('update:format', next === ALL_FORMATS ? '' : next);
}

const rowClass = 'flex w-full cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-left text-sm transition-colors';
const activeClass = 'nav-item-active';
const inactiveClass = 'nav-item-inactive';
const dropClass = 'border-primary/60 bg-primary/10 text-foreground';

function toggleArchetype(value: string) {
    emit('update:archetype', props.archetype === value ? '' : value);
}

function winrateClass(record: App.Data.Front.MatchRecordData): string {
    if (record.total === 0) return 'text-muted-foreground';
    return record.winrate >= 50 ? 'text-success' : 'text-destructive';
}

// Drop targets. `dragover` can only see the payload type, not its contents,
// so the highlight and the preventDefault both key off hasDeckDrag alone.
const dropTarget = ref<string | null>(null);

/**
 * An archetype row only takes decks of its own format. Unclassified and
 * "Other archetype…" pass null and take anything; the Other picker scopes
 * itself to the dropped deck's format.
 */
function acceptsDrag(optionFormat: string | null): boolean {
    if (props.dragFormat === null || optionFormat === null) return true;
    return archetypeFormatKey(props.dragFormat) === optionFormat;
}

function onDragOver(event: DragEvent, key: string, optionFormat: string | null = null) {
    if (!hasDeckDrag(event.dataTransfer)) return;
    if (!acceptsDrag(optionFormat)) {
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'none';
        return;
    }
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    dropTarget.value = key;
}

function onDragLeave(event: DragEvent, key: string) {
    const next = event.relatedTarget as Node | null;
    if (next && (event.currentTarget as HTMLElement).contains(next)) return;
    if (dropTarget.value === key) dropTarget.value = null;
}

function onDrop(event: DragEvent, archetypeId: number | null, optionFormat: string | null = null) {
    dropTarget.value = null;
    const ids = readDeckDrag(event.dataTransfer);
    if (ids.length === 0 || !acceptsDrag(optionFormat)) return;
    event.preventDefault();
    emit('assign', ids, archetypeId);
}

// "Other archetype…" opens the picker. A drop on it stashes the dragged ids so
// the picker assigns those; a click assigns the current selection instead.
const otherOpen = ref(false);
const pendingIds = ref<number[] | null>(null);

function onDropOther(event: DragEvent) {
    dropTarget.value = null;
    const ids = readDeckDrag(event.dataTransfer);
    if (ids.length === 0) return;
    event.preventDefault();
    pendingIds.value = ids;
    otherOpen.value = true;
}

function onOtherPick(archetypeId: number) {
    otherOpen.value = false;
    emit('assign', pendingIds.value ?? [], archetypeId);
    pendingIds.value = null;
}

function onOtherOpenChange(open: boolean) {
    otherOpen.value = open;
    if (!open) pendingIds.value = null;
}

// The decks the Other picker will assign: a drop in flight, else the selection.
const otherTargetIds = computed(() => pendingIds.value ?? props.selectedIds);

/**
 * Same-named archetypes exist once per format, so the picker is scoped to
 * the target decks' format when they all share one. Mixed formats fall back
 * to the full list with a format label on every row.
 */
const otherFormat = computed(() => {
    const formats = new Set(otherTargetIds.value.map((id) => props.deckFormats[id]).filter(Boolean));
    return formats.size === 1 ? [...formats][0] : null;
});
</script>

<template>
    <div class="flex h-full flex-col border-r border-black/80 bg-muted/20">
        <!-- Pinned top: search + format -->
        <div class="flex flex-col gap-4 border-b border-black/60 px-3 py-3">
            <div class="relative">
                <Search class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    :model-value="search"
                    placeholder="Search decks..."
                    class="h-8 pl-7 text-xs"
                    @update:model-value="(value) => emit('update:search', String(value))"
                />
            </div>

            <section class="flex flex-col gap-1">
                <h3 class="px-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">Format</h3>
                <Select :model-value="format === '' ? ALL_FORMATS : format" @update:model-value="onFormatChange">
                    <SelectTrigger class="h-8 w-full text-xs">
                        <SelectValue placeholder="All formats" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_FORMATS">
                            All
                            <template #trailing>
                                <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ totalDecks }}</span>
                            </template>
                        </SelectItem>
                        <SelectItem v-for="option in formatOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                            <template #trailing>
                                <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ option.count }}</span>
                            </template>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </section>
        </div>

        <!-- Scrolling middle: archetypes -->
        <section class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto border-t border-b border-white/5 border-b-black/60 px-3 py-3">
            <h3 class="px-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">Archetype</h3>

            <button
                type="button"
                :class="cn(rowClass, archetype === 'none' ? activeClass : inactiveClass, dropTarget === 'none' && dropClass)"
                :aria-pressed="archetype === 'none'"
                @click="toggleArchetype('none')"
                @dragover="onDragOver($event, 'none')"
                @dragleave="onDragLeave($event, 'none')"
                @drop="onDrop($event, null)"
            >
                <TriangleAlert :class="cn('size-3.5 shrink-0', unclassifiedCount === 0 ? 'text-muted-foreground' : 'text-warning')" />
                <span class="flex-1">Unclassified</span>
                <span :class="cn('text-xs font-medium tabular-nums', unclassifiedCount === 0 ? 'text-muted-foreground' : 'text-warning')">{{
                    unclassifiedCount
                }}</span>
            </button>

            <div class="my-1 border-t border-border/60" />

            <button
                v-for="option in archetypeOptions"
                :key="option.id"
                type="button"
                :class="
                    cn(
                        rowClass,
                        archetype === String(option.id) ? activeClass : inactiveClass,
                        dropTarget === String(option.id) && dropClass,
                        dragFormat !== null && !acceptsDrag(option.format) && 'opacity-40',
                    )
                "
                :aria-pressed="archetype === String(option.id)"
                :title="`${option.name} · ${option.record.label} · ${option.record.total} matches`"
                @click="toggleArchetype(String(option.id))"
                @dragover="onDragOver($event, String(option.id), option.format)"
                @dragleave="onDragLeave($event, String(option.id))"
                @drop="onDrop($event, option.id, option.format)"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate">{{ option.name }}</span>
                        <span :class="cn('shrink-0 tabular-nums', winrateClass(option.record))">
                            {{ option.record.total === 0 ? '—' : `${option.record.winrate}%` }}
                        </span>
                    </span>
                    <span class="flex items-center gap-2 text-xs">
                        <ManaSymbols v-if="option.colorIdentity" :symbols="option.colorIdentity" class="shrink-0" />
                        <span class="min-w-0 truncate text-[10px] tracking-wide text-muted-foreground uppercase">
                            <!-- Same-named archetypes exist per format; only ambiguous when no format filter is active. -->
                            <template v-if="format === '' && option.format">{{ option.format }} &middot; </template>
                            {{ option.deckCount }} {{ option.deckCount === 1 ? 'deck' : 'decks' }}
                        </span>
                        <span v-if="option.record.total > 0" class="ml-auto shrink-0 text-muted-foreground tabular-nums">
                            {{ option.record.label }}
                        </span>
                    </span>
                </span>
            </button>

            <Popover :open="otherOpen" @update:open="onOtherOpenChange">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        :class="cn(rowClass, inactiveClass, dropTarget === 'other' && dropClass)"
                        :title="selectedCount === 0 ? 'Select decks, or drop one here' : `Assign ${selectedCount} selected`"
                        @dragover="onDragOver($event, 'other')"
                        @dragleave="onDragLeave($event, 'other')"
                        @drop="onDropOther"
                    >
                        <Plus class="size-3.5 shrink-0" />
                        <span class="flex-1">Other archetype…</span>
                    </button>
                </PopoverTrigger>
                <PopoverContent side="right" align="start" class="w-72 p-2">
                    <p v-if="otherTargetIds.length === 0" class="px-2 py-3 text-sm text-muted-foreground">
                        Select one or two decks or drag a deck to assign a new archetype
                    </p>
                    <ArchetypePicker
                        v-else
                        :archetypes="archetypes"
                        :format="otherFormat"
                        :show-format="otherFormat === null"
                        autofocus
                        @select="onOtherPick"
                    />
                </PopoverContent>
            </Popover>
        </section>

        <!-- Pinned bottom: view -->
        <section class="flex flex-col gap-2 border-t border-white/5 px-3 py-3">
            <h3 class="px-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">View</h3>
            <div class="flex items-center justify-between px-2.5">
                <Label for="compact-cards" class="cursor-pointer text-xs">Compact cards</Label>
                <Switch id="compact-cards" :model-value="compactCards" @update:modelValue="(value) => emit('update:compactCards', value)" />
            </div>
            <div class="flex items-center justify-between px-2.5">
                <Label for="show-archived" class="cursor-pointer text-xs">Show archived</Label>
                <Switch id="show-archived" :model-value="showArchived" @update:modelValue="(value) => emit('update:showArchived', value)" />
            </div>
        </section>
    </div>
</template>
