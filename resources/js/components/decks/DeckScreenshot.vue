<script setup lang="ts">
import ManaSymbols from '@/components/ManaSymbols.vue';

type ScreenshotCard = {
    name: string;
    type: string;
    quantity: number;
    imageBase64: string | null;
};

type CmcBucket = { cmc: string; count: number };
type TypeCount = { type: string; count: number };

const props = defineProps<{
    name: string;
    format: string;
    colorIdentity: string | null;
    winRate: number;
    matchesWon: number;
    matchesLost: number;
    coverArtBase64: string | null;
    nonLandCards: ScreenshotCard[];
    landCards: ScreenshotCard[];
    sideboardCards: ScreenshotCard[];
    cmcDistribution: CmcBucket[];
    typeDistribution: TypeCount[];
}>();

const colors = {
    bg: '#111111',
    text: '#ffffff',
    muted: '#9ca3af',
    border: '#333333',
    bar: '#3b82f6',
    winRate: '#22c55e',
};

const cardWidth = 100;
const cardHeight = Math.round(cardWidth / 0.717);
const stackOffset = 22;

/**
 * Group cards into stacks for grid rendering.
 * Each stack = one unique card with its quantity for vertical stacking.
 */
function groupIntoStacks(cards: ScreenshotCard[]) {
    return cards.map((card) => ({
        items: Array.from({ length: card.quantity }, () => ({ name: card.name, imageBase64: card.imageBase64 })),
        height: cardHeight + (card.quantity - 1) * stackOffset,
    }));
}

type StackItem = { name: string; imageBase64: string | null };

/**
 * Group sideboard cards into compact stacks of up to maxPerStack,
 * stacking different cards together to save vertical space.
 */
function groupSideboardStacks(cards: ScreenshotCard[], maxPerStack = 4) {
    // Flatten all cards into individual items (expanding by quantity)
    const allItems: StackItem[] = cards.flatMap((card) =>
        Array.from({ length: card.quantity }, () => ({ name: card.name, imageBase64: card.imageBase64 })),
    );

    // Chunk into groups of maxPerStack
    const stacks: { items: StackItem[]; height: number }[] = [];
    for (let i = 0; i < allItems.length; i += maxPerStack) {
        const chunk = allItems.slice(i, i + maxPerStack);
        stacks.push({
            items: chunk,
            height: cardHeight + (chunk.length - 1) * stackOffset,
        });
    }
    return stacks;
}

const nonLandStacks = groupIntoStacks(props.nonLandCards);
const landStacks = groupIntoStacks(props.landCards);
const sideboardStacks = groupSideboardStacks(props.sideboardCards);

const cmcMax = Math.max(...props.cmcDistribution.map((d) => d.count), 1);

const maindeckCount = props.nonLandCards.reduce((s, c) => s + c.quantity, 0) + props.landCards.reduce((s, c) => s + c.quantity, 0);
const sideboardCount = props.sideboardCards.reduce((s, c) => s + c.quantity, 0);
</script>

