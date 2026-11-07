<script setup lang="ts">
import { XqSelect } from '@xelqun/forms';
import { computed, ref, watch } from 'vue';
import type { TableCell, TableColumn } from '../../schema/types';

const props = defineProps<{ column: TableColumn; cell: TableCell }>();
const emit = defineEmits<{ update: [value: string | number | null] }>();

const selected = ref(String(props.cell.state ?? ''));
const options = computed(() =>
    (props.column.props.options as Array<{ value: string | number; label: string }>).map(
        (option) => ({ value: String(option.value), label: option.label }),
    ),
);

watch(
    () => props.cell,
    (cell) => (selected.value = String(cell.state ?? '')),
);

/** The control only holds text, so the option's own value goes to the server. */
function change(value: string | number | null | undefined): void {
    selected.value = String(value ?? '');

    const option = (props.column.props.options as Array<{ value: string | number }>).find(
        (candidate) => String(candidate.value) === selected.value,
    );

    emit('update', option?.value ?? null);
}
</script>

<template>
    <XqSelect
        class="min-w-32"
        :model-value="selected"
        :options="options"
        :aria-label="column.label"
        :disabled="!cell.canEdit"
        @update:model-value="change"
    />
</template>
