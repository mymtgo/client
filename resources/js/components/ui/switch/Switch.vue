<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { Tally4 } from "lucide-vue-next"
import {
  SwitchRoot,
  SwitchThumb,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes["class"] }>()

const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SwitchRoot
    v-slot="slotProps"
    data-slot="switch"
    v-bind="forwarded"
    :class="cn(
      'peer relative inline-flex h-7 w-12 shrink-0 items-center rounded-md border border-black/60 bg-black/20 p-0.5 shadow-xs shadow-white/5 transition-[color,box-shadow] outline-none',
      'focus-visible:border-ring focus-visible:ring-ring/10 focus-visible:ring-[1px]',
      'disabled:cursor-not-allowed disabled:opacity-50',
      props.class,
    )"
  >
    <SwitchThumb
      data-slot="switch-thumb"
      :class="cn(
        'pointer-events-none flex size-6 items-center justify-center rounded-sm bg-black/40 ring-0 transition-transform',
        'data-[state=checked]:translate-x-[calc(100%-4px)] data-[state=unchecked]:translate-x-0',
      )"
    >
      <Tally4
        class="switch-bars size-4 transition-[color,filter] duration-150"
        :stroke-width="2.5"
      />
      <slot name="thumb" v-bind="slotProps" />
    </SwitchThumb>
  </SwitchRoot>
</template>

<style scoped>
[data-slot="switch"][data-state="checked"] .switch-bars {
  color: #4ade80;
  filter: drop-shadow(0 0 4px rgba(74, 222, 128, 0.7)) drop-shadow(0 0 8px rgba(74, 222, 128, 0.35));
}

[data-slot="switch"][data-state="unchecked"] .switch-bars {
  color: rgba(255, 255, 255, 0.2);
}
</style>
