<script setup lang="ts">
import { X } from '@lucide/vue';
import { useField, XqButton, XqDialog, XqFieldWrapper } from '@xelqun/forms';
import type { SchemaNode, SelectOption } from '@xelqun/forms';
import { computed, reactive, ref } from 'vue';
import type { TableSchema } from '../schema/types';
import XqTable from './XqTable.vue';

const props = defineProps<{ node: SchemaNode }>();
const { store, value, error, required, disabled, id, label } = useField<
    string | number | Array<string | number> | null
>(props.node);
const t = store.t;
const multiple = computed(() => !!props.node.props?.multiple);
const open = ref(false);
const picked = ref<string[]>([]);

/** Labels by key: the server's for saved values, and the table's for new picks. */
const labels = reactive(
    new Map<string, string>(
        ((props.node.props?.options ?? []) as SelectOption[]).map((option) => [
            String(option.value),
            option.label,
        ]),
    ),
);
const values = computed(() =>
    (Array.isArray(value.value) ? value.value : value.value == null ? [] : [value.value]).map(
        String,
    ),
);

function openPicker(): void {
    picked.value = [...values.value];
    open.value = true;
}

function remember(rows: TableSchema['rows']): void {
    const title = props.node.props?.titleAttribute;

    rows.forEach((row) => {
        const text = row.cells[title]?.state;

        if (text != null) {
            labels.set(row.key, String(text));
        }
    });
}

function confirm(): void {
    value.value = multiple.value ? [...picked.value] : (picked.value[0] ?? null);
    open.value = false;
}

function remove(key: string): void {
    value.value = multiple.value ? values.value.filter((item) => item !== key) : null;
}
</script>

<template>
    <XqFieldWrapper :node="node" :id="id" :error="error" :required="required" :label-for="null">
        <div :id="id" class="gap-2 flex flex-wrap items-center" data-xq-modal-table-select>
            <span
                v-for="key in values"
                :key="key"
                class="gap-1 pl-2.5 pr-1 py-0.5 text-sm bg-xq-secondary text-xq-secondary-foreground inline-flex items-center rounded-(--xq-pill-radius) border border-transparent"
            >
                {{ labels.get(key) ?? key }}
                <button
                    v-if="!disabled"
                    type="button"
                    class="size-4 hover:bg-xq-accent flex cursor-pointer items-center justify-center rounded-full"
                    :aria-label="t('Remove :item', { item: labels.get(key) ?? key })"
                    @click="remove(key)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>
            <XqButton
                v-if="!disabled"
                type="button"
                variant="outline"
                size="sm"
                :aria-invalid="error ? true : undefined"
                @click="openPicker"
            >
                {{ values.length && !multiple ? t('Change') : t('Select') }}
            </XqButton>
        </div>
        <XqDialog v-model:open="open" :title="label ?? t('Select')" width="4xl">
            <XqTable
                v-if="open"
                v-model:picked="picked"
                :table="node.props?.table"
                :pick="multiple ? 'multiple' : 'single'"
                @rows="remember"
            />
            <div class="gap-2 mt-4 flex justify-end">
                <XqButton type="button" variant="outline" size="sm" @click="open = false">
                    {{ t('Cancel') }}
                </XqButton>
                <XqButton type="button" size="sm" data-xq-pick-confirm @click="confirm">
                    {{ t('Select') }}
                </XqButton>
            </div>
        </XqDialog>
    </XqFieldWrapper>
</template>
