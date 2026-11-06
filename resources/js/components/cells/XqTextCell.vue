<script setup lang="ts">
import { cn, XqBadge, XqIcon } from '@xelqun/forms';
import { computed } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';
import { badgeTones, textTones } from './tones';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();

const values = computed(() =>
    (Array.isArray(props.cell.state) ? props.cell.state : [props.cell.state]).filter(
        (value) => value !== null && value !== undefined,
    ),
);
</script>

<template>
    <span v-if="column.props.badge" class="gap-1 flex flex-wrap">
        <XqBadge
            v-for="(value, index) in values"
            :key="index"
            variant="outline"
            :class="badgeTones[cell.color ?? 'gray']"
        >
            <XqIcon v-if="cell.icon" :name="cell.icon" />
            {{ value }}
        </XqBadge>
    </span>
    <span v-else :class="cn('gap-1.5 inline-flex items-center', textTones[cell.color])">
        <XqIcon v-if="cell.icon" :name="cell.icon" class="size-3.5" />
        {{ values.join(', ') }}
    </span>
</template>
