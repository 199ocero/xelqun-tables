import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { createFormStore, createXelqunForms, defaultComponents, XqForm } from '@xelqun/forms';
import { fieldNode, formSchema } from '@xelqun/forms/testing';
import { builtInCells } from '../../resources/js/components/cells';
import XqTable from '../../resources/js/components/XqTable.vue';
import { tablesPlugin } from '../../resources/js';
import type { TableSchema } from '../../resources/js/schema/types';

const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { replace, on: vi.fn(() => vi.fn()) },
    Link: { props: ['href'], template: '<a data-inertia-link :href="href"><slot /></a>' },
}));

function schema(overrides: Partial<TableSchema> = {}): TableSchema {
    return {
        $xq: 1,
        pkg: '0.1.0',
        id: 'products-table',
        token: 'token',
        endpoints: { query: '/query', update: '/update', reorder: '/reorder', action: '/action' },
        layout: { grid: null, striped: false, pollSeconds: null, reorderable: false },
        headerActions: [],
        bulkActions: [],
        maxSelectable: 1000,
        searchable: false,
        filters: null,
        filterDefaults: {},
        query: {},
        sort: null,
        activeFilters: 0,
        summaries: {},
        columns: [{ name: 'name', type: 'text-column', label: 'Name', sortable: false, props: {} }],
        rows: [{ key: '1', cells: { name: { state: 'Keyboard' } }, actions: [] }],
        pagination: { page: 1, perPage: 10, total: 1, lastPage: 1, options: [10] },
        ...overrides,
    };
}

