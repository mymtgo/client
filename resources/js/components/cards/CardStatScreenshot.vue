<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';
import { type CardStatsVisibility } from '@/pages/decks/partials/cardStatsColumns';
import { computed } from 'vue';

type CardStat = {
    name: string;
    oracleId: string;
    colorIdentity: string | null;
    type: string | null;
    image: string | null;
    isSideboard: boolean;
    totalGames: number;
    totalPossible: number;
    totalKept: number;
    keptGames: number;
    keptWon: number;
    keptLost: number;
    totalSeen: number;
    seenGames: number;
    seenWon: number;
    seenLost: number;
    totalCast: number;
    castGames: number;
    castWon: number;
    castLost: number;
    postboardGames: number;
    sidedOutGames: number;
    sidedInGames: number;
    totalPlayed: number;
    playedGames: number;
    totalKicked: number;
    totalActivated: number;
    totalFlashback: number;
    totalMadness: number;
    totalEvoked: number;
    pregameRevealedGames: number;
    pregamePlayedGames: number;
    pregameGames: number;
    pregameWon: number;
    pregameLost: number;
};

const props = defineProps<{
    stat: CardStat;
    visibleColumns: CardStatsVisibility;
    timeframeLabel: string;
    archetypeName?: string | null;
    archetypeColorIdentity?: string | null;
    boardLabel?: string | null;
    playDrawLabel?: string | null;
    imageDataUrl?: string | null;
}>();

const imageSrc = computed<string | null>(() => props.imageDataUrl ?? props.stat.image ?? null);

const colors = {
    bg: '#111111',
    text: '#ffffff',
    muted: 'rgba(255, 255, 255, 0.5)',
    subtle: 'rgba(255, 255, 255, 0.04)',
    win: '#22c55e',
    loss: '#ef4444',
    border: 'rgba(255, 255, 255, 0.08)',
};

type Tone = 'good' | 'bad' | 'neutral';
type Block = { label: string; value: string; sub?: string; tone?: Tone };

function pct(num: number, denom: number): number | null {
    return denom > 0 ? Math.round((num / denom) * 100) : null;
}

function toneFor(p: number | null): Tone {
    if (p === null) return 'neutral';
    if (p > 55) return 'good';
    if (p < 45) return 'bad';
    return 'neutral';
}

function toneColor(tone: Tone | undefined): string {
    if (tone === 'good') return colors.win;
    if (tone === 'bad') return colors.loss;
    return colors.text;
}

const blocks = computed<Block[]>(() => {
    const s = props.stat;
    const cols = props.visibleColumns;
    const out: Block[] = [];

    if (cols.keptPct) {
        const p = pct(s.keptGames, s.totalGames);
        out.push({ label: 'Kept', value: p === null ? '—' : `${p}%`, sub: `(${s.keptGames})` });
    }
    if (cols.keptWinPct) {
        const sample = s.keptWon + s.keptLost;
        const p = pct(s.keptWon, sample);
        out.push({ label: 'Kept Win', value: p === null ? '—' : `${p}%`, sub: `(${sample})`, tone: toneFor(p) });
    }
    if (cols.castPct) {
        const p = pct(s.castGames, s.totalGames);
        out.push({ label: 'Cast', value: p === null ? '—' : `${p}%`, sub: `(${s.castGames})` });
    }
    if (cols.castWinPct) {
        const sample = s.castWon + s.castLost;
        const p = pct(s.castWon, sample);
        out.push({ label: 'Cast Win', value: p === null ? '—' : `${p}%`, sub: `(${sample})`, tone: toneFor(p) });
    }
    if (cols.playedPct) {
        const p = pct(s.playedGames, s.totalGames);
        out.push({ label: 'Played', value: p === null ? '—' : `${p}%`, sub: `(${s.playedGames})` });
    }
    if (cols.kicked) {
        out.push({ label: 'Kicked', value: s.totalKicked > 0 ? String(s.totalKicked) : '—' });
    }
    if (cols.activated) {
        out.push({ label: 'Activated', value: s.totalActivated > 0 ? String(s.totalActivated) : '—' });
    }
    if (cols.pregamePct) {
        const p = pct(s.pregameGames, s.totalGames);
        out.push({ label: 'Pregame', value: p === null || s.pregameGames === 0 ? '—' : `${p}%`, sub: s.pregameGames > 0 ? `(${s.pregameGames})` : undefined });
    }
    if (cols.pregameWinPct) {
        const sample = s.pregameWon + s.pregameLost;
        const p = pct(s.pregameWon, sample);
        out.push({ label: 'Pregame Win', value: p === null ? '—' : `${p}%`, sub: `(${sample})`, tone: toneFor(p) });
    }
    if (cols.seenPct) {
        const p = pct(s.seenGames, s.totalGames);
        out.push({ label: 'Seen', value: p === null ? '—' : `${p}%`, sub: `(${s.seenGames})` });
    }
    if (cols.seenWinPct) {
        const sample = s.seenWon + s.seenLost;
        const p = pct(s.seenWon, sample);
        out.push({ label: 'Seen Win', value: p === null ? '—' : `${p}%`, sub: `(${sample})`, tone: toneFor(p) });
    }
    if (cols.sbOutPct) {
        const p = pct(s.sidedOutGames, s.postboardGames);
        out.push({ label: 'SB Out', value: p === null ? '—' : `${p}%`, sub: `(${s.sidedOutGames})` });
    }
    if (cols.sbInPct) {
        const p = pct(s.sidedInGames, s.postboardGames);
        out.push({ label: 'SB In', value: p === null ? '—' : `${p}%`, sub: `(${s.sidedInGames})` });
    }
    if (cols.games) {
        out.push({ label: 'Games', value: String(s.totalGames) });
    }

    return out;
});

