<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <tr
    data-slot="table-row"
    :class="cn('hover:bg-muted data-[state=selected]:bg-muted border-b border-border transition-colors', props.class)"
  >
    <slot />
  </tr>
</template>
