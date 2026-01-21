<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import { usePage } from '@inertiajs/vue3';
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, PointElement, CategoryScale, LinearScale, LineElement);

ChartJS.defaults.backgroundColor = '#9BD0F5';
ChartJS.defaults.borderColor = '#000';
ChartJS.defaults.color = '#fff';

const page = usePage();

const chart = {
    data: {
        labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        datasets: [
            {
                data: [50, 100, 20, 40, 50, 102, 40],
                label: 'Matches',
            },
        ],
    },
};
</script>

<template>
    <AppLayout>
        <div>
            {{ page.props.count }}
            <!--            <LineChart :data="chart.data" />-->

            <div>
                <div>
                    Hello
                    <!--                    <Table>-->
                    <!--                        <TableHeader>-->
                    <!--                            <tr>-->
                    <!--                                <TableHead>Deck</TableHead>-->
                    <!--                                <TableHead>W/L</TableHead>-->
                    <!--                                <TableHead>Opponent</TableHead>-->
                    <!--                                <TableHead>Format</TableHead>-->
                    <!--                                <TableHead>Date</TableHead>-->
                    <!--                            </tr>-->
                    <!--                        </TableHeader>-->
                    <!--                        <TableBody>-->
                    <!--                            <TableRow v-for="(match, idx) in matches.data" :key="`match_${idx}`">-->
                    <!--                                <TableCell>-->
                    <!--                                    {{ match.deck?.name || 'Unknown' }}-->
                    <!--                                </TableCell>-->
                    <!--                                <TableCell> {{ match.gamesWon }} - {{ match.gamesLost }} </TableCell>-->
                    <!--                                <TableCell>-->
                    <!--                                    <div class="grid grid-cols-2 space-x-1" v-for="(oa, ai) in match.opponentArchetypes" :key="`archetype_${ai}`">-->
                    <!--                                        <ManaSymbols :symbols="oa.archetype?.colorIdentity" />-->
                    <!--                                        <span>{{ oa.archetype?.name }}</span>-->
                    <!--                                    </div>-->
                    <!--                                </TableCell>-->
                    <!--                                <TableCell>-->
                    <!--                                    {{ match.format }}-->
                    <!--                                </TableCell>-->
                    <!--                                <TableCell>-->
                    <!--                                    {{ match.startedAt }}-->
                    <!--                                </TableCell>-->
                    <!--                            </TableRow>-->
                    <!--                        </TableBody>-->
                    <!--                    </Table>-->
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped></style>
