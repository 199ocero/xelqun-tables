# Changelog

Xelqun Tables is alpha. Until 1.0, a minor version can break existing code, and each break is listed here.

## Unreleased

### Fixed

- **A `Count` summary is a plain number**, not formatted like its column. On a money column it said `$300.00` where it meant 300 rows.
- The query builder calls its rows "Rule" and its button "Add rule", instead of "Item" and "Add item".
- A group's summary line has a light background, so it reads apart from the line for all rows.

## 0.8.0 - 2026-11-10

The schema version (`$xq`) stays 1. Update both halves together: `composer update xelqun/tables` and `npm install @xelqun/tables@0.8.0`.

### Upgrading

- **Requires `xelqun/forms` ^0.10** and `@xelqun/forms` ^0.10.0.
- **`Table::query()` is no longer abstract.** It returns `?Builder`, and `null` by default, for tables of custom data. A table that declares `query(): Builder` works as before. Code that calls `$table->query()` on another table should allow for `null`.
- `resolveRecords()`, `TableActionHost::getSelectedRecords()` and `BulkAction::getRecords()` are typed `Collection<int, covariant Model>`, because a row can be an `ArrayRecord`.

### Added

- **`visible()` on filters**, the opposite of `hidden()`, as on columns and in Filament.
- **Each group gets its own summary**, as in Filament. On a grouped table, a column's summarizers also add a line under the last row of each group on the page, beside the line for all rows. The result carries `groupSummaries`, keyed by each row's new `groupKey`, the group's raw value as JSON, so `null`, `false` and `""` stay apart. A group through a relation, such as `category.name`, gets none yet, and neither does any group on a page with more than 50 groups.
- **Groups of rules in the query builder**, as in Filament. A rule's column can be "Group of rules", which holds rules of its own with its own "match any" toggle, so `price > 15 AND (name has Lamp OR name has Sofa)` can be built. Groups nest up to **`maxNestingDepth()`**, 3 by default. **`maxRules()`** limits the rules across every group, and a filter past the limit is ignored as a whole. Rules saved in a URL or the session before keep working.
- **Tables of custom data**, as in Filament. Call `records()` in `boot()` with a closure that returns rows keyed by a stable key, from an API or anywhere else. It can ask for `$search`, `$columnSearches`, `$filters`, `$sortColumn`, `$sortDirection`, `$page` and `$recordsPerPage`. An array or collection is every row, and the table paginates it. A paginator is one page, as given.
    - Each row reaches columns and actions as an **`ArrayRecord`**: read it as `$record['title']` or `$record->title`, and get the array back with `toArray()`. It can't be saved or deleted, and a string `authorize()` ability denies unless a gate of that name allows it.
    - **`resolveSelectedRecordsUsing()`** finds rows again by key for actions. Without it, `records()` runs with no search or filters and all rows on one page. Rows the resolver returns beyond the keys it was asked for are dropped.
    - Nothing scopes custom rows for you, so `records()` must return only what the viewer may see.
    - Summaries, counts and sums of related rows, inline editing, the query builder, relationship filters, groups, reordering and cursor pagination need a database query. A table of custom data that uses one fails while the page renders, with a message that names it.

## 0.7.1 - 2026-11-10

The schema version (`$xq`) stays 1. Update both halves together: `composer update xelqun/tables` and `npm install @xelqun/tables@0.7.1`.

### Fixed

- The group picker works. Its "No grouping" option had an empty value, which the select refused, so the picker and the page size select beside it stopped working.
- A table whose only toolbar control is the group picker shows it.

## 0.7.0 - 2026-11-10

The schema version (`$xq`) stays 1. This needs `xelqun/forms` 0.9: `composer update xelqun/tables xelqun/forms` and `npm install @xelqun/tables@0.7.0 @xelqun/forms@0.9.0`.

### Added

