import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createXelqunForms, defaultComponents } from '@xelqun/forms';
import { builtInCells } from '../../resources/js/components/cells';
import XqTable from '../../resources/js/components/XqTable.vue';
import type { TableSchema } from '../../resources/js/schema/types';

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { replace } }));

function schema(overrides: Partial<TableSchema> = {}): TableSchema {
    return {
        $xq: 1,
        pkg: '0.1.0',
        id: 'products-table',
        token: 'token',
        endpoints: { query: '/query' },
        query: {},
        columns: [{ name: 'name', type: 'text-column', label: 'Name', props: {} }],
        rows: [{ key: '1', cells: { name: { state: 'Keyboard' } } }],
        pagination: { page: 1, perPage: 10, total: 1, lastPage: 1, options: [10] },
        ...overrides,
    };
}

function respondWith(result: Partial<TableSchema>, status = 200) {
    return vi.fn<(url: string, init: RequestInit) => Promise<Response>>(() =>
        Promise.resolve(new Response(JSON.stringify(result), { status })),
    );
}

function sentBody(fetchMock: ReturnType<typeof respondWith>, call = 0): string {
    return fetchMock.mock.calls[call]![1].body as string;
}

afterEach(() => {
    vi.unstubAllGlobals();
    replace.mockClear();
    window.history.replaceState(null, '', '/');
});

describe('XqTable', () => {
    const paged = { page: 1, perPage: 1, total: 3, lastPage: 3, options: [1, 2] };

    it('asks for the next page and puts it in the url', async () => {
        const fetchMock = respondWith({
            query: { page: 2 },
            rows: [],
            pagination: { ...paged, page: 2 },
        });

        vi.stubGlobal('fetch', fetchMock);
        window.history.replaceState(null, '', '/products?tab=a&other[page]=9');

        const wrapper = mount(XqTable, { props: { table: schema({ pagination: paged }) } });

        await wrapper.find('button[aria-label="Next page"]').trigger('click');
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock))).toEqual({
            token: 'token',
            query: { page: 2 },
        });
        expect(replace).toHaveBeenCalledWith({
            url: '/products?tab=a&other[page]=9&products-table[page]=2',
            preserveState: true,
            preserveScroll: true,
        });
    });

    it('leaves the url alone when it answers after leaving the page', async () => {
        vi.stubGlobal('fetch', respondWith({ query: { page: 2 }, rows: [], pagination: paged }));

        const wrapper = mount(XqTable, { props: { table: schema({ pagination: paged }) } });

        await wrapper.find('button[aria-label="Next page"]').trigger('click');
        wrapper.unmount();
        await flushPromises();

        expect(replace).not.toHaveBeenCalled();
    });

    it('keeps its buttons from submitting a form around it', () => {
        const wrapper = mount(XqTable, { props: { table: schema({ pagination: paged }) } });

        expect(
            wrapper.findAll('button').every((button) => button.attributes('type') === 'button'),
        ).toBe(true);
    });

    it("shows the version message for a schema it can't read", () => {
        const { query: _query, ...renamed } = schema({ $xq: 2 });

        window.history.replaceState(null, '', '/products?products-table[page]=2');

        const wrapper = mount(XqTable, { props: { table: renamed as TableSchema } });

        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });

    it('turns off the buttons at the ends', () => {
        const wrapper = mount(XqTable, { props: { table: schema({ pagination: paged }) } });

        expect(
            wrapper.find('button[aria-label="Previous page"]').attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper.find('button[aria-label="Next page"]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('loads the url when it is newer than the page, as after Back', async () => {
        const fetchMock = respondWith({
            query: { page: 2 },
            rows: [],
            pagination: { ...paged, page: 2 },
        });

        vi.stubGlobal('fetch', fetchMock);
        window.history.replaceState(null, '', '/products?products-table[page]=2');
        mount(XqTable, { props: { table: schema({ pagination: paged }) } });
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock)).query).toEqual({ page: '2' });
    });

    it('sends nothing when the url matches the page', async () => {
        const fetchMock = respondWith({});

        vi.stubGlobal('fetch', fetchMock);
        window.history.replaceState(null, '', '/products?products-table[page]=2');
        mount(XqTable, { props: { table: schema({ query: { page: 2 } }) } });
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('offers a retry when a request fails', async () => {
        const fetchMock = respondWith({}, 500);

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table: schema({ pagination: paged }) } });

        await wrapper.find('button[aria-label="Next page"]').trigger('click');
        await flushPromises();

        const alert = wrapper.find('[role="alert"]');

        expect(alert.text()).toContain('Could not load the records.');

        await alert.find('button').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(sentBody(fetchMock, 1)).toBe(sentBody(fetchMock));
    });

    it('starts over when a visit brings a new table', async () => {
        const wrapper = mount(XqTable, { props: { table: schema() } });

        await wrapper.setProps({
            table: schema({
                token: 'new',
                rows: [{ key: '2', cells: { name: { state: 'Mouse' } } }],
            }),
        });

        expect(wrapper.find('tbody td').text()).toBe('Mouse');
    });

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

    it('shows text in the language the server sent', () => {
        const table = schema({ rows: [], messages: { 'No records found.': 'Sin registros.' } });
        const wrapper = mount(XqTable, { props: { table } });

        expect(wrapper.text()).toContain('Sin registros.');
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
