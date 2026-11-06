<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Xelqun\Actions\Action;
use Xelqun\Actions\ActionGroup;
use Xelqun\Forms\Components\TextInput;
use Xelqun\Notifications\Notification;
use Xelqun\Tables\Actions\BulkAction;
use Xelqun\Tables\Columns\TextColumn;
use Xelqun\Tables\Exceptions\TableDefinitionException;
use Xelqun\Tables\Table;
use Xelqun\Tables\Tests\Fixtures\Product;

class GroupedActionsTable extends Table
{
    public static array $calls = [];

    public function query(): Builder
    {
        return Product::query()->where('status', 'live');
    }

    public function columns(): array
    {
        return [TextColumn::make('name')];
    }

    public function recordActions(): array
    {
        return [
            Action::make('plain')->authorize(true)->action(fn () => null),
            ActionGroup::make([
                Action::make('touch')
                    ->authorize(true)
                    ->successNotificationTitle('Touched')
                    ->action(function (Product $record): void {
                        self::$calls[] = $record->id;
                    }),
                Action::make('rename')
                    ->authorize(true)
                    ->fillForm(fn (Product $record): array => ['name' => $record->name])
                    ->schema([TextInput::make('name')->required()])
                    ->action(fn (Product $record, array $data) => $record->update($data)),
                Action::make('refused')
                    ->authorize(true)
                    ->successNotificationTitle('Refused')
                    ->action(function (): void {
                        Notification::make()->title('Halfway')->send();

                        throw ValidationException::withMessages(['data' => 'Not today.']);
                    }),
                Action::make('secret')->label('Secret label')->action(fn () => null),
            ])->label('More'),
            ActionGroup::make([Action::make('alsoSecret')->action(fn () => null)]),
        ];
    }

    public function headerActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('import')
                    ->authorize(true)
                    ->action(function (?Product $record): void {
                        self::$calls[] = ['record' => $record];
                    }),
            ]),
        ];
    }

    public function bulkActions(): array
    {
        return [
            ActionGroup::make([
                BulkAction::make('archive')
                    ->authorize('archive-product')
                    ->successNotificationTitle('Archived')
                    ->action(function (Collection $records): void {
                        self::$calls[] = $records->pluck('id')->all();
                    }),
            ]),
        ];
    }
}

class DuplicateGroupedActionsTable extends GroupedActionsTable
{
    public function recordActions(): array
    {
        return [
            Action::make('touch')->authorize(true),
            ActionGroup::make([Action::make('touch')->authorize(true)]),
        ];
    }
}

function liveProduct(string $name = 'Keyboard'): Product
{
    return Product::create(['name' => $name, 'status' => 'live']);
}

beforeEach(function () {
    GroupedActionsTable::$calls = [];
    Gate::define('archive-product', fn (?User $user, Product $product): bool => $product->name !== 'Locked');
});

it('sends a group with only what the viewer can see, and leaves out an empty one', function () {
    liveProduct();

    $actions = GroupedActionsTable::make()->toSchema()['rows'][0]['actions'];

    expect(array_column($actions, 'name'))->toHaveCount(2)
        ->and($actions[0]['name'])->toBe('plain')
        ->and(array_column($actions[1]['actions'], 'name'))->toBe(['touch', 'rename', 'refused'])
        ->and(json_encode($actions))->not->toContain('Secret label');
});

it('runs an action from a group on the row it was asked for', function () {
    liveProduct();
    $record = liveProduct();

    $this->runTableAction(GroupedActionsTable::make(), 'touch', $record->id)->assertOk();

    expect(GroupedActionsTable::$calls)->toBe([$record->id]);
});

it('refuses an action in a group the viewer may not run, with a 403', function () {
    $this->runTableAction(GroupedActionsTable::make(), 'secret', liveProduct()->id)->assertForbidden();
});

it('opens a modal filled from the row, and saves through it', function () {
    $record = liveProduct();

    $this->mountTableAction(GroupedActionsTable::make(), 'rename', $record->id)
        ->assertOk()
        ->assertJsonPath('form.state.data.name', 'Keyboard');

    $this->runTableAction(GroupedActionsTable::make(), 'rename', $record->id, ['name' => 'Mouse'])->assertOk();

    expect($record->refresh()->name)->toBe('Mouse');
});

it('runs a header action from a group, with no record', function () {
    $this->runTableAction(GroupedActionsTable::make(), 'import')->assertOk();

    expect(GroupedActionsTable::$calls)->toBe([['record' => null]]);
});

it('runs a bulk action from a group on the selected rows', function () {
    $first = liveProduct();
    $second = liveProduct('Mouse');

    $this->runBulkTableAction(GroupedActionsTable::make(), 'archive', [$first->id, $second->id])
        ->assertOk()
        ->assertJsonPath('notifications.0.title', 'Archived');

    expect(GroupedActionsTable::$calls)->toBe([[$first->id, $second->id]]);
});

it('checks a policy against every selected row in a group, with a 403', function () {
    $this->runBulkTableAction(GroupedActionsTable::make(), 'archive', [liveProduct()->id, liveProduct('Locked')->id])
        ->assertForbidden();

    expect(GroupedActionsTable::$calls)->toBe([]);
});

it('refuses two actions with one name, even when one is in a group', function () {
    liveProduct();

    DuplicateGroupedActionsTable::make()->toSchema();
})->throws(TableDefinitionException::class, 'two actions with the same name');

it('tells the user an action worked', function () {
    $this->runTableAction(GroupedActionsTable::make(), 'touch', liveProduct()->id)
        ->assertOk()
        ->assertJsonPath('notifications.0.title', 'Touched')
        ->assertJsonPath('notifications.0.status', 'success');
});

it('shows nothing from an action that was rolled back', function () {
    $this->runTableAction(GroupedActionsTable::make(), 'refused', liveProduct()->id)
        ->assertUnprocessable()
        ->assertJsonMissingPath('notifications');
});

it('leaves the key out when there is nothing to show', function () {
    $this->runTableAction(GroupedActionsTable::make(), 'plain', liveProduct()->id)
        ->assertOk()
        ->assertJsonMissingPath('notifications');
});
