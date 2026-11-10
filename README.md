# wpmeet — Meet With a Librarian

No-code WordPress plugin for a "Meet With a Librarian" directory. Staff manage librarians
and their Google appointment-booking links under **Meet With a Librarian** in wp-admin;
display the directory anywhere with the `[meet_a_librarian]` shortcode. Replaces LibCal
Appointments.

- Per-librarian booking links and subject specialties
- Patrons pick a time on the librarian's Google appointment schedule first, then fill in a
  short intake form; the answers are emailed to the librarian and kept under
  **Appointment Requests** in wp-admin
- Optional Google Calendar integration adds the patron's answers to the booked event
- Deep-linkable per-librarian widget for embedding elsewhere
- No custom database tables — the roster lives in a WordPress option, requests in a private post type

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.

## Optional: add answers to the calendar invite

Under **Meet With a Librarian → Calendar Integration** (site admins only):

1. In Google Cloud, enable the Google Calendar API, create a service account, and download a
   JSON key for it. Paste the key into the page and save. No Google Workspace admin access is needed.
2. Each librarian shares their Google Calendar with the service-account address shown on that page,
   with **Make changes to events** permission. The email in their directory entry must be that
   calendar's address.
3. Click **Check each librarian's calendar access** to confirm.

Until a key is saved, the plugin works as before and sends requests by email only.

## License

GPL-2.0+
