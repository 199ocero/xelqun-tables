import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ColumnCell from '../../stubs/column.vue';

describe('make:xelqun-column stub', () => {
    it('renders the state', () => {
        const wrapper = mount(ColumnCell, {
            props: {
                column: { name: 'a', type: 'app/a', label: 'A', sortable: false, props: {} },
                cell: { state: 'value' },
            },
        });

        expect(wrapper.text()).toBe('value');
    });
});
