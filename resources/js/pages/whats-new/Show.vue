<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ html: string }>();
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col px-6 py-8">
        <Head title="What's new" />
        <!-- Content is our own bundled markdown, rendered server-side. -->
        <article class="markdown-content" v-html="html" />
    </div>
</template>
