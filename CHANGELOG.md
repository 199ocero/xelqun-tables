# Changelog

Xelqun Tables is pre-alpha. Until 1.0, a minor version can break existing code, and each break is listed here.

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
