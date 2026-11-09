<script setup lang="ts">
import { XqInput } from '@xelqun/forms';
import { onScopeDispose, ref, useTemplateRef, watch } from 'vue';
import type { TableStore } from '../core/store';

const DEBOUNCE_MS = 300;

const props = defineProps<{ value: string; t: TableStore['t']; label?: string }>();
const emit = defineEmits<{ search: [search: string] }>();

const wrapper = useTemplateRef<HTMLElement>('wrapper');
const input = ref(props.value);

let timer: ReturnType<typeof setTimeout> | undefined;

function search(): void {
    clearTimeout(timer);
    timer = setTimeout(() => emit('search', String(input.value ?? '').trim()), DEBOUNCE_MS);
}

// While typing, an answer for fewer letters must not overwrite the rest.
watch(
    () => props.value,
    (value) => {
        if (!wrapper.value?.contains(document.activeElement)) {
            input.value = value;
        }
    },
);

onScopeDispose(() => clearTimeout(timer));
</script>

<template>
    <div ref="wrapper" class="max-w-xs">
        <XqInput
            v-model="input"
            type="search"
            :placeholder="label ?? t('Search')"
            :aria-label="label ?? t('Search')"
            @update:model-value="search"
        />
    </div>
</template>
