<?php

namespace Xelqun\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    /** The constraint value a row has when it is a group of rules. */
    public const string GROUP = '__group';

    /** @var array<int, Constraint> */
    protected array $constraints = [];

    protected int $maxNestingDepth = 3;

    protected ?int $maxRules = null;

    public function needsQuery(): bool
    {
        return true;
    }

    /**
     * @param  array<int, Constraint>  $constraints
     */
    public function constraints(array $constraints): static
    {
        $this->constraints = $constraints;

        return $this;
    }

    /**
     * How deep groups of rules can go. 1 allows no groups, only rules.
     */
    public function maxNestingDepth(int $depth): static
    {
        $this->maxNestingDepth = max(1, $depth);

        return $this;
    }

    /**
     * The most rules, counted across every group. Rules past it are not
     * applied in part: the whole filter is ignored, as in Filament.
     */
    public function maxRules(?int $count): static
    {
        $this->maxRules = $count;

        return $this;
    }

    public function getFormComponents(): array
    {
        return [
            $this->makeRulesRepeater($this->name, 1)->label($this->getLabel()),
            Toggle::make($this->matchFieldName())->label(__('xelqun-tables::messages.query_builder.match_any')),
        ];
    }

    /**
     * The rules at one depth. A row is a rule, or below the deepest level, a
     * group with rules and a match toggle of its own.
     */
    private function makeRulesRepeater(string $name, int $depth): Repeater
    {
        $canNest = $depth < $this->maxNestingDepth;
        $isRule = When::neq('constraint', self::GROUP);

        return Repeater::make($name)
            ->defaultItems(0)
            ->itemLabel(__('xelqun-tables::messages.query_builder.rule'))
            ->addActionLabel(__('xelqun-tables::messages.query_builder.add_rule'))
            ->columns(2)
            ->schema([
                Select::make('constraint')
                    ->label(__('xelqun-tables::messages.query_builder.column'))
                    ->required()
                    ->live()
                    ->options([
                        ...collect($this->constraints)->mapWithKeys(fn (Constraint $constraint): array => [$constraint->getAttribute() => $constraint->getLabel()])->all(),
                        ...($canNest ? [self::GROUP => __('xelqun-tables::messages.query_builder.group')] : []),
                    ]),
                Select::make('operator')
                    ->label(__('xelqun-tables::messages.query_builder.operator'))
                    ->requiredWhen($isRule)
                    ->visibleWhen($isRule)
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
                ...($canNest ? [
                    Toggle::make('match_any')
                        ->label(__('xelqun-tables::messages.query_builder.match_any'))
                        ->columnSpanFull()
                        ->visibleWhen(When::eq('constraint', self::GROUP)),
                    $this->makeRulesRepeater('rules', $depth + 1)
                        ->label(__('xelqun-tables::messages.query_builder.rules'))
                        ->columnSpanFull()
                        ->visibleWhen(When::eq('constraint', self::GROUP)),
                ] : []),
            ]);
    }

    public function apply(Builder $query, array $data): void
    {
        if ($this->isWithinLimits($data)) {
            $this->applyRules($query, (array) ($data[$this->name] ?? []), (bool) ($data[$this->matchFieldName()] ?? false), 1);
        }
    }

    /**
     * Only rules that would change the rows, so the count never says a filter is on when nothing was filtered.
     */
    public function isActive(array $data): bool
    {
        return $this->isWithinLimits($data) && $this->countUsable((array) ($data[$this->name] ?? []), 1) > 0;
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  array<array-key, mixed>  $rules
     */
    private function applyRules(Builder $query, array $rules, bool $matchAny, int $depth): void
    {
        $boolean = $matchAny ? 'or' : 'and';

        foreach ($rules as $rule) {
            if (! is_array($rule) || $this->countUsable([$rule], $depth) === 0) {
                continue;
            }

            if ($this->isGroup($rule, $depth)) {
                $query->where(fn (Builder $group) => $this->applyRules($group, (array) ($rule['rules'] ?? []), (bool) ($rule['match_any'] ?? false), $depth + 1), boolean: $boolean);

                continue;
            }

            $constraint = $this->findConstraint($rule['constraint'] ?? null);

            if ($constraint !== null) {
                $query->where(fn (Builder $group) => $constraint->apply($group, (string) ($rule['operator'] ?? ''), $this->valueOf($constraint, $rule)), boolean: $boolean);
            }
        }
    }

    /**
     * The rules that would change the rows, in groups too. A group deeper
     * than the form allows counts nothing, so it can't be posted around it.
     *
     * @param  array<array-key, mixed>  $rules
     */
    private function countUsable(array $rules, int $depth): int
    {
        $count = 0;

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if ($this->isGroup($rule, $depth)) {
                $count += $this->countUsable((array) ($rule['rules'] ?? []), $depth + 1);
            } elseif ($this->isUsable($rule)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function isWithinLimits(array $data): bool
    {
        return $this->maxRules === null || $this->countUsable((array) ($data[$this->name] ?? []), 1) <= $this->maxRules;
    }

    /**
     * @param  array<array-key, mixed>  $rule
     */
    private function isGroup(array $rule, int $depth): bool
    {
        return ($rule['constraint'] ?? null) === self::GROUP && $depth < $this->maxNestingDepth;
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
