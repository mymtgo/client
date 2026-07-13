<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import ColorPicker from '@/components/ui/color-picker/ColorPicker.vue';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
} from '@/components/ui/combobox';
import { DateTimePicker } from '@/components/ui/date-time-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Check } from 'lucide-vue-next';
import { ref } from 'vue';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';

const inputValue = ref('Jace, the Mind Sculptor');
const checkboxValue = ref(true);
const switchValue = ref(true);
const sliderValue = ref([45]);
const colorValue = ref('#3b82f6');

const archetypes = ['UW Control', 'Mono Red Aggro', 'Jund Midrange', 'Dimir Reanimator', 'Selesnya Company'];
const selectValue = ref('UW Control');
const comboboxValue = ref('UW Control');

const dateTimeValue = ref<string | null>('2026-07-09 14:30:00');
</script>

<template>
    <SinkSection id="forms" title="Forms">
        <div class="flex flex-wrap items-start gap-6">
            <div class="flex flex-col gap-1.5">
                <Label for="sink-input">Deck name</Label>
                <Input id="sink-input" v-model="inputValue" class="w-56" />
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="sink-input-disabled">Deck name (disabled)</Label>
                <Input id="sink-input-disabled" model-value="UW Control" disabled class="w-56" />
            </div>
            <div class="flex items-center gap-2 pt-6">
                <Checkbox id="sink-checkbox" v-model:model-value="checkboxValue" />
                <Label for="sink-checkbox">Track practice games</Label>
            </div>
            <div class="flex items-center gap-2 pt-6">
                <Switch id="sink-switch" v-model:model-value="switchValue" />
                <Label for="sink-switch">Auto-detect archetype</Label>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label>Trust threshold</Label>
                <Slider v-model="sliderValue" class="w-56" :max="100" :step="1" />
            </div>
        </div>

        <ResolvedNote
            tag="was four"
            note="Pickers — two components. Select for enumerated choices (≤ ~10); Combobox = same trigger + search for long lists. NativeSelect deleted; Command demoted to the global ⌘K palette (see Overlays)."
        />
        <div class="flex flex-wrap items-start gap-6">
            <div class="flex flex-col gap-1.5">
                <Label>Select</Label>
                <Select v-model="selectValue">
                    <SelectTrigger class="w-56">
                        <SelectValue placeholder="Choose an archetype" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="a in archetypes" :key="a" :value="a">{{ a }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="flex flex-col gap-1.5">
                <Label>Combobox</Label>
                <Combobox v-model="comboboxValue" class="w-56">
                    <ComboboxAnchor>
                        <ComboboxInput placeholder="Search archetypes…" />
                    </ComboboxAnchor>
                    <ComboboxList>
                        <ComboboxEmpty>No archetype found.</ComboboxEmpty>
                        <ComboboxGroup>
                            <ComboboxItem v-for="a in archetypes" :key="a" :value="a">
                                {{ a }}
                                <ComboboxItemIndicator>
                                    <Check class="size-4" />
                                </ComboboxItemIndicator>
                            </ComboboxItem>
                        </ComboboxGroup>
                    </ComboboxList>
                </Combobox>
            </div>
        </div>

        <ResolvedNote
            tag="was two surfaces"
            note="Date & time — one calendar. Calendar is the only date surface; DateTimePicker = input trigger → popover of Calendar + time field."
        />
        <div class="flex flex-wrap items-start gap-6">
            <div class="flex flex-col gap-1.5">
                <Label>DateTimePicker</Label>
                <DateTimePicker v-model="dateTimeValue" />
            </div>

            <div class="flex flex-col gap-1.5">
                <Label>ColorPicker</Label>
                <ColorPicker v-model="colorValue" />
            </div>
        </div>
    </SinkSection>
</template>
