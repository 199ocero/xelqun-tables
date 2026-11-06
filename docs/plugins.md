# Building a Xelqun Tables plugin

A plugin adds columns to Xelqun Tables from its own repository. Built-in columns use the same contract, so a plugin column can do what a built-in one does.

Tables has no plugin system of its own. A plugin is a [Xelqun Forms plugin](https://github.com/199ocero/xelqun-forms/blob/main/docs/plugins.md): the same `composer.json`, `package.json`, peer dependencies and `xelqun-forms:plugin` install command. Add `@xelqun/tables` to its peer dependencies.

## A column

`php artisan make:xelqun-column Sparkline --type=acme/sparkline` creates both halves. In a plugin repository, move the files into your `src/` and `resources/js/`.

A column is a PHP class:

```php
class Sparkline extends Column
{
    protected string $tone = 'primary';

    public function getType(): string
    {
        return 'acme/sparkline';
    }

    public function tone(string $tone): static
    {
        $this->tone = $tone;

        return $this;
    }

    // For the whole column, available as column.props.
    protected function getProps(): array
    {
        return ['tone' => $this->tone];
    }

    // For one cell, beside its state. It goes to the browser, so send only what the cell shows.
    protected function getCellProps(Model $record, mixed $state): array
    {
        return ['points' => $record->history->pluck('value')];
    }
}
```

Plugin types need a vendor prefix: `acme/sparkline`. Built-in columns end in `-column`, and forms shares the same registry, so never use a bare name.

And a Vue component that receives the column and the cell:

```vue
<script setup lang="ts">
import type { TableCell, TableColumn } from '@xelqun/tables';

defineProps<{ column: TableColumn; cell: TableCell }>();
</script>

<template>
    <span>{{ cell.state }}</span>
</template>
```

Register it like a forms field:

```ts
export function sparklinePlugin(): XelqunPlugin {
    return {
        id: 'acme/sparkline',
        components: { 'acme/sparkline': () => import('./components/SparklineCell.vue') },
    };
}
```

An app's own `components` option can replace a built-in cell by its type, such as `text-column`.

## What a column gets for free

These work on any column, with no code in your cell:

- `label()`, `hidden()`, `visible()`, `state()` and `formatStateUsing()`
- `sortable()` and `searchable()`, including through relations
- `url()`, which wraps your cell in a link
- `color()` and `icon()`, which arrive as `cell.color` and `cell.icon`
- `configureUsing()` and macros

To change how a value shows, override `formatValue()`. A list calls it once for each value.

**Never send a whole model.** Cells carry only what you put in them, and a column named after a relation sends the record's key. That is what keeps undeclared columns out of the browser.

## Security

- **Plugin code runs with full access to the app.** The contracts control how requests reach a column. They can't make a malicious package safe.
- **Cells aren't sanitized.** Vue escapes text, but it doesn't sanitize a bound `href` or `src`. For a link, use `url()`. If you bind one yourself, pass it through `Xelqun\Forms\Support\SafeUrl::filter()` in PHP.

## Testing

Add `Xelqun\Tables\Testing\InteractsWithTables` to your test case, or `uses(InteractsWithTables::class)` in Pest. It drives the real endpoints:

```php
$this->queryTable(ProductsTable::make(), ['search' => 'key', 'page' => 2])
    ->assertJsonPath('pagination.total', 3);

$this->mountTableAction($table, 'edit', $product->id);
$this->runTableAction($table, 'edit', $product->id, ['name' => 'New']);
$this->runTableAction($table, 'create', data: ['name' => 'New']);
$this->runBulkTableAction($table, 'delete', [$first->id, $second->id]);
```

## What you can rely on

Classes and methods tagged `@api` are the public API. `@internal` marks exceptions inside an `@api` class, and anything untagged may change without notice. Before 1.0, changes to `@api` items are listed in the release notes.

On the browser side, the public API is what `@xelqun/tables` exports.
