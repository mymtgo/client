<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { SwitchRoot, SwitchThumb, useForwardPropsEmits } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes['class'] }>();

const emits = defineEmits<SwitchRootEmits>();

const delegatedProps = reactiveOmit(props, 'class');

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <SwitchRoot
        v-slot="slotProps"
        data-slot="switch"
        v-bind="forwarded"
        :class="
            cn(
                'peer relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-black/70 bg-black/50 shadow-[inset_0_1px_2px_rgba(0,0,0,0.55)] transition-colors outline-none',
                'focus-visible:border-ring focus-visible:ring-[1px] focus-visible:ring-ring/30',
                'disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )
        "
    >
        <SwitchThumb
            data-slot="switch-thumb"
            :class="
                cn(
                    'pointer-events-none ml-0.5 block size-4 rounded-full bevel ring-1 ring-black/50 transition-[translate,background-color,box-shadow] duration-200 ease-out',
                    'bg-gradient-to-b from-zinc-400 to-zinc-500 shadow-[0_1px_2px_rgba(0,0,0,0.45)]',
                    'data-[state=checked]:translate-x-[14px] data-[state=unchecked]:translate-x-0',
                    'data-[state=checked]:from-green-400 data-[state=checked]:to-green-600 data-[state=checked]:ring-green-700/60',
                    'data-[state=checked]:shadow-[0_0_0_3px_rgba(34,197,94,0.15),0_0_8px_rgba(34,197,94,0.5)]',
                )
            "
        >
            <slot name="thumb" v-bind="slotProps" />
        </SwitchThumb>
    </SwitchRoot>
</template>
