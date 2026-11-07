<?php

namespace Xelqun\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Xelqun\Forms\Components\Repeater;
use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\Components\Toggle;
use Xelqun\Forms\State\Get;
use Xelqun\Tables\Filters\Constraints\Constraint;

/**
 * Lets the viewer build their own rules: a column, an operator and a value, as
 * many as they like. Only the constraints listed here can be asked about.
 *
 * @api
 */
class QueryBuilder extends BaseFilter
{
    /** @var array<int, Constraint> */
    protected array $constraints = [];

    /**
     * @param  array<int, Constraint>  $constraints
     */
    public function constraints(array $constraints): static
    {
        $this->constraints = $constraints;

        return $this;
    }

    public function getFormComponents(): array
    {
        return [
            Repeater::make($this->name)
                ->label($this->getLabel())
                ->defaultItems(0)
                ->columns(3)
                ->schema([
                    Select::make('constraint')
                        ->label(__('xelqun-tables::messages.query_builder.column'))
                        ->required()
                        ->live()
                        ->options(collect($this->constraints)->mapWithKeys(fn (Constraint $constraint): array => [$constraint->getAttribute() => $constraint->getLabel()])->all()),
                    Select::make('operator')
                        ->label(__('xelqun-tables::messages.query_builder.operator'))
                        ->required()
                        ->options(fn (Get $get): array => $this->findConstraint($get('constraint'))?->getOperators() ?? []),
                    TextInput::make('value')->label(__('xelqun-tables::messages.query_builder.value')),
                ]),
            Toggle::make($this->matchFieldName())->label(__('xelqun-tables::messages.query_builder.match_any')),
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $boolean = ($data[$this->matchFieldName()] ?? false) ? 'or' : 'and';

        foreach ((array) ($data[$this->name] ?? []) as $rule) {
            $constraint = $this->findConstraint($rule['constraint'] ?? null);

            if ($constraint !== null) {
                $query->where(fn (Builder $group) => $constraint->apply($group, (string) ($rule['operator'] ?? ''), $rule['value'] ?? null), boolean: $boolean);
            }
        }
    }

    public function isActive(array $data): bool
    {
        return array_filter((array) ($data[$this->name] ?? []), fn (mixed $rule): bool => is_array($rule) && $this->findConstraint($rule['constraint'] ?? null) !== null) !== [];
    }

    private function findConstraint(mixed $attribute): ?Constraint
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->getAttribute() === $attribute) {
                return $constraint;
            }
        }

        return null;
    }

    private function matchFieldName(): string
    {
        return "{$this->name}_match_any";
    }
}