- **`TextColumn::description()`** puts smaller text under the value, or above it, and **`placeholder()`** fills an empty cell.
- **`Column::default()`** stands in for an empty value, and **`Column::tooltip()`** shows text on hover.
- **`TextColumn::limit()`** and **`words()`** cut long text and show the whole of it on hover. **`lineClamp()`** wraps the text to at most that many lines.
- **`TextColumn::copyable()`**, with `copyMessage()` and `copyMessageDuration()`.
- **`TextColumn::numeric()`**, **`time()`**, **`since()`** and **`timezone()`**. `date()` and `dateTime()` take a time zone too.
- **`TextColumn::listWithLineBreaks()`**, **`bulleted()`**, **`limitList()`** and **`expandableLimitedList()`** for a cell that holds a list.
- An action that calls `$action->halt()` keeps its modal open, and a bulk action keeps its selection.
- **`Column::alignment()`**, **`width()`**, **`grow()`** and **`wrapHeader()`**.
- **`ColumnGroup::make('Money', [...])`** puts a heading over neighbouring columns.
- **`Table::heading()`** and **`description()`** go above the table.
- **`emptyState()`** takes an icon, and **`Table::emptyStateActions()`** puts buttons in it, such as one that creates the first record.
- **`checkIfRecordIsSelectableUsing()`** decides which rows can be selected. The server checks it too, so a row it refuses never reaches a bulk action or select all.
- **`BulkAction::authorizeIndividualRecords('archive')`** runs the action on the selected records the policy allows, instead of refusing them all.
- **`Table::recordAction()`** names the record action a click on the row opens.
- **`searchable(isIndividual: true)`** gives a column a search box of its own, and **`isGlobal: false`** keeps it out of the search above the table.
- **`counts('orders')`** and **`sum('orders', 'total')`** load related totals with the rows, and they can be sorted.
- **`paginated(false)`** shows every row with no pager, and `'all'` can be one of the page sizes.
- **`paginationMode('simple')`** and **`paginationMode('cursor')`** page without counting the rows. They offer previous and next, with no select all.
- **`deferLoading()`** renders the page without rows and loads them right after.
- **`deferFilters(false)`** applies each filter as it changes, with no Apply button.
- **`TernaryFilter::trueLabel()`**, **`falseLabel()`**, **`placeholder()`**, **`nullable()`** and **`queries(true:, false:, blank:)`**. A filter can narrow the query while it's off through `applyWhenInactive()`.
- **`persistSearchInSession()`**, **`persistSortInSession()`**, **`persistFiltersInSession()`** and **`persistColumnSearchesInSession()`**. A link with state of its own wins over what the session kept.
- **`groups(['status', Group::make('category.name')->collapsible()])`** lets the viewer pick how rows are grouped, or not grouped, and fold a collapsible group away.
- **`ModalTableSelect`**, a form field that picks related records from a table in a modal, with its search, filters and pages. It takes `relationship()` and `tableConfiguration(CategoriesTable::class)`, and the server checks each picked key against the relationship. Register `tablesPlugin()` with `createXelqunForms({ plugins: [tablesPlugin()] })`. Give the picker table a column named after the relationship's title attribute, so a new pick shows its label.
- A chip for each active filter, which removes it. **`indicateUsing()`** on a filter says what its chip reads.

### Fixed

- A `contentGrid()` table with no rows shows its empty state, and the empty row of a reorderable table spans every column.

## 0.6.1 - 2026-11-08

The schema version (`$xq`) stays 1. Update both halves together: `composer update xelqun/tables` and `npm install @xelqun/tables@0.6.1`. With `xelqun/panels`, this is what hides restore and force delete from a selection while deleted rows are hidden.

### Added

- A bulk action's closures can take `$query`: the selected rows as a query, still inside the table's query, loaded only as you go through them. Use it with `chunkById()` or `update()` when `maxSelectableRecords` is high.

### Changed

- `$records` is only loaded for a closure that asks for it, and a policy in `authorize()` checks the selection one row at a time. A 100,000-row selection run through `$query` peaks around 17 MB over the app instead of about 300 MB.
- `bulkActions` comes with every query result, not only the first render, so the server can change which bulk actions show as filters change.
- A row's actions reuse the table they belong to rather than building it again for each row.

