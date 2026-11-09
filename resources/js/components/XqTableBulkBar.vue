<script setup lang="ts">
import { XqButton } from '@xelqun/forms';
import { computed } from 'vue';
import type { TableStore } from '../core/store';

// Total is null when the pagination doesn't count the rows, and then there's no select all.
const props = defineProps<{
    count: number;
    total: number | null;
    max: number;
    t: TableStore['t'];
}>();
const emit = defineEmits<{ selectAll: []; clear: [] }>();

const canSelectAll = computed(
    () => props.total !== null && props.count < props.total && props.total <= props.max,
);
</script>

<template>
    <div
        data-xq-table-bulk
        class="gap-3 px-3 py-2 text-sm rounded-xq-md bg-xq-muted/50 animate-xq-rise-in flex flex-wrap items-center"
    >
        <span aria-live="polite">{{ t(':count selected', { count }) }}</span>
        <XqButton
            v-if="canSelectAll"
            type="button"
            variant="link"
            size="sm"
            @click="emit('selectAll')"
        >
            {{ t('Select all :count', { count: total ?? 0 }) }}
        </XqButton>
        <XqButton type="button" variant="ghost" size="sm" @click="emit('clear')">
            {{ t('Clear selection') }}
        </XqButton>
        <div class="gap-2 ml-auto flex items-center">
            <slot />
        </div>
    </div>
</template>
