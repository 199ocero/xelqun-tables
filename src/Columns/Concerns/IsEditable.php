<?php

namespace Xelqun\Tables\Columns\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Xelqun\Tables\Exceptions\TableDefinitionException;

/**
 * A cell the viewer can change. Nothing is editable until authorize() says so,
 * the same as actions, and every write finds the row through the table's query.
 */
trait IsEditable
{
    protected bool|string|Closure $editAuthorization = false;

    /** @var array<int, mixed> */
    protected array $editRules = [];

    protected ?Closure $updateStateUsing = null;

    /**
     * Who may change this cell: an ability of the row's policy, or a closure.
     */
    public function authorize(bool|string|Closure $authorization): static
    {
        $this->editAuthorization = $authorization;

        return $this;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    public function rules(array $rules): static
    {
        $this->editRules = $rules;

        return $this;
    }

    /**
     * Save the value yourself. It gets $state and $record.
     */
    public function updateStateUsing(?Closure $using): static
    {
        $this->updateStateUsing = $using;

        return $this;
    }

    public function isEditable(Model $record): bool
    {
        return match (true) {
            is_string($this->editAuthorization) => Gate::allows($this->editAuthorization, $record),
            default => (bool) $this->evaluate($this->editAuthorization, ['record' => $record]),
        };
    }

    /**
     * What a value must be, on top of rules(). A column with a fixed kind of value
     * adds to this, so a posted array can't reach the model.
     *
     * @return array<int, mixed>
     */
    protected function getValueRules(): array
    {
        return [];
    }

    public function updateState(Model $record, mixed $value): void
    {
        Validator::make(['value' => $value], ['value' => [...$this->getValueRules(), ...$this->editRules]])->validate();

        if ($this->updateStateUsing !== null) {
            $this->evaluate($this->updateStateUsing, ['state' => $value, 'record' => $record]);

            return;
        }

        if (str_contains($this->getName(), '.')) {
            throw new TableDefinitionException("The column [{$this->getName()}] belongs to a relation, so it needs updateStateUsing().");
        }

        $record->setAttribute($this->getName(), $value)->save();
    }

    protected function getProps(): array
    {
        return ['editable' => true];
    }

    protected function getCellProps(Model $record, mixed $state): array
    {
        return ['canEdit' => $this->isEditable($record)];
    }
}
