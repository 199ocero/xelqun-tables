<script setup lang="ts">
import { XqCheckbox } from '@xelqun/forms';
import { ref, watch } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();
const emit = defineEmits<{ update: [value: boolean] }>();

const isChecked = ref(Boolean(props.cell.state));

watch(
    () => props.cell,
    (cell) => (isChecked.value = Boolean(cell.state)),
);

function change(value: boolean | 'indeterminate'): void {
    isChecked.value = value === true;
    emit('update', isChecked.value);
}
</script>

<template>
    <XqCheckbox
        :model-value="isChecked"
        :aria-label="column.label"
        :disabled="!cell.canEdit"
        @update:model-value="change"
    />
</template>
