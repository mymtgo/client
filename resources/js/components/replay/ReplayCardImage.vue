<script setup lang="ts">
import { shallowRef, watch } from 'vue';

const props = defineProps<{
    name?: string | null;
    type?: string | null;
    image?: string | null;
    /** Show the type line on the fallback face. */
    showType?: boolean;
}>();

const failed = shallowRef(false);

watch(
    () => props.image,
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <div class="absolute inset-0 overflow-hidden rounded-[inherit] bg-linear-160 from-[#3b3a35] to-[#23221f]">
        <div class="absolute inset-0 flex flex-col justify-between p-[8%] text-[10px] leading-tight text-[#eee]">
            <span class="font-semibold">{{ name ?? 'Unknown card' }}</span>
            <span v-if="showType && type" class="text-[9px] text-[#cfcfcf]">{{ type }}</span>
        </div>
        <img
            v-if="image && !failed"
            :src="image"
            :alt="name ?? 'card'"
            draggable="false"
            class="absolute inset-0 block size-full object-cover"
            @error="failed = true"
        />
    </div>
</template>
