<?php

namespace Xelqun\Tables\State;

use Illuminate\Support\Arr;
use Xelqun\Forms\Components\Component;
use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Support\StatePath;
use Xelqun\Forms\Validation\FormValidator;
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
     * @param  array<string, mixed>  $filters  Every filter field's value.
     * @param  array<string, string>  $columnSearches  A search of its own for each column that has a box.
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $search = '',
        public readonly ?string $sort = null,
        public readonly array $filters = [],
        public readonly array $columnSearches = [],
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
            filters: self::readFilters($input['filters'] ?? [], $table),
            columnSearches: self::readColumnSearches($input['column_search'] ?? [], $table),
        );
    }

    /**
     * Only columns with a box of their own, and only text.
     *
     * @return array<string, string>
     */
    private static function readColumnSearches(mixed $input, Table $table): array
    {
        $searches = [];

        foreach ($table->getColumns() as $column) {
            $value = is_array($input) ? ($input[$column->getName()] ?? null) : null;

            if ($column->isIndividuallySearchable() && is_string($value) && trim($value) !== '') {
                $searches[$column->getName()] = mb_substr(trim($value), 0, self::MAX_SEARCH_LENGTH);
            }
        }

        return $searches;
    }

    /**
     * Only declared fields get through, and a field's own rules decide if its
     * value is valid. A bad value falls back to the default without an error,
     * because the first render has nowhere to show one.
     *
     * @return array<string, mixed>
     */
    private static function readFilters(mixed $input, Table $table): array
    {
        if ($table->getFilters() === []) {
            return [];
        }

        $form = $table->getFiltersForm();
        $defaults = $table->getFilterDefaults();
        $posted = Arr::only(is_array($input) ? $input : [], array_keys($defaults));
        $posted = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $posted);

        $form->walk(function (Component $component) use (&$posted): void {
            if ($component instanceof Select && array_key_exists($component->getName(), $posted)) {
                $posted[$component->getName()] = self::matchOptionKey($posted[$component->getName()]);
            }
        });
        $validator = app(FormValidator::class);

        $validator->prepare($form, [...$defaults, ...$posted]);

        foreach (array_keys($validator->errorsFor($form, [StatePath::ROOT])) as $path) {
            $name = explode('.', $path)[1];
            $form->setStateValue(StatePath::ROOT.".{$name}", $defaults[$name] ?? null);
        }

        return Arr::only($form->getData(), array_keys($defaults));
    }

    /**
     * The URL only holds text, and an option's value is an integer when PHP made
     * its key one, so "1" would match nothing in a select. This is PHP's own rule
     * for keys, which leaves "0012" alone. It's for selects only, because a text
     * field's rules want text.
     */
    private static function matchOptionKey(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::matchOptionKey(...), $value),
            // JSON and the testing helpers send true and false, which PHP keys as 1 and 0.
            is_bool($value) => (int) $value,
            is_string($value) && (string) (int) $value === $value => (int) $value,
            default => $value,
        };
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->perPage, $this->search, $this->sort, $this->filters, $this->columnSearches);
    }

    /**
     * The URL's version of this state. Defaults are left out, so an untouched
     * table keeps a clean URL.
     *
     * @return array<string, mixed>
     */
    public function toQuery(Table $table): array
    {
        $defaults = $table->getFilterDefaults();
        $filters = array_filter(
            $this->filters,
            fn (mixed $value, string $name): bool => ! self::isSameValue($value, $defaults[$name] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        return array_filter([
            'search' => $this->search === '' ? null : $this->search,
            'sort' => $this->sort === $table->getDefaultSort() ? null : $this->sort,
            'filters' => $filters === [] ? null : $filters,
            'column_search' => $this->columnSearches === [] ? null : $this->columnSearches,
            'page' => $this->page > 1 ? $this->page : null,
            'per_page' => $this->perPage === $table->getPaginationPageOptions()[0] ? null : $this->perPage,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The URL holds "1" where a default holds 1.
     */
    private static function isSameValue(mixed $first, mixed $second): bool
    {
        return json_encode($first, JSON_NUMERIC_CHECK) === json_encode($second, JSON_NUMERIC_CHECK);
    }
}
