# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Single-file WordPress plugin (`meet-a-librarian.php`) for Wheaton College's Madeleine Clark Wallace Library. It gives the library a "Meet With a Librarian" directory to replace LibCal Appointments. There is no build step, package manager, linter config, or test suite. The PHP file is the whole plugin. To release it, zip the file and upload it through wp-admin (**Plugins → Add New → Upload Plugin**). Targets WP ≥ 5.6 and PHP ≥ 7.2, so avoid newer PHP syntax such as arrow functions (7.4+), union types, `match`, and `str_contains`.

To check syntax locally: `php -l meet-a-librarian.php`. Everything else has to be tested manually on a WordPress install.

The plugin version is set only in the `Version:` plugin header and follows semantic versioning. Bump it in every commit that changes the plugin: PATCH for bug and accessibility fixes, MINOR for new backward-compatible features, MAJOR for breaking changes (for example, changes to the shortcode, the REST routes, or the stored option or post-meta shape).

## Architecture

The front-end and admin UIs are vanilla ES5 JavaScript and inline CSS. They sit in PHP **nowdoc** heredocs (`<<<'HTML'`), so PHP does not interpolate `$` inside them. PHP passes config to the JS through `window.*` globals that it prints just before each script:

- Admin editor: `window.MEETLIB`, set by `meetlib_admin_page()`, rendered by `meetlib_editor_markup()`.
- Shortcode `[meet_a_librarian]`: `window.MEETLIB_URL` and `window.MEETLIB_FORM`, set by `meetlib_shortcode()`, rendered by `meetlib_widget_markup()` and `meetlib_widget_script()`.

**Storage:** the plugin creates no custom tables.
- The librarian roster is one array in the `meetlib_librarians` option. `meetlib_get()` returns `meetlib_default()` when the option is empty.
- The booking page URL is stored in the `meetlib_page_url` option.
- Patron appointment requests are stored as the private CPT `meetlib_request`, with answers in `_meetlib_*` post meta. Code is the only thing that creates these records (`create_posts => do_not_allow`). Staff view them read-only under the admin menu.
- Everything uses the `meetlib` / `MEETLIB` / `meet-a-librarian` prefix. Version 1.x stored data under an older prefix. `meetlib_migrate()` runs once on `init` to move that data and is the only code that refers to the old keys. It is guarded by the `meetlib_db_version` option. If a later release changes the storage shape again, bump `MEETLIB_DB_VERSION` and add a new migration step.

**REST API** (`meet-a-librarian/v1`):
- `GET /librarians`: public. Both UIs load the roster from here.
- `POST /librarians`: requires `edit_pages`. Takes `{librarians, pageUrl}`, then runs the data through `meetlib_sanitize()`, a whitelist of the 8 roster fields. Rows without a name are dropped.
- `POST /request`: public, for patron booking-form submissions. The handler (`meetlib_handle_request`) protects it with a `wp_rest` nonce and a `website` honeypot. The flow is: validate the fields → check the email domain → upload the optional attachment → save a CPT record → `wp_mail` the librarian (a failure here does not fail the request) → return the Google `embedUrl` so the scheduler iframe can load.

**Patron flow:** the patron clicks "Book an appointment" and fills in the intake form. After the form is submitted, the Google Calendar appointment schedule is embedded through `?gv=true` (see `meetlib_embed_url`). Links that are not Google appointment links fall back to a plain external link.

## Invariants to preserve

- **Form fields are defined once.** `meetlib_form_fields()`, `meetlib_email_domains()`, `meetlib_upload_exts()`, and `meetlib_max_upload_bytes()` are serialized into `MEETLIB_FORM` and drive client-side rendering and validation. The server repeats the same validation. Exception: the allowed meeting options are **hard-coded a second time** as `$allowed_meetings` in `meetlib_handle_request`, and the `wp_handle_upload` mimes map is kept separately from `meetlib_upload_exts()`. When you change either one, update both places.
- **Slugs must match between PHP and JS.** `meetlib_slug()` in PHP and `slug()` in both JS blocks must produce the same slug (ASCII lowercase, non-alphanumeric runs become `-`, and leading/trailing `-` are trimmed). The deep link `?librarian=<slug>` automatically opens that librarian's form. The admin page generates copyable per-librarian widget HTML for LibApps/LibGuides profiles, and that HTML uses these links.
- **Requests use the roster index.** The front end sends `librarian=<index>` into the current roster array, not an ID. Reordering or removing librarians while a patron has the page open can send the request to the wrong librarian.
- **The `meetlib_request` CPT is added to the menu by hand** in `admin_menu`, after the editor, so the editor stays the default submenu (`show_in_menu => false`).
- Escaping: JS builds its HTML strings with the local `esc()` helper. PHP output uses `esc_html` or `esc_url`.
