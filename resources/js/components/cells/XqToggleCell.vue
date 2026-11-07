<script setup lang="ts">
import { XqSwitch } from '@xelqun/forms';
import { ref, watch } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();
const emit = defineEmits<{ update: [value: boolean] }>();

const isOn = ref(Boolean(props.cell.state));

watch(
    () => props.cell,
    (cell) => (isOn.value = Boolean(cell.state)),
);

function change(value: boolean): void {
    isOn.value = value;
    emit('update', value);
}
</script>

<template>
    <XqSwitch
        :model-value="isOn"
        :aria-label="column.label"
        :disabled="!cell.canEdit"
        @update:model-value="change"
    />
</template>