<template>
    <div
        :style="{
            width: '1200px',
            backgroundColor: colors.bg,
            color: colors.text,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            padding: '24px',
            boxSizing: 'border-box',
        }"
    >
        <!-- HEADER -->
        <div
            :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '16px',
                marginBottom: '16px',
                paddingBottom: '12px',
                borderBottom: `1px solid ${colors.border}`,
            }"
        >
            <!-- Cover Art -->
            <img
                v-if="coverArtBase64"
                :src="coverArtBase64"
                :style="{
                    width: '64px',
                    height: '64px',
                    borderRadius: '8px',
                    objectFit: 'cover',
                    flexShrink: '0',
                    border: `2px solid ${colors.border}`,
                }"
            />

            <!-- Name, Symbols, Format -->
            <div :style="{ flex: '1' }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px' }">
                    <span :style="{ fontSize: '22px', fontWeight: '700' }">{{ name }}</span>
                    <ManaSymbols
                        v-if="colorIdentity"
                        :symbols="colorIdentity"
                        class="[&_svg]:w-4.5"
                        :style="{ display: 'flex', gap: '2px' }"
                    />
                </div>
                <div :style="{ fontSize: '13px', color: colors.muted, marginTop: '2px' }">{{ format }}</div>
            </div>

            <!-- Win Rate & Record -->
            <div :style="{ display: 'flex', alignItems: 'center', gap: '16px' }">
                <div :style="{ textAlign: 'center' }">
                    <div :style="{ fontSize: '20px', fontWeight: '700', color: colors.winRate }">{{ winRate }}%</div>
                    <div :style="{ fontSize: '11px', color: colors.muted }">Win Rate</div>
                </div>
                <div :style="{ textAlign: 'center' }">
                    <div :style="{ fontSize: '16px', fontWeight: '600' }">{{ matchesWon }}-{{ matchesLost }}</div>
                    <div :style="{ fontSize: '11px', color: colors.muted }">Record</div>
                </div>
            </div>
        </div>

        <!-- CARD GRID + SIDEBOARD -->
        <div :style="{ display: 'flex', gap: '16px' }">
            <!-- Main Deck -->
            <div :style="{ flex: '1' }">
                <!-- Non-land cards -->
                <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '6px' }">
                    <div
                        v-for="(stack, i) in nonLandStacks"
                        :key="`nl-${i}`"
                        :style="{
                            position: 'relative',
                            width: `${cardWidth}px`,
                            height: `${stack.height}px`,
                        }"
                    >
                        <img
                            v-for="(item, j) in stack.items"
                            :key="`nl-${i}-${j}`"
                            :src="item.imageBase64 ?? undefined"
                            :alt="item.name"
                            :style="{
                                position: 'absolute',
                                width: `${cardWidth}px`,
                                borderRadius: '5px',
                                top: `${j * stackOffset}px`,
                                zIndex: j + 1,
                            }"
                        />
                    </div>
                </div>

                <!-- Separator -->
                <div :style="{ borderTop: `1px solid ${colors.border}`, margin: '10px 0' }" />

                <!-- Land cards -->
                <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '6px' }">
                    <div
                        v-for="(stack, i) in landStacks"
                        :key="`land-${i}`"
                        :style="{
                            position: 'relative',
                            width: `${cardWidth}px`,
                            height: `${stack.height}px`,
                        }"
                    >
                        <img
                            v-for="(item, j) in stack.items"
                            :key="`land-${i}-${j}`"
                            :src="item.imageBase64 ?? undefined"
                            :alt="item.name"
                            :style="{
                                position: 'absolute',
                                width: `${cardWidth}px`,
                                borderRadius: '5px',
                                top: `${j * stackOffset}px`,
                                zIndex: j + 1,
                            }"
                        />
                    </div>
                </div>
            </div>

            <!-- Sideboard -->
            <div
                v-if="sideboardStacks.length"
                :style="{
                    flexShrink: '0',
                    borderLeft: `1px solid ${colors.border}`,
                    paddingLeft: '16px',
                }"
            >
                <div
                    :style="{
                        fontSize: '11px',
                        color: colors.muted,
                        textTransform: 'uppercase',
                        letterSpacing: '0.5px',
                        marginBottom: '8px',
                        fontWeight: '600',
                    }"
                >
                    Sideboard
                </div>

                <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '6px', maxWidth: `${cardWidth * 3 + 12}px` }">
                    <div
                        v-for="(stack, i) in sideboardStacks"
                        :key="`sb-${i}`"
                        :style="{
                            position: 'relative',
                            width: `${cardWidth}px`,
                            height: `${stack.height}px`,
                        }"
                    >
                        <img
                            v-for="(item, j) in stack.items"
                            :key="`sb-${i}-${j}`"
                            :src="item.imageBase64 ?? undefined"
                            :alt="item.name"
                            :style="{
                                position: 'absolute',
                                width: `${cardWidth}px`,
                                borderRadius: '5px',
                                top: `${j * stackOffset}px`,
                                zIndex: j + 1,
                            }"
                        />
                    </div>
                </div>

                <!-- Stats below sideboard -->
                <div :style="{ marginTop: '12px', paddingTop: '12px', borderTop: `1px solid ${colors.border}` }">
                    <div :style="{ display: 'flex', alignItems: 'flex-end', gap: '3px', height: '60px', marginBottom: '8px' }">
                        <div
                            v-for="bucket in cmcDistribution"
                            :key="bucket.cmc"
                            :style="{
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                gap: '2px',
                            }"
                        >
                            <span :style="{ fontSize: '8px', color: colors.muted }">{{ bucket.count || '' }}</span>
                            <div
                                :style="{
                                    width: '16px',
                                    backgroundColor: colors.bar,
                                    borderRadius: '2px 2px 0 0',
                                    height: `${Math.max((bucket.count / cmcMax) * 48, bucket.count > 0 ? 3 : 0)}px`,
                                }"
                            />
                            <span :style="{ fontSize: '9px', color: colors.muted }">{{ bucket.cmc }}</span>
                        </div>
                    </div>
                    <div :style="{ display: 'flex', gap: '8px', fontSize: '11px', color: colors.muted, flexWrap: 'wrap' }">
                        <span v-for="t in typeDistribution" :key="t.type">
                            {{ t.type }}
                            <strong :style="{ color: colors.text }">{{ t.count }}</strong>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div
            :style="{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                marginTop: '16px',
                paddingTop: '10px',
                borderTop: `1px solid ${colors.border}`,
            }"
        >
            <span :style="{ fontSize: '11px', color: '#555555' }">mymtgo.com</span>
            <span :style="{ fontSize: '10px', color: '#444444' }">{{ maindeckCount }} cards main · {{ sideboardCount }} sideboard</span>
        </div>
    </div>
</template>
