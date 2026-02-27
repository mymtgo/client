<script setup lang="ts">
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManaSymbols from '@/components/ManaSymbols.vue';
import WinRateBar from '@/components/WinRateBar.vue';

defineProps<{
    matchupSpread: any[]
}>()
</script>

<template>
    <div>
        <Card class="gap-0 overflow-hidden p-0">
            <CardContent class="px-0">
                <p v-if="!matchupSpread.length" class="text-muted-foreground py-8 text-center text-sm">
                    Archetype matchup data will appear as you play matches.
                </p>

                <Table v-if="matchupSpread.length">
                    <TableHeader class="bg-muted">
                        <TableRow>
                            <TableHead>Archetype</TableHead>
                            <TableHead></TableHead>
                            <TableHead>Matches</TableHead>
                            <TableHead>Match winrate</TableHead>
                            <TableHead>Games</TableHead>
                            <TableHead>Game winrate</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(matchup, idx) in matchupSpread" :key="`matchup_${idx}`">
                            <TableCell>
                                {{ matchup.name }}
                            </TableCell>
                            <TableCell>
                                <ManaSymbols :symbols="matchup.color_identity" />
                            </TableCell>
                            <TableCell> {{ matchup.match_record }}</TableCell>
                            <TableCell class="min-w-32">
                                <WinRateBar :winrate="matchup.match_winrate ?? 0" size="sm" />
                            </TableCell>
                            <TableCell> {{ matchup.game_record }}</TableCell>
                            <TableCell class="min-w-32">
                                <WinRateBar :winrate="matchup.game_winrate ?? 0" size="sm" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
