<script setup lang="ts">
import UpdateAutostartController from '@/actions/App/Http/Controllers/Settings/UpdateAutostartController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';

defineProps<{
    autostartEnabled: boolean;
    trayAvailable: boolean;
}>();

const { processing, send } = useSettingsRequest();

function toggleAutostart(val: boolean) {
    send('autostart', 'patch', UpdateAutostartController.url(), { enabled: val });
}
</script>

<template>
    <SettingsSection title="Background" description="Keep mymtgo running in the system tray and launch automatically when you sign in.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Launch at login</Label>
                <p class="text-sm text-muted-foreground">Start mymtgo automatically when your computer signs in.</p>
            </div>
            <Switch :modelValue="autostartEnabled" @update:modelValue="toggleAutostart" :disabled="processing === 'autostart'" />
        </div>
        <Separator />
        <div>
            <Label>Closing the window</Label>
            <p v-if="trayAvailable" class="text-sm text-muted-foreground">
                Closing the main window leaves mymtgo running in the system tray. Match ingestion continues in the background, so open it again from
                the tray icon.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Your operating system doesn't support a system tray for this app. Closing the main window will quit mymtgo and stop match ingestion.
            </p>
        </div>
    </SettingsSection>
</template>
