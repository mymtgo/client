<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { customRange, customRangeLabel, customTimeframe, TIMEFRAME_OPTIONS } from '@/lib/timeframes';
import { getLocalTimeZone, today, type CalendarDate, type DateValue } from '@internationalized/date';
import { CalendarIcon, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const open = ref(false);
const from = ref<CalendarDate | undefined>();
const to = ref<CalendarDate | undefined>();

const isCustom = computed(() => customRange(props.modelValue) !== null);
const customLabel = computed(() => customRangeLabel(props.modelValue));

/** Opening the picker starts from the range on screen, or the last 30 days. */
watch(open, (isOpen) => {
    if (!isOpen) return;

    const range = customRange(props.modelValue);
    to.value = range?.end ?? today(getLocalTimeZone());
    from.value = range?.start ?? to.value.subtract({ days: 29 });
});

function clear() {
    open.value = false;
    emit('update:modelValue', 'alltime');
}

function apply() {
    if (!from.value || !to.value) return;

    emit('update:modelValue', customTimeframe(from.value, to.value));
    open.value = false;
}
</script>

<template>
    <div class="flex items-center gap-1">
        <SegmentedControl
            :model-value="isCustom ? '' : modelValue"
            :options="TIMEFRAME_OPTIONS"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="relative flex cursor-pointer items-center gap-1.5 rounded border border-black px-4 py-2 text-xs font-medium transition-all"
                    :class="
                        isCustom ? 'nav-item-active' : 'bevel border bg-background text-muted-foreground hover:text-foreground hover:brightness-125'
                    "
                >
                    <CalendarIcon class="size-3.5" />
                    {{ customLabel ?? 'Custom' }}
                    <span
                        v-if="isCustom"
                        role="button"
                        aria-label="Clear custom range"
                        class="-mr-2 rounded p-0.5 hover:bg-white/10"
                        @click.stop.prevent="clear"
                    >
                        <X class="size-3.5" />
                    </span>
                </button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <div class="flex flex-col divide-y divide-border sm:flex-row sm:divide-x sm:divide-y-0">
                    <div class="flex flex-col gap-1 pt-3">
                        <span class="px-3 text-xs font-medium text-muted-foreground">From</span>
                        <Calendar
                            :model-value="from"
                            @update:model-value="(value: DateValue | undefined) => (from = value as CalendarDate | undefined)"
                        />
                    </div>
                    <div class="flex flex-col gap-1 pt-3">
                        <span class="px-3 text-xs font-medium text-muted-foreground">To</span>
                        <Calendar
                            :model-value="to"
                            @update:model-value="(value: DateValue | undefined) => (to = value as CalendarDate | undefined)"
                        />
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-border px-3 py-2">
                    <Button v-if="isCustom" variant="ghost" size="sm" class="mr-auto h-7 text-xs" @click="clear">Clear</Button>
                    <Button variant="ghost" size="sm" class="h-7 text-xs" @click="open = false">Cancel</Button>
                    <Button size="sm" class="h-7 text-xs" :disabled="!from || !to" @click="apply">Apply</Button>
                </div>
            </PopoverContent>
        </Popover>
    </div>
</template>
