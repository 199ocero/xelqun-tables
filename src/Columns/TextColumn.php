<?php

namespace Xelqun\Tables\Columns;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * @api
 */
class TextColumn extends Column
{
    protected bool $isBadge = false;

    protected bool $isWrapped = false;

    public function getType(): string
    {
        return 'text-column';
    }

    protected function getProps(): array
    {
        return array_filter(['badge' => $this->isBadge, 'wrap' => $this->isWrapped]);
    }

    /**
     * Show the value in a pill. A list shows one pill for each value.
     */
    public function badge(bool $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    /**
     * Let long text run onto more lines. Cells stay on one line otherwise.
     */
    public function wrap(bool $condition = true): static
    {
        $this->isWrapped = $condition;

        return $this;
    }

    public function money(string $currency, ?string $locale = null): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => is_numeric($state)
            ? Number::currency((float) $state, $currency, $locale ?? app()->getLocale())
            : $state);
    }

    public function date(string $format = 'M j, Y'): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => $this->formatDate($state, $format));
    }

    public function dateTime(string $format = 'M j, Y H:i'): static
    {
        return $this->date($format);
    }

    /**
     * One bad value shouldn't take the whole table down, so it shows as it is.
     */
    private function formatDate(mixed $state, string $format): mixed
    {
        // Carbon reads an empty string as now.
        if ($state === '') {
            return $state;
        }

        try {
            return Carbon::parse($state)->translatedFormat($format);
        } catch (InvalidFormatException) {
            return $state;
        }
    }
}
