import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { createXelqunForms, defaultComponents } from '@xelqun/forms';
import { builtInCells } from '../../resources/js/components/cells';
import XqTable from '../../resources/js/components/XqTable.vue';
import type { TableSchema } from '../../resources/js/schema/types';

function schema(overrides: Partial<TableSchema> = {}): TableSchema {
    return {
        $xq: 1,
        pkg: '0.1.0',
        id: 'products-table',
        columns: [{ name: 'name', type: 'text-column', label: 'Name', props: {} }],
        rows: [{ key: '1', cells: { name: { state: 'Keyboard' } } }],
        pagination: { page: 1, perPage: 10, total: 1, lastPage: 1, options: [10] },
        ...overrides,
    };
}

describe('XqTable', () => {
    it('renders headers and cells', () => {
        const wrapper = mount(XqTable, { props: { table: schema() } });

        expect(wrapper.find('th').text()).toBe('Name');
        expect(wrapper.find('tbody td').text()).toBe('Keyboard');
    });

    it('renders its own cells with the forms registry installed', () => {
        const wrapper = mount(XqTable, {
            props: { table: schema() },
            global: { plugins: [createXelqunForms()] },
        });

        expect(wrapper.find('tbody td').text()).toBe('Keyboard');
    });

    it('shares no type name with a forms component', () => {
        const formTypes = Object.keys(defaultComponents());

        expect(Object.keys(builtInCells).filter((type) => formTypes.includes(type))).toEqual([]);
    });

    it('lets an app replace a built-in cell', () => {
        const wrapper = mount(XqTable, {
            props: { table: schema() },
            global: {
                plugins: [
                    createXelqunForms({
                        components: { 'text-column': { template: '<i>replaced</i>' } },
                    }),
                ],
            },
        });

        expect(wrapper.find('tbody i').text()).toBe('replaced');
    });

    it('shows a message when there are no rows', () => {
        const wrapper = mount(XqTable, { props: { table: schema({ rows: [] }) } });

        expect(wrapper.text()).toContain('No records found.');
    });

    it('renders a plugin column from the forms registry', () => {
        const Stars = defineComponent({
            props: { column: Object, cell: Object },
            setup: (props) => () => h('b', `${props.cell?.state} stars`),
        });
        const table = schema({
            columns: [{ name: 'rating', type: 'acme/stars', label: 'Rating', props: {} }],
            rows: [{ key: '1', cells: { rating: { state: 4 } } }],
        });
        const wrapper = mount(XqTable, {
            props: { table },
            global: {
                plugins: [
                    createXelqunForms({
                        plugins: [{ id: 'acme/stars', components: { 'acme/stars': Stars } }],
                    }),
                ],
            },
        });

        expect(wrapper.find('tbody b').text()).toBe('4 stars');
    });

    it('names a column type nothing renders', () => {
        const table = schema({
            columns: [{ name: 'name', type: 'acme/missing', label: 'Name', props: {} }],
        });
        const wrapper = mount(XqTable, { props: { table } });

        expect(wrapper.find('tbody td').text()).toContain('acme/missing');
    });

    it('refuses a schema it does not understand', () => {
        const wrapper = mount(XqTable, { props: { table: schema({ $xq: 99 }) } });

        expect(wrapper.find('table').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });
});