function respondWith(result: Record<string, unknown>, status = 200) {
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

    it('shows a scrollbar for a wide table and keeps it in step with the table', async () => {
        let resized = (): void => {};

        vi.stubGlobal(
            'ResizeObserver',
            class {
                constructor(callback: () => void) {
                    resized = callback;
                }

                observe(): void {}

                disconnect(): void {}
            },
        );

        const wrapper = mount(XqTable, { props: { table: schema() } });
        const table = wrapper.find<HTMLElement>('[data-xq-table-scroll]');
        const bar = wrapper.find<HTMLElement>('[data-xq-table-scrollbar]');

        expect(bar.attributes('tabindex')).toBe('-1');

        await flushPromises();
        Object.defineProperty(table.element, 'scrollWidth', { value: 500, configurable: true });
        Object.defineProperty(table.element, 'clientWidth', { value: 500, configurable: true });
        resized();
        await flushPromises();

        expect(bar.element.style.display).toBe('none');

        Object.defineProperty(table.element, 'scrollWidth', { value: 900 });
        resized();
        await flushPromises();

        expect(bar.element.style.display).toBe('');
        expect(bar.find('div').element.style.width).toBe('900px');

        bar.element.scrollLeft = 120;
        await bar.trigger('scroll');
        expect(table.element.scrollLeft).toBe(120);

        table.element.scrollLeft = 40;
        await table.trigger('scroll');
        expect(bar.element.scrollLeft).toBe(40);

        // Dragging on before the table's own scroll event arrives doesn't pull the bar back.
        bar.element.scrollLeft = 200;
        await bar.trigger('scroll');
        bar.element.scrollLeft = 260;
        await table.trigger('scroll');
        expect(bar.element.scrollLeft).toBe(260);

        await bar.trigger('scroll');
        expect(table.element.scrollLeft).toBe(260);
    });

    it('wraps only the cells of a column that asks for it', () => {
        const column = { type: 'text-column', label: 'Label', sortable: false };
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    columns: [
                        { ...column, name: 'name', props: {} },
                        { ...column, name: 'notes', props: { wrap: true } },
                    ],
                    rows: [
                        {
                            key: '1',
                            cells: { name: { state: 'Lamp' }, notes: { state: 'Long' } },
                            actions: [],
                        },
                    ],
                }),
            },
        });
        const [plain, wrapped] = wrapper.findAll('[data-xq-table-row] td');

        expect(wrapper.find('table').classes()).toContain('whitespace-nowrap');
        expect(plain!.classes()).not.toContain('whitespace-normal');
        expect(wrapped!.classes()).toContain('whitespace-normal');
    });

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

    it('asks for ascending, then descending, on a sortable column', async () => {
        const fetchMock = respondWith({
            query: { sort: 'name' },
            sort: 'name',
            rows: [],
            pagination: paged,
        });

        vi.stubGlobal('fetch', fetchMock);

        const table = schema({
            pagination: { ...paged, page: 2 },
            query: { page: 2 },
            columns: [
                { name: 'name', type: 'text-column', label: 'Name', sortable: true, props: {} },
            ],
        });

        window.history.replaceState(null, '', '/products?products-table[page]=2');

        const wrapper = mount(XqTable, { props: { table } });

        await wrapper.find('th button').trigger('click');
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock)).query).toEqual({ page: 1, sort: 'name' });
        expect(wrapper.find('th').attributes('aria-sort')).toBe('ascending');

        await wrapper.find('th button').trigger('click');
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock, 1)).query.sort).toBe('-name');
    });

    it('waits for a pause in typing before it searches', async () => {
        vi.useFakeTimers();

        const fetchMock = respondWith({
            query: { search: 'ke' },
            sort: null,
            rows: [],
            pagination: paged,
        });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table: schema({ searchable: true }) } });
        const input = wrapper.find('input[type="search"]');

        await input.setValue('k');
        await vi.advanceTimersByTimeAsync(100);
        await input.setValue('ke');
        await vi.advanceTimersByTimeAsync(299);

        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(JSON.parse(sentBody(fetchMock)).query).toEqual({ search: 'ke', page: 1 });
        vi.useRealTimers();
    });

    it('does not overwrite what is being typed with an older answer', async () => {
        const wrapper = mount(XqTable, {
            props: { table: schema({ searchable: true, query: { search: 'k' } }) },
            attachTo: document.body,
        });
        const input = wrapper.find<HTMLInputElement>('input[type="search"]');

        input.element.focus();
        await input.setValue('ke');
        await wrapper.setProps({
            table: schema({ searchable: true, token: 'new', query: { search: 'k' } }),
        });

        expect(input.element.value).toBe('ke');
        wrapper.unmount();
    });

    it('does not search after leaving the page', async () => {
        vi.useFakeTimers();

        const fetchMock = respondWith({});

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table: schema({ searchable: true }) } });

        await wrapper.find('input[type="search"]').setValue('k');
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(1000);

        expect(fetchMock).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('says where the viewer is', () => {
        const pagination = { page: 2, perPage: 10, total: 25, lastPage: 3, options: [10] };
        const wrapper = mount(XqTable, { props: { table: schema({ pagination }) } });

        expect(wrapper.text()).toContain('Showing 11 to 20 of 25');
        expect(wrapper.text()).toContain('Page 2 of 3');
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
                rows: [{ key: '2', cells: { name: { state: 'Mouse' } }, actions: [] }],
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

    it("shows the table's own empty state", () => {
        const table = schema({
            rows: [],
            emptyState: { heading: 'No products', description: 'Add one.' },
        });
        const wrapper = mount(XqTable, { props: { table } });

        expect(wrapper.text()).toContain('No products');
        expect(wrapper.text()).toContain('Add one.');
        expect(wrapper.text()).not.toContain('No records found.');
    });

    it("links every cell of a row, with a cell's own link winning", () => {
        const table = schema({
            columns: [
                { name: 'name', type: 'text-column', label: 'Name', sortable: false, props: {} },
                { name: 'site', type: 'text-column', label: 'Site', sortable: false, props: {} },
            ],
            rows: [
                {
                    key: '1',
                    url: '/products/1',
                    cells: { name: { state: 'Keyboard' }, site: { state: 'Shop', url: '/shop' } },
                    actions: [],
                },
            ],
        });
        const wrapper = mount(XqTable, { props: { table } });
        const links = wrapper.findAll('tbody a');

        expect(links.map((link) => link.attributes('href'))).toEqual(['/products/1', '/shop']);
        expect(links[0].classes()).toContain('block');
        expect(links[1].classes()).toContain('hover:underline');
    });

    it("stops Tab on a row's link once", () => {
        const table = schema({
            columns: [
                { name: 'name', type: 'text-column', label: 'Name', sortable: false, props: {} },
                { name: 'price', type: 'text-column', label: 'Price', sortable: false, props: {} },
            ],
            rows: [
                {
                    key: '1',
                    url: '/products/1',
                    cells: { name: { state: 'Keyboard' }, price: { state: '$5' } },
                    actions: [],
                },
            ],
        });
        const wrapper = mount(XqTable, { props: { table } });

        expect(wrapper.findAll('tbody a').map((link) => link.attributes('tabindex'))).toEqual([
            undefined,
            '-1',
        ]);
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
            columns: [
                { name: 'rating', type: 'acme/stars', label: 'Rating', sortable: false, props: {} },
            ],
            rows: [{ key: '1', cells: { rating: { state: 4 } }, actions: [] }],
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
            columns: [
                { name: 'name', type: 'acme/missing', label: 'Name', sortable: false, props: {} },
            ],
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

describe('cells', () => {
    function cell(type: string, cellProps: Record<string, any>, columnProps = {}, options = {}) {
        const table = schema({
            columns: [
                { name: 'a', type, label: 'A', sortable: false, props: columnProps, ...options },
            ],
            rows: [{ key: '1', cells: { a: { state: null, ...cellProps } }, actions: [] }],
        });

        return mount(XqTable, { props: { table } }).find('tbody td');
    }

    it('joins a list in a text cell', () => {
        expect(cell('text-column', { state: ['a', 'b'] }).text()).toBe('a, b');
    });

    it('shows a pill for each value, in its color', () => {
        const pills = cell(
            'text-column',
            { state: ['a', 'b'], color: 'success' },
            { badge: true },
        ).findAll('[data-slot="xq-badge"]');

        expect(pills).toHaveLength(2);
        expect(pills[0]!.classes()).toContain('text-xq-success');
    });

    it('shows no pill for an empty value', () => {
        expect(
            cell('text-column', {}, { badge: true }).find('[data-slot="xq-badge"]').exists(),
        ).toBe(false);
    });

    it('gives an icon a name screen readers can read', () => {
        const icon = cell('icon-column', { icon: 'check', color: 'success', label: 'Yes' });

        expect(icon.find('.sr-only').text()).toBe('Yes');
        expect(icon.find('svg').exists()).toBe(true);
    });

    it('sizes an image and describes it', () => {
        const image = cell(
            'image-column',
            { state: '/a.png', alt: 'Photo' },
            { size: 32, circular: true },
        ).find('img');

        expect(image.attributes('alt')).toBe('Photo');
        expect(image.attributes('width')).toBe('32');
        expect(image.classes()).toContain('rounded-full');
    });

    it('shows no broken image for an empty state', () => {
        expect(cell('image-column', {}, { size: 32 }).find('img').exists()).toBe(false);
    });

    it('links a cell, with an Inertia visit on this site', () => {
        expect(
            cell('text-column', { state: 'a', url: '/a' })
                .find('[data-inertia-link]')
                .attributes('href'),
        ).toBe('/a');
    });

    it('uses a plain link for another site or a new tab', () => {
        const external = cell('text-column', { state: 'a', url: 'https://example.com/a' }).find(
            'a',
        );

        expect(external.attributes('data-inertia-link')).toBeUndefined();

        const newTab = cell(
            'text-column',
            { state: 'a', url: '/a' },
            {},
            { opensInNewTab: true },
        ).find('a');

        expect(newTab.attributes('target')).toBe('_blank');
        expect(newTab.attributes('rel')).toBe('noopener noreferrer');
    });

    it('keeps repeated images apart', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        cell('image-column', { state: ['/a.png', '/a.png'], alt: 'A' }, { size: 32 });

        expect(warn).not.toHaveBeenCalled();
        warn.mockRestore();
    });

    it('adds no link to a cell without a url', () => {
        expect(cell('text-column', { state: 'a' }).find('a').exists()).toBe(false);
    });
});

describe('filters', () => {
    const filters = formSchema([fieldNode('data.status', 'text-input')], { status: null });
    const table = () => schema({ filters, filterDefaults: { status: null } });

    function popoverButton(label: string): HTMLButtonElement {
        return [
            ...document.body.querySelectorAll<HTMLButtonElement>('[data-slot="xq-popover"] button'),
        ].find((button) => button.textContent?.includes(label))!;
    }

    async function openFilters(wrapper: ReturnType<typeof mount>) {
        await wrapper.find('[data-xq-table-filters]').trigger('click');
        await flushPromises();

        return document.body.querySelector<HTMLInputElement>('[data-slot="xq-popover"] input')!;
    }

    it('shows how many filters are on', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({ filters, filterDefaults: { status: null }, activeFilters: 2 }),
            },
        });

        expect(wrapper.find('[data-xq-table-filters] [data-slot="xq-badge"]').text()).toBe('2');
    });

    it('sends the form when applied, and goes back to page one', async () => {
        const fetchMock = respondWith({
            query: { filters: { status: 'draft' } },
            sort: null,
            activeFilters: 1,
            rows: [],
        });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table: table() }, attachTo: document.body });
        const input = await openFilters(wrapper);

        input.value = 'draft';
        input.dispatchEvent(new Event('input'));
        document.body
            .querySelector<HTMLFormElement>('[data-slot="xq-popover"] form')!
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock)).query).toEqual({
            filters: { status: 'draft' },
            page: 1,
        });
        wrapper.unmount();
    });

    it('goes back to the defaults on reset', async () => {
        const fetchMock = respondWith({ query: {}, sort: null, activeFilters: 0, rows: [] });

        vi.stubGlobal('fetch', fetchMock);

        const start = { ...table(), query: { filters: { status: 'draft' } } };

        window.history.replaceState(null, '', '/products?products-table[filters][status]=draft');

        const wrapper = mount(XqTable, { props: { table: start }, attachTo: document.body });

        await openFilters(wrapper);
        popoverButton('Reset').click();
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock)).query.filters).toEqual({ status: null });
        wrapper.unmount();
    });

    it('fills the form from what the table loaded, as after Back', async () => {
        const fetchMock = respondWith({
            query: { filters: { status: 'draft' } },
            sort: null,
            activeFilters: 1,
            rows: [],
        });

        vi.stubGlobal('fetch', fetchMock);
        window.history.replaceState(null, '', '/products?products-table[filters][status]=draft');

        const wrapper = mount(XqTable, { props: { table: table() }, attachTo: document.body });

        await flushPromises();

        const input = await openFilters(wrapper);

        expect(input.value).toBe('draft');
        wrapper.unmount();
    });
});

