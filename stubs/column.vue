<script setup lang="ts">
import type { TableCell, TableColumn } from '@xelqun/tables';

defineProps<{ column: TableColumn; cell: TableCell }>();
</script>

<template>
    <span>{{ cell.state }}</span>
</template>
