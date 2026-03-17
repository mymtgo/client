<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManaSymbols from '@/components/ManaSymbols.vue';

defineProps<{
    matchupSpread: any[];
}>();
</script>

<template>
    <Card class="gap-0 overflow-hidden p-0">
        <CardContent class="px-0">
            <p v-if="!matchupSpread?.length" class="py-8 text-center text-sm text-muted-foreground">
                No matchup data yet.
            </p>
            <Table v-else>
                <TableHeader class="bg-muted sticky top-0">
                    <TableRow>
                        <TableHead class="w-8 pl-3 pr-0"></TableHead>
                        <TableHead>Archetype</TableHead>
                        <TableHead class="text-right">Record</TableHead>
                        <TableHead class="text-right">Win %</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="matchup in matchupSpread" :key="matchup.archetype_id">
                        <TableCell class="w-8 pl-3 pr-0">
                            <ManaSymbols :symbols="matchup.color_identity" class="shrink-0" />
                        </TableCell>
                        <TableCell class="truncate">{{ matchup.name }}</TableCell>
                        <TableCell class="text-right tabular-nums text-muted-foreground">{{ matchup.match_record }}</TableCell>
                        <TableCell class="text-right">
                            <span
                                class="font-medium tabular-nums"
                                :class="matchup.match_winrate > 50 ? 'text-success' : matchup.match_winrate < 50 ? 'text-destructive' : ''"
                            >{{ matchup.match_winrate }}%</span>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
