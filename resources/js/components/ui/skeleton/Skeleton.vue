<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

interface SkeletonProps {
  class?: HTMLAttributes["class"]
}

const props = defineProps<SkeletonProps>()
</script>

<template>
  <div
    data-slot="skeleton"
    :class="cn('animate-shimmer rounded-sm bg-[linear-gradient(90deg,var(--muted)_25%,var(--secondary)_45%,var(--muted)_65%)] bg-[size:300%_100%] motion-reduce:animate-none', props.class)"
  />
</template>