describe('actions', () => {
    const action = (name: string, props = {}) => ({
        name,
        props: { label: name, style: 'button', hasForm: false, heading: name, ...props },
    });
    const rows = [{ key: '7', cells: { name: { state: 'Keyboard' } }, actions: [action('touch')] }];

    function mountTable(table: Partial<TableSchema>) {
        return mount(XqTable, { props: { table: schema(table) }, attachTo: document.body });
    }

    it('adds an actions column only when a row has actions', () => {
        expect(mountTable({ rows }).findAll('th')).toHaveLength(2);
        expect(mount(XqTable, { props: { table: schema() } }).findAll('th')).toHaveLength(1);
    });

    it('sends the row and the action, then reloads the rows', async () => {
        const fetchMock = respondWith({ query: {}, sort: null, activeFilters: 0, rows: [] });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountTable({ rows, query: { page: 1 } });

        window.history.replaceState(null, '', '/products?products-table[page]=1');
        await wrapper.find('tbody [data-xq-action] button').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.map((call) => call[0]).slice(-2)).toEqual([
            '/action',
            '/query',
        ]);
        expect(JSON.parse(sentBody(fetchMock, fetchMock.mock.calls.length - 2))).toMatchObject({
            token: 'token',
            action: 'touch',
            kind: 'record',
            record: '7',
            phase: 'run',
        });
        wrapper.unmount();
    });

    describe('in a menu', () => {
        const group = (actions: ReturnType<typeof action>[], name = 'data#1') => ({
            name,
            props: {},
            actions,
        });

        function items(): HTMLElement[] {
            return [...document.querySelectorAll<HTMLElement>('[role="menuitem"]')];
        }

        async function openMenu(wrapper: ReturnType<typeof mountTable>): Promise<void> {
            await wrapper
                .find('[data-xq-action-group] button')
                .trigger('keydown', { key: 'Enter' });
            await flushPromises();
        }

        afterEach(() => {
            document.body.innerHTML = '';
        });

        it('sends the row and the action of a menu item', async () => {
            const fetchMock = respondWith({ query: {}, sort: null, activeFilters: 0, rows: [] });

            vi.stubGlobal('fetch', fetchMock);

            const wrapper = mountTable({
                rows: [{ ...rows[0]!, actions: [group([action('touch')])] }],
            });

            await openMenu(wrapper);
            items()[0]!.click();
            await flushPromises();

            expect(JSON.parse(sentBody(fetchMock))).toMatchObject({
                action: 'touch',
                kind: 'record',
                record: '7',
                phase: 'run',
            });
            wrapper.unmount();
        });

        it('renders a menu in the header and for selected rows', async () => {
            const wrapper = mountTable({
                rows,
                headerActions: [group([action('import')], 'data#0')],
                bulkActions: [group([action('archive')], 'data#0')],
            });

            await wrapper.find('tbody input, tbody [role="checkbox"]').trigger('click');
            await flushPromises();

            expect(wrapper.findAll('[data-xq-action-group]')).toHaveLength(2);
            wrapper.unmount();
        });
    });

    it('shows the notifications an action sends', async () => {
        const body = {
            query: {},
            sort: null,
            activeFilters: 0,
            rows: [],
            notifications: [{ title: 'Touched' }],
        };

        vi.stubGlobal('fetch', respondWith(body));

        const app = createApp({ render: () => h('div') });

        app.use(createXelqunForms());
        app.mount(document.createElement('div'));

        const wrapper = mountTable({ rows });

        await wrapper.find('tbody [data-xq-action] button').trigger('click');
        await flushPromises();

        expect(document.querySelector('[data-slot="xq-notifications"]')?.textContent).toContain(
            'Touched',
        );
        wrapper.unmount();
        app.unmount();
    });

    it('sends a header action with no record', async () => {
        const fetchMock = respondWith({ query: {}, sort: null, activeFilters: 0, rows: [] });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountTable({ headerActions: [action('export')] });

        await wrapper.find('[data-xq-action] button').trigger('click');
        await flushPromises();

        const body = JSON.parse(sentBody(fetchMock));

        expect(body).toMatchObject({ action: 'export', kind: 'header', phase: 'run' });
        expect(body).not.toHaveProperty('record');
        wrapper.unmount();
    });

    it('asks first when an action needs confirming', async () => {
        const fetchMock = respondWith({});

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mountTable({
            rows: [{ ...rows[0]!, actions: [action('delete', { confirmation: true })] }],
        });

        await wrapper.find('tbody [data-xq-action] button').trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.querySelector('[role="alertdialog"]')).not.toBeNull();
        wrapper.unmount();
    });

    it('says so plainly when a row has gone', async () => {
        vi.stubGlobal('fetch', respondWith({}, 404));

        const wrapper = mountTable({ rows });

        await wrapper.find('tbody [data-xq-action] button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('That action could not run.');
        wrapper.unmount();
    });

    it('shows why an action failed and keeps the rows', async () => {
        vi.stubGlobal('fetch', respondWith({ errors: { 'data.name': ['Not today.'] } }, 422));

        const wrapper = mountTable({ rows });

        await wrapper.find('tbody [data-xq-action] button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('Not today.');
        expect(wrapper.find('tbody td').text()).toBe('Keyboard');
        wrapper.unmount();
    });
});

