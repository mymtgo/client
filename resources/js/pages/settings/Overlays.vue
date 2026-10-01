<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import type { OverlayState } from '@/components/leagues/LeagueOverlayCard.vue';
import DraftNotesCard from '@/components/settings/DraftNotesCard.vue';
import GameOverlayCard from '@/components/settings/GameOverlayCard.vue';
import LeagueWindowCard from '@/components/settings/LeagueWindowCard.vue';
import StreamOverlayCard from '@/components/settings/StreamOverlayCard.vue';
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
    overlayArtwork: 'deck' | 'none' | 'custom';
    overlaySize: 'full' | 'compact';
    overlayDeckArtUrl: string | null;
    overlayDeck: OverlayState['deck'];
    overlayPublish: boolean;
    overlayPublicUrl: string | null;
    overlayLastPublishedAt: string | null;
    overlayPublishError: string | null;
    overlayObsSizes: Record<'full' | 'compact', [number, number]>;
    syncLinked: boolean;
}>();
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <LeagueWindowCard
            :enabled="leagueWindowEnabled"
            :background-url="overlayBackgroundUrl"
            :artwork="overlayArtwork"
            :size="overlaySize"
            :deck-art-url="overlayDeckArtUrl"
            :deck="overlayDeck"
        />
        <StreamOverlayCard
            :enabled="overlayPublish"
            :url="overlayPublicUrl"
            :size="overlaySize"
            :obs-sizes="overlayObsSizes"
            :last-published-at="overlayLastPublishedAt"
            :error="overlayPublishError"
            :linked="syncLinked"
        />
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
