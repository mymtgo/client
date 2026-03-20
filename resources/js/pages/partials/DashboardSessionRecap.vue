<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import dayjs from 'dayjs';

type SessionMatch = {
    id: number;
    outcome: string;
    opponentArchetype: string;
    gamesWon: number;
    gamesLost: number;
};

type LastSession = {
    startedAt: string;
    endedAt: string;
    matches: SessionMatch[];
    record: string;
    duration: string;
};

const props = defineProps<{
    lastSession: LastSession | null;
}>();

const sessionDate = computed(() => {
    if (!props.lastSession) return null;
    return dayjs(props.lastSession.startedAt).format('MMM D, YYYY');
});
</script>

<template>
    <Card class="flex flex-col">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm font-medium text-muted-foreground uppercase tracking-wide">Last Session</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-1 flex-col gap-3">
            <template v-if="lastSession">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>{{ sessionDate }}</span>
                    <span class="tabular-nums">{{ lastSession.matches.length }} matches</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div
                        v-for="match in lastSession.matches"
                        :key="match.id"
                        class="flex items-center gap-2 rounded-sm px-2 py-1 text-sm"
                        :class="match.outcome === 'win' ? 'bg-success/10' : 'bg-destructive/10'"
                    >
                        <span
                            class="w-4 shrink-0 font-bold tabular-nums"
                            :class="match.outcome === 'win' ? 'text-success' : 'text-destructive'"
                        >
                            {{ match.outcome === 'win' ? 'W' : 'L' }}
                        </span>
                        <span class="flex-1 truncate text-xs">{{ match.opponentArchetype }}</span>
                        <span class="tabular-nums text-xs text-muted-foreground">
                            {{ match.gamesWon }}-{{ match.gamesLost }}
                        </span>
                    </div>
                </div>

                <div class="mt-auto flex items-center justify-between border-t pt-2 text-xs text-muted-foreground">
                    <span class="font-medium tabular-nums">{{ lastSession.record }}</span>
                    <span>{{ lastSession.duration }}</span>
                </div>
            </template>

            <div v-else class="flex flex-1 items-center justify-center py-8 text-sm text-muted-foreground">
                No sessions recorded
            </div>
        </CardContent>
    </Card>
</template>