describe('selection', () => {
    const bulk = [
        { name: 'remove', props: { label: 'Remove', style: 'button', heading: 'Remove' } },
    ];
    const rows = [
        { key: '1', cells: { name: { state: 'One' } }, actions: [] },
        { key: '2', cells: { name: { state: 'Two' } }, actions: [] },
    ];
    const table = (extra: Partial<TableSchema> = {}) =>
        schema({
            bulkActions: bulk,
            rows,
            pagination: { page: 1, perPage: 2, total: 5, lastPage: 3, options: [2] },
            ...extra,
        });

    it('shows checkboxes only with bulk actions', () => {
        expect(
            mount(XqTable, { props: { table: schema({ rows }) } })
                .find('tbody [role="checkbox"]')
                .exists(),
        ).toBe(false);
        expect(
            mount(XqTable, { props: { table: table() } }).findAll('tbody [role="checkbox"]'),
        ).toHaveLength(2);
    });

    it('shows the bar and a mixed header while part of the page is selected', async () => {
        const wrapper = mount(XqTable, { props: { table: table() } });

        expect(wrapper.find('[data-xq-table-bulk]').exists()).toBe(false);

        await wrapper.findAll('tbody [role="checkbox"]')[0]!.trigger('click');

        expect(wrapper.find('[data-xq-table-bulk]').text()).toContain('1 selected');
        expect(wrapper.find('th [role="checkbox"]').attributes('aria-checked')).toBe('mixed');
    });

    it('selects the page from the header', async () => {
        const wrapper = mount(XqTable, { props: { table: table() } });

        await wrapper.find('th [role="checkbox"]').trigger('click');

        expect(wrapper.find('[data-xq-table-bulk]').text()).toContain('2 selected');
        expect(wrapper.find('th [role="checkbox"]').attributes('aria-checked')).toBe('true');
    });

    it('offers select all only within the limit', async () => {
        const within = mount(XqTable, { props: { table: table() } });

        await within.find('th [role="checkbox"]').trigger('click');

        expect(within.find('[data-xq-table-bulk]').text()).toContain('Select all 5');

        const over = mount(XqTable, { props: { table: table({ maxSelectable: 4 }) } });

        await over.find('th [role="checkbox"]').trigger('click');

        expect(over.find('[data-xq-table-bulk]').text()).not.toContain('Select all');
    });

    it('clears from the bar', async () => {
        const wrapper = mount(XqTable, { props: { table: table() } });

        await wrapper.find('th [role="checkbox"]').trigger('click');

        const clear = wrapper
            .findAll('[data-xq-table-bulk] button')
            .find((button) => button.text() === 'Clear selection')!;

        await clear.trigger('click');

        expect(wrapper.find('[data-xq-table-bulk]').exists()).toBe(false);
    });
});

