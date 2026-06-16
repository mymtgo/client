<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Check, Filter } from 'lucide-vue-next';
import { computed } from 'vue';
import { CARD_TYPE_KEYS, CARD_TYPE_OPTIONS, type CardTypeKey } from './cardTypes';

const props = withDefaults(
    defineProps<{
        /** Per-type enabled state. A key set to false hides that type. */
        modelValue: Partial<Record<CardTypeKey, boolean>>;
        /** Which type keys to render, in order. Defaults to the canonical seven. */
        keys?: CardTypeKey[];
        /** Render a divider before this key (e.g. to set "Sideboard" apart). */
        separatorBefore?: CardTypeKey;
        align?: 'start' | 'end';
        triggerClass?: string;
    }>(),
    {
        keys: () => CARD_TYPE_KEYS,
        separatorBefore: undefined,
        align: 'start',
        triggerClass: '',
    },
);

const emit = defineEmits<{ 'update:modelValue': [Partial<Record<CardTypeKey, boolean>>] }>();

const options = computed(() => props.keys.map((key) => CARD_TYPE_OPTIONS[key]));
const hiddenCount = computed(() => options.value.filter((o) => !props.modelValue[o.key]).length);
const allVisible = computed(() => options.value.every((o) => props.modelValue[o.key]));

function setFilter(key: CardTypeKey, value: boolean) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function toggleAll() {
    const next = !allVisible.value;
    const updated = { ...props.modelValue };
    for (const option of options.value) {
        updated[option.key] = next;
    }
    emit('update:modelValue', updated);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm" :class="['h-8 gap-1.5', triggerClass]">
                <Filter class="size-3.5" />
                <span v-if="hiddenCount > 0">{{ hiddenCount }} hidden</span>
                <span v-else>Card types</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent :align="align" class="w-48">
            <div class="flex items-center justify-between px-2 py-1.5">
                <span class="text-xs font-semibold">Filter by type</span>
                <button class="text-xs text-muted-foreground hover:text-foreground" @click="toggleAll">
                    {{ allVisible ? 'Hide all' : 'Show all' }}
                </button>
            </div>
            <DropdownMenuSeparator />
            <template v-for="option in options" :key="option.key">
                <DropdownMenuSeparator v-if="separatorBefore && option.key === separatorBefore" />
                <DropdownMenuCheckboxItem
                    :modelValue="!!modelValue[option.key]"
                    @update:modelValue="(val: boolean) => setFilter(option.key, val)"
                    @select.prevent
                >
                    <template #indicator-icon>
                        <Check class="size-4 text-success" />
                    </template>
                    <component :is="option.icon" class="mr-2 size-3.5 text-muted-foreground" />
                    {{ option.label }}
                </DropdownMenuCheckboxItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
