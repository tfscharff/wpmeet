# wpmeet — Meet With a Librarian

No-code WordPress plugin for a "Meet With a Librarian" directory. Staff manage librarians
and their Google appointment-booking links under **Meet With a Librarian** in wp-admin;
display the directory anywhere with the `[meet_a_librarian]` shortcode. Replaces LibCal
Appointments.

- Per-librarian booking links and subject specialties
- Patrons answer a few short questions, then pick a time on the librarian's embedded Google
  appointment schedule on the same screen (the calendar unlocks once the questions are sent);
  the answers are emailed to the librarian and kept under **Appointment Requests** in wp-admin
- Optional Google Calendar integration adds the patron's answers to the booked event and holds
  the librarian's email until the booking actually appears, so nobody is emailed about a
  request that never became an appointment
- A patron's attachment is emailed to the librarian and is not kept on the website
- Deep-linkable per-librarian widget for embedding elsewhere
- No custom database tables — the roster lives in a WordPress option, requests in a private post type

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.
To upgrade, upload the new zip the same way and choose **Replace current with uploaded**.
Upgrading never changes the directory, the booking links, the saved Calendar key, or the
librarians' calendar sharing.

## Optional: add answers to the calendar invite

Under **Meet With a Librarian → Calendar Integration** (site admins only):

1. In Google Cloud, enable the Google Calendar API, create a service account, and download a
   JSON key for it. Paste the key into the page and save. The key is never shown again, so the
   box is empty afterwards; "Status: on" means it is saved.
2. Each librarian shares their Google Calendar with the service-account address shown on that page,
   with **Make changes to events** permission ("see private events as busy" is enough). The email
   in their directory entry must be that calendar's address. If Google will not offer that
   permission for an outside address, the college's Google Workspace settings are blocking it
   and IT would need to allow it.
3. Click **Check each librarian's calendar access** to confirm.

Until a key is saved, the plugin sends each request by email straight away.

### How a request is matched to a booking

After a patron sends the form, the plugin checks the librarian's calendar 3, 10 and 30 minutes,
then 2, 6 and 24 hours later. A booking is an event created after the form was sent that lists
the patron's email address as a guest. When it is found, the answers are added to the event and
the librarian is emailed "Appointment booked" with the time. If none is found within a day, the
librarian is emailed the answers labelled "no booking found", along with any bookings made
around the same time under a different address.

- Patrons must book with the same email address they typed in the form. The page reminds them.
- To test, use a college address that is not the librarian's own. A librarian's own address is
  on every event they own, so it is recognised as a test and emailed without a calendar match.

## When something isn't working

Warnings appear at the top of the plugin's admin screens when a librarian's calendar can't be
used, when requests are overdue for a calendar check, or when an email could not be sent.

- **Appointment Requests** has a **Status** column: Booked, Waiting for booking, No booking
  found, and whether the librarian was emailed.
- **Calendar Integration → Run waiting checks now** checks every waiting request immediately.
  Use it if the site's background tasks are not running.
- **Meet With a Librarian → Earlier versions** restores the directory as it was before any of
  the last five saves.

## Development

The plugin is the single file `meet-a-librarian.php`. Tests need only PHP and run offline
against in-memory stand-ins for WordPress and Google Calendar:

```
php -l meet-a-librarian.php
php tests/run.php
php -S 127.0.0.1:8123 tests/ui.php
```

The last command serves a local preview of the patron page at `http://127.0.0.1:8123/` and of
the directory editor at `/editor`; add `?autotest=1` to run the scripted checks.

## License

GPL-2.0+