describe('the gliding row highlight', () => {
    const columns = [
        { name: 'name', type: 'text-column', label: 'Name', sortable: false, props: {} },
    ];
    const rows = ['1', '2'].map((key) => ({
        key,
        cells: { name: { state: 'Lamp' } },
        actions: [],
    }));
    const render = (overrides: Partial<TableSchema> = {}) =>
        mount(XqTable, {
            props: { table: schema({ columns, rows, ...overrides }) },
            global: { plugins: [createXelqunForms()] },
        });
    const glide = (wrapper: ReturnType<typeof render>) => wrapper.find('[data-xq-table-glide]');
    // The glide measures on the next frame.
    const frame = () =>
        new Promise((resolve) => requestAnimationFrame(resolve)).then(() => nextTick());

    it('stays hidden until a row is hovered', () => {
        expect(glide(render()).attributes('style')).toContain('opacity: 0');
    });

    it('appears on the first row, then slides to the next', async () => {
        const wrapper = render();
        const tableRows = wrapper.findAll('[data-xq-table-row]');

        await tableRows[0].trigger('mouseover');
        await frame();
        expect(glide(wrapper).attributes('style')).toContain('opacity: 1');
        expect(glide(wrapper).classes().join(' ')).not.toContain('[transform');

        await tableRows[1].trigger('mouseover');
        await frame();
        expect(glide(wrapper).classes().join(' ')).toContain('[transform');
    });

    it('hides over the header, and when the pointer leaves', async () => {
        const wrapper = render();

        await wrapper.find('[data-xq-table-row]').trigger('mouseover');
        await wrapper.find('thead').trigger('mouseover');
        expect(glide(wrapper).attributes('style')).toContain('opacity: 0');

        await wrapper.find('[data-xq-table-row]').trigger('mouseover');
        await wrapper.find('[data-xq-table-scroll]').trigger('mouseleave');
        expect(glide(wrapper).attributes('style')).toContain('opacity: 0');
    });

    it('follows the keyboard too', async () => {
        const wrapper = render();

        await wrapper.find('[data-xq-table-row]').trigger('focusin');
        await frame();

        expect(glide(wrapper).attributes('style')).toContain('opacity: 1');
    });

    it('stays out of the way of screen readers and clicks', () => {
        const wrapper = render();

        expect(glide(wrapper).attributes('aria-hidden')).toBe('true');
        expect(glide(wrapper).classes()).toContain('pointer-events-none');
    });

    it('has no highlight on cards', () => {
        const layout = { grid: 2, striped: false, pollSeconds: null, reorderable: false };

        expect(glide(render({ layout })).exists()).toBe(false);
    });
});

