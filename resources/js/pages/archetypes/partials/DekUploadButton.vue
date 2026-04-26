<script setup lang="ts">
import UploadDekController from '@/actions/App/Http/Controllers/Archetypes/UploadDekController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Upload } from 'lucide-vue-next';
import { ref } from 'vue';

const emit = defineEmits<{
    resolved: [data: { cards: any[]; color_identity: string | null }];
}>();

const uploading = ref(false);
const error = ref<string | null>(null);
const fileName = ref<string | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);

async function handleUpload(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    uploading.value = true;
    error.value = null;
    fileName.value = file.name;

    try {
        const formData = new FormData();
        formData.append('dek_file', file);

        const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';
        const response = await fetch(UploadDekController.url(), {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(xsrf),
                Accept: 'application/json',
            },
            body: formData,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(data?.message ?? 'Failed to parse deck file.');
        }

        const data = await response.json();
        emit('resolved', data);
    } catch (e: any) {
        error.value = e.message ?? 'An error occurred.';
        fileName.value = null;
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

function trigger() {
    inputEl.value?.click();
}
</script>

<template>
    <div>
        <Button type="button" variant="outline" class="w-full justify-start" :disabled="uploading" @click="trigger">
            <Spinner v-if="uploading" class="mr-2 size-4" />
            <Upload v-else class="mr-2 size-4" />
            <span class="truncate">{{ fileName ?? (uploading ? 'Parsing…' : 'Upload .dek file') }}</span>
        </Button>
        <input ref="inputEl" type="file" accept=".dek" class="hidden" :disabled="uploading" @change="handleUpload" />
        <p v-if="error" class="mt-1.5 text-xs text-red-400">{{ error }}</p>
    </div>
</template>
