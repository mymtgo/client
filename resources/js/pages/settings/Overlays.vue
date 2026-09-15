<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DraftNotesCard from '@/components/settings/DraftNotesCard.vue';
import GameOverlayCard from '@/components/settings/GameOverlayCard.vue';
import LeagueWindowCard from '@/components/settings/LeagueWindowCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

defineOptions({ layout: [AppLayout, SettingsLayout] });

defineProps<{
    leagueWindowEnabled: boolean;
    gameOverlayEnabled: boolean;
    draftNotesWindowEnabled: boolean;
    overlayShowOpponent: boolean;
    overlayShowDrawOdds: boolean;
    overlayShowSideboard: boolean;
    overlayShowReveals: boolean;
    overlayBackgroundUrl: string | null;
}>();
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <LeagueWindowCard :enabled="leagueWindowEnabled" :background-url="overlayBackgroundUrl" />
        <GameOverlayCard
            :enabled="gameOverlayEnabled"
            :show-opponent="overlayShowOpponent"
            :show-draw-odds="overlayShowDrawOdds"
            :show-reveals="overlayShowReveals"
            :show-sideboard="overlayShowSideboard"
        />
        <DraftNotesCard :enabled="draftNotesWindowEnabled" />
    </div>
</template>
