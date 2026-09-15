<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import FilePathsCard from '@/components/settings/FilePathsCard.vue';
import LocalImagesCard from '@/components/settings/LocalImagesCard.vue';
import WatcherCard from '@/components/settings/WatcherCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { PathStatus } from '@/types/settings';
import { computed } from 'vue';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const props = defineProps<{
    logPath: string;
    dataPath: string;
    logPathStatus: PathStatus;
    dataPathStatus: PathStatus;
    watcherActive: boolean;
    localImages: boolean;
    localImagesSize: string;
}>();

const pathsValid = computed(() => props.logPathStatus.valid && props.dataPathStatus.valid);
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <LocalImagesCard :enabled="localImages" :usage="localImagesSize" />
        <FilePathsCard :log-path="logPath" :data-path="dataPath" :log-path-status="logPathStatus" :data-path-status="dataPathStatus" />
        <WatcherCard :active="watcherActive" :paths-valid="pathsValid" />
    </div>
</template>
