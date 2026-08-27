<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { NO_VALUE, ordinalLabel, type CrossDraftStats } from '@/types/limited';
import { computed } from 'vue';

const props = defineProps<{
    card: App.Data.Front.LimitedCardData | null;
    setCode: string | null;
    stats: CrossDraftStats | undefined;
    packSize: number;
}>();

const stat = computed(() => (props.card?.oracleId && props.stats ? props.stats[props.card.oracleId] ?? null : null));
</script>

<template>
    <div class="flex flex-col gap-2 rounded-lg border border-black/60 bg-card p-4 text-xs">
        <span class="font-semibold">
            <template v-if="card">{{ card.name }} in your other {{ setCode ?? '' }} drafts</template>
            <template v-else>Select a card to compare across drafts</template>
        </span>
        <div v-if="stats === undefined" class="flex flex-col gap-2">
            <Skeleton class="h-3 w-3/4" />
            <Skeleton class="h-3 w-1/2" />
            <Skeleton class="h-3 w-2/3" />
        </div>
        <template v-else-if="card">
            <div v-if="!stat" class="text-muted-foreground">Never seen in another {{ setCode ?? '' }} draft.</div>
            <dl v-else class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                <dt class="text-muted-foreground">Taken</dt>
                <dd class="tabular-nums">{{ stat.timesTaken }}× <span v-if="stat.avgOrdinal !== null" class="text-muted-foreground">· avg {{ ordinalLabel(Math.round(stat.avgOrdinal), packSize) }}</span></dd>
                <dt class="text-muted-foreground">Seen & passed</dt>
                <dd class="tabular-nums">{{ stat.timesPassed }}×</dd>
                <dt class="text-muted-foreground">Wheeled</dt>
                <dd class="tabular-nums">{{ stat.timesWheeled }} of {{ stat.drafts }} drafts</dd>
                <dt class="text-muted-foreground">Made your deck</dt>
                <dd class="tabular-nums">{{ stat.madeDeck > 0 ? `${stat.madeDeck} of ${stat.drafts}` : NO_VALUE }}</dd>
            </dl>
        </template>
    </div>
</template>
