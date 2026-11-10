<script setup lang="ts">
import { cn } from '@xelqun/forms';
import type { TableColumn, TableSummaries } from '../schema/types';

defineProps<{
    summaries: TableSummaries;
    columns: TableColumn[];
    columnClass: (column: TableColumn) => string;
    reorderable: boolean;
    hasSelection: boolean;
    hasRowActions: boolean;
}>();
</script>

<template>
    <tr class="border-xq-border border-t">
        <td v-if="reorderable"></td>
        <td v-if="hasSelection"></td>
        <td
            v-for="column in columns"
            :key="column.name"
            :class="cn('px-3 py-2 text-sm font-medium align-top', columnClass(column))"
        >
            <p v-for="(line, position) in summaries[column.name]" :key="position">
                <span v-if="line.label" class="text-xq-muted-foreground">{{ line.label }}:</span>
                {{ line.value }}
            </p>
        </td>
        <td v-if="hasRowActions"></td>
    </tr>
</template>
