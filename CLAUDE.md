# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Single-file WordPress plugin (`meet-a-librarian.php`) for Wheaton College's Madeleine Clark Wallace Library. It gives the library a "Meet With a Librarian" directory to replace LibCal Appointments. There is no build step, package manager, linter config, or test suite. The PHP file is the whole plugin. To release it, zip the file and upload it through wp-admin (**Plugins → Add New → Upload Plugin**). Targets WP ≥ 5.6 and PHP ≥ 7.2, so avoid newer PHP syntax such as arrow functions (7.4+), union types, `match`, and `str_contains`.

To check syntax locally: `php -l meet-a-librarian.php`. Everything else has to be tested manually on a WordPress install.

The plugin version is set only in the `Version:` plugin header and follows semantic versioning. Bump it in every commit that changes the plugin: PATCH for bug and accessibility fixes, MINOR for new backward-compatible features, MAJOR for breaking changes (for example, changes to the shortcode, the REST routes, or the stored option or post-meta shape).

Every major version gets a GitHub release. To publish one:
1. Tag the release commit as `vX.Y.Z` with an annotated tag.
2. Build `meet-a-librarian-X.Y.Z.zip` from that commit (`git show <sha>:meet-a-librarian.php`). The zip must contain a `meet-a-librarian/` folder holding the PHP file.
3. Run `gh release create vX.Y.Z <zip> --latest`.

Breaking changes and upgrade steps go at the top of the release notes. Existing releases: `v1.2.1` (last 1.x; its zip keeps the 1.x plugin folder so an existing install upgrades in place) and `v2.0.0`.

## Architecture

The front-end and admin UIs are vanilla ES5 JavaScript and inline CSS. They sit in PHP **nowdoc** heredocs (`<<<'HTML'`), so PHP does not interpolate `$` inside them. PHP passes config to the JS through `window.*` globals that it prints just before each script:

- Admin editor: `window.MEETLIB`, set by `meetlib_admin_page()`, rendered by `meetlib_editor_markup()`.
- Shortcode `[meet_a_librarian]`: `window.MEETLIB_URL` and `window.MEETLIB_FORM`, set by `meetlib_shortcode()`, rendered by `meetlib_widget_markup()` and `meetlib_widget_script()`.

**Storage:** the plugin creates no custom tables. Option and CPT keys are referenced through the `MEETLIB_*` constants at the top of the file, not string literals.
- The librarian roster is one array in the `meetlib_librarians` option. Each row has exactly 8 fields: `name, pronouns, title, liaison, email, photo, profile, booking`. `meetlib_get()` returns `meetlib_default()` when the option is empty. Adding a field means updating `meetlib_sanitize()`, `meetlib_default()`, the editor's `FIELDS` and "add" row in JS, and the widget JS.
- The booking page URL is stored in the `meetlib_page_url` option.
- Patron appointment requests are stored as the private CPT `meetlib_request`, with answers in `_meetlib_*` post meta. Code is the only thing that creates these records (`create_posts => do_not_allow`). Staff view them read-only under the admin menu.
- Everything uses the `meetlib` / `MEETLIB` / `meet-a-librarian` prefix. Version 1.x stored data under an older prefix. `meetlib_migrate()` runs once on `init` to move that data and is the only code that refers to the old keys. It is guarded by the `meetlib_db_version` option. If a later release changes the storage shape again, bump `MEETLIB_DB_VERSION` and add a new migration step.

**REST API** (`meet-a-librarian/v1`):
- `GET /librarians`: public. Both UIs load the roster from here (`meetlib_public_roster()`, which adds a computed `embed` scheduler URL per row; the sanitizer drops it when the editor saves).
- `POST /librarians`: requires `edit_pages`. Takes `{librarians, pageUrl}`, then runs the data through `meetlib_sanitize()`, a whitelist of the 8 roster fields. Rows without a name are dropped.
- `POST /request`: public, for patron booking-form submissions. The handler (`meetlib_handle_request`) protects it with a `wp_rest` nonce and a `website` honeypot. The flow is: resolve the librarian → validate the fields → check the email domain → upload the optional attachment → save a CPT record → try the optional Calendar annotation → `wp_mail` the librarian (a failure here does not fail the request) → return `{ok, calendar, embedUrl, booking}` (`embedUrl`/`booking` are kept only for pages still serving the 2.0 script from a cache).

**Patron flow (2.1+):** the patron clicks "Book an appointment" and sees step 1, the Google Calendar appointment schedule embedded through `?gv=true` (see `meetlib_embed_url`), with the `meetlib_urgent_email()` note under it. Links that are not Google appointment links fall back to a plain external link. "I've booked my time — continue" reveals step 2, the intake form. Both steps stay in the DOM and are toggled with `hidden`, so going back doesn't reload the iframe. The scheduler is a cross-origin iframe: the plugin cannot see whether a booking actually happened.

**Optional Google Calendar integration** (`meetlib_gcal_*`, admin page "Calendar Integration", `manage_options`): a service-account JSON key stored in the `meetlib_gcal_key` option (autoload off). No Workspace domain-wide delegation; each librarian shares their own calendar with the service account ("Make changes to events"), and the calendar ID is the roster `email`. The OAuth token comes from a hand-rolled RS256 JWT (`openssl_sign`, no Composer deps) and is cached in a transient. After a submission, `meetlib_gcal_annotate()` lists upcoming events updated in the last 6h matching the patron's email, picks the newest-created one with the patron as a guest that doesn't already contain `MEETLIB_GCAL_MARKER`, and PATCHes its description (HTML, `sendUpdates=none`). On `not_found`/`error`, one WP-cron retry (`meetlib_gcal_retry`) runs 15 minutes later. The status is stored in `_meetlib_gcal`. With no key saved, everything is a no-op that returns `off`.

## Invariants to preserve

- **Form fields are defined once.** `meetlib_form_fields()`, `meetlib_email_domains()`, `meetlib_upload_mimes()` (from which `meetlib_upload_exts()` is derived), `meetlib_max_upload_bytes()`, and `meetlib_urgent_email()` are serialized into `MEETLIB_FORM` and drive client-side rendering and validation. The server repeats the same validation from the same functions (meeting options via `meetlib_field('meeting')`). The request handler's `$data` array, the CPT meta list, the admin request view, `meetlib_notify()`, and `meetlib_gcal_details_html()` each list the text fields by hand, so a new field must be added to all of them.
- **Slugs must match between PHP and JS.** `meetlib_slug()` in PHP and `slug()` in both JS blocks must produce the same slug (ASCII lowercase, non-alphanumeric runs become `-`, and leading/trailing `-` are trimmed). The deep link `?librarian=<slug>` automatically opens that librarian's booking step. The admin page generates copyable per-librarian widget HTML for LibApps/LibGuides profiles, and that HTML uses these links, so **renaming a librarian breaks their already-pasted widget**. `esc()` is likewise duplicated in both JS blocks.
- **Requests are routed by roster index plus slug.** The front end sends `librarian=<index>` and `librarianSlug`. If the slug doesn't match the row at that index (the roster was edited while the page was open), the server looks the librarian up by slug instead. Requests without a slug (2.0 cached pages) use the index alone.
- **Never make librarians redo setup.** Booking links, roster shape, the shortcode, REST routes, and the widget deep-link format are what librarians and LibGuides pages depend on. New behavior must be additive or optional.
- **The `meetlib_request` CPT is added to the menu by hand** in `admin_menu`, after the editor, so the editor stays the default submenu (`show_in_menu => false`).
- Escaping: JS builds its HTML strings with the local `esc()` helper. PHP output uses `esc_html` or `esc_url`.
