<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import type { LeagueRun } from '@/types/leagues';

defineProps<{
    league: LeagueRun;
}>();

const colors = {
    bg: '#111111',
    text: '#ffffff',
    muted: 'rgba(255, 255, 255, 0.5)',
    win: '#22c55e',
    loss: '#ef4444',
    border: 'rgba(255, 255, 255, 0.1)',
};
</script>

<template>
    <div
        :style="{
            width: '520px',
            backgroundColor: colors.bg,
            color: colors.text,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            padding: '20px 24px',
            borderRadius: '12px',
        }"
    >
        <!-- Header -->
        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }">
            <div>
                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                    <span :style="{ fontSize: '16px', fontWeight: '700', lineHeight: '1.2' }">
                        {{ league.deck?.name ?? 'Unknown Deck' }}
                    </span>
                    <ManaSymbols v-if="league.deck?.colorIdentity" :symbols="league.deck.colorIdentity" class="[&_svg]:w-3" :style="{ display: 'flex', gap: '1px' }" />
                </div>
                <div :style="{ fontSize: '12px', color: colors.muted, marginTop: '2px' }">
                    {{ league.format }}
                </div>
            </div>
            <div :style="{ fontSize: '16px', fontWeight: '700', fontVariantNumeric: 'tabular-nums', paddingTop: '2px' }">
                {{ league.results.filter((r) => r === 'W').length }}-{{ league.results.filter((r) => r === 'L').length }}
            </div>
        </div>

        <!-- Matchup rows -->
        <div :style="{ marginTop: '14px', fontSize: '12px' }">
            <div
                v-for="(match, index) in league.matches"
                :key="match.id"
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '6px 8px',
                    borderRadius: '4px',
                    backgroundColor: index % 2 === 0 ? 'rgba(255, 255, 255, 0.04)' : 'transparent',
                }"
            >
                <div :style="{ color: match.result === 'W' ? colors.win : colors.loss, fontWeight: '600', width: '50px', flexShrink: '0' }">
                    ● {{ match.result === 'W' ? 'Win' : 'Loss' }}
                </div>
                <div :style="{ width: '100px', flexShrink: '0', fontWeight: '500' }">
                    {{ match.opponentName ?? '—' }}
                </div>
                <div :style="{ flex: '1', color: match.opponentArchetype ? colors.text : colors.muted }">
                    {{ match.opponentArchetype ?? 'Unknown' }}
                </div>
                <div :style="{ display: 'flex', gap: '4px', alignItems: 'center', flexShrink: '0' }">
                    <div
                        v-for="(game, i) in match.gameResults"
                        :key="i"
                        :style="{
                            width: '8px',
                            height: '8px',
                            borderRadius: '50%',
                            backgroundColor: game.result === 'W' ? colors.win : colors.loss,
                        }"
                    />
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div :style="{ marginTop: '12px', textAlign: 'right', fontSize: '10px', color: colors.muted }">mymtgo.com</div>
    </div>
</template>
