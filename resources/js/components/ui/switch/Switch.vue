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
                'peer relative inline-flex h-[22px] w-[38px] shrink-0 items-center rounded-full transition-colors outline-none',
                'data-[state=checked]:bg-win data-[state=unchecked]:bg-secondary',
                'focus-visible:ring-[3px] focus-visible:ring-primary-soft',
                'disabled:cursor-not-allowed disabled:opacity-50',
                props.class,
            )
        "
    >
        <SwitchThumb
            data-slot="switch-thumb"
            :class="
                cn(
                    'pointer-events-none ml-0.5 block size-[18px] rounded-full bg-white transition-[translate] duration-200 ease-out',
                    'data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0',
                )
            "
        >
            <slot name="thumb" v-bind="slotProps" />
        </SwitchThumb>
    </SwitchRoot>
</template>
