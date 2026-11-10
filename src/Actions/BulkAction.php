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
 * A policy ability in authorize() has to pass for every record, or the action
 * doesn't run. authorizeIndividualRecords() runs it on the ones that pass.
 *
 * @api
 */
class BulkAction extends Action
{
    protected ?string $individualAbility = null;

    /** @var array<int, mixed>|null The selected keys the ability allows, once checked. */
    private ?array $allowedKeys = null;

    /**
     * Check this policy ability on each selected record, and run the action on
     * the ones it allows instead of refusing them all. It shows while any of
     * the selection is allowed.
     */
    public function authorizeIndividualRecords(?string $ability): static
    {
        $this->individualAbility = $ability;

        return $this;
    }

    /**
     * @return Collection<int, covariant Model>
     */
    public function getRecords(): Collection
    {
        if ($this->individualAbility === null) {
            return $this->getHost()->getSelectedRecords();
        }

        if ($this->getHost()->usesCustomData()) {
            // No query to narrow, so the allowed rows come from the selection.
            return $this->getHost()->getSelectedRecords()
                ->filter(fn (Model $record): bool => in_array($record->getKey(), $this->getAllowedKeys(), true))
                ->values();
        }

        return $this->getRecordsQuery()->get();
    }

    /**
     * @return Builder<covariant Model>
     */
    public function getRecordsQuery(): Builder
    {
        $query = $this->getHost()->getSelectedQuery();

        return $this->individualAbility === null ? $query : $query->whereKey($this->getAllowedKeys());
    }

    /**
     * @return array<int, mixed>
     */
    private function getAllowedKeys(): array
    {
        // shortcut: the allowed keys go back into the query as a list, which grows with the selection; check in SQL through a scope if selections get very large.
        return $this->allowedKeys ??= $this->getHost()->eachSelectedRecord()
            ->filter(fn (Model $record): bool => Gate::allows($this->individualAbility, $record))
            ->map(fn (Model $record): mixed => $record->getKey())
            ->values()
            ->all();
    }

    /**
     * A policy method wants a model, so it can't answer for the class. With no
     * selection the button shows, and the action needs one to run. One query,
     * with one model in memory at a time, and no page of rows to miss.
     */
    public function isAuthorized(): bool
    {
        if ($this->individualAbility !== null && $this->getHost()->hasSelection() && $this->getAllowedKeys() === []) {
            return false;
        }

        if (! is_string($this->authorization)) {
            return parent::isAuthorized();
        }

        return ! $this->getHost()->hasSelection()
            || $this->getHost()->eachSelectedRecord()->every(fn (Model $record): bool => Gate::allows($this->authorization, $record));
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
