import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createTableStore } from '../../resources/js/core/store';
import type { TableResult, TableSchema } from '../../resources/js/schema/types';

const pagination = { page: 1, perPage: 10, total: 30, lastPage: 3, options: [10] };

function schema(): TableSchema {
    return {
        $xq: 1,
        pkg: '0.1.0',
        id: 'orders',
        token: 'token',
        endpoints: { query: '/query', update: '/update', action: '/action' },
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
        columns: [],
        rows: [],
        pagination,
    };
}

function result(page: number): TableResult {
    return {
        query: { page },
        sort: null,
        activeFilters: 0,
        summaries: {},
        rows: [],
        pagination: { ...pagination, page },
    };
}

function response(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

/** Requests that stay open until the test answers them. */
function holdRequests() {
    const held: Array<{ signal: AbortSignal; answer: (response: Response) => void }> = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(
            (_url: string, init: RequestInit) =>
                new Promise<Response>((resolve, reject) => {
                    init.signal?.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    );
                    held.push({ signal: init.signal as AbortSignal, answer: resolve });
                }),
        ),
    );

    return held;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('table store', () => {
    it('cancels the older request and keeps the newer answer', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });

        void store.update({ page: 2 });
        void store.update({ page: 3 });

        expect(held[0].signal.aborted).toBe(true);

        held[1].answer(response(result(3)));
        await flushPromises();

        expect(store.schema.value.pagination.page).toBe(3);
        expect(store.loading.value).toBe(false);
        expect(store.error.value).toBeNull();
        expect(onChange).toHaveBeenCalledOnce();
    });

    it('ignores an answer that a reset made old', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });
        const load = store.update({ page: 2 });

        store.reset({ ...schema(), id: 'new' });
        await load;

        expect(store.schema.value.id).toBe('new');
        expect(store.loading.value).toBe(false);
        expect(store.error.value).toBeNull();
        expect(onChange).not.toHaveBeenCalled();
        expect(held).toHaveLength(1);
    });

    it('keeps a change made while another is still loading', async () => {
        const held = holdRequests();
        const store = createTableStore(schema());

        void store.update({ per_page: 25, page: 1 });
        void store.update({ page: 2 });
        held[1].answer(response(result(2)));
        await flushPromises();

        const sent = (fetch as any).mock.calls[1][1].body;

        expect(JSON.parse(sent).query).toEqual({ per_page: 25, page: 2 });
    });

    it('drops the invalid state once the server has cleaned it', async () => {
        const fetchMock = vi.fn(async () => response(result(3)));

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore(schema());

        await store.load({ page: '999', junk: 'x' });
        await store.update({ per_page: 25 });

        expect(JSON.parse((fetchMock.mock.calls[1] as any[])[1].body).query).toEqual({
            page: 3,
            per_page: 25,
        });
    });

    it('ignores an answer after it is disposed', async () => {
        const held = holdRequests();
        const onChange = vi.fn();
        const store = createTableStore(schema(), { onChange });

        void store.update({ page: 2 });
        store.dispose();
        await flushPromises();

        expect(held[0].signal.aborted).toBe(true);
        expect(store.error.value).toBeNull();
        expect(store.loading.value).toBe(false);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('keeps the selection across pages and sorting', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response(result(2))),
        );

        const store = createTableStore(schema());

        store.toggleRow('1', true);
        await store.update({ page: 2 });
        await store.update({ sort: 'name', page: 1 });
        await store.update({ per_page: 25 });

        expect([...store.selected.value]).toEqual(['1']);
    });

    it.each(['search', 'filters'])('clears the selection when %s changes', async (key) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response(result(1))),
        );

        const store = createTableStore(schema());

        store.toggleRow('1', true);
        await store.update({ [key]: 'x' });

        expect(store.selected.value.size).toBe(0);
    });

    it('clears the selection on a new visit', () => {
        const store = createTableStore(schema());

        store.toggleRow('1', true);
        store.reset(schema());

        expect(store.selected.value.size).toBe(0);
    });

    it('selects a page and removes it again, keeping other pages', () => {
        const store = createTableStore({
            ...schema(),
            rows: [
                { key: '1', cells: {}, actions: [] },
                { key: '2', cells: {}, actions: [] },
            ],
        });

        store.toggleRow('9', true);
        store.togglePage(true);

        expect([...store.selected.value]).toEqual(['9', '1', '2']);

        store.togglePage(false);

        expect([...store.selected.value]).toEqual(['9']);
    });

    it('selects every row the server lists', async () => {
        const fetchMock = vi.fn(async () => response({ keys: ['1', '2', '3'] }));

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore({ ...schema(), query: { search: 'a' } });

        await store.selectAll();

        expect(JSON.parse((fetchMock.mock.calls[0] as any[])[1].body)).toMatchObject({
            keys: true,
            query: { search: 'a' },
        });
        expect(store.selected.value.size).toBe(3);
    });

    it('drops a select all that a newer search overtook', async () => {
        const held = holdRequests();
        const store = createTableStore(schema());
        const selecting = store.selectAll();

        void store.update({ search: 'other' });
        held[0]!.answer(response({ keys: ['1', '2'] }));
        await selecting;

        expect(store.selected.value.size).toBe(0);
    });

    describe('after an action runs', () => {
        const node = {
            key: 'touch',
            type: 'action',
            hidden: false,
            props: {},
            children: [],
            deps: [],
        } as any;

        it('stays busy until the new rows arrive, without dimming the other buttons', async () => {
            let finishQuery = (): void => {};
            const rows = new Promise<void>((resolve) => (finishQuery = resolve));

            vi.stubGlobal(
                'fetch',
                vi.fn(async (url: string) => {
                    if (url === '/action') {
                        return response({});
                    }

                    await rows;

                    return response(result(1));
                }),
            );

            const runner = createTableStore(schema()).actionRunner({ kind: 'header' });
            let isFinished = false;
            const run = runner.runAction(node).then(() => (isFinished = true));

            await flushPromises();

            expect(isFinished).toBe(false);
            expect(runner.processing.value).toBe(false);

            finishQuery();
            await run;

            expect(isFinished).toBe(true);
        });

        it('does not load rows when the action sends the user away', async () => {
            const fetchMock = vi.fn(async () => response({ redirect: '/products' }));

            vi.stubGlobal('fetch', fetchMock);

            const result = await createTableStore(schema())
                .actionRunner({ kind: 'header' })
                .runAction(node);

            expect(result?.redirect).toBe('/products');
            expect(fetchMock).toHaveBeenCalledOnce();
        });
    });

    it('sends the selection with a bulk action, then clears it', async () => {
        const fetchMock = vi.fn(async (url: string) =>
            response(url === '/action' ? {} : result(1)),
        );

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore(schema());

        store.toggleRow('1', true);
        store.toggleRow('2', true);

        const node = {
            key: 'remove',
            type: 'action',
            hidden: false,
            props: {},
            children: [],
            deps: [],
        };

        await store.actionRunner({ kind: 'bulk' }).runAction(node as any);

        expect(JSON.parse((fetchMock.mock.calls[0] as any[])[1].body)).toMatchObject({
            action: 'remove',
            kind: 'bulk',
            records: ['1', '2'],
        });
        expect(store.selected.value.size).toBe(0);
    });

    it('keeps the selection when a bulk action fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response({}, 403)),
        );

        const store = createTableStore(schema());

        store.toggleRow('1', true);

        const node = {
            key: 'remove',
            type: 'action',
            hidden: false,
            props: {},
            children: [],
            deps: [],
        };

        await store.actionRunner({ kind: 'bulk' }).runAction(node as any);

        expect(store.selected.value.size).toBe(1);
        expect(store.error.value).toBe('That action could not run.');
    });

    it('says an old page has expired', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response({}, 419)),
        );

        const store = createTableStore(schema());

        await store.update({ page: 2 });

        expect(store.error.value).toContain('expired');
        expect(store.loading.value).toBe(false);
    });

    it('keeps the rows on screen when a request fails', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => response({}, 500)),
        );

        const store = createTableStore({
            ...schema(),
            rows: [{ key: '1', cells: {}, actions: [] }],
        });

        await store.update({ page: 2 });

        expect(store.schema.value.rows).toHaveLength(1);
        expect(store.error.value).toBe('Could not load the records.');
    });

    it('merges a change into the current state', async () => {
        const fetchMock = vi.fn(async () => response(result(1)));

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore({ ...schema(), query: { per_page: 25, page: 2 } });

        await store.update({ page: 3 });

        expect(JSON.parse((fetchMock.mock.calls[0] as any[])[1].body).query).toEqual({
            per_page: 25,
            page: 3,
        });
    });
});

