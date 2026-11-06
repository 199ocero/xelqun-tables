import { describe, expect, it } from 'vitest';
import { isSameQuery, readQuery, writeQuery } from '../../resources/js/core/url';

describe('table url state', () => {
    it('reads only this table', () => {
        expect(readQuery('?orders[page]=2&items[page]=9&tab=a', 'orders')).toEqual({ page: '2' });
    });

    it('does not read a table whose name starts the same', () => {
        expect(readQuery('?orders-archive[page]=2', 'orders')).toEqual({});
    });

    it('reads nested values', () => {
        expect(readQuery('?orders[filters][status]=draft', 'orders')).toEqual({
            filters: { status: 'draft' },
        });
    });

    it('survives a key a person typed by hand', () => {
        expect(readQuery('?orders[=1&orders[]=2', 'orders')).toEqual({ '': '2' });
    });

    it.each(['__proto__', 'constructor', 'prototype'])('never writes through %s', (key) => {
        readQuery(`?orders[${key}][polluted]=1&orders[a][${key}][polluted]=1`, 'orders');

        expect(({} as any).polluted).toBeUndefined();
        expect((Object as any).polluted).toBeUndefined();
    });

    it('replaces this table and keeps everything else', () => {
        expect(writeQuery('?a=1&orders[page]=2&items[page]=9', 'orders', { page: 3 })).toBe(
            '?a=1&items[page]=9&orders[page]=3',
        );
    });

    it('clears this table back to a clean url', () => {
        expect(writeQuery('?orders[page]=2', 'orders', {})).toBe('');
    });

    it('writes nested values and escapes what needs it', () => {
        expect(writeQuery('', 'orders', { filters: { name: 'a&b' } })).toBe(
            '?orders[filters][name]=a%26b',
        );
    });

    it('writes booleans the way the server reads them', () => {
        expect(writeQuery('', 'orders', { filters: { on: true, off: false } })).toBe(
            '?orders[filters][off]=0&orders[filters][on]=1',
        );
    });

    it('compares a url string with a server number', () => {
        expect(isSameQuery('orders', { page: '2' }, { page: 2 })).toBe(true);
        expect(isSameQuery('orders', { page: '2', per_page: '5' }, { per_page: 5, page: 2 })).toBe(
            true,
        );
        expect(isSameQuery('orders', {}, { page: 2 })).toBe(false);
    });
});
