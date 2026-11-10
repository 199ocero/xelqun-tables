<?php

namespace Xelqun\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Xelqun\Forms\Components\DatePicker;
use Xelqun\Forms\Components\Repeater;
use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Forms\Components\Toggle;
use Xelqun\Forms\Conditions\When;
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
    public function needsQuery(): bool
    {
        return true;
    }

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
                ->columns(2)
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
                    // One field for each kind of value, shown for the constraints that take it.
                    TextInput::make('value')
                        ->label(__('xelqun-tables::messages.query_builder.value'))
                        ->columnSpanFull()
                        ->visibleWhen(When::in('constraint', $this->constraintsTaking('value'))),
                    Select::make('option')
                        ->label(__('xelqun-tables::messages.query_builder.value'))
                        ->columnSpanFull()
                        ->options(fn (Get $get): array => $this->findConstraint($get('constraint'))?->getOptions() ?? [])
                        ->visibleWhen(When::in('constraint', $this->constraintsTaking('option'))),
                    DatePicker::make('date')
                        ->label(__('xelqun-tables::messages.query_builder.value'))
                        ->columnSpanFull()
                        ->visibleWhen(When::in('constraint', $this->constraintsTaking('date'))),
                ]),
            Toggle::make($this->matchFieldName())->label(__('xelqun-tables::messages.query_builder.match_any')),
        ];
    }

    public function apply(Builder $query, array $data): void
    {
        $boolean = ($data[$this->matchFieldName()] ?? false) ? 'or' : 'and';

        foreach ((array) ($data[$this->name] ?? []) as $rule) {
            $constraint = $this->findConstraint($rule['constraint'] ?? null);

            if ($constraint !== null && $this->isUsable($rule)) {
                $query->where(fn (Builder $group) => $constraint->apply($group, (string) ($rule['operator'] ?? ''), $this->valueOf($constraint, $rule)), boolean: $boolean);
            }
        }
    }

    /**
     * Only rules that would change the rows, so the count never says a filter is on when nothing was filtered.
     */
    public function isActive(array $data): bool
    {
        return array_filter((array) ($data[$this->name] ?? []), fn (mixed $rule): bool => is_array($rule) && $this->isUsable($rule)) !== [];
    }

    /**
     * @param  array<array-key, mixed>  $rule
     */
    private function isUsable(array $rule): bool
    {
        $constraint = $this->findConstraint($rule['constraint'] ?? null);

        return $constraint !== null && $constraint->isUsable((string) ($rule['operator'] ?? ''), $this->valueOf($constraint, $rule));
    }

    /**
     * @param  array<array-key, mixed>  $rule
     */
    private function valueOf(Constraint $constraint, array $rule): mixed
    {
        $field = $constraint->getValueField();

        return $field === null ? null : $rule[$field] ?? null;
    }

    /**
     * @return array<int, string>
     */
    private function constraintsTaking(string $field): array
    {
        return collect($this->constraints)
            ->filter(fn (Constraint $constraint): bool => $constraint->getValueField() === $field)
            ->map(fn (Constraint $constraint): string => $constraint->getAttribute())
            ->values()
            ->all();
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
