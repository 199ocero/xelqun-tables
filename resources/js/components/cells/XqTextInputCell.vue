<script setup lang="ts">
import { XqInput } from '@xelqun/forms';
import { ref, watch } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();
const emit = defineEmits<{ update: [value: string] }>();

const text = ref(String(props.cell.state ?? ''));

// A rule the value broke keeps what was typed, so it can be fixed rather than typed again.
watch(
    () => props.cell,
    (cell) => {
        if (!cell.error) {
            text.value = String(cell.state ?? '');
        }
    },
);

function save(): void {
    if (text.value !== String(props.cell.state ?? '') || props.cell.error) {
        emit('update', text.value);
    }
}
</script>

<template>
    <XqInput
        v-model="text"
        :aria-label="column.label"
        :aria-invalid="cell.error ? true : undefined"
        :disabled="!cell.canEdit"
        class="h-8 min-w-24"
        @blur="save"
        @keydown.enter.prevent="save"
    />
</template>
