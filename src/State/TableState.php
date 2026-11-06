<?php

namespace Xelqun\Tables\State;

use Xelqun\Tables\Table;

/**
 * What the viewer is looking at. It comes from the page's URL on the first
 * render and from the browser after that, so nothing in it is trusted: anything
 * invalid falls back to the table's default.
 */
final class TableState
{
    private const int MAX_SEARCH_LENGTH = 200;

    /**
     * @param  string|null  $sort  A sortable column's name, with a leading "-" for descending.
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $search = '',
        public readonly ?string $sort = null,
    ) {}

    /**
     * @param  array<mixed>  $input
     */
    public static function from(array $input, Table $table): self
    {
        $options = $table->getPaginationPageOptions();
        $perPage = filter_var($input['per_page'] ?? null, FILTER_VALIDATE_INT);
        $search = $input['search'] ?? null;
        $sort = $input['sort'] ?? null;

        return new self(
            page: filter_var($input['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1,
            perPage: in_array($perPage, $options, true) ? $perPage : $options[0],
            search: is_string($search) && $table->isSearchable() ? mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH) : '',
            sort: is_string($sort) && $table->findSortableColumn(str_starts_with($sort, '-') ? substr($sort, 1) : $sort) !== null
                ? $sort
                : $table->getDefaultSort(),
        );
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->perPage, $this->search, $this->sort);
    }

    /**
     * The URL's version of this state. Defaults are left out, so an untouched
     * table keeps a clean URL.
     *
     * @return array<string, int|string>
     */
    public function toQuery(Table $table): array
    {
        return array_filter([
            'search' => $this->search === '' ? null : $this->search,
            'sort' => $this->sort === $table->getDefaultSort() ? null : $this->sort,
            'page' => $this->page > 1 ? $this->page : null,
            'per_page' => $this->perPage === $table->getPaginationPageOptions()[0] ? null : $this->perPage,
        ], fn (int|string|null $value): bool => $value !== null);
    }
}
