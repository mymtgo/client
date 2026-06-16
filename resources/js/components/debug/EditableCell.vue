<script setup lang="ts">
import { DateTimePicker } from '@/components/ui/date-time-picker';
import { Input } from '@/components/ui/input';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string | number | boolean | null;
    type?: 'text' | 'number' | 'select' | 'switch' | 'datetime' | 'readonly';
    options?: Array<{ label: string; value: string }>;
    nullable?: boolean;
    flash?: 'success' | 'error' | null;
}>();

const emit = defineEmits<{
    save: [value: string | number | boolean | null];
}>();

const localValue = ref(String(props.modelValue ?? ''));

watch(() => props.modelValue, (val) => {
    localValue.value = String(val ?? '');
});

function onBlur() {
    const raw = localValue.value;
    const parsed = props.type === 'number' ? (raw === '' ? null : Number(raw)) : raw || null;
    if (parsed !== props.modelValue) {
        emit('save', parsed);
    }
}

function onSelect(val: string) {
    const emitVal = val === '__null__' ? null : val;
    if (emitVal !== String(props.modelValue ?? '')) {
        emit('save', emitVal);
    }
}

function onDateTimeChange(val: string | null) {
    if (val !== props.modelValue) {
        emit('save', val);
    }
}
</script>

<template>
    <td
        class="px-2 py-1 transition-colors duration-300"
        :class="{
            'bg-green-500/20': props.flash === 'success',
            'bg-red-500/20': props.flash === 'error',
        }"
    >
        <span v-if="type === 'readonly'" class="text-xs text-muted-foreground">{{ modelValue ?? '—' }}</span>

        <NativeSelect
            v-else-if="type === 'select'"
            :modelValue="String(modelValue ?? '__null__')"
            class="h-7 w-full truncate border-black/60 bg-black/20 px-2 py-0 pr-7 text-xs"
            @change="onSelect(($event.target as HTMLSelectElement).value)"
        >
            <NativeSelectOption v-if="nullable" value="__null__">—</NativeSelectOption>
            <NativeSelectOption v-for="opt in options" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </NativeSelectOption>
        </NativeSelect>

        <Switch
            v-else-if="type === 'switch'"
            :modelValue="!!modelValue"
            @update:modelValue="(val: boolean) => emit('save', val)"
        />

        <DateTimePicker
            v-else-if="type === 'datetime'"
            :modelValue="String(modelValue ?? '')"
            @update:modelValue="onDateTimeChange"
        />

        <Input
            v-else
            v-model="localValue"
            :type="type === 'number' ? 'number' : 'text'"
            class="h-7 text-xs"
            @blur="onBlur"
            @keydown.enter="($event.target as HTMLInputElement).blur()"
        />
    </td>
</template>
