<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Slider } from '@/components/ui/slider';
import { Sliders } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: number;
    max?: number;
}>(), {
    max: 200,
});

const emit = defineEmits<{
    'update:modelValue': [value: number];
    reset: [];
}>();

const sliderModel = computed<number[]>({
    get: () => [props.modelValue],
    set: (next) => {
        const v = next[0];
        if (typeof v === 'number') emit('update:modelValue', v);
    },
});
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="sm"
                class="bevel py-4 gap-1.5 border border-black/60 px-2.5 text-xs text-muted-foreground"
                data-testid="trust-popover-trigger"
            >
                <Sliders class="size-3.5" />
                <span class="hidden lg:inline">Trust</span>
                <span class="hidden lg:inline text-foreground/80 tabular-nums">{{ modelValue }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-72">
            <div class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm font-semibold">Games to trust</span>
                    <span class="text-xs text-muted-foreground tabular-nums">{{ modelValue }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    How many games a card needs before its win rate stops getting pulled toward the deck baseline.
                    Lower values trust small samples more; higher values keep flaky cards close to baseline.
                </p>
                <Slider
                    v-model="sliderModel"
                    :min="0"
                    :max="props.max"
                    :step="1"
                    data-testid="trust-slider"
                />
                <div class="flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>Trust raw data</span>
                    <span>Be skeptical</span>
                </div>
                <div class="flex justify-end">
                    <Button variant="ghost" size="sm" class="text-xs h-7 px-2" @click="emit('reset')">
                        Reset to 50
                    </Button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