const contextChips = computed<string[]>(() => {
    const chips: string[] = [];
    if (props.boardLabel) chips.push(props.boardLabel);
    if (props.playDrawLabel) chips.push(props.playDrawLabel);
    chips.push(props.timeframeLabel);
    return chips;
});
</script>

<template>
    <div
        :style="{
            width: '640px',
            backgroundColor: colors.bg,
            color: colors.text,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            padding: '20px 22px',
            borderRadius: '12px',
            position: 'relative',
            overflow: 'hidden',
        }"
    >
        <!-- Header -->
        <div :style="{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '16px' }">
            <div :style="{ minWidth: 0, flex: 1 }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }">
                    <span :style="{ fontSize: '20px', fontWeight: '700', lineHeight: '1.2' }">{{ stat.name }}</span>
                    <ManaSymbols
                        v-if="stat.colorIdentity"
                        :symbols="stat.colorIdentity"
                        class="[&_svg]:w-3.5"
                        :style="{ display: 'flex', gap: '1px' }"
                    />
                    <span
                        v-if="stat.isSideboard"
                        :style="{
                            fontSize: '9px',
                            fontWeight: '600',
                            letterSpacing: '0.08em',
                            textTransform: 'uppercase',
                            padding: '2px 6px',
                            borderRadius: '4px',
                            backgroundColor: colors.subtle,
                            color: colors.muted,
                        }"
                    >Sideboard</span>
                </div>
                <div v-if="stat.type" :style="{ fontSize: '11px', color: colors.muted, marginTop: '2px' }">
                    {{ stat.type }}
                </div>
            </div>
            <div
                v-if="archetypeName"
                :style="{ textAlign: 'right', flexShrink: 0 }"
            >
                <div :style="{ fontSize: '9px', fontWeight: '600', letterSpacing: '0.08em', textTransform: 'uppercase', color: colors.muted }">
                    Versus
                </div>
                <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '6px', marginTop: '3px' }">
                    <span :style="{ fontSize: '14px', fontWeight: '600', lineHeight: '1.2' }">{{ archetypeName }}</span>
                    <ManaSymbols
                        v-if="archetypeColorIdentity"
                        :symbols="archetypeColorIdentity"
                        class="[&_svg]:w-3.5"
                        :style="{ display: 'flex', gap: '1px' }"
                    />
                </div>
            </div>
        </div>

        <!-- Context chips -->
        <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '10px' }">
            <span
                v-for="(chip, i) in contextChips"
                :key="i"
                :style="{
                    fontSize: '10px',
                    fontWeight: '500',
                    letterSpacing: '0.04em',
                    textTransform: 'uppercase',
                    color: colors.muted,
                    backgroundColor: colors.subtle,
                    padding: '3px 8px',
                    borderRadius: '999px',
                }"
            >{{ chip }}</span>
        </div>

        <div :style="{ height: '1px', backgroundColor: colors.border, margin: '14px 0' }" />

        <!-- Body: image + stat blocks -->
        <div :style="{ display: 'flex', gap: '16px', alignItems: 'flex-start' }">
            <div
                :style="{
                    width: '170px',
                    flexShrink: 0,
                    aspectRatio: '488 / 680',
                    borderRadius: '10px',
                    overflow: 'hidden',
                    backgroundColor: colors.subtle,
                }"
            >
                <img
                    v-if="imageSrc"
                    :src="imageSrc"
                    :alt="stat.name"
                    :style="{ width: '100%', height: '100%', display: 'block', objectFit: 'cover' }"
                />
            </div>

            <div
                :style="{
                    flex: 1,
                    display: 'grid',
                    gridTemplateColumns: 'repeat(3, 1fr)',
                    gap: '6px',
                }"
            >
                <div
                    v-for="(block, i) in blocks"
                    :key="i"
                    :style="{
                        backgroundColor: colors.subtle,
                        padding: '8px 10px',
                        borderRadius: '6px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '2px',
                    }"
                >
                    <div :style="{ fontSize: '9px', fontWeight: '600', letterSpacing: '0.06em', textTransform: 'uppercase', color: colors.muted }">
                        {{ block.label }}
                    </div>
                    <div :style="{ display: 'flex', alignItems: 'baseline', gap: '4px' }">
                        <span :style="{ fontSize: '17px', fontWeight: '700', color: toneColor(block.tone), fontVariantNumeric: 'tabular-nums', lineHeight: '1.1' }">
                            {{ block.value }}
                        </span>
                        <span v-if="block.sub" :style="{ fontSize: '10px', color: colors.muted, fontVariantNumeric: 'tabular-nums' }">
                            {{ block.sub }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div :style="{ marginTop: '14px', textAlign: 'right', fontSize: '10px', color: colors.muted }">mymtgo.com</div>
    </div>
</template>
