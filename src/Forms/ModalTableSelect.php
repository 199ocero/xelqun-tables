<?php

namespace Xelqun\Tables\Forms;

use Xelqun\Forms\Components\Select;
use Xelqun\Forms\Exceptions\FormDefinitionException;
use Xelqun\Tables\Table;

/**
 * Pick related records from a table in a modal, with its search, filters and
 * pages, instead of a dropdown. It needs relationship(), which also checks
 * every picked key on the server. Register tablesPlugin() in the browser.
 *
 * @api
 */
class ModalTableSelect extends Select
{
    /** @var class-string<Table>|null */
    protected ?string $tableClass = null;

    public function getType(): string
    {
        return 'xelqun/modal-table-select';
    }

    /**
     * The table to pick from. Its query should list the related records, and
     * its authorize() decides who may open it.
     *
     * @param  class-string<Table>  $table
     */
    public function tableConfiguration(string $table): static
    {
        $this->tableClass = $table;

        return $this;
    }

    /**
     * Only the picked records' labels go to the browser, never the whole list.
     */
    public function isAsync(): bool
    {
        return true;
    }

    protected function getProps(): array
    {
        if ($this->tableClass === null || $this->relationship === null) {
            throw new FormDefinitionException("The field [{$this->getName()}] needs relationship() and tableConfiguration().");
        }

        // The rows load when the modal opens, so the form costs no table query.
        $table = $this->tableClass::make()->deferLoading();

        return [
            ...parent::getProps(),
            'table' => $table->toSchema(),
            'titleAttribute' => $this->titleAttribute,
        ];
    }
}
