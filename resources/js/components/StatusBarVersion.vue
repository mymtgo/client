<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useInstallUpdate } from '@/composables/useInstallUpdate';
import { useUpdateStatus } from '@/composables/useUpdateStatus';

const props = withDefaults(defineProps<{ whatsNewUrl?: string | null }>(), { whatsNewUrl: null });

const update = useUpdateStatus();
const { installing, install } = useInstallUpdate();
</script>

<template>
    <button
        v-if="update.status === 'ready'"
        type="button"
        class="flex cursor-pointer items-center gap-1.5 rounded-sm bg-primary px-2 py-0.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
        :disabled="installing"
        @click="install"
    >
        Update ready: v{{ update.available }}
    </button>
    <span v-else-if="update.status === 'downloading'">Downloading update...</span>
    <Link v-else-if="props.whatsNewUrl" :href="props.whatsNewUrl" class="cursor-pointer hover:text-foreground">v{{ update.current }}</Link>
    <span v-else>v{{ update.current }}</span>
</template>
