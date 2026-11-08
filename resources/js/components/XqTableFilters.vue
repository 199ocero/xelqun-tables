<script setup lang="ts">
import { useXqForm, XqBadge, XqButton, XqForm, XqPopover } from '@xelqun/forms';
import type { FormSchema, FormStore } from '@xelqun/forms';
import { ListFilter } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import type { TableStore } from '../core/store';

const props = defineProps<{
    form: FormSchema;
    defaults: Record<string, unknown>;
    /** The values the rows were loaded with. Only those that differ from a default. */
    values?: Record<string, unknown>;
    active: number;
    t: TableStore['t'];
}>();
const emit = defineEmits<{ apply: [filters: Record<string, unknown>] }>();

// Here and not in the popover, so values survive it closing.
const store = useXqForm(props.form);
const open = ref(false);

async function apply(form: FormStore): Promise<void> {
    await form.settled();
    emit('apply', { ...form.state.data });
    open.value = false;
}

// The form starts from the page's props, and Back restores old ones while the table reloads the URL.
watch(
    () => props.values,
    (values) => {
        Object.entries(props.defaults).forEach(([name, fallback]) => {
            const value = values && name in values ? values[name] : fallback;

            if (JSON.stringify(store.get(`data.${name}`)) !== JSON.stringify(value)) {
                store.set(`data.${name}`, value);
            }
        });
    },
);

function reset(): void {
    Object.entries(props.defaults).forEach(([name, value]) => store.set(`data.${name}`, value));
    void apply(store);
}
</script>

<template>
    <!-- Capped to the room on screen and scrolled inside, so Apply stays in reach as rules are added. -->
    <XqPopover
        v-model:open="open"
        align="end"
        class="max-h-(--reka-popover-content-available-height) w-[min(28rem,calc(100vw-2rem))] overflow-y-auto"
    >
        <template #trigger>
            <XqButton type="button" variant="outline" data-xq-table-filters>
                <ListFilter />
                {{ t('Filters') }}
                <XqBadge v-if="active > 0" variant="secondary">{{ active }}</XqBadge>
            </XqButton>
        </template>
        <XqForm :form="store" @submit="apply">
            <template #actions>
                <div class="gap-2 flex justify-end">
                    <XqButton type="button" variant="ghost" size="sm" @click="reset">
                        {{ t('Reset') }}
                    </XqButton>
                    <XqButton type="submit" size="sm">{{ t('Apply') }}</XqButton>
                </div>
            </template>
        </XqForm>
    </XqPopover>
</template>
