<script setup lang="ts">
import UpdateAccountTrackingController from '@/actions/App/Http/Controllers/Settings/UpdateAccountTrackingController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import type { SettingsAccount } from '@/types/settings';

defineProps<{
    accounts: SettingsAccount[];
}>();

const { processing, send } = useSettingsRequest();

function toggleTracking(username: string, tracked: boolean) {
    send(`account-${username}`, 'patch', UpdateAccountTrackingController.url(), { username, tracked });
}
</script>

<template>
    <SettingsSection title="Accounts" description="Toggle tracking to control which accounts record match data.">
        <div v-for="account in accounts" :key="account.id" class="flex items-center justify-between">
            <div>
                <Label>
                    {{ account.username }}
                    <Badge v-if="account.active" variant="default" class="ml-1 text-xs">Active</Badge>
                </Label>
                <p class="text-sm text-muted-foreground">
                    {{ account.tracked ? 'Recording matches' : 'Not recording matches' }}
                </p>
            </div>
            <Switch
                :modelValue="account.tracked"
                @update:modelValue="(val: boolean) => toggleTracking(account.username, val)"
                :disabled="processing === `account-${account.username}`"
            />
        </div>
    </SettingsSection>
</template>
