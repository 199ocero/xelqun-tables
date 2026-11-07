<?php

namespace Xelqun\Tables\Columns;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Xelqun\Tables\Columns\Concerns\IsEditable;
use Xelqun\Tables\Columns\Contracts\Editable;

/**
 * A list of choices in the cell. Only those choices can be saved.
 *
 * @api
 */
class SelectColumn extends Column implements Editable
{
    use IsEditable {
        getProps as private getEditableProps;
    }

    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    /**
     * @param  array<string|int, string>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getType(): string
    {
        return 'select-column';
    }

    /**
     * The control matches on the value, so an enum stays one here, not its label.
     */
    protected function formatValue(mixed $value, Model $record): mixed
    {
        return $value instanceof BackedEnum ? $value->value : parent::formatValue($value, $record);
    }

    /**
     * @return array<int, mixed>
     */
    protected function getValueRules(): array
    {
        return ['nullable', Rule::in(array_keys((array) $this->evaluate($this->options)))];
    }

    protected function getProps(): array
    {
        $options = [];

        foreach ((array) $this->evaluate($this->options) as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return [...$this->getEditableProps(), 'options' => $options];
    }
}
