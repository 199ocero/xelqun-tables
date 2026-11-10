<?php

namespace Xelqun\Tables\Filters;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Xelqun\Forms\Form;
use Xelqun\Tables\ArrayRecord;
use Xelqun\Tables\Table;

/**
 * The fields of a table's filters. It is a form of its own, so dependent
 * options, search and rules work as usual. Its token names the table, so each
 * of those requests goes through the table's authorize() again.
 *
 * @internal
 */
final class TableFiltersForm extends Form
{
    private ?Table $table = null;

    public static function for(Table $table): self
    {
        return self::make(null, ['table' => $table::class, 'tableContext' => $table->getContext(), 'tableId' => $table->getId()]);
    }

    public function schema(): array
    {
        return array_merge(...array_map(
            fn (BaseFilter $filter): array => $filter->getFormComponents(),
            $this->getTable()->getFilters(),
        ));
    }

    public function authorize(?Authenticatable $user, ?Model $record): bool
    {
        return $this->getTable()->authorize($user);
    }

    public function model(): string
    {
        return $this->getTable()->usesCustomData() ? ArrayRecord::class : $this->getTable()->getQuery()->getModel()::class;
    }

    public function getId(): string
    {
        return $this->context['tableId'].'-filters';
    }

    public function getUploadScope(): string
    {
        return parent::getUploadScope().'>'.$this->getTable()::class;
    }

    private function getTable(): Table
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $class = $this->context['table'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, Table::class)) {
            throw new LogicException('A table\'s filters need the table class.');
        }

        return $this->table = $class::make((array) ($this->context['tableContext'] ?? []));
    }
}
