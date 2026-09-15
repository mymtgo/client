<script setup lang="ts">
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';

defineProps<{
    enabled: boolean;
}>();

const { processing, send } = useSettingsRequest();

function setEnabled(val: boolean) {
    send('draftNotesWindow', 'post', UpdateOverlaySettingsController.url(), { draft_notes_window: val });
}
</script>

<template>
    <SettingsSection title="Draft notes window" description="A small always-on-top window shown while you draft.">
        <div class="flex items-center justify-between">
            <div>
                <Label>Show draft notes window</Label>
                <p class="text-sm text-muted-foreground">Shows the current pick, its timer, and a place to jot why you took the card.</p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="setEnabled" :disabled="processing === 'draftNotesWindow'" />
        </div>
    </SettingsSection>
</template>
