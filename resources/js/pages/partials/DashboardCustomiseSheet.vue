<script setup lang="ts">
import UpdateLayoutController from '@/actions/App/Http/Controllers/Dashboard/UpdateLayoutController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import {
    DEFAULT_LAYOUT,
    WIDGET_CATALOG,
    WIDGET_KEYS,
    type LayoutRow,
    type WidgetInstance,
    type WidgetKey,
    type WidgetOptions,
} from '@/pages/partials/dashboardWidgets';
import { useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, GripVertical, Plus, Settings2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    layout: WidgetInstance[];
    options?: WidgetOptions;
}>();

const open = defineModel<boolean>('open', { default: false });

function cloneLayout(rows: LayoutRow[]): LayoutRow[] {
    return rows.map(({ id, type, config }) => ({ id, type, config: { ...config } }));
}

const form = useForm<{ layout: LayoutRow[] }>({ layout: cloneLayout(props.layout) });

watch(open, (isOpen) => {
    if (isOpen) {
        form.layout = cloneLayout(props.layout);
        form.clearErrors();
        openSettings.value = new Set();
    }
});

const addable = computed(() => WIDGET_KEYS.filter((key) => WIDGET_CATALOG[key].allowsMultiple || !form.layout.some((row) => row.type === key)));

function add(type: WidgetKey) {
    const id = crypto.randomUUID();
    form.layout.push({ id, type, config: { ...WIDGET_CATALOG[type].defaultConfig } });
    if (hasSettings(type)) openSettings.value = new Set([...openSettings.value, id]);
}

function remove(index: number) {
    form.layout.splice(index, 1);
}

function move(index: number, delta: -1 | 1) {
    const target = index + delta;
    if (target < 0 || target >= form.layout.length) return;
    const [row] = form.layout.splice(index, 1);
    form.layout.splice(target, 0, row);
}

const CONFIGURABLE: ReadonlySet<WidgetKey> = new Set(['deck_stats', 'archetype_stats', 'league_results', 'limited_picks', 'format_stats']);
const openSettings = ref<Set<string>>(new Set());

function hasSettings(type: WidgetKey): boolean {
    return CONFIGURABLE.has(type);
}

function settingsOpen(id: string): boolean {
    return openSettings.value.has(id);
}

