<script setup lang="ts">
import UpdateOverlaySettingsController from '@/actions/App/Http/Controllers/Settings/UpdateOverlaySettingsController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useSettingsRequest } from '@/composables/useSettingsRequest';

type SectionKey = 'opponent' | 'draw_odds' | 'reveals' | 'sideboard';

const props = defineProps<{
    enabled: boolean;
    showOpponent: boolean;
    showDrawOdds: boolean;
    showReveals: boolean;
    showSideboard: boolean;
}>();

const { processing, send } = useSettingsRequest();

const sections: Array<{ key: SectionKey; label: string; value: () => boolean }> = [
    { key: 'opponent', label: 'Show opponent scout', value: () => props.showOpponent },
    { key: 'draw_odds', label: 'Show draw odds', value: () => props.showDrawOdds },
    { key: 'reveals', label: 'Show revealed cards', value: () => props.showReveals },
    { key: 'sideboard', label: 'Show sideboard guide', value: () => props.showSideboard },
];

function setEnabled(val: boolean) {
    send('gameOverlay', 'post', UpdateOverlaySettingsController.url(), { game_overlay: val });
}

function setSection(key: SectionKey, val: boolean) {
    send(`overlay-${key}`, 'post', UpdateOverlaySettingsController.url(), { [`overlay_show_${key}`]: val });
}
</script>

<template>
    <SettingsSection
        title="Game overlay"
        description="A floating panel during matches with your opponent's archetype, live draw odds, and your sideboard guide."
    >
        <div class="flex items-center justify-between">
            <div>
                <Label>Show game overlay</Label>
                <p class="text-sm text-muted-foreground">Opens when a match starts and closes when it ends.</p>
            </div>
            <Switch :modelValue="enabled" @update:modelValue="setEnabled" :disabled="processing === 'gameOverlay'" />
        </div>

        <div class="flex flex-col gap-3 pl-6">
            <div v-for="section in sections" :key="section.key" class="flex items-center justify-between">
                <Label :class="enabled ? '' : 'text-muted-foreground'">{{ section.label }}</Label>
                <Switch
                    :modelValue="section.value()"
                    @update:modelValue="(val: boolean) => setSection(section.key, val)"
                    :disabled="!enabled || processing === `overlay-${section.key}`"
                />
            </div>
        </div>
    </SettingsSection>
</template>
