<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import AccountsCard from '@/components/settings/AccountsCard.vue';
import BackgroundCard from '@/components/settings/BackgroundCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { SettingsAccount } from '@/types/settings';

defineOptions({ layout: [AppLayout, SettingsLayout] });

defineProps<{
    accounts: SettingsAccount[];
    autostartEnabled: boolean;
    trayAvailable: boolean;
}>();
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <AccountsCard v-if="accounts.length" :accounts="accounts" />
        <BackgroundCard :autostart-enabled="autostartEnabled" :tray-available="trayAvailable" />
    </div>
</template>
