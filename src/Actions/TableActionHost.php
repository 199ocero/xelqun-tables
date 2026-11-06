<?php

namespace Xelqun\Tables\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Xelqun\Actions\Action;
use Xelqun\Actions\ActionGroup;
use Xelqun\Actions\ActionSet;
use Xelqun\Forms\Components\Component;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;

/**
 * Forms' actions need a form to live in, so a table's actions live in this one.
 * That brings modals, uploads and their tokens with no new code.
 *
 * There is one host for each kind of action. A row's host holds that row, so a
 * record action can't run without a record, or a header action with one.
 *
 * Every record it holds comes through the table's own query, on every request.
 *
 * @internal
 */
final class TableActionHost extends ActionSet
{
    public const string RECORD = 'record';

    public const string HEADER = 'header';

    public const string BULK = 'bulk';

    private ?Table $table = null;

    /** @var Collection<int, Model>|null */
    private ?Collection $selectedRecords = null;

    /**
     * @param  array<int, string>  $selection  The keys of a bulk action's rows.
     */
    public static function for(Table $table, string $kind, ?Model $record = null, array $selection = []): self
    {
        return self::make($record, [
            'table' => $table::class,
            'tableContext' => $table->getContext(),
            'tableId' => $table->getId(),
            'kind' => $kind,
            'selection' => $selection,
        ]);
    }

    public function actions(): array
    {
        $actions = match ($this->context['kind']) {
            self::RECORD => $this->getTable()->recordActions(),
            self::HEADER => $this->getTable()->headerActions(),
            self::BULK => $this->getTable()->bulkActions(),
            default => [],
        };
        $names = array_map(fn (Action $action): string => $action->getName(), ActionGroup::flatten($actions));

        if (count($names) !== count(array_unique($names))) {
            throw new TableDefinitionException('The table ['.$this->getTable()::class.'] has two actions with the same name.');
        }

        return $actions;
    }

    /**
     * The selection, found again on every request. It travels in a modal's
     * token, and a row in it may have left the table since.
     *
     * @return Collection<int, Model>
     */
    public function getSelectedRecords(): Collection
    {
        return $this->selectedRecords ??= $this->getTable()->resolveRecords((array) ($this->context['selection'] ?? []));
    }

    /**
     * Found by name, because a position could mean another action after a
     * deploy, with the old page still open.
     */
    public function findAction(string $name): ?Action
    {
        foreach (ActionGroup::flatten($this->getComponents()) as $action) {
            if ($action->getName() === $name) {
                return $action;
            }
        }

        return null;
    }

    /**
     * The actions the viewer can see, with only what a button needs.
     *
     * @param  array<int, Component>|null  $components
     * @return array<int, array{name: string, props: mixed, actions?: array<int, mixed>}>
     */
    public function toActionNodes(?array $components = null): array
    {
        $nodes = [];

        foreach ($components ?? $this->getComponents() as $component) {
            $node = $component->toNode();

            if ($node === null || $node['hidden']) {
                continue;
            }

            if ($component instanceof ActionGroup) {
                $nodes[] = ['name' => $component->getKey(), 'props' => $node['props'], 'actions' => $this->toActionNodes($component->getChildComponents())];
            } elseif ($component instanceof Action) {
                $nodes[] = ['name' => $component->getName(), 'props' => $node['props']];
            }
        }

        return $nodes;
    }

    public function authorize(?Authenticatable $user, ?Model $record): bool
    {
        return $this->getTable()->authorize($user);
    }

    public function model(): string
    {
        return $this->getTable()->query()->getModel()::class;
    }

    /**
     * Requests from a modal arrive after the page loaded, and the row may have
     * left the table since. Forms' own lookup would still find it.
     */
    public function resolveRecord(int|string $key): Model
    {
        return $this->getTable()->resolveRecord($key);
    }

    public function getId(): string
    {
        return $this->context['tableId'].'-actions';
    }

    public function getUploadScope(): string
    {
        return parent::getUploadScope().'>'.$this->getTable()::class.'>'.$this->context['kind'];
    }

    private function getTable(): Table
    {
        if ($this->table !== null) {
            return $this->table;
        }

        $class = $this->context['table'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, Table::class)) {
            throw new LogicException('A table\'s actions need the table class.');
        }

        return $this->table = $class::make((array) ($this->context['tableContext'] ?? []));
    }
}
