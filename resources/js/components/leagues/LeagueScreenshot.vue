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
            width: '720px',
            backgroundColor: colors.bg,
            color: colors.text,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            padding: '20px 24px',
            borderRadius: '12px',
            position: 'relative',
            overflow: 'hidden',
        }"
    >
        <!-- Cover art background (base64 from server for html-to-image compatibility) -->
        <img
            v-if="league.deck?.coverArtBase64"
            :src="league.deck.coverArtBase64"
            :style="{
                position: 'absolute',
                top: '0',
                left: '0',
                width: '100%',
                height: '100%',
                objectFit: 'cover',
                objectPosition: 'top',
                opacity: '0.25',
                pointerEvents: 'none',
            }"
        />
        <!-- Content (above background) -->
        <div :style="{ position: 'relative' }">
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
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '6px 8px',
                    color: colors.muted,
                    fontSize: '10px',
                    textTransform: 'uppercase',
                    letterSpacing: '0.05em',
                }"
            >
                <div :style="{ width: '24px', flexShrink: '0' }">#</div>
                <div :style="{ width: '50px', flexShrink: '0' }">Result</div>
                <div :style="{ width: '100px', flexShrink: '0' }">Opponent</div>
                <div :style="{ flex: '1' }">Vs</div>
                <div :style="{ width: '80px', flexShrink: '0', textAlign: 'center' }">Game 1</div>
                <div :style="{ width: '80px', flexShrink: '0', textAlign: 'center' }">Game 2</div>
                <div :style="{ width: '80px', flexShrink: '0', textAlign: 'center' }">Game 3</div>
                <div :style="{ width: '40px', flexShrink: '0', textAlign: 'right' }">Time</div>
            </div>
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
                <div :style="{ color: colors.muted, fontWeight: '500', width: '24px', flexShrink: '0', fontVariantNumeric: 'tabular-nums' }">
                    {{ index + 1 }}
                </div>
                <div :style="{ color: match.result === 'W' ? colors.win : colors.loss, fontWeight: '600', width: '50px', flexShrink: '0' }">
                    {{ match.result === 'W' ? 'Win' : 'Loss' }}
                </div>
                <div :style="{ width: '100px', flexShrink: '0', fontWeight: '500' }">
                    {{ match.opponentName ?? '—' }}
                </div>
                <div :style="{ flex: '1', color: match.opponentArchetype ? colors.text : colors.muted, paddingRight: '8px' }">
                    {{ match.opponentArchetype ?? 'Unknown' }}
                </div>
                <template v-for="i in 3" :key="i">
                    <div :style="{ width: '80px', flexShrink: '0', textAlign: 'center' }">
                        <template v-if="match.gameResults[i - 1]">
                            <span :style="{ color: match.gameResults[i - 1].result === 'W' ? colors.win : colors.loss, fontWeight: '600' }">
                                {{ match.gameResults[i - 1].result === 'W' ? 'Win' : 'Loss' }}
                            </span>
                            <span v-if="match.gameResults[i - 1].onPlay !== null" :style="{ color: colors.muted, marginLeft: '4px', fontSize: '10px' }">
                                ({{ match.gameResults[i - 1].onPlay ? 'OTP' : 'OTD' }})
                            </span>
                        </template>
                        <span v-else :style="{ color: colors.muted }">—</span>
                    </div>
                </template>
                <div :style="{ width: '40px', flexShrink: '0', textAlign: 'right', color: colors.muted, fontVariantNumeric: 'tabular-nums' }">
                    {{ match.durationSeconds ? `${Math.round(match.durationSeconds / 60)}m` : '—' }}
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div :style="{ marginTop: '12px', textAlign: 'right', fontSize: '10px', color: colors.muted }">mymtgo.com</div>
        </div>
    </div>
</template>