### Fixed

- Dragging the sticky scrollbar, or scrolling the table sideways, no longer jitters. Each side used to copy the other's late scroll event back and pull it a frame behind.

## 0.6.0 - 2026-11-08

This release needs `xelqun/forms` 0.8. The schema version (`$xq`) stays 1.

### Upgrading

Update both halves together: `composer update xelqun/tables xelqun/forms` and `npm install @xelqun/tables@0.6.0 @xelqun/forms@0.8.0`. A saved URL with a query builder rule on a select or date constraint keeps its value under `value`, which no longer applies; the rule has to be made again.

### Changed

- **Icons come from `@lucide/vue`**, the new name of the deprecated `lucide-vue-next`.
- **`xelqun-tables:install` sets up forms too.** It installs both npm packages and runs `xelqun-forms:install`, so the plugin, the forms stylesheet (which holds the theme tokens) and the Vite setting are in place. Before, badges and the filters panel rendered unstyled after a tables-only install.
- **Query builder rules keep each kind of value in its own field.** Text and numbers stay in `value`, a `SelectConstraint` with options uses a select called `option`, and a `DateConstraint` uses a date picker called `date`. A `BooleanConstraint` asks for no value. A saved URL that holds a select or date rule in `value` no longer applies it.
- **`poll()` takes seconds or minutes**, as in `'10s'` or `'1m'`, and throws on anything else. `'1m'` used to poll every second.
- A validation message from an editable cell shows under that cell, keeps what was typed, and names the column ("The Stock field...") rather than "value".

### Added

- **`SelectFilter::relationship()->searchable()`** searches the related records on the server, so a long list never reaches the page.
- A relationship `SelectFilter` with more than 500 related records searches on the server even without `searchable()`.
- In card layout, a **Sort by** menu replaces the column headers.

### Fixed

- Each table endpoint has its own rate limit. They shared one counter per user, so 60 searches or polls in a minute blocked every action and edit.
- A `searchable(query:)` closure that starts with `where()` no longer narrows the search on every other column.
- `defaultGroup()` on a related column, such as `category.name`, keeps each group's rows together. A related group with no matching column throws a `TableDefinitionException`.
- A grouped table keeps the bindings of its sort, so a sort closure with `orderByRaw(..., [$value])` orders correctly.
- `TernaryFilter` reads `true` and `false`, and `default(false)` works.
- A query builder rule only counts as an active filter when it filters something.
- The filters panel fits the screen and scrolls, so Apply stays in reach as rules are added.
- Polling waits while someone types in a cell or has a dialog open, instead of wiping what they typed.
- A refused action says 'You are not allowed to do that.', and Retry only shows for a table that failed to load.
- Big tables are faster: a change to one cell or one selection renders only that row, and the row highlight measures at most once a frame. A filter's options load once per request instead of twice.
- A column's built-in summaries run as one query, rather than one for each line.
- The first row can't move up and the last can't move down, and each row's move buttons and checkbox say which row they belong to.

## 0.5.1 - 2026-11-07

### Added

- **`TextColumn::wrap()`** lets long text run onto more lines.

### Fixed

- A wide table scrolls sideways instead of wrapping its cells to fit. Cells stay on one line unless the column calls `wrap()`, and images keep their size.
- A wide table's scrollbar stays on screen while the table is in view, and stays visible on macOS.

## 0.5.0 - 2026-11-07

This release needs `xelqun/forms` 0.7. The schema version (`$xq`) stays 1.

### Added

