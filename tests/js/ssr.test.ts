// @vitest-environment node
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import XqTable from '../../resources/js/components/XqTable.vue';
import type { TableSchema } from '../../resources/js/schema/types';

vi.mock('@inertiajs/vue3', () => ({
    router: { replace: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const table: TableSchema = {
    $xq: 1,
    pkg: '0.1.0',
    id: 'products-table',
    token: 'token',
    endpoints: { query: '/query' },
    searchable: true,
    query: {},
    sort: null,
    columns: [{ name: 'name', type: 'text-column', label: 'Name', sortable: true, props: {} }],
    rows: [
        { key: '1', cells: { name: { state: 'Keyboard', url: '/products/1' } } },
        { key: '2', cells: { name: { state: 'Mouse', url: 'https://example.com/2' } } },
    ],
    pagination: { page: 1, perPage: 10, total: 2, lastPage: 1, options: [10, 25] },
};

describe('server rendering', () => {
    it('renders a table, links included, with no browser globals', async () => {
        expect(typeof window).toBe('undefined');

        const html = await renderToString(createSSRApp({ render: () => h(XqTable, { table }) }));

        expect(html).toContain('Keyboard');
        expect(html).toContain('href="/products/1"');
        expect(html).toContain('href="https://example.com/2"');
    });
});
