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
    public function __construct(public readonly int $page, public readonly int $perPage) {}

    /**
     * @param  array<mixed>  $input
     */
    public static function from(array $input, Table $table): self
    {
        $options = $table->getPaginationPageOptions();
        $perPage = filter_var($input['per_page'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            page: filter_var($input['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1,
            perPage: in_array($perPage, $options, true) ? $perPage : $options[0],
        );
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->perPage);
    }

    /**
     * The URL's version of this state. Defaults are left out, so an untouched
     * table keeps a clean URL.
     *
     * @return array<string, int>
     */
    public function toQuery(Table $table): array
    {
        return array_filter([
            'page' => $this->page > 1 ? $this->page : null,
            'per_page' => $this->perPage === $table->getPaginationPageOptions()[0] ? null : $this->perPage,
        ]);
    }
}