describe('layouts', () => {
    const layout = { grid: null, striped: false, pollSeconds: null, reorderable: false };
    const row = { key: '1', cells: { name: { state: 'Lamp' } }, actions: [] };
    const columns = [
        {
            name: 'name',
            type: 'text-column',
            label: 'Name',
            sortable: false,
            toggleable: true,
            props: {},
        },
    ];
    const render = (overrides: Partial<TableSchema>) =>
        mount(XqTable, {
            props: { table: schema({ columns, rows: [row], ...overrides }) },
            global: { plugins: [createXelqunForms()] },
        });

    afterEach(() => localStorage.clear());

    it('shows rows as cards', () => {
        const wrapper = render({ layout: { ...layout, grid: 2 } });

        expect(wrapper.find('[data-xq-table-grid]').text()).toContain('Lamp');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('offers a move for each row only on a reorderable table', () => {
        expect(render({}).find('[aria-label="Move up"]').exists()).toBe(false);
        expect(
            render({ layout: { ...layout, reorderable: true } })
                .find('[aria-label="Move up"]')
                .exists(),
        ).toBe(true);
    });

    it('starts with a column hidden by default, and remembers a choice', async () => {
        const hidden = [{ ...columns[0], hiddenByDefault: true }];

        expect(render({ columns: hidden }).find('tbody').text()).not.toContain('Lamp');

        localStorage.setItem('xq-table-columns:products-table', '[]');
        const wrapper = render({ columns: hidden });

        await flushPromises();

        expect(wrapper.find('tbody').text()).toContain('Lamp');
    });

    it('survives something else in the saved columns', async () => {
        localStorage.setItem('xq-table-columns:products-table', 'not json');
        const wrapper = render({});

        await flushPromises();

        expect(wrapper.find('tbody').text()).toContain('Lamp');
    });
});

describe('editable cells and grouped rows', () => {
    const cellProps = (state: unknown, canEdit: boolean, extra = {}) => ({
        column: { name: 'a', type: 'x', label: 'Featured', sortable: false, props: extra },
        cell: { state, canEdit },
    });

    it("turns a switch off for someone who can't edit", () => {
        const wrapper = mount(builtInCells['toggle-column'] as never, {
            props: cellProps(true, false) as never,
            global: { plugins: [createXelqunForms()] },
        });

        expect(wrapper.find('button').attributes('disabled')).toBeDefined();
    });

    it('sends a changed value once, on leaving the box', async () => {
        const wrapper = mount(builtInCells['text-input-column'] as never, {
            props: cellProps('Lamp', true) as never,
            global: { plugins: [createXelqunForms()] },
        });
        const input = wrapper.find('input');

        await input.trigger('blur');
        expect(wrapper.emitted('update')).toBeUndefined();

        await input.setValue('Bulb');
        await input.trigger('blur');
        expect(wrapper.emitted('update')).toEqual([['Bulb']]);
    });

    it('puts a heading over each group, and a line under the columns', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    columns: [
                        {
                            name: 'name',
                            type: 'text-column',
                            label: 'Name',
                            sortable: false,
                            props: {},
                        },
                    ],
                    summaries: { name: [{ label: 'Count', value: 3 }] },
                    rows: ['Red', 'Red', 'Blue'].map((group, index) => ({
                        key: String(index),
                        group,
                        cells: { name: { state: 'x' } },
                        actions: [],
                    })),
                }),
            },
            global: { plugins: [createXelqunForms()] },
        });

        expect(wrapper.findAll('[data-xq-table-group]').map((row) => row.text())).toEqual([
            'Red',
            'Blue',
        ]);
        expect(wrapper.find('[data-xq-table-summary]').text()).toMatch(/Count:\s+3/);
    });
});

describe('editing and polling', () => {
    const layout = { grid: null, striped: false, pollSeconds: 5, reorderable: false };
    const columns = [
        {
            name: 'stock',
            type: 'text-input-column',
            label: 'Stock',
            sortable: false,
            props: { editable: true },
        },
    ];
    const rows = [{ key: '1', cells: { stock: { state: '42', canEdit: true } }, actions: [] }];

    afterEach(() => vi.useRealTimers());

    it('shows a refused value on its cell and keeps what was typed', async () => {
        vi.stubGlobal(
            'fetch',
            respondWith({ errors: { value: ['Stock must be at least 0.'] } }, 422),
        );

        const wrapper = mount(XqTable, {
            props: { table: schema({ columns, rows }) },
            global: { plugins: [createXelqunForms()] },
        });
        const input = wrapper.find('input');

        await input.setValue('-5');
        await input.trigger('blur');
        await flushPromises();

        expect(wrapper.find('[data-xq-cell-error]').text()).toBe('Stock must be at least 0.');
        expect(wrapper.find('input').element.value).toBe('-5');
        expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('[role="alert"] button').exists()).toBe(false);
    });

    it('shows a refused value on its card too', async () => {
        vi.stubGlobal('fetch', respondWith({ errors: { value: ['Too low.'] } }, 422));

        const wrapper = mount(XqTable, {
            props: {
                table: schema({ columns, rows, layout: { ...layout, pollSeconds: null, grid: 2 } }),
            },
            global: { plugins: [createXelqunForms()] },
        });

        await wrapper.find('input').setValue('-5');
        await wrapper.find('input').trigger('blur');
        await flushPromises();

        expect(wrapper.find('[data-xq-table-grid] [data-xq-cell-error]').text()).toBe('Too low.');
    });

    it('skips a poll while a cell is being typed in', async () => {
        vi.useFakeTimers();

        const fetchMock = respondWith({});

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, {
            props: { table: schema({ columns, rows, layout }) },
            global: { plugins: [createXelqunForms()] },
            attachTo: document.body,
        });

        wrapper.find<HTMLInputElement>('input').element.focus();
        vi.advanceTimersByTime(5000);
        expect(fetchMock).not.toHaveBeenCalled();

        wrapper.find<HTMLInputElement>('input').element.blur();
        vi.advanceTimersByTime(5000);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('offers no move past the first or last row', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    layout: { ...layout, pollSeconds: null, reorderable: true },
                    rows: [
                        { key: '1', cells: { name: { state: 'A' } }, actions: [] },
                        { key: '2', cells: { name: { state: 'B' } }, actions: [] },
                    ],
                }),
            },
            global: { plugins: [createXelqunForms()] },
        });
        const up = wrapper.findAll('[aria-label="Move up"]');
        const down = wrapper.findAll('[aria-label="Move down"]');

        expect(up[0].attributes('disabled')).toBeDefined();
        expect(up[1].attributes('disabled')).toBeUndefined();
        expect(down[1].attributes('disabled')).toBeDefined();
        expect(up[1].attributes('aria-describedby')).toBe('xq-products-table-2');
        expect(wrapper.find('#xq-products-table-2').text()).toBe('B');
    });
});

