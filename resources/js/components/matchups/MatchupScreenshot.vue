<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import type { MatchupDetail, MatchupSpread, PerGameWinrate } from '@/types/decks';

const props = defineProps<{
    matchup: MatchupSpread;
    detail: MatchupDetail;
    timeframeLabel: string;
}>();

const colors = {
    bg: '#111111',
    text: '#ffffff',
    muted: 'rgba(255, 255, 255, 0.5)',
    subtle: 'rgba(255, 255, 255, 0.04)',
    win: '#22c55e',
    loss: '#ef4444',
    border: 'rgba(255, 255, 255, 0.08)',
};

function rateColor(rate: number): string {
    if (rate > 50) return colors.win;
    if (rate < 50) return colors.loss;
    return colors.text;
}

function gameAt(num: number): PerGameWinrate | null {
    return props.detail.perGameWinrates.find((g) => g.gameNumber === num) ?? null;
}

function format1dp(value: number | null): string {
    return value === null ? '—' : value.toFixed(1);
}
</script>

<template>
    <div
        :style="{
            width: '480px',
            backgroundColor: colors.bg,
            color: colors.text,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            padding: '18px 22px',
            borderRadius: '12px',
            position: 'relative',
            overflow: 'hidden',
        }"
    >
        <!-- Header -->
        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }">
            <div>
                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                    <span :style="{ fontSize: '17px', fontWeight: '700', lineHeight: '1.2' }">{{ matchup.name }}</span>
                    <ManaSymbols
                        v-if="matchup.color_identity"
                        :symbols="matchup.color_identity"
                        class="[&_svg]:w-3"
                        :style="{ display: 'flex', gap: '1px' }"
                    />
                </div>
                <div :style="{ fontSize: '12px', color: colors.muted, marginTop: '2px' }">
                    {{ matchup.matches }} matches · {{ timeframeLabel }}
                </div>
            </div>
            <div :style="{ textAlign: 'right' }">
                <div :style="{ fontSize: '24px', fontWeight: '700', lineHeight: '1', color: rateColor(matchup.match_winrate), fontVariantNumeric: 'tabular-nums' }">
                    {{ matchup.match_winrate }}%
                </div>
                <div :style="{ fontSize: '12px', color: colors.muted, marginTop: '2px', fontVariantNumeric: 'tabular-nums' }">
                    {{ matchup.match_record }}
                </div>
            </div>
        </div>

        <div :style="{ height: '1px', backgroundColor: colors.border, margin: '12px 0' }" />

        <!-- Per-game grid -->
        <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '8px' }">
            <div
                v-for="num in [1, 2, 3]"
                :key="num"
                :style="{ backgroundColor: colors.subtle, padding: '10px', borderRadius: '6px', textAlign: 'center' }"
            >
                <div :style="{ fontSize: '11px', color: colors.muted }">Game {{ num }}</div>
                <template v-if="gameAt(num)">
                    <div :style="{ fontSize: '18px', fontWeight: '600', marginTop: '4px', color: rateColor(gameAt(num)!.winrate), fontVariantNumeric: 'tabular-nums' }">
                        {{ gameAt(num)!.winrate }}%
                    </div>
                    <div :style="{ fontSize: '11px', color: colors.muted, fontVariantNumeric: 'tabular-nums' }">
                        {{ gameAt(num)!.record }}
                    </div>
                </template>
                <template v-else>
                    <div :style="{ fontSize: '18px', fontWeight: '600', marginTop: '4px', color: colors.muted }">—</div>
                    <div :style="{ fontSize: '11px', color: colors.muted }">—</div>
                </template>
            </div>
        </div>

        <div :style="{ height: '1px', backgroundColor: colors.border, margin: '12px 0' }" />

        <!-- Bottom big stats -->
        <div :style="{ display: 'flex' }">
            <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '8px 0', flex: '1' }">
                <div :style="{ fontSize: '22px', fontWeight: '700', color: rateColor(detail.otpWinrate), fontVariantNumeric: 'tabular-nums' }">
                    {{ detail.otpWinrate }}%
                </div>
                <div :style="{ fontSize: '10px', textTransform: 'uppercase', letterSpacing: '0.05em', color: colors.muted, marginTop: '2px' }">OTP</div>
            </div>
            <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '8px 0', flex: '1' }">
                <div :style="{ fontSize: '22px', fontWeight: '700', color: colors.text, fontVariantNumeric: 'tabular-nums' }">
                    {{ format1dp(detail.avgTurns) }}
                </div>
                <div :style="{ fontSize: '10px', textTransform: 'uppercase', letterSpacing: '0.05em', color: colors.muted, marginTop: '2px' }">Avg turns</div>
            </div>
            <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '8px 0', flex: '1' }">
                <div :style="{ fontSize: '22px', fontWeight: '700', color: colors.text, fontVariantNumeric: 'tabular-nums' }">
                    {{ format1dp(detail.avgMulligans) }}
                </div>
                <div :style="{ fontSize: '10px', textTransform: 'uppercase', letterSpacing: '0.05em', color: colors.muted, marginTop: '2px' }">Avg mulls</div>
            </div>
        </div>

        <div :style="{ marginTop: '12px', textAlign: 'right', fontSize: '10px', color: colors.muted }">mymtgo.com</div>
    </div>
</template>
