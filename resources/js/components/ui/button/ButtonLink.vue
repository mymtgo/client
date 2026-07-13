<script setup lang="ts">
import type { InertiaLinkProps } from "@inertiajs/vue3"
import type { HTMLAttributes } from "vue"
import type { ButtonVariants } from "."
import { Link } from "@inertiajs/vue3"
import { cn } from "@/lib/utils"
import { buttonVariants } from "."

interface Props extends /* @vue-ignore */ InertiaLinkProps {
  variant?: ButtonVariants["variant"]
  size?: ButtonVariants["size"]
  class?: HTMLAttributes["class"]
}

const props = withDefaults(defineProps<Props>(), {
  variant: "outline",
})
</script>

<template>
  <Link
    data-slot="button"
    :data-variant="props.variant"
    :data-size="props.size"
    :class="cn(buttonVariants({ variant: props.variant, size: props.size }), props.class)"
  >
    <slot />
  </Link>
</template>
