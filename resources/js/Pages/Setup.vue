<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';

const page = usePage();

const form = useForm({});
</script>

<template>
    <div class="mx-auto mt-34 max-w-xl">
        <form v-if="page.props.logs_missing">
            <label> Unable to locate MTGO logs </label>
            <!--            <input type="file" v-model="logFileForm.log_path" />-->
        </form>
        <form @submit.prevent="form.post('/setup')">
            <button>Run setup {{ form.processing }}</button>
        </form>
    </div>
</template>

<style scoped></style>
