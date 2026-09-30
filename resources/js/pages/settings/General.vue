<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import WhatsNewController from '@/actions/App/Http/Controllers/WhatsNewController';
import AppLayout from '@/AppLayout.vue';
import AccountsCard from '@/components/settings/AccountsCard.vue';
import BackgroundCard from '@/components/settings/BackgroundCard.vue';
import HelperCard from '@/components/settings/HelperCard.vue';
import UpdatesCard from '@/components/settings/UpdatesCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { HelperStatus, SettingsAccount } from '@/types/settings';

defineOptions({ layout: [AppLayout, SettingsLayout] });

defineProps<{
    accounts: SettingsAccount[];
    autostartEnabled: boolean;
    trayAvailable: boolean;
    helper: HelperStatus | null;
}>();

const page = usePage();
const whatsNewUrl = computed(() => (page.props.whatsNewAvailable ? WhatsNewController.url() : null));
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <UpdatesCard :whats-new-url="whatsNewUrl" />
        <AccountsCard v-if="accounts.length" :accounts="accounts" />
        <BackgroundCard :autostart-enabled="autostartEnabled" :tray-available="trayAvailable" />
        <HelperCard v-if="helper" :helper="helper" />
    </div>
</template>
