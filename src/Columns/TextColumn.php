<?php

namespace Xelqun\Tables\Columns;

use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * @api
 */
class TextColumn extends Column
{
    protected bool $isBadge = false;

    protected bool $isWrapped = false;

    protected string|Closure|null $description = null;

    protected string $descriptionPosition = 'below';

    protected string|Closure|null $placeholder = null;

    protected ?int $characterLimit = null;

    protected ?int $wordLimit = null;

    protected string $limitEnd = '...';

    protected ?int $lineClamp = null;

    protected bool $isCopyable = false;

    protected ?string $copyMessage = null;

    protected int $copyMessageDuration = 2000;

    protected string|Closure|null $timezone = null;

    protected bool $isListWithLineBreaks = false;

    protected bool $isBulleted = false;

    protected ?int $listLimit = null;

    protected bool $isListExpandable = false;

    public function getType(): string
    {
        return 'text-column';
    }

    protected function getProps(): array
    {
        return array_filter([
            'badge' => $this->isBadge,
            'wrap' => $this->isWrapped,
            'descriptionAbove' => $this->descriptionPosition === 'above',
            'placeholder' => $this->evaluate($this->placeholder),
            'lineClamp' => $this->lineClamp,
            'copyable' => $this->isCopyable,
            'copyMessage' => $this->isCopyable ? $this->copyMessage : null,
            'copyMessageDuration' => $this->isCopyable && $this->copyMessageDuration !== 2000 ? $this->copyMessageDuration : null,
            'listWithLineBreaks' => $this->isListWithLineBreaks,
            'bulleted' => $this->isBulleted,
            'limitList' => $this->listLimit,
            'expandableLimitedList' => $this->isListExpandable,
        ]);
    }

    protected function getCellProps(Model $record, mixed $state): array
    {
        $full = $this->tooltip === null && ($this->characterLimit !== null || $this->wordLimit !== null) && is_string($state)
            ? (string) $this->formatValue($state, $record, truncate: false)
            : null;

        return [
            'description' => $this->evaluateForCell($this->description, $record, $state),
            // The whole text, on hover, when the cell shows less of it. Absent otherwise, so tooltip() keeps its own.
            ...($full !== null && $full !== $this->formatValue($state, $record) ? ['tooltip' => $full] : []),
        ];
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

    /**
     * Smaller text under the value, or above it. A closure gets $record and $state.
     */
    public function description(string|Closure|null $description, string $position = 'below'): static
    {
        $this->description = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    /**
     * What an empty cell shows, in a muted color.
     */
    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Cut the text to this many characters. The whole of it shows on hover.
     */
    public function limit(?int $length, string $end = '...'): static
    {
        $this->characterLimit = $length;
        $this->limitEnd = $end;

        return $this;
    }

    /**
     * Cut the text to this many words. The whole of it shows on hover.
     */
    public function words(?int $words, string $end = '...'): static
    {
        $this->wordLimit = $words;
        $this->limitEnd = $end;

        return $this;
    }

    /**
     * Wrap the text, but show at most this many lines.
     */
    public function lineClamp(?int $lines): static
    {
        $this->lineClamp = $lines;

        return $this->wrap($lines !== null);
    }

    /**
     * A button that copies the value.
     */
    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    public function copyMessage(?string $message): static
    {
        $this->copyMessage = $message;

        return $this;
    }

    /**
     * How long the message shows, in milliseconds.
     */
    public function copyMessageDuration(int $milliseconds): static
    {
        $this->copyMessageDuration = $milliseconds;

        return $this;
    }

    public function money(string $currency, ?string $locale = null): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => is_numeric($state)
            ? Number::currency((float) $state, $currency, $locale ?? app()->getLocale())
            : $state);
    }

    /**
     * A number with the locale's separators, and as many decimals as you ask for.
     */
    public function numeric(?int $decimalPlaces = null, ?string $locale = null): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => is_numeric($state)
            ? Number::format((float) $state, $decimalPlaces, locale: $locale ?? app()->getLocale())
            : $state);
    }

    /**
     * The time zone dates and times show in. The app's own unless you choose.
     */
    public function timezone(string|Closure|null $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function date(string $format = 'M j, Y', ?string $timezone = null): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => $this->formatDate($state, fn (Carbon $date): string => $date->translatedFormat($format), $timezone));
    }

    public function dateTime(string $format = 'M j, Y H:i', ?string $timezone = null): static
    {
        return $this->date($format, $timezone);
    }

    public function time(string $format = 'H:i:s', ?string $timezone = null): static
    {
        return $this->date($format, $timezone);
    }

    /**
     * How long ago, such as "3 days ago".
     */
    public function since(?string $timezone = null): static
    {
        return $this->formatStateUsing(fn (mixed $state): mixed => $this->formatDate($state, fn (Carbon $date): string => $date->diffForHumans(), $timezone));
    }

    /**
     * One value of a list to a line.
     */
    public function listWithLineBreaks(bool $condition = true): static
    {
        $this->isListWithLineBreaks = $condition;

        return $this;
    }

    /**
     * One value of a list to a line, each with a bullet.
     */
    public function bulleted(bool $condition = true): static
    {
        $this->isBulleted = $condition;

        return $this;
    }

    /**
     * Show only the first values of a list, with how many more there are.
     */
    public function limitList(?int $count): static
    {
        $this->listLimit = $count;

        return $this;
    }

    /**
     * Let the viewer open the rest of a limited list. It needs listWithLineBreaks() or bulleted().
     */
    public function expandableLimitedList(bool $condition = true): static
    {
        $this->isListExpandable = $condition;

        return $this;
    }

    protected function formatValue(mixed $value, Model $record, bool $truncate = true): mixed
    {
        $value = parent::formatValue($value, $record);

        if (! $truncate || ! is_string($value)) {
            return $value;
        }

        return match (true) {
            $this->characterLimit !== null => Str::limit($value, $this->characterLimit, $this->limitEnd),
            $this->wordLimit !== null => Str::words($value, $this->wordLimit, $this->limitEnd),
            default => $value,
        };
    }

    /**
     * One bad value shouldn't take the whole table down, so it shows as it is.
     *
     * @param  Closure(Carbon): string  $format
     */
    private function formatDate(mixed $state, Closure $format, ?string $timezone): mixed
    {
        // Carbon reads an empty string as now.
        if ($state === '') {
            return $state;
        }

        try {
            $date = Carbon::parse($state);
        } catch (InvalidFormatException) {
            return $state;
        }

        $timezone ??= $this->evaluate($this->timezone);

        return $format($timezone === null ? $date : $date->setTimezone($timezone));
    }
}
