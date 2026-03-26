<script setup lang="ts">
import { Calendar } from '@/components/ui/calendar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CalendarIcon } from 'lucide-vue-next';
import { CalendarDate, getLocalTimeZone, type DateValue } from '@internationalized/date';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string | null;
    placeholder?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const open = ref(false);

const hours = ref('00');
const minutes = ref('00');
const seconds = ref('00');
const calendarValue = ref<DateValue | undefined>(undefined);

function parseDateTime(value: string | null) {
    if (!value) return;

    const date = new Date(value);
    if (isNaN(date.getTime())) return;

    calendarValue.value = new CalendarDate(date.getFullYear(), date.getMonth() + 1, date.getDate());
    hours.value = String(date.getHours()).padStart(2, '0');
    minutes.value = String(date.getMinutes()).padStart(2, '0');
    seconds.value = String(date.getSeconds()).padStart(2, '0');
}

parseDateTime(props.modelValue);

watch(() => props.modelValue, (val) => parseDateTime(val));

const displayValue = computed(() => {
    if (!calendarValue.value) return props.placeholder ?? 'Pick date & time';

    const d = calendarValue.value;
    const day = String(d.day).padStart(2, '0');
    const month = String(d.month).padStart(2, '0');
    return `${day}/${month}/${d.year} ${hours.value}:${minutes.value}:${seconds.value}`;
});

function clampTimeValue(val: string, max: number): string {
    const num = parseInt(val, 10);
    if (isNaN(num)) return '00';
    return String(Math.min(Math.max(0, num), max)).padStart(2, '0');
}

function emitValue() {
    if (!calendarValue.value) {
        emit('update:modelValue', null);
        return;
    }

    const d = calendarValue.value;
    const h = clampTimeValue(hours.value, 23);
    const m = clampTimeValue(minutes.value, 59);
    const s = clampTimeValue(seconds.value, 59);

    hours.value = h;
    minutes.value = m;
    seconds.value = s;

    const year = d.year;
    const month = String(d.month).padStart(2, '0');
    const day = String(d.day).padStart(2, '0');

    emit('update:modelValue', `${year}-${month}-${day} ${h}:${m}:${s}`);
}

function onCalendarSelect(date: DateValue | undefined) {
    calendarValue.value = date;
    emitValue();
}

function clear() {
    calendarValue.value = undefined;
    hours.value = '00';
    minutes.value = '00';
    seconds.value = '00';
    emit('update:modelValue', null);
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger asChild>
            <Button
                variant="outline"
                :class="[
                    'justify-start text-left font-normal',
                    !calendarValue && 'text-muted-foreground',
                ]"
            >
                <CalendarIcon class="mr-2 h-4 w-4 shrink-0" />
                <span class="truncate">{{ displayValue }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="calendarValue"
                @update:model-value="onCalendarSelect"
                layout="month-and-year"
            />
            <div class="border-t border-border px-3 py-3">
                <div class="flex items-center gap-1">
                    <Input
                        v-model="hours"
                        class="h-8 w-12 text-center text-xs"
                        maxlength="2"
                        @blur="emitValue"
                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                    />
                    <span class="text-muted-foreground">:</span>
                    <Input
                        v-model="minutes"
                        class="h-8 w-12 text-center text-xs"
                        maxlength="2"
                        @blur="emitValue"
                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                    />
                    <span class="text-muted-foreground">:</span>
                    <Input
                        v-model="seconds"
                        class="h-8 w-12 text-center text-xs"
                        maxlength="2"
                        @blur="emitValue"
                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                    />
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-border px-3 py-2">
                <Button variant="ghost" size="sm" class="h-7 text-xs" @click="clear">Clear</Button>
                <Button size="sm" class="h-7 text-xs" @click="open = false">Done</Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
