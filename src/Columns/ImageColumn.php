<?php

namespace Xelqun\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Xelqun\Forms\Support\SafeUrl;

/**
 * The state is a URL, or a path on the disk given to disk().
 *
 * @api
 */
class ImageColumn extends Column
{
    protected ?string $disk = null;

    protected int $size = 40;

    protected bool $isCircular = false;

    protected string|Closure|null $alt = null;

    public function getType(): string
    {
        return 'image-column';
    }

    public function disk(?string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function size(int $pixels): static
    {
        $this->size = $pixels;

        return $this;
    }

    public function circular(bool $condition = true): static
    {
        $this->isCircular = $condition;

        return $this;
    }

    /**
     * What a screen reader says. It defaults to the column's label.
     */
    public function alt(string|Closure|null $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    protected function getProps(): array
    {
        return ['size' => $this->size, 'circular' => $this->isCircular];
    }

    protected function getCellProps(Model $record, mixed $state): array
    {
        return ['alt' => $this->evaluateForCell($this->alt, $record, $state) ?? $this->getLabel()];
    }

    protected function formatValue(mixed $value, Model $record): mixed
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return SafeUrl::filter($this->disk === null ? $value : Storage::disk($this->disk)->url($value));
    }
}