describe('sorting cards', () => {
    it('sorts a grid from a menu, since cards have no header', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    layout: { grid: 2, striped: false, pollSeconds: null, reorderable: false },
                    columns: [
                        {
                            name: 'name',
                            type: 'text-column',
                            label: 'Name',
                            sortable: true,
                            props: {},
                        },
                    ],
                }),
            },
            global: { plugins: [createXelqunForms()] },
        });

        expect(wrapper.find('label[for="xq-products-table-sort"]').text()).toBe('Sort by');
        expect(wrapper.find('#xq-products-table-sort').exists()).toBe(true);
    });
});

describe('text cells', () => {
    function render(props: Record<string, unknown>, cell: Record<string, unknown>) {
        return mount(XqTable, {
            props: {
                table: schema({
                    columns: [
                        {
                            name: 'name',
                            type: 'text-column',
                            label: 'Name',
                            sortable: false,
                            props,
                        },
                    ],
                    rows: [{ key: '1', cells: { name: cell as any }, actions: [] }],
                }),
            },
        });
    }

    it('shows a placeholder, a description and a tooltip', () => {
        const empty = render({ placeholder: 'None' }, { state: null });

        expect(empty.find('[data-xq-cell-placeholder]').text()).toBe('None');

        const described = render({}, { state: 'Lamp', description: 'In stock', tooltip: 'Full' });

        expect(described.find('[data-xq-cell-description]').text()).toBe('In stock');
        expect(described.find('[data-xq-table-row] td').attributes('title')).toBe('Full');
    });

    it('shows the first values of a list and opens the rest when it may', async () => {
        const wrapper = render(
            { bulleted: true, limitList: 2, expandableLimitedList: true },
            { state: ['A', 'B', 'C'] },
        );

        expect(wrapper.findAll('li').map((item) => item.text())).toEqual(['A', 'B']);
        expect(wrapper.find('[data-xq-cell-more]').text()).toBe('and 1 more');

        await wrapper.find('[data-xq-cell-more]').trigger('click');

        expect(wrapper.findAll('li')).toHaveLength(3);

        const fixed = render({ listWithLineBreaks: true, limitList: 1 }, { state: ['A', 'B'] });

        expect(fixed.find('[data-xq-cell-more]').element.tagName).toBe('SPAN');
    });

    it('copies its value', async () => {
        const writeText = vi.fn(() => Promise.resolve());

        vi.stubGlobal('navigator', { clipboard: { writeText } });

        const wrapper = render({ copyable: true, copyMessage: 'Done' }, { state: 'SKU-1' });

        await wrapper.find('[data-xq-cell-copy]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('SKU-1');
        expect(wrapper.find('[role="status"]').text()).toBe('Done');
    });
});

describe('table chrome', () => {
    it('shows its heading, groups its columns and lines up a column', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    heading: 'Products',
                    description: 'In stock',
                    columns: [
                        {
                            name: 'name',
                            type: 'text-column',
                            label: 'Name',
                            sortable: false,
                            props: {},
                        },
                        {
                            name: 'price',
                            type: 'text-column',
                            label: 'Price',
                            sortable: false,
                            alignment: 'end',
                            group: 'Money',
                            props: {},
                        },
                    ],
                    rows: [
                        {
                            key: '1',
                            cells: { name: { state: 'Lamp' }, price: { state: 5 } },
                            actions: [],
                        },
                    ],
                }),
            },
        });

        expect(wrapper.find('[data-xq-table-heading]').text()).toContain('Products');
        expect(
            wrapper.findAll('[data-xq-table-column-groups] th').map((cell) => cell.text()),
        ).toEqual(['', 'Money']);
        expect(wrapper.findAll('thead tr')[1].findAll('th')[1].classes()).toContain('text-right');
        expect(wrapper.findAll('[data-xq-table-row] td')[1].classes()).toContain('text-right');
    });

    it('removes a filter from its chip', async () => {
        const filtered = schema({
            query: { filters: { status: 'active', priced: true } },
            indicators: [{ label: 'Status: Active', fields: ['status'] }],
        });
        const fetchMock = respondWith({ ...filtered });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table: filtered } });

        await flushPromises();
        expect(wrapper.find('[data-xq-table-indicators]').text()).toContain('Status: Active');

        await wrapper.find('[data-xq-table-indicators] button').trigger('click');
        await flushPromises();

        const last = fetchMock.mock.calls.length - 1;

        expect(JSON.parse(sentBody(fetchMock, last)).query.filters).toEqual({
            status: null,
            priced: true,
        });
    });

    it('shows the empty state with its icon and actions, in a grid too', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    rows: [],
                    layout: { grid: 2, striped: false, pollSeconds: null, reorderable: false },
                    emptyState: { heading: 'No products', icon: 'package' },
                    emptyStateActions: [
                        { name: 'create', props: { label: 'Create', style: 'button' } },
                    ],
                }),
            },
        });

        expect(wrapper.find('[data-xq-table-empty]').text()).toContain('No products');
        expect(wrapper.find('[data-xq-table-empty] [data-xq-action]').text()).toContain('Create');
    });
});

