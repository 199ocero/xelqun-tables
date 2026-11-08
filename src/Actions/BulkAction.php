<?php

namespace Xelqun\Tables\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ReflectionFunction;
use ReflectionParameter;
use Xelqun\Actions\Action;

/**
 * An action on the rows the viewer selected. Closures receive them as $records,
 * and they only hold rows the table's query still reaches. For a big selection,
 * take $query instead: the same rows, loaded only as you go through them.
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
        return $this->getHost()->getSelectedRecords();
    }

    /**
     * @return Builder<covariant Model>
     */
    public function getRecordsQuery(): Builder
    {
        return $this->getHost()->getSelectedQuery();
    }

    /**
     * A policy method wants a model, so it can't answer for the class. With no
     * selection the button shows, and the action needs one to run. One query,
     * with one model in memory at a time, and no page of rows to miss.
     */
    public function isAuthorized(): bool
    {
        if (! is_string($this->authorization)) {
            return parent::isAuthorized();
        }

        return ! $this->getHost()->hasSelection()
            || $this->getRecordsQuery()->cursor()->every(fn (Model $record): bool => Gate::allows($this->authorization, $record));
    }

    /**
     * $records loads every selected row, so only a closure that asks for it gets it.
     */
    public function evaluate(mixed $value, array $parameters = []): mixed
    {
        if (! $value instanceof Closure) {
            return parent::evaluate($value, $parameters);
        }

        $asks = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionFunction($value))->getParameters());

        return parent::evaluate($value, [
            ...(in_array('records', $asks, true) ? ['records' => $this->getRecords()] : []),
            ...(in_array('query', $asks, true) ? ['query' => $this->getRecordsQuery()] : []),
            ...$parameters,
        ]);
    }

    private function getHost(): TableActionHost
    {
        $host = $this->getForm();

        if (! $host instanceof TableActionHost) {
            throw new LogicException('A bulk action only works in a table.');
        }

        return $host;
    }
}