function toggleSettings(id: string) {
    const next = new Set(openSettings.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    openSettings.value = next;
}

const dragging = ref<number | null>(null);
/** Slot the dragged row would land in: 0 = before the first row, length = after the last. */
const dropSlot = ref<number | null>(null);

function onDragStart(index: number, event: DragEvent) {
    dragging.value = index;
    event.dataTransfer?.setData('text/plain', String(index));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function onDragOver(index: number, event: DragEvent) {
    if (dragging.value === null) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const after = event.clientY > rect.top + rect.height / 2;
    dropSlot.value = after ? index + 1 : index;
}

function onDrop() {
    const from = dragging.value;
    const slot = dropSlot.value;
    clearDrag();
    if (from === null || slot === null) return;
    const to = slot > from ? slot - 1 : slot;
    if (to === from) return;
    const [row] = form.layout.splice(from, 1);
    form.layout.splice(to, 0, row);
}

function clearDrag() {
    dragging.value = null;
    dropSlot.value = null;
}

function showsLineBefore(index: number): boolean {
    return dropSlot.value === index && dragging.value !== index && dragging.value !== index - 1;
}

function showsLineAfter(index: number): boolean {
    return index === form.layout.length - 1 && dropSlot.value === index + 1 && dragging.value !== index;
}

function reset() {
    form.layout = cloneLayout(DEFAULT_LAYOUT);
}

function selectedFormats(row: LayoutRow): string[] {
    return (row.config.formats as string[] | undefined) ?? [];
}

function toggleFormat(row: LayoutRow, code: string, checked: boolean) {
    const current = selectedFormats(row);
    row.config.formats = checked ? [...new Set([...current, code])] : current.filter((c) => c !== code);
}

function save() {
    form.post(UpdateLayoutController.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

function rowError(index: number): string | undefined {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find((k) => k.startsWith(`layout.${index}.`));
    return key ? errors[key] : undefined;
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="flex w-full flex-col gap-5 p-6 sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>Customise dashboard</SheetTitle>
                <SheetDescription>Choose which widgets show and in what order.</SheetDescription>
            </SheetHeader>

            <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto pr-1" @dragover.prevent @drop.prevent="onDrop">
                <div
                    v-for="(row, index) in form.layout"
                    :key="row.id"
                    class="relative flex flex-col gap-2 rounded-lg border px-3 py-2"
                    :class="{ 'opacity-50': dragging === index }"
                    @dragover="onDragOver(index, $event)"
                    @drop.prevent="onDrop"
                >
                    <div v-if="showsLineBefore(index)" class="pointer-events-none absolute inset-x-0 -top-[7px] h-0.5 rounded-full bg-primary" />
                    <div v-if="showsLineAfter(index)" class="pointer-events-none absolute inset-x-0 -bottom-[7px] h-0.5 rounded-full bg-primary" />
                    <div class="flex items-center gap-2">
                        <span
                            draggable="true"
                            class="shrink-0 cursor-grab text-muted-foreground hover:text-foreground active:cursor-grabbing"
                            aria-label="Drag to reorder"
                            @dragstart="onDragStart(index, $event)"
                            @dragend="clearDrag"
                        >
                            <GripVertical class="size-4" />
                        </span>
                        <span class="flex-1 truncate text-sm font-medium">{{ WIDGET_CATALOG[row.type].label }}</span>
                        <Button
                            v-if="hasSettings(row.type)"
                            variant="ghost"
                            size="icon-sm"
                            class="cursor-pointer"
                            :class="settingsOpen(row.id) ? 'text-foreground' : 'text-muted-foreground'"
                            aria-label="Widget settings"
                            @click="toggleSettings(row.id)"
                        >
                            <Settings2 class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="cursor-pointer"
                            :disabled="index === 0"
                            aria-label="Move up"
                            @click="move(index, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="cursor-pointer"
                            :disabled="index === form.layout.length - 1"
                            aria-label="Move down"
                            @click="move(index, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon-sm" class="cursor-pointer" aria-label="Remove" @click="remove(index)">
                            <X class="size-4" />
                        </Button>
                    </div>

                    <Select
                        v-if="row.type === 'deck_stats' && settingsOpen(row.id)"
                        :model-value="row.config.deck_id == null ? '' : String(row.config.deck_id)"
                        @update:model-value="(value) => (row.config.deck_id = Number(value))"
                    >
                        <SelectTrigger class="h-9 cursor-pointer">
                            <SelectValue placeholder="Choose a deck" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="deck in options?.decks ?? []" :key="deck.id" :value="String(deck.id)">
                                {{ deck.name }} <span class="text-muted-foreground">· {{ deck.format }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="row.type === 'archetype_stats' && settingsOpen(row.id)"
                        :model-value="row.config.archetype_id == null ? '' : String(row.config.archetype_id)"
                        @update:model-value="(value) => (row.config.archetype_id = Number(value))"
                    >
                        <SelectTrigger class="h-9 cursor-pointer">
                            <SelectValue placeholder="Choose an archetype" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="archetype in options?.archetypes ?? []" :key="archetype.id" :value="String(archetype.id)">
                                {{ archetype.name }} <span class="text-muted-foreground">· {{ archetype.format }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="row.type === 'league_results' && settingsOpen(row.id)"
                        :model-value="(row.config.format as string | null) ?? 'all'"
                        @update:model-value="(value) => (row.config.format = value === 'all' ? null : String(value))"
                    >
                        <SelectTrigger class="h-9 cursor-pointer">
                            <SelectValue placeholder="All formats" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All formats</SelectItem>
                            <SelectItem v-for="f in options?.formats ?? []" :key="f.value" :value="f.value">{{ f.label }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-if="row.type === 'limited_picks' && settingsOpen(row.id)"
                        :model-value="(row.config.set_code as string | null) ?? 'latest'"
                        @update:model-value="(value) => (row.config.set_code = value === 'latest' ? null : String(value))"
                    >
                        <SelectTrigger class="h-9 cursor-pointer">
                            <SelectValue placeholder="Latest set" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="latest">Latest set</SelectItem>
                            <SelectItem v-for="set in options?.sets ?? []" :key="set.code" :value="set.code">{{ set.name }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <div v-if="row.type === 'format_stats' && settingsOpen(row.id)" class="grid grid-cols-2 gap-2">
                        <Label v-for="f in options?.formats ?? []" :key="f.value" class="flex cursor-pointer items-center gap-2 text-sm font-normal">
                            <Checkbox
                                :model-value="selectedFormats(row).includes(f.value)"
                                @update:model-value="(value) => toggleFormat(row, f.value, value === true)"
                            />
                            {{ f.label }}
                        </Label>
                        <span v-if="(options?.formats ?? []).length === 0" class="col-span-2 text-xs text-muted-foreground">
                            All formats you play will show.
                        </span>
                    </div>

                    <p v-if="rowError(index)" class="text-xs text-destructive">{{ rowError(index) }}</p>
                </div>

                <p v-if="form.layout.length === 0" class="py-6 text-center text-sm text-muted-foreground">No widgets. Add one below.</p>
            </div>

            <div class="flex items-center gap-2">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="cursor-pointer gap-2" :disabled="addable.length === 0">
                            <Plus class="size-4" />
                            Add widget
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem v-for="key in addable" :key="key" class="cursor-pointer" @select="add(key)">
                            {{ WIDGET_CATALOG[key].label }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button variant="ghost" size="sm" class="cursor-pointer" @click="reset">Reset to default</Button>
            </div>

            <SheetFooter class="flex-row justify-end gap-2">
                <Button variant="outline" class="cursor-pointer" @click="open = false">Cancel</Button>
                <Button class="cursor-pointer" :disabled="form.processing" @click="save">Save</Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
