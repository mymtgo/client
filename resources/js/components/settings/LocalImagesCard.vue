<script setup lang="ts">
import UpdateLocalImagesController from '@/actions/App/Http/Controllers/Settings/UpdateLocalImagesController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';

defineProps<{
    enabled: boolean;
    usage: string;
}>();

const { processing, send } = useSettingsRequest();

function toggle(val: boolean) {
    send('localImages', 'patch', UpdateLocalImagesController.url(), { enabled: val });
}
</script>

<template>
    <SettingsSection title="Card images" description="Manage how card imagery is stored on your machine.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Download card images locally</Label>
                <p class="text-sm text-muted-foreground">
                    Save card imagery to your machine for speed and offline use. This will increase disk usage.
                </p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="toggle" :disabled="processing === 'localImages'" />
        </div>
        <p class="text-sm text-muted-foreground">Current usage: {{ usage }}</p>
    </SettingsSection>
</template>
