<script setup lang="ts">
import { Empty, EmptyDescription } from '@/components/ui/empty';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import ManaSymbols from '@/components/ManaSymbols.vue';

defineProps<{
    matchupSpread: any[]
}>()
</script>

<template>
    <div>
        <Card class="gap-0 overflow-hidden p-0">
            <CardContent class="px-0">
                <Empty v-if="!matchupSpread.length">
                    <EmptyDescription> Archetype matchup data will appear as you play matches. </EmptyDescription>
                </Empty>

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
                            <TableCell class="">
                                <span
                                    :class="{
                                        'text-red-500': !matchup.match_winrate,
                                        'text-orange-500': matchup.match_winrate && matchup.match_winrate < 50,
                                        'text-green-500': matchup.match_winrate >= 50,
                                    }"
                                >
                                    {{ matchup.match_winrate }}%
                                </span>
                            </TableCell>
                            <TableCell> {{ matchup.game_record }}</TableCell>
                            <TableCell>
                                <span
                                    :class="{
                                        'text-red-500': !matchup.game_winrate,
                                        'text-orange-500': matchup.game_winrate && matchup.game_winrate < 50,
                                        'text-green-500': matchup.game_winrate >= 50,
                                    }"
                                >
                                    {{ matchup.game_winrate }}%
                                </span>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>

<style scoped></style>
