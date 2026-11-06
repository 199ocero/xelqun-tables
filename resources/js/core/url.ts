import type { TableQuery } from '../schema/types';

const UNSAFE_KEYS = ['__proto__', 'constructor', 'prototype'];

function flatten(value: TableQuery, prefix: string): [string, string][] {
    return Object.keys(value)
        .sort()
        .flatMap((key) => {
            const item = value[key];
            const name = `${prefix}[${key}]`;

            return typeof item === 'object' && item !== null
                ? flatten(item, name)
                : ([[name, String(item ?? '')]] as [string, string][]);
        });
}

function setNested(target: TableQuery, path: string[], value: string): void {
    const last = path.pop() as string;
    const parent = path.reduce((node, key) => (node[key] ??= {}), target);

    parent[last] = value;
}

/**
 * This table's part of a query string: ?orders[page]=2 reads as { page: '2' }.
 */
export function readQuery(search: string, id: string): TableQuery {
    const query: TableQuery = {};

    new URLSearchParams(search).forEach((value, key) => {
        if (key.startsWith(`${id}[`)) {
            const path = key.slice(id.length + 1, -1).split('][');

            // Anyone can write a link, and these keys would change every object on the page.
            if (!path.some((part) => UNSAFE_KEYS.includes(part))) {
                setNested(query, path, value);
            }
        }
    });

    return query;
}

/**
 * Replace this table's part of a query string, leaving the rest alone.
 */
export function writeQuery(search: string, id: string, query: TableQuery): string {
    const params = new URLSearchParams(search);

    [...params.keys()]
        .filter((key) => key.startsWith(`${id}[`))
        .forEach((key) => params.delete(key));
    flatten(query, id).forEach(([key, value]) => params.append(key, value));

    const text = params.toString().replaceAll('%5B', '[').replaceAll('%5D', ']');

    return text === '' ? '' : `?${text}`;
}

export function isSameQuery(id: string, first: TableQuery, second: TableQuery): boolean {
    return writeQuery('', id, first) === writeQuery('', id, second);
}
