import type { FormSchema } from '@xelqun/forms';

export const SUPPORTED_SCHEMA_VERSIONS = [1] as const;

export interface TableColumn {
    name: string;
    type: string;
    label: string;
    sortable: boolean;
    toggleable?: boolean;
    hiddenByDefault?: boolean;
    opensInNewTab?: boolean;
    alignment?: 'start' | 'center' | 'end';
    /** Any CSS width. */
    width?: string;
    grow?: boolean;
    wrapHeader?: boolean;
    /** The heading of the ColumnGroup it sits in. */
    group?: string;
    /** It has a search box of its own. */
    searchableIndividually?: boolean;
    props: Record<string, any>;
}

export interface TableCell {
    state: any;
    /** Why the last value typed into an editable cell was refused. */
    error?: string;
    [key: string]: any;
}

export interface TableAction {
    name: string;
    props: Record<string, any>;
    /** Set on a menu, which holds these in place of running anything. */
    actions?: TableAction[];
}

/** Lines under each column, by column name. */
export type TableSummaries = Record<string, Array<{ label: string | null; value: unknown }>>;

export interface TableRow {
    key: string;
    url?: string;
    /** The heading this row sits under, on a grouped table. */
    group?: string;
    /** The group's raw value, which keys groupSummaries. Sent only with them. */
    groupKey?: string;
    /** False for a row that can't be selected for bulk actions. */
    selectable?: boolean;
    /** The name of the record action a click on the row opens. */
    action?: string;
    cells: Record<string, TableCell>;
    actions: TableAction[];
}

export interface TablePagination {
    page: number;
    perPage: number | 'all';
    /** Left out by simple and cursor pagination, which don't count. */
    total?: number;
    lastPage?: number;
    options: Array<number | 'all'>;
    mode?: 'simple' | 'cursor';
    /** Simple pagination: whether there's a next page. */
    hasMore?: boolean;
    /** Cursor pagination: where the next and previous pages start. */
    next?: string | null;
    previous?: string | null;
    /** paginated(false): every row, with no pager. */
    hidden?: boolean;
}

/**
 * The table's state as the URL holds it. Defaults are left out.
 */
export type TableQuery = Record<string, any>;

export interface TableResult {
    query: TableQuery;
    /** A column's name, with a leading "-" for descending. */
    sort: string | null;
    activeFilters: number;
    /** The column the rows are grouped by, if any. */
    group?: string | null;
    /** A chip for each active filter, with the fields that removing it clears. */
    indicators?: Array<{ label: string; fields: string[] }>;
    summaries: TableSummaries;
    /** Each group's summaries on this page, by the rows' groupKey. */
    groupSummaries?: Record<string, TableSummaries>;
    bulkActions: TableAction[];
    rows: TableRow[];
    pagination: TablePagination;
}

export interface TableSchema extends TableResult {
    $xq: number;
    pkg: string;
    id: string;
    token: string;
    endpoints: { query: string; update: string; reorder: string; action: string };
    layout: {
        grid: number | null;
        striped: boolean;
        pollSeconds: number | null;
        reorderable: boolean;
    };
    headerActions: TableAction[];
    maxSelectable: number;
    emptyState?: { heading: string; description?: string; icon?: string };
    emptyStateActions?: TableAction[];
    heading?: string;
    description?: string;
    searchable: boolean;
    filters: FormSchema | null;
    filterDefaults: Record<string, unknown>;
    /** Filters apply as they change, with no Apply button. */
    liveFilters?: boolean;
    /** The rows load after the first render. */
    deferred?: boolean;
    /** Groupings the viewer picks from. */
    groups?: Array<{ name: string; label: string; collapsible?: boolean }>;
    messages?: Record<string, string>;
    columns: TableColumn[];
}
