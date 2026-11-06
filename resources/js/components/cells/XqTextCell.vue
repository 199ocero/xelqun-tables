<script setup lang="ts">
import type { TableCell, TableColumn } from '../../schema/types';

defineProps<{ column: TableColumn; cell: TableCell }>();
</script>

<template>
    <span>{{ cell.state }}</span>
</template>
