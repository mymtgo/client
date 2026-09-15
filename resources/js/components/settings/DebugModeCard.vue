<script setup lang="ts">
import UpdateDebugModeController from '@/actions/App/Http/Controllers/Settings/UpdateDebugModeController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';

defineProps<{
    enabled: boolean;
}>();

const { processing, send } = useSettingsRequest();

function toggle(val: boolean) {
    send('debugMode', 'patch', UpdateDebugModeController.url(), { enabled: val });
}
</script>

<template>
    <SettingsSection title="Debug Mode" description="This will add more menu items and editing capabilities, use at your own risk.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Enable debug mode</Label>
                <p class="text-sm text-muted-foreground">Access raw database tables for matches, games, and log events.</p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="toggle" :disabled="processing === 'debugMode'" />
        </div>
    </SettingsSection>
</template>
