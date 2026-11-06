<script setup lang="ts">
import { cn, XqIcon } from '@xelqun/forms';
import type { TableCell, TableColumn } from '../../schema/types';
import { textTones } from './tones';

defineProps<{ column: TableColumn; cell: TableCell }>();
</script>

<template>
    <span v-if="cell.icon" :title="cell.label" :class="cn('inline-flex', textTones[cell.color])">
        <XqIcon :name="cell.icon" />
        <span v-if="cell.label" class="sr-only">{{ cell.label }}</span>
    </span>
</template>
