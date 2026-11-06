<?php

namespace Xelqun\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Xelqun\Tables\Columns\Column;
use Xelqun\Tables\Schema\SchemaVersion;

/**
 * A table of records, defined in PHP and rendered by XqTable.
 *
 * Every request rebuilds a table from its class, so query() can't read the page
 * it first appeared on: route parameters are gone on later requests. Pass what
 * it needs through $context.
 *
 * @api Anything public or protected here, unless it says @internal.
 */
abstract class Table
{
    private ?string $tableId = null;

    /** @var array<int, int> */
    private array $paginationPageOptions = [10, 25, 50];

    /** @var array<int, Column>|null */
    private ?array $mountedColumns = null;

    /**
     * @param  array<string, mixed>  $context  Scalar values that are needed to rebuild the table.
     */
    final public function __construct(protected array $context = [])
    {
        $this->boot();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(array $context = []): static
    {
        return new static($context);
    }

    /**
     * Fluent settings go here, such as paginationPageOptions().
     */
    protected function boot(): void {}

    /**
     * The records this table can show. Every read and every action stays inside it.
     *
     * @return Builder<covariant Model>
     */
    abstract public function query(): Builder;

    /**
     * @return array<int, Column>
     */
    abstract public function columns(): array;

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    /**
     * Name this table when a page shows the same class twice.
     */
    public function id(string $id): static
    {
        $this->tableId = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->tableId ?? Str::kebab(class_basename(static::class));
    }

    /**
     * @param  array<int, int>  $options
     */
    public function paginationPageOptions(array $options): static
    {
        $this->paginationPageOptions = array_values($options);

        return $this;
    }

    /**
     * @return array<int, int>
     */
    public function getPaginationPageOptions(): array
    {
        return $this->paginationPageOptions;
    }

    /**
     * The columns the viewer may see. A hidden one is left out of everything.
     *
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        return $this->mountedColumns ??= array_values(array_filter(
            array_map(fn (Column $column): Column => $column->mount($this), $this->columns()),
            fn (Column $column): bool => ! $column->isHidden(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        abort_unless($this->authorize(auth()->user()), 403);

        $columns = $this->getColumns();
        $records = $this->query()->paginate($this->paginationPageOptions[0], page: 1);

        return [
            '$xq' => SchemaVersion::CURRENT,
            'pkg' => SchemaVersion::PACKAGE,
            'id' => $this->getId(),
            'columns' => array_map(fn (Column $column): array => $column->toDefinition(), $columns),
            'rows' => $records->getCollection()->map(fn (Model $record): array => [
                'key' => (string) $record->getKey(),
                'cells' => collect($columns)
                    ->mapWithKeys(fn (Column $column): array => [$column->getName() => $column->toCell($record)])
                    ->all(),
            ])->all(),
            'pagination' => [
                'page' => $records->currentPage(),
                'perPage' => $records->perPage(),
                'total' => $records->total(),
                'lastPage' => $records->lastPage(),
                'options' => $this->paginationPageOptions,
            ],
        ];
    }
}
