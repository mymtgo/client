<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string | number | boolean | null;
    type?: 'text' | 'number' | 'select' | 'readonly';
    options?: Array<{ label: string; value: string }>;
    nullable?: boolean;
}>();

const emit = defineEmits<{
    save: [value: string | number | boolean | null];
}>();

const flash = ref<'success' | 'error' | null>(null);

function flashCell(state: 'success' | 'error') {
    flash.value = state;
    setTimeout(() => (flash.value = null), 1000);
}

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

defineExpose({ flashCell });
</script>

<template>
    <td
        class="px-2 py-1 transition-colors duration-300"
        :class="{
            'bg-green-500/20': flash === 'success',
            'bg-red-500/20': flash === 'error',
        }"
    >
        <span v-if="type === 'readonly'" class="text-xs text-muted-foreground">{{ modelValue ?? '—' }}</span>

        <Select v-else-if="type === 'select'" :modelValue="String(modelValue ?? '__null__')" @update:modelValue="onSelect">
            <SelectTrigger class="h-7 text-xs">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-if="nullable" value="__null__">—</SelectItem>
                <SelectItem v-for="opt in options" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </SelectItem>
            </SelectContent>
        </Select>

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