describe('updateCell', () => {
    const row = {
        key: '1',
        cells: { name: { state: 'Lamp' } },
        actions: [],
    };

    it('posts the cell and replaces it with the answer', async () => {
        const fetchMock = vi.fn(
            async (_url: string, _init: RequestInit) =>
                new Response(JSON.stringify({ cell: { state: 'Bulb' } })),
        );

        vi.stubGlobal('fetch', fetchMock);

        const store = createTableStore({ ...schema(), rows: [row] });

        await store.updateCell('1', 'name', 'Bulb');

        expect(store.schema.value.rows[0].cells.name.state).toBe('Bulb');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toMatchObject({
            record: '1',
            column: 'name',
            value: 'Bulb',
        });
        vi.unstubAllGlobals();
    });

    it('shows why a value was refused, and hands the cell back so it can reset', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(JSON.stringify({ errors: { value: ['Too long.'] } }), {
                        status: 422,
                    }),
            ),
        );

        const store = createTableStore({ ...schema(), rows: [row] });
        const before = store.schema.value.rows[0].cells.name;

        await store.updateCell('1', 'name', 'x'.repeat(99));

        expect(store.error.value).toBe('Too long.');
        expect(store.schema.value.rows[0].cells.name).toEqual(before);
        expect(store.schema.value.rows[0].cells.name).not.toBe(before);
        vi.unstubAllGlobals();
    });
});
