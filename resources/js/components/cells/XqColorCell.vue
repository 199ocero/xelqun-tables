<script setup lang="ts">
import type { TableCell, TableColumn } from '../../schema/types';

defineProps<{ column: TableColumn; cell: TableCell }>();
</script>

<template>
    <span v-if="cell.state" class="gap-2 text-sm inline-flex items-center">
        <span
            aria-hidden="true"
            class="size-5 border-xq-border rounded-xq-sm border"
            :style="{ background: String(cell.state) }"
        ></span>
        {{ cell.state }}
    </span>
</template>
