# Changelog

Xelqun Tables is pre-alpha. Until 1.0, a minor version can break existing code, and each break is listed here.

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
