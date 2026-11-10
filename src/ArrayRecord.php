<?php

namespace Xelqun\Tables;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One row of a table with custom data. Read it like the array records() gave,
 * as $record['title'] or $record->title, and get that array back with
 * toArray(). It is a model only so columns, actions and forms take it as they
 * take any record. It is never saved: write through your own API in the action.
 *
 * No policy can exist for it, so a string authorize() ability denies unless
 * a Gate::define() of that name allows it. Authorize custom data with a closure.
 *
 * @api
 */
final class ArrayRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    private string $recordKey = '';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(int|string $key, array $attributes): self
    {
        $record = new self;
        // Raw, so a key such as "password" or "id" is kept as given instead of filtered or cast.
        $record->setRawAttributes($attributes, sync: true);
        $record->recordKey = (string) $key;
        $record->exists = true;

        return $record;
    }

    public function getKey(): string
    {
        return $this->recordKey;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): never
    {
        throw new LogicException('A row of custom data can\'t be saved. Write it through your own API in the action.');
    }

    public function delete(): never
    {
        throw new LogicException('A row of custom data can\'t be deleted. Delete it through your own API in the action.');
    }
}
