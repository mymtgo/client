<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from 'lucide-vue-next';

defineProps<{
    href: string;
    label: string;
}>();
</script>

<template>
    <Link :href="href" class="inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground">
        <ChevronLeft class="size-4" />
        {{ label }}
    </Link>
</template>
