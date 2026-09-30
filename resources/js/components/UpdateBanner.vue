<script setup lang="ts">
import { Download } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { useInstallUpdate } from '@/composables/useInstallUpdate';
import { useUpdateStatus } from '@/composables/useUpdateStatus';

const update = useUpdateStatus();
const { installing, install } = useInstallUpdate();
</script>

<template>
    <div
        v-if="update.status === 'ready'"
        class="flex h-9 shrink-0 items-center justify-between gap-4 border-b border-black/80 bg-primary/15 px-4 text-sm"
    >
        <div class="flex items-center gap-2">
            <Download :size="14" class="text-primary" />
            <span>Update v{{ update.available }} is ready.</span>
        </div>
        <Button size="sm" class="cursor-pointer" :disabled="installing" @click="install">Install & restart</Button>
    </div>
</template>