describe('records', () => {
    it('leaves the checkbox off a row that can not be selected, and skips it on select page', async () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    bulkActions: [
                        { name: 'archive', props: { label: 'Archive', style: 'button' } },
                    ],
                    rows: [
                        { key: '1', cells: { name: { state: 'Open' } }, actions: [] },
                        {
                            key: '2',
                            selectable: false,
                            cells: { name: { state: 'Locked' } },
                            actions: [],
                        },
                    ],
                }),
            },
        });
        const rows = wrapper.findAll('[data-xq-table-row]');

        expect(rows[1].find('[role="checkbox"]').exists()).toBe(false);

        await wrapper.find('thead [role="checkbox"]').trigger('click');

        expect(wrapper.text()).toContain('1');
        expect(rows[0].classes()).toContain('bg-xq-accent/60');
        expect(wrapper.findAll('[data-xq-table-row]')[1].classes()).not.toContain(
            'bg-xq-accent/60',
        );
    });

    it('opens the record action when the row is clicked', async () => {
        const fetchMock = respondWith({ form: null });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    rows: [
                        {
                            key: '1',
                            action: 'open',
                            cells: { name: { state: 'Lamp' } },
                            actions: [{ name: 'open', props: { label: 'Open', style: 'button' } }],
                        },
                    ],
                }),
            },
        });

        await wrapper.find('[data-xq-table-row] td').trigger('click');
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock))).toMatchObject({
            action: 'open',
            kind: 'record',
            record: '1',
        });
    });

    it('searches a column from its own box', async () => {
        vi.useFakeTimers();

        const fetchMock = respondWith({ ...schema() });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    columns: [
                        {
                            name: 'name',
                            type: 'text-column',
                            label: 'Name',
                            sortable: false,
                            searchableIndividually: true,
                            props: {},
                        },
                    ],
                }),
            },
        });

        await wrapper.find('[data-xq-table-column-search] input').setValue('lam');
        vi.advanceTimersByTime(400);
        await flushPromises();
        vi.useRealTimers();

        const last = fetchMock.mock.calls.length - 1;

        expect(JSON.parse(sentBody(fetchMock, last)).query.column_search).toEqual({ name: 'lam' });
    });
});

describe('pagination modes and loading', () => {
    it('pages by cursor and leaves the counts out', async () => {
        const table = schema({
            pagination: {
                page: 1,
                perPage: 2,
                options: [2],
                mode: 'cursor',
                next: 'abc',
                previous: null,
            },
        });
        const fetchMock = respondWith({ ...table });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, { props: { table } });

        expect(wrapper.text()).not.toContain('Showing');
        expect(wrapper.find('[aria-label="Previous page"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[aria-label="Next page"]').trigger('click');
        await flushPromises();

        expect(JSON.parse(sentBody(fetchMock)).query.cursor).toBe('abc');
    });

    it('hides the pager when the table is not paginated', () => {
        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    pagination: {
                        page: 1,
                        perPage: 'all',
                        total: 1,
                        lastPage: 1,
                        options: ['all'],
                        hidden: true,
                    },
                }),
            },
        });

        expect(wrapper.find('[aria-label="Next page"]').exists()).toBe(false);
    });

    it('loads its rows after mounting when loading is deferred', async () => {
        const fetchMock = respondWith({ ...schema() });

        vi.stubGlobal('fetch', fetchMock);
        mount(XqTable, { props: { table: schema({ rows: [], deferred: true }) } });
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('groups and picking', () => {
    it('regroups from the picker and folds a collapsible group', async () => {
        const fetchMock = respondWith({ ...schema() });

        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(XqTable, {
            props: {
                table: schema({
                    groups: [{ name: 'status', label: 'Status', collapsible: true }],
                    group: 'status',
                    rows: [
                        { key: '1', group: 'live', cells: { name: { state: 'A' } }, actions: [] },
                        { key: '2', group: 'live', cells: { name: { state: 'B' } }, actions: [] },
                    ],
                }),
            },
        });

        expect(wrapper.findAll('[data-xq-table-row]')).toHaveLength(2);

        await wrapper.find('[data-xq-table-group] button').trigger('click');

        expect(wrapper.findAll('[data-xq-table-row]')).toHaveLength(0);
    });

    it('sets a ModalTableSelect to the rows picked in its table', async () => {
        const table = schema({
            rows: [
                { key: '7', cells: { name: { state: 'Desks' } }, actions: [] },
                { key: '8', cells: { name: { state: 'Lamps' } }, actions: [] },
            ],
        });

        vi.stubGlobal('fetch', respondWith({ ...table }));

        const form = createFormStore(
            formSchema(
                [
                    fieldNode('data.category_id', 'xelqun/modal-table-select', {
                        props: { options: [], titleAttribute: 'name', table },
                    }),
                ],
                { category_id: null },
            ),
        );
        const wrapper = mount(XqForm, {
            props: { form },
            global: { plugins: [createXelqunForms({ plugins: [tablesPlugin()] })] },
            attachTo: document.body,
        });

        await vi.dynamicImportSettled();
        await flushPromises();
        await wrapper.find('[data-xq-modal-table-select] button').trigger('click');
        await flushPromises();

        const checkboxes = document.body.querySelectorAll<HTMLElement>(
            '[data-xq-table-row] [role="checkbox"]',
        );

        checkboxes[1].click();
        await flushPromises();
        document.body.querySelector<HTMLElement>('[data-xq-pick-confirm]')!.click();
        await flushPromises();

        expect(form.get('data.category_id')).toBe('8');
        expect(wrapper.find('[data-xq-modal-table-select]').text()).toContain('Lamps');
        wrapper.unmount();
    });
});
