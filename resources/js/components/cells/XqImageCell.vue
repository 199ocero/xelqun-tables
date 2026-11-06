<script setup lang="ts">
import { cn } from '@xelqun/forms';
import { computed } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();

const sources = computed<string[]>(() =>
    (Array.isArray(props.cell.state) ? props.cell.state : [props.cell.state]).filter(Boolean),
);
</script>

<template>
    <span v-if="sources.length" class="gap-1 flex">
        <img
            v-for="(source, index) in sources"
            :key="index"
            :src="source"
            :alt="cell.alt"
            :width="column.props.size"
            :height="column.props.size"
            loading="lazy"
            :class="cn('object-cover', column.props.circular ? 'rounded-full' : 'rounded-xq-sm')"
            :style="{ width: `${column.props.size}px`, height: `${column.props.size}px` }"
        />
    </span>
</template>
