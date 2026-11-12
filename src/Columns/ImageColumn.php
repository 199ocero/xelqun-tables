<?php

namespace Xelqun\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Xelqun\Forms\Support\SafeUrl;

/**
 * The state is a URL, or a path on the disk given to disk(). Without one, a
 * plain path is on the disk FileUpload saves to by default.
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

        // A plain path with no disk is where FileUpload put it, so it's on the same default disk.
        $disk = $this->disk ?? (preg_match('#\A(?:[a-z][a-z0-9+.-]*:|/)#i', $value) === 1 ? null : (string) config('xelqun-forms.uploads.disk', 'public'));

        return SafeUrl::filter($disk === null ? $value : Storage::disk($disk)->url($value));
    }
}
