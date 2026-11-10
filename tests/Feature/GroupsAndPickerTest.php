<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Xelqun\Forms\Form;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Forms\ModalTableSelect;
use Xelqun\Tables\Group;
use Xelqun\Tables\State\TableState;
use Xelqun\Tables\Summarizers\Sum;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Category;
use Xelqun\Tables\Tests\Fixtures\Product;

class GroupedTable extends Table
{
    protected function boot(): void
    {
        $this->defaultGroup('status')->groups(['status', Group::make('is_featured')->label('Featured')->collapsible()]);
    }

    public function query(): Builder
    {
        return Product::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')];
    }
}

class CategoryPickerTable extends Table
{
    public function query(): Builder
    {
        return Category::query();
    }

    public function authorize(?Authenticatable $user): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [TextColumn::make('name')->searchable()];
    }
}

class ProductPickerForm extends Form
{
    public function model(): ?string
    {
        return Product::class;
    }

    public function schema(): array
    {
        return [ModalTableSelect::make('category_id')->relationship('category', 'name')->tableConfiguration(CategoryPickerTable::class)];
    }
}

it('groups by what the viewer picks from the table\'s groups, or by none', function () {
    Product::create(['name' => 'A', 'status' => 'live', 'is_featured' => true]);

    expect(GroupedTable::make()->toSchema()['groups'])->toBe([
        ['name' => 'status', 'label' => 'Status'],
        ['name' => 'is_featured', 'label' => 'Featured', 'collapsible' => true],
    ]);

    $this->queryTable(GroupedTable::make(), ['group' => 'is_featured'])->assertJsonPath('group', 'is_featured')->assertJsonPath('rows.0.group', '1');
    $this->queryTable(GroupedTable::make(), ['group' => 'secret'])->assertJsonPath('group', 'status');
    $this->queryTable(GroupedTable::make(), ['group' => ''])->assertJsonPath('group', null)->assertJsonMissingPath('rows.0.group');
});

it('sends a picker table without rows, and only the picked label', function () {
    $desks = Category::create(['name' => 'Desks']);
    Category::create(['name' => 'Lamps']);

    $form = ProductPickerForm::make(Product::create(['name' => 'Chair', 'category_id' => $desks->id]));
    $props = json_decode(json_encode(collect($form->toSchema()['components'])->firstWhere('path', 'data.category_id')), true)['props'];

    expect($props['options'])->toBe([['value' => $desks->id, 'label' => 'Desks']])
        ->and($props['titleAttribute'])->toBe('name')
        ->and($props['table'])->toMatchArray(['deferred' => true, 'rows' => [], 'id' => 'category-picker-table']);
});

it('accepts only related records that exist', function () {
    $desks = Category::create(['name' => 'Desks']);

    expect(ProductPickerForm::make()->validate(['data' => ['category_id' => $desks->id]])['category_id'])->toBe($desks->id)
        ->and(fn () => ProductPickerForm::make()->validate(['data' => ['category_id' => 999]]))->toThrow(ValidationException::class);
});

it('sums each group on the page apart, inside the table\'s query', function () {
    $table = new class extends Table
    {
        protected function boot(): void
        {
            $this->defaultGroup('is_featured')->groups(['is_featured']);
        }

        public function query(): Builder
        {
            return Product::query()->where('status', 'live')->orWhere('status', 'draft');
        }

        public function authorize(?Authenticatable $user): bool
        {
            return true;
        }

        public function columns(): array
        {
            return [TextColumn::make('name'), TextColumn::make('price')->summarize(Sum::make())];
        }
    };
    Product::create(['name' => 'A', 'status' => 'live', 'is_featured' => true, 'price' => 10]);
    Product::create(['name' => 'B', 'status' => 'live', 'is_featured' => false, 'price' => 20]);
    Product::create(['name' => 'C', 'status' => 'draft', 'is_featured' => true, 'price' => 5]);
    Product::create(['name' => 'D', 'status' => 'private', 'is_featured' => true, 'price' => 100]);

    $result = $table->toResult(TableState::from([], $table));
    $groups = json_decode(json_encode($result['groupSummaries']), true);

    expect(collect($result['rows'])->pluck('groupKey')->unique()->values()->all())->toBe(['0', '1'])
        ->and($groups['1']['price'][0]['value'])->toEqual(15)
        ->and($groups['0']['price'][0]['value'])->toEqual(20)
        ->and($result['summaries']['price'][0]['value'])->toEqual(35);
});

it('keeps a group with no value apart, and sums it', function () {
    $table = new class extends Table
    {
        protected function boot(): void
        {
            $this->defaultGroup('category_id')->groups(['category_id']);
        }

        public function query(): Builder
        {
            return Product::query();
        }

        public function authorize(?Authenticatable $user): bool
        {
            return true;
        }

        public function columns(): array
        {
            return [TextColumn::make('price')->summarize(Sum::make())];
        }
    };
    $category = Category::create(['name' => 'Audio']);
    Product::create(['name' => 'A', 'status' => 'live', 'price' => 4, 'category_id' => $category->id]);
    Product::create(['name' => 'B', 'status' => 'live', 'price' => 7]);
    Product::create(['name' => 'C', 'status' => 'live', 'price' => 3]);

    $groups = json_decode(json_encode($table->toResult(TableState::from([], $table))['groupSummaries']), true);

    expect($groups['null']['price'][0]['value'])->toEqual(10)
        ->and($groups[(string) $category->id]['price'][0]['value'])->toEqual(4);
});

it('gives no group a summary past the limit of groups on a page', function () {
    $table = new class extends Table
    {
        protected function boot(): void
        {
            $this->paginationPageOptions(['all'])->defaultGroup('name')->groups(['name']);
        }

        public function query(): Builder
        {
            return Product::query();
        }

        public function authorize(?Authenticatable $user): bool
        {
            return true;
        }

        public function columns(): array
        {
            return [TextColumn::make('price')->summarize(Sum::make())];
        }
    };

    foreach (range(1, 51) as $index) {
        Product::create(['name' => "P{$index}", 'status' => 'live', 'price' => 1]);
    }

    $result = $table->toResult(TableState::from([], $table));

    expect($result)->not->toHaveKey('groupSummaries')
        ->and($result['summaries']['price'][0]['value'])->toEqual(51);
});

it('sends no group summaries without summaries or groups', function () {
    $table = GroupedTable::make();

    expect($table->toResult(TableState::from([], $table)))->not->toHaveKey('groupSummaries');
});