- **Editable columns.** `ToggleColumn`, `CheckboxColumn`, `TextInputColumn` and `SelectColumn`. They're read-only until `authorize()` says who can edit them, and the row comes through the table's query.
- **`ColorColumn`** shows a swatch.
- **Summaries.** `summarize()` with `Sum`, `Average`, `Count` and `Range`, over every row of the search and filters.
- **Grouping.** `defaultGroup()` keeps rows together under a heading.
- **`QueryBuilder` filter.** People build their own rules from `TextConstraint`, `NumberConstraint`, `DateConstraint`, `BooleanConstraint` and `SelectConstraint`.
- **Row reordering.** `reorderable('sort', fn () => ...)` adds up and down buttons.
- **Column toggles.** `toggleable()` lets the viewer hide a column.
- **`contentGrid()`** shows rows as cards, **`striped()`** shades every other row, and **`poll('10s')`** keeps a table fresh.
- **Motion.** New rows ripple in, one highlight glides between rows, a selected row tints, and the bulk bar rises. It all follows forms' `--xq-*` tokens.
- **Enums.** A cast enum's `getLabel()`, `getColor()` and `getIcon()` fill in the cell.

## 0.4.0 - 2026-11-06

This release needs `xelqun/forms` 0.6. The schema version (`$xq`) stays 1.

### Upgrading

Update both halves together: `composer update xelqun/tables xelqun/forms` and `npm install @xelqun/tables@0.4.0 @xelqun/forms@0.6.0`.

### Added

- **`recordUrl()`** makes a whole row a link. Override it on the table, and a cell's own link wins over it. An unsafe URL is dropped.
- **`emptyState()`** sets the heading and description shown when there are no rows.
- **`actionNode`** is exported, so a custom view of a table's rows can render its actions with `XqAction`.

### Fixed

- **An upload stays with its table when one class serves several.** The upload's scope now includes the table's id.

## 0.3.0 - 2026-11-06

This release needs `xelqun/forms` 0.5. The schema version (`$xq`) stays 1.

### Upgrading

Update both halves together: `composer update xelqun/tables xelqun/forms` and `npm install @xelqun/tables@0.3.0 @xelqun/forms@0.5.0`.

- **`recordActions()` and `headerActions()` can return an `ActionGroup`, and so can `bulkActions()`.** A class that calls these and expects only actions needs `ActionGroup::flatten()`.

### Added

- **Action menus.** Put an `ActionGroup` in `recordActions()`, `headerActions()` or `bulkActions()` to show actions behind one button. Modals, confirmations and policies work as they do on a plain button.
- **Notifications from actions.** `successNotificationTitle()` and `Notification::make()->send()` work in row, header and bulk actions. An action that rolls back shows nothing.

### Fixed

- **A wide table no longer stretches the page on a phone.** Hidden labels escaped the table's scrolling area, so the browser zoomed the whole page out to fit them.

## 0.2.0 - 2026-11-06

This release needs `xelqun/forms` 0.4. The schema version (`$xq`) stays 1.

### Upgrading

Update both halves together: `composer update xelqun/tables xelqun/forms` and `npm install @xelqun/tables@0.2.0 @xelqun/forms@0.4.0`.

- **Actions moved.** `Action` is now `Xelqun\Actions\Action`. `BulkAction` stays in `Xelqun\Tables\Actions`.

### Changed

- **Action buttons no longer dim while the rows reload.** The clicked button stays busy until the new rows arrive.
- **`Action::successRedirectUrl()` works in tables.** The rows don't reload when the action sends the user away.

## 0.1.0 - 2026-11-06

The first version. It needs `xelqun/forms` 0.3.

- **Tables** as PHP classes, rendered by `XqTable`, rebuilt from an encrypted token on every request.
- **Columns:** `TextColumn` with badges, money, dates, colors, icons and links, plus `IconColumn`, `ImageColumn` and `CustomColumn`.
- **Search and sorting**, through relations too.
- **Filters** built on real form fields: `SelectFilter`, `TernaryFilter` and `Filter`.
- **Actions:** row and header actions with forms' `Action`, and `BulkAction` with selection across pages.
- **URL state:** search, sort, filters and the page survive a refresh or a shared link.
- **Plugins:** custom columns through forms' plugin system, and `make:xelqun-column`.
- **Commands:** `xelqun-tables:install`, `make:xelqun-table` and `make:xelqun-column`.
