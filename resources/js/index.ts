import type { XelqunPlugin } from '@xelqun/forms';
export { actionNode } from './components/actionNode';
export { default as XqTable } from './components/XqTable.vue';
export { createTableStore } from './core/store';
export type { TableStore, TableStoreOptions } from './core/store';
export * from './schema/types';

/**
 * Registers the form fields tables brings, such as ModalTableSelect. Add it to
 * createXelqunForms({ plugins: [tablesPlugin()] }).
 */
export function tablesPlugin(): XelqunPlugin {
    return {
        id: 'xelqun/tables',
        components: {
            'xelqun/modal-table-select': () => import('./components/XqModalTableSelectField.vue'),
        },
    };
}
