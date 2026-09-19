<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import SyncCard from '@/components/settings/SyncCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

defineOptions({ layout: [AppLayout, SettingsLayout] });
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <SyncCard />
    </div>
</template>
