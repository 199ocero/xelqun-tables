<?php

namespace Xelqun\Tables\Actions;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Xelqun\Actions\Action;

/**
 * An action on the rows the viewer selected. Closures receive them as $records,
 * and they only hold rows the table's query still reaches.
 *
 * A policy ability has to pass for every record, or the action doesn't run.
 *
 * @api
 */
class BulkAction extends Action
{
    /**
     * @return Collection<int, Model>
     */
    public function getRecords(): Collection
    {
        $host = $this->getForm();

        if (! $host instanceof TableActionHost) {
            throw new LogicException('A bulk action only works in a table.');
        }

        return $host->getSelectedRecords();
    }

    /**
     * A policy method wants a model, so it can't answer for the class. With no
     * selection the button shows, and the action needs one to run.
     */
    public function isAuthorized(): bool
    {
        if (! is_string($this->authorization)) {
            return parent::isAuthorized();
        }

        return $this->getRecords()->every(fn (Model $record): bool => Gate::allows($this->authorization, $record));
    }

    public function evaluate(mixed $value, array $parameters = []): mixed
    {
        return parent::evaluate($value, $value instanceof Closure ? ['records' => $this->getRecords(), ...$parameters] : $parameters);
    }
}
