# Repository Guidelines

This repository contains the SAVE JSON WordPress plugin: a lightweight SEO toolkit with admin screens, per‑post meta, JSON‑LD, sitemaps UI, and WP‑CLI helpers.

## Project Structure & Module Organization
- `save-json-content.php` — plugin bootstrap; loads classes and features.
- `includes/` — core modules: `class-savejson.php` (Plugin), `cli.php` (WP‑CLI), `migration.php`, `breadcrumbs.php`.
- `admin/` — admin UI: `class-savejson-admin.php` (menus, settings, tools, migrations).
- `assets/` — JS/CSS assets (e.g., `voice.js`, admin assets).
- `data/` — optional exports or generated files (empty in repo).

If you add a new include, explicitly `require_once` it in `save-json-content.php`.

## Build, Test, and Development Commands
- PHP syntax check: `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- Local run: install into `wp-content/plugins/save-json-content/`, activate in WP Admin.
- WP‑CLI (from a WP install):
  - `wp savejson migrate_yoast --dry-run` — preview Yoast → SAVE JSON migration.
  - `wp savejson recalc` — print coverage stats for titles/descriptions/robots.
  - `wp savejson export_settings > savejson-settings.json` — export options JSON.

## Coding Style & Naming Conventions
- PHP 8+, 4‑space indentation, no tabs. Namespace: `SaveJSON`.
- Class files: `class-*.php` (PascalCase classes). Feature files: `*.php`.
- Methods and hooks prefer lower_snake_case (WordPress style).
- Escape on output (`esc_html__`, `esc_attr`, `esc_url`); sanitize on input.
- Use `SaveJSON\Plugin` constants for meta keys; store options under `savejson_options`.
- Optional linting: `phpcs` with WordPress rules if available.

## Testing Guidelines
- No PHPUnit suite. Validate in a local WP site:
  - Activate plugin, review admin pages (Search Appearance, Site Representation, etc.).
  - Create/edit a post and verify meta boxes and front‑end `<head>` output.
  - Run `wp savejson recalc` and relevant WP‑CLI commands.

## Commit & Pull Request Guidelines
- Write imperative, concise commits (e.g., `core: fix meta description sanitization`).
- Use a short scope prefix when helpful: `core`, `admin`, `cli`, `assets`.
- PRs should include: clear description, rationale, test plan (steps + WP‑CLI output), screenshots for UI changes, and linked issues if applicable.

## Security & Configuration Tips
- Always guard files with `if (!defined('ABSPATH')) { exit; }`.
- Check capabilities (`current_user_can`) and nonces for admin actions.
- Sanitize/validate on save; escape on render; prefer `wp_json_encode` for JSON.
