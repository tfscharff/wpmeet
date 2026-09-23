# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Single-file WordPress plugin (`mcw-meet-a-librarian.php`) for Wheaton College's Madeleine Clark Wallace Library. It gives the library a "Meet With a Librarian" directory to replace LibCal Appointments. There is no build step, package manager, linter config, or test suite. The PHP file is the whole plugin. To release it, zip the file and upload it through wp-admin (**Plugins → Add New → Upload Plugin**). Targets WP ≥ 5.6 and PHP ≥ 7.2, so avoid newer PHP syntax such as arrow functions (7.4+), union types, `match`, and `str_contains`.

To check syntax locally: `php -l mcw-meet-a-librarian.php`. Everything else has to be tested manually on a WordPress install.

The plugin version is set only in the `Version:` plugin header.

## Architecture

The front-end and admin UIs are vanilla ES5 JavaScript and inline CSS. They sit in PHP **nowdoc** heredocs (`<<<'HTML'`), so PHP does not interpolate `$` inside them. PHP passes config to the JS through `window.*` globals that it prints just before each script:

- Admin editor: `window.MCW_LIB`, set by `mcw_lib_admin_page()`, rendered by `mcw_lib_editor_markup()`.
- Shortcode `[meet_a_librarian]`: `window.MCW_LIB_URL` and `window.MCW_LIB_FORM`, set by `mcw_lib_shortcode()`, rendered by `mcw_lib_widget_markup()` and `mcw_lib_widget_script()`.

**Storage:** the plugin creates no custom tables.
- The librarian roster is one array in the `mcw_librarians` option. `mcw_lib_get()` returns `mcw_lib_default()` when the option is empty.
- The booking page URL is stored in the `mcw_lib_page_url` option.
- Patron appointment requests are stored as the private CPT `mcw_request`, with answers in `_mcw_*` post meta. Code is the only thing that creates these records (`create_posts => do_not_allow`). Staff view them read-only under the admin menu.

**REST API** (`mcw-librarians/v1`):
- `GET /librarians`: public. Both UIs load the roster from here.
- `POST /librarians`: requires `edit_pages`. Takes `{librarians, pageUrl}`, then runs the data through `mcw_lib_sanitize()`, a whitelist of the 8 roster fields. Rows without a name are dropped.
- `POST /request`: public, for patron booking-form submissions. The handler (`mcw_lib_handle_request`) protects it with a `wp_rest` nonce and a `website` honeypot. The flow is: validate the fields → check the email domain → upload the optional attachment → save a CPT record → `wp_mail` the librarian (a failure here does not fail the request) → return the Google `embedUrl` so the scheduler iframe can load.

**Patron flow:** the patron clicks "Book an appointment" and fills in the intake form. After the form is submitted, the Google Calendar appointment schedule is embedded through `?gv=true` (see `mcw_lib_embed_url`). Links that are not Google appointment links fall back to a plain external link.

## Invariants to preserve

- **Form fields are defined once.** `mcw_lib_form_fields()`, `mcw_lib_email_domains()`, `mcw_lib_upload_exts()`, and `mcw_lib_max_upload_bytes()` are serialized into `MCW_LIB_FORM` and drive client-side rendering and validation. The server repeats the same validation. Exception: the allowed meeting options are **hard-coded a second time** as `$allowed_meetings` in `mcw_lib_handle_request`, and the `wp_handle_upload` mimes map is kept separately from `mcw_lib_upload_exts()`. When you change either one, update both places.
- **Slugs must match between PHP and JS.** `mcw_lib_slug()` in PHP and `slug()` in both JS blocks must produce the same slug (ASCII lowercase, non-alphanumeric runs become `-`, and leading/trailing `-` are trimmed). The deep link `?librarian=<slug>` automatically opens that librarian's form. The admin page generates copyable per-librarian widget HTML for LibApps/LibGuides profiles, and that HTML uses these links.
- **Requests use the roster index.** The front end sends `librarian=<index>` into the current roster array, not an ID. Reordering or removing librarians while a patron has the page open can send the request to the wrong librarian.
- **The `mcw_request` CPT is added to the menu by hand** in `admin_menu`, after the editor, so the editor stays the default submenu (`show_in_menu => false`).
- Escaping: JS builds its HTML strings with the local `esc()` helper. PHP output uses `esc_html` or `esc_url`.
