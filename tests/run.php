<?php
/**
 * Offline tests for meet-a-librarian.php. No WordPress, no network: WordPress functions and
 * Google's Calendar API are replaced by small in-memory fakes below.
 *
 *   php tests/run.php
 *
 * Exit code 0 = all passed. Covers the parts that have gone wrong before: which calendar event a
 * request is matched to, when the librarian is emailed, and what the form handler accepts.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
ini_set( 'error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meetlib-tests.log' );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
if ( ! defined( 'OPENSSL_ALGO_SHA256' ) ) { define( 'OPENSSL_ALGO_SHA256', 7 ); }
if ( ! function_exists( 'openssl_sign' ) ) {
	function openssl_sign( $d, &$s, $k, $a = 0 ) { $s = 'sig'; return true; }
	function openssl_pkey_get_private( $k ) { return 'key'; }
}

require __DIR__ . '/wp-stubs.php';
require dirname( __DIR__ ) . '/meet-a-librarian.php';

/* ---------------- helpers ---------------- */

$GLOBALS['fails'] = 0; $GLOBALS['passes'] = 0;
function ok( $cond, $what ) {
	if ( $cond ) { $GLOBALS['passes']++; return; }
	$GLOBALS['fails']++;
	echo "FAIL: $what\n";
}
function eq( $got, $want, $what ) { ok( $got === $want, $what . ' (got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) . ')' ); }
function has( $hay, $needle, $what ) { ok( false !== strpos( (string) $hay, $needle ), $what . ' (missing "' . $needle . '")' ); }
function lacks( $hay, $needle, $what ) { ok( false === strpos( (string) $hay, $needle ), $what . ' (should not contain "' . $needle . '")' ); }
function form( $over = array() ) {
	return new WP_REST_Request( array_merge( array(
		'librarian' => '0', 'librarianSlug' => 'example-librarian-one', 'email' => 'student@wheatoncollege.edu',
		'help' => 'Finding sources', 'course' => 'ENG 101', 'due' => 'Friday', 'meeting' => 'On Zoom',
	), $over ) );
}
/** Submit the form as if it had been sent $ago seconds ago; returns the request's post ID. */
function submit( $ago = 0, $over = array() ) {
	$r = meetlib_handle_request( form( $over ) );
	if ( is_wp_error( $r ) ) { throw new Exception( 'submit failed: ' . $r->get_error_message() ); }
	$id = $GLOBALS['T']['next_id'] - 1;
	update_post_meta( $id, '_meetlib_sent', time() - $ago );
	update_post_meta( $id, '_meetlib_gcal_next', time() - 1 );
	return $id;
}
function st( $id ) { return get_post_meta( $id, '_meetlib_gcal', true ); }
function mails() { return $GLOBALS['T']['mails']; }
function last_mail() { $m = mails(); return $m ? $m[ count( $m ) - 1 ] : array( 'to' => '', 'subject' => '', 'body' => '', 'attach' => array() ); }
const CAL = 'librarian1@wheatoncollege.edu';
const STU = 'student@wheatoncollege.edu';
$now = time();

/* ---------------- tests ---------------- */

// 1. The normal path: form, then a booking, then the check finds it.
t_reset();
$id = submit( 600 );
eq( st( $id ), 'pending', '1 new request waits for the booking' );
eq( count( mails() ), 0, '1 no email while waiting' );
eq( count( $GLOBALS['T']['g']['calls'] ), 0, '1 submitting the form does not contact Google' );
t_event( CAL, 'book1', $now - 500, STU );
meetlib_gcal_retry( $id );
eq( st( $id ), 'added', '1 booking found' );
has( t_desc( CAL, 'book1' ), 'Finding sources', '1 answers written to the booking' );
has( last_mail()['subject'], 'Appointment booked: Tuesday, November 17 at 2:00 pm', '1 email subject has the time in the calendar time zone' );
eq( last_mail()['to'], CAL, '1 email goes to the librarian' );
has( last_mail()['body'], 'eid=book1', '1 email links to the event' );
meetlib_gcal_retry( $id, true );
eq( count( mails() ), 1, '1 a second run sends nothing more' );

// 2. Events that existed before the form are never touched, even with the patron on them.
t_reset();
t_event( CAL, 'staffmtg', $now - 9000, array( 'librarian3@wheatoncollege.edu' ) );
t_event( CAL, 'oldmtg', $now - 9000, STU );
$GLOBALS['T']['g']['cals'][ CAL ]['events']['oldmtg']['updated'] = t_iso( $now - 100 ); // someone RSVPed just now
$id = submit( 600 );
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '2 no match among older events' );
eq( t_desc( CAL, 'oldmtg' ), '', '2 older event with the patron as guest untouched' );
eq( t_desc( CAL, 'staffmtg' ), '', '2 staff meeting untouched' );

// 3. A librarian testing with their own address: emailed at once, calendar never searched.
t_reset();
t_event( CAL, 'curriculum', $now - 100, array( 'librarian3@wheatoncollege.edu' ) );
$id = submit( 0, array( 'email' => CAL ) );
eq( st( $id ), 'self', '3 own-address test is recognised' );
eq( count( mails() ), 1, '3 own-address test is emailed immediately' );
eq( count( $GLOBALS['T']['g']['calls'] ), 0, '3 own-address test never contacts Google' );
eq( t_desc( CAL, 'curriculum' ), '', '3 the librarian\'s meeting is untouched' );

// 4. Two forms back to back by one patron: each gets its own booking, in either processing order.
foreach ( array( 'A first', 'B first' ) as $order ) {
	t_reset();
	$a = submit( 400, array( 'help' => 'TOPIC-A' ) );
	t_event( CAL, 'bookA', $now - 350, STU );
	$b = submit( 300, array( 'help' => 'TOPIC-B' ) );
	t_event( CAL, 'bookB', $now - 250, STU );
	if ( 'A first' === $order ) { meetlib_gcal_retry( $a ); meetlib_gcal_retry( $b ); } else { meetlib_gcal_retry( $b ); meetlib_gcal_retry( $a ); }
	has( t_desc( CAL, 'bookA' ), 'TOPIC-A', "4 ($order) first booking gets the first form" );
	lacks( t_desc( CAL, 'bookA' ), 'TOPIC-B', "4 ($order) first booking does not get the second form" );
	has( t_desc( CAL, 'bookB' ), 'TOPIC-B', "4 ($order) second booking gets the second form" );
	lacks( t_desc( CAL, 'bookB' ), 'TOPIC-A', "4 ($order) second booking does not get the first form" );
}

// 5. Second form sent before the first check ran, only the first booking exists yet.
t_reset();
$a = submit( 200, array( 'help' => 'TOPIC-A' ) );
t_event( CAL, 'bookA', $now - 150, STU );
$b = submit( 100, array( 'help' => 'TOPIC-B' ) );
meetlib_gcal_retry( $b );
eq( st( $b ), 'pending', '5 second form does not take the first booking' );
eq( t_desc( CAL, 'bookA' ), '', '5 first booking still untagged' );

// 6. Booked under a personal address: no match, and after the last check a "no booking" email with a hint.
t_reset();
$id = submit( 600 );
t_event( CAL, 'gmailbook', $now - 500, array( 'student.personal@gmail.com' ) );
for ( $i = 0; $i < 6; $i++ ) { meetlib_gcal_retry( $id, true ); }
eq( st( $id ), 'not_found', '6 ends as not found after the last check' );
eq( t_desc( CAL, 'gmailbook' ), '', '6 the other-address booking is not written to' );
eq( count( mails() ), 1, '6 exactly one email' );
has( last_mail()['subject'], 'no booking found', '6 email is labelled no booking found' );
has( last_mail()['body'], 'booked as student.personal@gmail.com', '6 email lists the likely booking' );

// 7. Checks stop early and count correctly: 5 not-found checks leave it pending, the 6th finishes.
t_reset();
$id = submit( 600 );
for ( $i = 0; $i < 5; $i++ ) { meetlib_gcal_retry( $id, true ); }
eq( st( $id ), 'pending', '7 still waiting after 5 checks' );
eq( count( mails() ), 0, '7 no email yet' );
meetlib_gcal_retry( $id, true );
eq( st( $id ), 'not_found', '7 finished on the 6th check' );

// 8. Booked hours later, for a slot that is already over by the time of the check.
t_reset();
$id = submit( 20 * 3600 );
t_event( CAL, 'late', $now - 17 * 3600, STU, array( 'start' => array( 'dateTime' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 3600 ) ) ) );
meetlib_gcal_retry( $id );
eq( st( $id ), 'added', '8 late booking for a past slot is still found' );

// 9. Calendar shared read-only: stop waiting, email now, flag it, and do not hold later requests.
t_reset();
$GLOBALS['T']['g']['cals'][ CAL ]['role'] = 'reader';
$id = submit( 300 );
t_event( CAL, 'book1', $now - 200, STU );
meetlib_gcal_retry( $id );
eq( st( $id ), 'no_access', '9 read-only calendar ends the wait' );
eq( count( mails() ), 1, '9 librarian emailed straight away' );
has( last_mail()['body'], 'could not reach your Google Calendar', '9 email explains the calendar was not checked' );
ok( meetlib_gcal_flagged( CAL ), '9 calendar is flagged' );
$id2 = submit( 0, array( 'help' => 'second' ) );
eq( st( $id2 ), 'no_access', '9 next request is not held' );
eq( count( mails() ), 2, '9 next request emailed immediately' );
ok( count( meetlib_health() ) > 0, '9 health warning shown' );

// 10. Calendar not shared at all (404).
t_reset();
unset( $GLOBALS['T']['g']['cals'][ CAL ] );
$id = submit( 300 );
meetlib_gcal_retry( $id );
eq( st( $id ), 'no_access', '10 unshared calendar ends the wait' );
eq( count( mails() ), 1, '10 emailed' );

// 11. Google outage: keep waiting and retry; nothing is flagged.
t_reset();
$id = submit( 300 );
$GLOBALS['T']['g']['fail'] = 503;
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '11 outage keeps the request waiting' );
ok( ! meetlib_gcal_flagged( CAL ), '11 outage does not flag the calendar' );
eq( count( mails() ), 0, '11 no email during outage' );
$GLOBALS['T']['g']['fail'] = 0;
t_event( CAL, 'book1', $now - 200, STU );
meetlib_gcal_retry( $id, true );
eq( st( $id ), 'added', '11 found once Google is back' );

// 12. Outage through the very last check: still emailed, never lost.
t_reset();
$id = submit( 300 );
$GLOBALS['T']['g']['fail'] = 500;
for ( $i = 0; $i < 6; $i++ ) { meetlib_gcal_retry( $id, true ); }
eq( st( $id ), 'error', '12 ends as error' );
eq( count( mails() ), 1, '12 emailed anyway' );

// 13. This server's clock is 5 minutes fast.
t_reset();
$GLOBALS['T']['g']['skew'] = 300;
$id = submit( 100 );                                    // sent at server now-100 = Google now-400
t_event( CAL, 'before', $now - 300 - 200, STU );         // Google now-500: before the form
t_event( CAL, 'after', $now - 300 - 40, STU );           // Google now-340: after the form
meetlib_gcal_retry( $id );
eq( st( $id ), 'added', '13 match despite a fast server clock' );
has( t_desc( CAL, 'after' ), 'Finding sources', '13 the booking made after the form is tagged' );
eq( t_desc( CAL, 'before' ), '', '13 the event from before the form is not' );

// 14. This server's clock is 5 minutes slow.
t_reset();
$GLOBALS['T']['g']['skew'] = -300;
$id = submit( 100 );                                    // Google now+200
t_event( CAL, 'before', $now + 300 - 200, STU );         // Google now+100: before the form
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '14 slow clock does not match an older event' );

// 15. Someone edits the event between our read and our write (HTTP 412): try again later.
t_reset();
$id = submit( 300 );
t_event( CAL, 'book1', $now - 200, STU );
$GLOBALS['T']['g']['patch_fail'] = 412;
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '15 conflict keeps waiting' );
ok( ! meetlib_gcal_flagged( CAL ), '15 conflict is not a sharing problem' );
$GLOBALS['T']['g']['patch_fail'] = 0;
meetlib_gcal_retry( $id, true );
eq( st( $id ), 'added', '15 succeeds on the next check' );

// 16. Skipped events: cancelled, recurring, already tagged.
t_reset();
$id = submit( 300 );
t_event( CAL, 'cancelled', $now - 200, STU, array( 'status' => 'cancelled' ) );
t_event( CAL, 'weekly', $now - 200, STU, array( 'recurrence' => array( 'RRULE:FREQ=WEEKLY' ) ) );
t_event( CAL, 'tagged', $now - 200, STU, array( 'description' => 'x <b>Meet With a Librarian request</b> y' ) );
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '16 cancelled, recurring and tagged events are skipped' );

// 17. A leftover cron event for a check that already ran does not use up an attempt.
t_reset();
$id = submit( 300 );
update_post_meta( $id, '_meetlib_gcal_next', time() + 600 );
meetlib_gcal_retry( $id );
eq( (int) get_post_meta( $id, '_meetlib_gcal_tries', true ), 0, '17 early cron event is ignored' );

// 18. The sweep runs overdue checks, adopts requests from 2.4 (no next-check time), and clears its flag.
t_reset();
$id = submit( 600 );
update_post_meta( $id, '_meetlib_gcal_next', time() - 300 );
$old = submit( 600, array( 'help' => 'from 2.4' ) );
delete_post_meta( $old, '_meetlib_gcal_next' );
t_event( CAL, 'b1', $now - 500, STU );
t_event( CAL, 'b2', $now - 400, STU );
eq( (int) get_option( 'meetlib_work' ), 1, '18 work flag raised by a waiting request' );
meetlib_sweep();
eq( st( $id ), 'added', '18 sweep ran the overdue check' );
eq( st( $old ), 'added', '18 sweep adopted the 2.4 request' );
eq( (int) get_option( 'meetlib_work' ), 0, '18 work flag cleared when nothing is waiting' );

// 19. Email failure: retried by the sweep, sent once the mailer works.
t_reset();
update_option( 'meetlib_gcal_key', '' );
$GLOBALS['T']['mail_ok'] = false;
$id = submit( 0 );
eq( st( $id ), 'off', '19 integration off' );
eq( (int) get_post_meta( $id, '_meetlib_mail_fails', true ), 1, '19 failure counted' );
has( meetlib_status_short( $id ), 'email failed', '19 list shows the failure' );
ok( count( meetlib_health() ) > 0, '19 health warning for failed email' );
$GLOBALS['T']['mail_ok'] = true;
meetlib_sweep( 3, true );
eq( count( mails() ), 1, '19 email sent on retry' );
has( meetlib_status_short( $id ), 'emailed', '19 list shows it was emailed' );
eq( (int) get_option( 'meetlib_work' ), 0, '19 nothing left to do' );

// 20. Integration off: email immediately with the plain wording.
t_reset();
update_option( 'meetlib_gcal_key', '' );
$id = submit( 0 );
eq( count( mails() ), 1, '20 emailed immediately' );
has( last_mail()['subject'], 'Appointment request', '20 plain subject' );
has( last_mail()['body'], 'expect a booking shortly', '20 plain wording' );
eq( last_mail()['headers'], array( 'Reply-To: ' . STU ), '20 reply-to is the patron' );

// 21. Librarian with no email on file: goes to the shared inbox, not nowhere.
t_reset();
$r = meetlib_handle_request( form( array( 'librarian' => '1', 'librarianSlug' => 'example-librarian-two' ) ) );
ok( ! is_wp_error( $r ), '21 accepted' );
eq( last_mail()['to'], meetlib_urgent_email(), '21 sent to the shared inbox' );
has( last_mail()['body'], 'No email address is on file for Example Librarian Two', '21 says why' );

// 22. What the handler refuses.
t_reset();
$bad = array(
	'honeypot'      => array( 'meetlib_hp' => 'http://spam' ),
	'old honeypot'  => array( 'website' => 'http://spam' ),
	'outside email' => array( 'email' => 'someone@gmail.com' ),
	'missing help'  => array( 'help' => '  ' ),
	'bad meeting'   => array( 'meeting' => 'On the moon' ),
	'too long'      => array( 'course' => str_repeat( 'x', 600 ) ),
	'no librarian'  => array( 'librarian' => null, 'librarianSlug' => '' ),
	'gone librarian' => array( 'librarian' => '7', 'librarianSlug' => 'nobody-here' ),
);
foreach ( $bad as $what => $over ) { ok( is_wp_error( meetlib_handle_request( form( $over ) ) ), "22 refuses: $what" ); }
eq( count( $GLOBALS['T']['posts'] ), 0, '22 nothing stored for refused submissions' );

// 23. Roster edited while the page was open: the slug wins over a stale index.
t_reset();
$r = meetlib_handle_request( form( array( 'librarian' => '1', 'librarianSlug' => 'example-librarian-one' ) ) );
$id = $GLOBALS['T']['next_id'] - 1;
eq( get_post_meta( $id, '_meetlib_librarian', true ), 'Example Librarian One', '23 routed by slug' );

// 24. The same answers sent twice are one request; different answers are two.
t_reset();
meetlib_handle_request( form() );
meetlib_handle_request( form() );
eq( count( $GLOBALS['T']['posts'] ), 1, '24 duplicate submission stored once' );
meetlib_handle_request( form( array( 'help' => 'Something else' ) ) );
eq( count( $GLOBALS['T']['posts'] ), 2, '24 different answers stored separately' );

// 25. Rate limit per email address.
t_reset();
$blocked = false;
for ( $i = 0; $i < 12; $i++ ) { if ( is_wp_error( meetlib_handle_request( form( array( 'help' => "q$i" ) ) ) ) ) { $blocked = true; break; } }
ok( $blocked && $i === 8, '25 ninth request in an hour from one address is refused' );

// 26. A duplicate request that never got its own booking says so, pointing at the matched one.
t_reset();
$a = submit( 600, array( 'help' => 'first try' ) );
$b = submit( 590, array( 'help' => 'second try' ) );
t_event( CAL, 'book1', $now - 500, STU );
meetlib_gcal_retry( $a );
for ( $i = 0; $i < 6; $i++ ) { meetlib_gcal_retry( $b, true ); }
eq( st( $a ), 'added', '26 first request matched' );
eq( st( $b ), 'not_found', '26 second request unmatched' );
has( last_mail()['body'], 'same patron sent another request that was matched', '26 email explains the duplicate' );

// 27. Booking links.
eq( meetlib_embed_url( 'https://calendar.google.com/calendar/appointments/schedules/AAA' ), 'https://calendar.google.com/calendar/appointments/schedules/AAA?gv=true', '27 plain link' );
eq( meetlib_embed_url( 'https://calendar.google.com/calendar/u/0/appointments/schedules/AAA' ), 'https://calendar.google.com/calendar/appointments/schedules/AAA?gv=true', '27 /u/0/ link' );
eq( meetlib_embed_url( 'https://calendar.google.com/calendar/appointments/schedules/AAA?gv=true' ), 'https://calendar.google.com/calendar/appointments/schedules/AAA?gv=true', '27 already an embed link' );
eq( meetlib_embed_url( 'https://calendar.google.com/calendar/appointments/AAA?x=1#top' ), 'https://calendar.google.com/calendar/appointments/AAA?x=1&gv=true#top', '27 query and fragment' );
eq( meetlib_embed_url( 'https://calendar.app.google/short' ), '', '27 short link is not embeddable until it has been looked up' );
eq( meetlib_embed_url( '' ), '', '27 empty' );

// 28. Saving the directory: empty is refused, the previous version is kept and can be found.
t_reset();
$before = get_option( 'meetlib_librarians' );
ok( is_wp_error( meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array() ) ) ) ), '28 empty roster refused' );
ok( is_wp_error( meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => '  ' ) ) ) ) ) ), '28 roster of unnamed rows refused' );
eq( get_option( 'meetlib_librarians' ), $before, '28 roster unchanged after refused saves' );
meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => 'New Person', 'booking' => 'https://x.example/' ) ) ) ) );
eq( count( get_option( 'meetlib_librarians' ) ), 1, '28 new roster saved' );
$bk = get_option( 'meetlib_librarians_backup' );
eq( $bk[0]['rows'], $before, '28 previous roster backed up' );

// 29. Time formatting.
eq( meetlib_gcal_when( array( 'dateTime' => '2026-11-13T16:00:00Z' ), 'America/New_York' ), 'Friday, November 13 at 11:00 am', '29 UTC time shown in the calendar zone' );
eq( meetlib_gcal_when( array( 'dateTime' => '2026-11-13T11:00:00-05:00' ) ), 'Friday, November 13 at 11:00 am', '29 offset time kept' );
eq( meetlib_gcal_when( array( 'date' => '2026-11-13' ) ), 'Friday, November 13', '29 all-day' );
eq( meetlib_gcal_when( array() ), '', '29 no start' );

// 30. Trashed requests are left alone.
t_reset();
$id = submit( 300 );
$GLOBALS['T']['posts'][ $id ]['status'] = 'trash';
t_event( CAL, 'book1', $now - 200, STU );
meetlib_gcal_retry( $id, true );
eq( t_desc( CAL, 'book1' ), '', '30 trashed request is not processed' );

// 31. Short Google links: looked up once, then embeddable; a failed lookup stays a plain link and is reported.
t_reset();
$short = 'https://calendar.app.google/short';
eq( meetlib_links_pending(), array( $short ), '31 the short link is waiting for a lookup' );
meetlib_resolve_links();
eq( meetlib_embed_url( $short ), '', '31 a failed lookup leaves the link non-embeddable' );
ok( (bool) preg_grep( '/short booking link/', meetlib_health() ), '31 failed lookup is reported' );
eq( meetlib_links_pending(), array(), '31 a failed lookup is not retried straight away' );
$l = get_option( 'meetlib_links' ); $l[ $short ]['t'] = time() - 2 * HOUR_IN_SECONDS; update_option( 'meetlib_links', $l );
$GLOBALS['T']['g']['redirects'][ $short ] = 'https://calendar.google.com/appointments/schedules/AcZ_x-9';
meetlib_resolve_links();
eq( meetlib_embed_url( $short ), 'https://calendar.google.com/calendar/appointments/schedules/AcZ_x-9?gv=true', '31 resolved short link is embeddable' );
eq( count( preg_grep( '/short booking link/', meetlib_health() ) ), 0, '31 warning clears once resolved' );
$rows = meetlib_public_roster();
eq( $rows[1]['booking'], $short, '31 the directory still holds the link the librarian pasted' );
has( $rows[1]['embed'], 'AcZ_x-9?gv=true', '31 public roster carries the embed address' );
$calls = count( $GLOBALS['T']['g']['calls'] );
meetlib_public_roster(); meetlib_resolve_links();
eq( count( $GLOBALS['T']['g']['calls'] ), $calls, '31 a resolved link is never looked up again' );

// 32. The lookup follows a second hop, refuses other hosts, and refuses non-schedule answers.
t_reset();
$GLOBALS['T']['g']['redirects'] = array(
	'https://calendar.app.google/hop'  => 'https://calendar.google.com/calendar/r/hop2',
	'https://calendar.google.com/calendar/r/hop2' => 'https://calendar.google.com/calendar/u/0/appointments/schedules/BBB?x=1',
	'https://calendar.app.google/evil' => 'https://evil.example/calendar/appointments/schedules/CCC',
	'https://calendar.app.google/home' => 'https://calendar.google.com/calendar/r',
);
eq( meetlib_resolve_short( 'https://calendar.app.google/hop' ), 'https://calendar.google.com/calendar/appointments/schedules/BBB', '32 second hop followed' );
eq( meetlib_resolve_short( 'https://calendar.app.google/evil' ), '', '32 another host is refused' );
eq( meetlib_resolve_short( 'https://calendar.app.google/home' ), '', '32 a non-schedule page is refused' );
eq( meetlib_resolve_short( 'https://evil.example/x' ), '', '32 only short Google links are ever requested' );
ok( ! in_array( 'HEAD https://evil.example/calendar/appointments/schedules/CCC', $GLOBALS['T']['g']['calls'], true ), '32 the other host was never contacted' );

// 33. Saving the directory looks up a newly pasted short link.
t_reset();
$GLOBALS['T']['g']['redirects']['https://calendar.app.google/new1'] = 'https://calendar.google.com/appointments/schedules/NEW1';
meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => 'New Person', 'booking' => 'https://calendar.app.google/new1' ) ) ) ) );
$rows = meetlib_public_roster();
has( $rows[0]['embed'], 'schedules/NEW1?gv=true', '33 embeddable right after saving' );
eq( array_keys( get_option( 'meetlib_links' ) ), array( 'https://calendar.app.google/new1' ), '33 links no longer in the directory are forgotten' );

// 34. Booked under another address: the email carries a link, and confirming it attaches the answers.
t_reset();
$id = submit( 600 );
t_event( CAL, 'gmailbook', $now - 500, array( 'student.personal@gmail.com' ), array( 'description' => 'Booked by Sam' ) );
for ( $i = 0; $i < 6; $i++ ) { meetlib_gcal_retry( $id, true ); }
$key = get_post_meta( $id, '_meetlib_attach_key', true );
ok( strlen( $key ) >= 32, '34 a key was made for the link' );
has( last_mail()['body'], 'wp-json/meet-a-librarian/v1/attach?r=' . $id . '&b=0&k=' . $key, '34 email carries the link' );
$r = meetlib_attach_handle( $id, 0, $key, false );
eq( $r['state'], 'ask', '34 opening the link only asks' );
eq( t_desc( CAL, 'gmailbook' ), 'Booked by Sam', '34 opening the link writes nothing' );
eq( st( $id ), 'not_found', '34 opening the link changes nothing' );
has( meetlib_attach_page( $r, 'https://example.edu/x' ), 'student.personal@gmail.com', '34 the page names the booking address' );
has( meetlib_attach_page( $r, 'https://example.edu/x' ), '<button type="submit">', '34 the page has the confirm button' );
foreach ( array( 'wrong key' => array( $id, 0, 'nope' ), 'empty key' => array( $id, 0, '' ), 'wrong booking' => array( $id, 5, $key ), 'wrong request' => array( $id + 50, 0, $key ) ) as $what => $a ) {
	$bad = meetlib_attach_handle( $a[0], $a[1], $a[2], true );
	eq( $bad['state'], 'invalid', "34 refused: $what" );
	lacks( meetlib_attach_page( $bad, '' ), STU, "34 ($what) page reveals nothing about the request" );
}
eq( t_desc( CAL, 'gmailbook' ), 'Booked by Sam', '34 refused attempts write nothing' );
$r = meetlib_attach_handle( $id, 0, $key, true );
eq( $r['state'], 'done', '34 confirming attaches' );
has( t_desc( CAL, 'gmailbook' ), 'Booked by Sam<br><br><b>Meet With a Librarian request</b>', '34 answers appended after the existing description' );
has( t_desc( CAL, 'gmailbook' ), 'Finding sources', '34 answers are on the booking' );
eq( st( $id ), 'added', '34 request is now booked' );
eq( get_post_meta( $id, '_meetlib_when', true ), 'Tuesday, November 17 at 2:00 pm', '34 appointment time recorded' );
eq( get_post_meta( $id, '_meetlib_confirmed_as', true ), 'student.personal@gmail.com', '34 records who it was booked as' );
eq( count( mails() ), 1, '34 no second email' );
$r = meetlib_attach_handle( $id, 0, $key, true );
eq( $r['state'], 'already', '34 a second click does nothing' );
eq( substr_count( t_desc( CAL, 'gmailbook' ), 'Meet With a Librarian request' ), 1, '34 answers are not added twice' );

// 35. Confirming is refused when the booking is gone, cancelled, already has answers, or lost its guest.
$cases = array(
	'deleted'   => function () { unset( $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook'] ); },
	'cancelled' => function () { $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook']['status'] = 'cancelled'; },
	'tagged'    => function () { $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook']['description'] = '<b>Meet With a Librarian request</b> other patron'; },
	'new guest' => function () { $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook']['attendees'][1]['email'] = 'someone.else@gmail.com'; },
	'outage'    => function () { $GLOBALS['T']['g']['fail'] = 503; },
	'conflict'  => function () { $GLOBALS['T']['g']['patch_fail'] = 412; },
);
foreach ( $cases as $what => $break ) {
	t_reset();
	$id = submit( 600 );
	t_event( CAL, 'gmailbook', $now - 500, array( 'student.personal@gmail.com' ) );
	for ( $i = 0; $i < 6; $i++ ) { meetlib_gcal_retry( $id, true ); }
	$break();
	$before = isset( $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook'] ) ? t_desc( CAL, 'gmailbook' ) : null;
	$r = meetlib_attach_handle( $id, 0, get_post_meta( $id, '_meetlib_attach_key', true ), true );
	eq( $r['state'], 'failed', "35 ($what) refused" );
	ok( '' !== $r['msg'], "35 ($what) says why" );
	eq( st( $id ), 'not_found', "35 ($what) request unchanged" );
	eq( isset( $GLOBALS['T']['g']['cals'][ CAL ]['events']['gmailbook'] ) ? t_desc( CAL, 'gmailbook' ) : null, $before, "35 ($what) booking unchanged" );
	eq( get_post_meta( $id, '_meetlib_lock', true ), '', "35 ($what) lock released" );
}

// 36. A request that was matched normally has no link, and one from before 2.6 (text hints only) still emails.
t_reset();
$id = submit( 600 );
t_event( CAL, 'book1', $now - 500, STU );
meetlib_gcal_retry( $id );
lacks( last_mail()['body'], '/attach?', '36 matched request has no attach link' );
eq( meetlib_attach_handle( $id, 0, '', true )['state'], 'invalid', '36 no key, no attaching' );
t_reset();
$id = submit( 600 );
update_post_meta( $id, '_meetlib_gcal', 'not_found' );
update_post_meta( $id, '_meetlib_hints', array( 'Tuesday at 2:00 pm (booked as old@gmail.com)' ) );
meetlib_notify( $id );
has( last_mail()['body'], 'booked as old@gmail.com', '36 pre-2.6 hints still listed' );
lacks( last_mail()['body'], '/attach?', '36 pre-2.6 hints have no link' );

// 37. The roster whitelist with junk input: only named rows survive, only the 8 fields, tags stripped.
eq( meetlib_sanitize( 'not a list' ), array(), '37 a string is no roster' );
eq( meetlib_sanitize( null ), array(), '37 null is no roster' );
eq( meetlib_sanitize( 5 ), array(), '37 a number is no roster' );
$rows = meetlib_sanitize( array(
	'k1' => array( 'name' => "  <b>Ann</b>\t  Lee ", 'email' => ' ann@wheatoncollege.edu ', 'role' => 'administrator', 'embed' => 'https://evil.example/' ),
	'junk', 7, null,
	array( 'name' => '<br>' ),
	array( 'title' => 'No name at all' ),
	array( 'name' => array( 'x' ) ),
	array( 'name' => 42 ),
) );
eq( count( $rows ), 2, '37 rows without a usable name are dropped' );
eq( array_keys( $rows ), array( 0, 1 ), '37 rows are renumbered' );
eq( array_keys( $rows[0] ), array( 'name', 'pronouns', 'title', 'liaison', 'email', 'photo', 'profile', 'booking' ), '37 exactly the 8 roster fields, unknown keys dropped' );
eq( $rows[0]['name'], 'Ann Lee', '37 tags stripped and spaces collapsed in the name' );
eq( $rows[0]['email'], 'ann@wheatoncollege.edu', '37 email trimmed' );
eq( $rows[0]['booking'], '', '37 missing fields are empty' );
eq( $rows[1]['name'], '42', '37 a numeric name is kept as text' );
t_reset();
$before = get_option( 'meetlib_librarians' );
foreach ( array( 'not a list' => array( 'librarians' => 'Ann' ), 'missing' => array( 'pageUrl' => 'https://x.example/' ) ) as $what => $body ) {
	$r = meetlib_save_roster( new WP_REST_Request( $body ) );
	eq( is_wp_error( $r ) ? $r->get_error_code() : '', 'meetlib_bad_data', "37 save refuses: $what" );
}
eq( get_option( 'meetlib_librarians' ), $before, '37 roster unchanged after junk saves' );
eq( get_option( 'meetlib_page_url' ), false, '37 page URL not saved by a refused save' );
meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => 'Ann' ) ), 'pageUrl' => '  https://lib.example/meet/  ' ) ) );
eq( get_option( 'meetlib_page_url' ), 'https://lib.example/meet/', '37 page URL saved trimmed' );

// 38. REST permissions: only saving the directory needs a login (edit_pages); the rest are public by design.
t_reset();
$GLOBALS['T_routes'] = array();
call_user_func( t_hook( 'rest_api_init' ) );
eq( array_keys( $GLOBALS['T_routes'] ), array( 'GET meet-a-librarian/v1/librarians', 'POST meet-a-librarian/v1/librarians', 'POST meet-a-librarian/v1/request', 'GET meet-a-librarian/v1/attach', 'POST meet-a-librarian/v1/attach' ), '38 exactly these routes' );
$perm = function ( $route ) { return call_user_func( $GLOBALS['T_routes'][ $route ]['permission_callback'] ); };
foreach ( array( 'none' => array(), 'subscriber' => array( 'read' ), 'author' => array( 'read', 'edit_posts', 'publish_posts' ) ) as $who => $caps ) {
	$GLOBALS['T_caps'] = $caps;
	eq( $perm( 'POST meet-a-librarian/v1/librarians' ), false, "38 $who cannot save the directory" );
	eq( $perm( 'GET meet-a-librarian/v1/librarians' ), true, "38 $who can read the directory" );
	eq( $perm( 'POST meet-a-librarian/v1/request' ), true, "38 $who can send the form" );
	eq( $perm( 'GET meet-a-librarian/v1/attach' ), true, "38 $who can open an email link (the key is the permission)" );
}
$GLOBALS['T_caps'] = array( 'read', 'edit_posts', 'edit_pages' );
eq( $perm( 'POST meet-a-librarian/v1/librarians' ), true, '38 an editor can save the directory' );
eq( $GLOBALS['T_routes']['POST meet-a-librarian/v1/librarians']['callback'], 'meetlib_save_roster', '38 the save route is the roster save' );
$GLOBALS['T_caps'] = array();

// 39. The public directory (GET /librarians) carries the 8 roster fields plus `embed`, and nothing else.
t_reset();
meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array(
	array( 'name' => 'Ann', 'email' => 'ann@wheatoncollege.edu', 'booking' => 'https://calendar.google.com/calendar/appointments/schedules/AAA', 'notes' => 'private note', 'key' => 'secret' ),
) ) ) );
$id = submit( 600, array( 'librarianSlug' => 'ann' ) );
meetlib_attach_key( $id );
meetlib_gcal_flag( CAL, 'flag message' );
$pub = call_user_func( $GLOBALS['T_routes']['GET meet-a-librarian/v1/librarians']['callback'] );
eq( $pub, meetlib_public_roster(), '39 the route serves meetlib_public_roster()' );
eq( array_keys( $pub[0] ), array( 'name', 'pronouns', 'title', 'liaison', 'email', 'photo', 'profile', 'booking', 'embed' ), '39 only the roster fields and embed' );
$json = json_encode( $pub );
foreach ( array( 'private note', 'secret', 'private_key', 'svc@proj', get_post_meta( $id, '_meetlib_attach_key', true ), STU, 'flag message' ) as $x ) {
	lacks( $json, $x, '39 public directory does not leak' );
}

// 40. A stale nonce from an old cached page is ignored for the form route only.
$err = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed.' );
$_SERVER['REQUEST_URI'] = '/wp-json/meet-a-librarian/v1/request';
eq( meetlib_rest_stale_nonce( $err ), true, '40 stale nonce on the form route becomes anonymous' );
ok( meetlib_rest_stale_nonce( new WP_Error( 'rest_forbidden', 'x' ) ) instanceof WP_Error, '40 other errors on the form route are kept' );
eq( meetlib_rest_stale_nonce( null ), null, '40 no error stays no error' );
foreach ( array( '/wp-json/meet-a-librarian/v1/librarians', '/wp-json/meet-a-librarian/v1/attach', '/wp-json/wp/v2/users' ) as $uri ) {
	$_SERVER['REQUEST_URI'] = $uri;
	ok( meetlib_rest_stale_nonce( $err ) === $err, "40 stale nonce still refused on $uri" );
}
$_SERVER['REQUEST_URI'] = '/index.php';
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/meet-a-librarian/v1/request' ) );
eq( meetlib_rest_stale_nonce( $err ), true, '40 also for ?rest_route= addresses' );
unset( $GLOBALS['wp'], $_SERVER['REQUEST_URI'] );

// 41. Admin actions refuse a user without the capability before doing anything.
t_reset();
$GLOBALS['T_caps'] = array( 'read', 'edit_posts', 'edit_pages' ); // an editor, not an administrator
$_POST = array( 'meetlib_gcal_remove' => '1' );
$_GET  = array( 'i' => '0' );
update_option( 'meetlib_librarians_backup', array( array( 't' => 1, 'by' => '', 'rows' => array( array( 'name' => 'Old' ) ), 'page' => '' ) ) );
$key_before = get_option( 'meetlib_gcal_key' );
foreach ( array( 'admin_post_meetlib_gcal_save', 'admin_post_meetlib_gcal_run', 'admin_post_meetlib_purge_files' ) as $hook ) {
	$died = '';
	try { call_user_func( t_hook( $hook ) ); } catch ( Exception $e ) { $died = $e->getMessage(); }
	has( $died, 'Not allowed', "41 an editor is refused: $hook" );
}
eq( get_option( 'meetlib_gcal_key' ), $key_before, '41 the Calendar key is not removed by an editor' );
$GLOBALS['T_caps'] = array( 'read' );
$roster_before = get_option( 'meetlib_librarians' );
$died = '';
try { call_user_func( t_hook( 'admin_post_meetlib_restore' ) ); } catch ( Exception $e ) { $died = $e->getMessage(); }
has( $died, 'Not allowed', '41 a subscriber cannot restore an earlier directory' );
eq( get_option( 'meetlib_librarians' ), $roster_before, '41 directory unchanged' );
$GLOBALS['T_caps'] = array(); $_POST = array(); $_GET = array();

// 42. Form validation at its edges.
t_reset();
ok( ! is_wp_error( meetlib_handle_request( form( array( 'email' => 'Student@WheatonCollege.EDU' ) ) ) ), '42 the email domain is not case-sensitive' );
ok( ! is_wp_error( meetlib_handle_request( form( array( 'email' => 'x@wheatonma.edu', 'due' => '' ) ) ) ), '42 the second domain is accepted and due is optional' );
foreach ( array( 'x@evil.wheatoncollege.edu.com', 'x@notwheatoncollege.edu', 'x@sub.wheatoncollege.edu', 'x@wheatoncollege.edu@gmail.com', 'wheatoncollege.edu' ) as $e ) {
	ok( is_wp_error( meetlib_handle_request( form( array( 'email' => $e ) ) ) ), "42 refuses $e" );
}
ok( is_wp_error( meetlib_handle_request( form( array( 'course' => '' ) ) ) ), '42 course is required' );
ok( is_wp_error( meetlib_handle_request( form( array( 'meeting' => 'on zoom' ) ) ) ), '42 meeting option must match exactly' );
t_reset();
ok( ! is_wp_error( meetlib_handle_request( form( array( 'course' => str_repeat( 'x', 500 ) ) ) ) ), '42 a 500-character answer is accepted (300 + slack)' );
eq( is_wp_error( $r = meetlib_handle_request( form( array( 'course' => str_repeat( 'y', 501 ) ) ) ) ) ? $r->get_error_code() : '', 'meetlib_long', '42 a 501-character answer is refused' );
ok( ! is_wp_error( meetlib_handle_request( form( array( 'help' => str_repeat( 'h', 5200 ) ) ) ) ), '42 a 5200-character long answer is accepted' );
ok( is_wp_error( meetlib_handle_request( form( array( 'help' => str_repeat( 'i', 5201 ) ) ) ) ), '42 a 5201-character long answer is refused' );
if ( function_exists( 'mb_strlen' ) ) {
	ok( ! is_wp_error( meetlib_handle_request( form( array( 'course' => str_repeat( 'é', 500 ) ) ) ) ), '42 length counts characters, not bytes' );
}
t_reset();
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$blocked = 0;
for ( $i = 0; $i < 31; $i++ ) {
	$r = meetlib_handle_request( form( array( 'email' => "s$i@wheatoncollege.edu" ) ) );
	if ( is_wp_error( $r ) ) { $blocked = $i + 1; break; }
}
eq( $blocked, 31, '42 the 31st request from one IP in 10 minutes is refused' );
eq( count( $GLOBALS['T']['posts'] ), 30, '42 the refused one is not stored' );
unset( $_SERVER['REMOTE_ADDR'] );

// 43. Google errors: a rate limit is temporary even as HTTP 403; a refused key or a 401 is a setup problem.
eq( meetlib_gcal_fail_kind( new WP_Error( 'meetlib_gcal_http', 'x', array( 'status' => 403, 'reason' => 'rateLimitExceeded' ) ) ), 'error', '43 403 rate limit is temporary' );
eq( meetlib_gcal_fail_kind( new WP_Error( 'meetlib_gcal_http', 'x', array( 'status' => 403, 'reason' => 'quotaExceeded' ) ) ), 'error', '43 403 quota is temporary' );
eq( meetlib_gcal_fail_kind( new WP_Error( 'meetlib_gcal_http', 'x', array( 'status' => 403, 'reason' => 'forbidden' ) ) ), 'no_access', '43 403 forbidden is a sharing problem' );
eq( meetlib_gcal_fail_kind( new WP_Error( 'meetlib_gcal_http', 'x', array( 'status' => 429, 'reason' => '' ) ) ), 'error', '43 429 is temporary' );
eq( meetlib_gcal_fail_kind( new WP_Error( 'http_request_failed', 'cURL error 28' ) ), 'error', '43 a network error is temporary' );
eq( meetlib_gcal_fail_kind( new WP_Error( 'meetlib_gcal_auth', 'x' ) ), 'no_access', '43 a rejected key is a setup problem' );
t_reset();
$id = submit( 300 );
$GLOBALS['T']['g']['fail'] = 403; $GLOBALS['T']['g']['fail_reason'] = 'rateLimitExceeded';
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '43 rate-limited check keeps waiting' );
ok( ! meetlib_gcal_flagged( CAL ), '43 rate limit does not flag the calendar' );
t_reset();
$id = submit( 300 );
$GLOBALS['T']['g']['fail'] = 401;
meetlib_gcal_retry( $id );
eq( st( $id ), 'no_access', '43 a revoked token ends the wait' );
eq( get_transient( 'meetlib_gcal_token_' . md5( 'svc@proj.iam.gserviceaccount.com' ) ), false, '43 a 401 drops the cached token' );
eq( count( mails() ), 1, '43 emailed after 401' );

// 44. Getting the token: a key Google rejects stops the wait; a sign-in outage keeps waiting.
t_reset();
delete_transient( 'meetlib_gcal_token_' . md5( 'svc@proj.iam.gserviceaccount.com' ) );
$GLOBALS['T']['g']['token'] = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) );
$id = submit( 300 );
t_event( CAL, 'book1', $now - 200, STU );
meetlib_gcal_retry( $id );
eq( st( $id ), 'no_access', '44 a rejected key ends the wait' );
eq( t_desc( CAL, 'book1' ), '', '44 nothing written with a rejected key' );
eq( count( mails() ), 1, '44 librarian emailed' );
$p = meetlib_gcal_problems();
has( isset( $p[ CAL ]['msg'] ) ? $p[ CAL ]['msg'] : '', 'Invalid JWT Signature.', '44 Google\'s reason is kept for the admin warning' );
t_reset();
delete_transient( 'meetlib_gcal_token_' . md5( 'svc@proj.iam.gserviceaccount.com' ) );
$GLOBALS['T']['g']['token'] = array( 503, array() );
$id = submit( 300 );
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '44 sign-in outage keeps waiting' );
ok( ! meetlib_gcal_flagged( CAL ), '44 sign-in outage does not flag' );
$GLOBALS['T']['g']['token'] = null;
t_event( CAL, 'book1', $now - 200, STU );
meetlib_gcal_retry( $id, true );
eq( st( $id ), 'added', '44 found once sign-in works' );
eq( get_transient( 'meetlib_gcal_token_' . md5( 'svc@proj.iam.gserviceaccount.com' ) ), 'tok', '44 the new token is cached' );

// 45. Email retries: the attachment rides along on the first two attempts only; after 5 failures it stops but stays visible.
function t_held_file() {
	$dir = sys_get_temp_dir() . '/meetlib-private/' . bin2hex( random_bytes( 8 ) );
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/brief.pdf', 'x' );
	return $dir . '/brief.pdf';
}
foreach ( array( 1 => true, 2 => false ) as $nfail => $attached ) { // not $fails: that is the global failure counter
	t_reset();
	update_option( 'meetlib_gcal_key', '' );
	$GLOBALS['T']['mail_ok'] = false;
	$id = submit( 0 );
	$file = t_held_file();
	update_post_meta( $id, '_meetlib_file', $file );
	update_post_meta( $id, '_meetlib_file_name', 'brief.pdf' );
	update_post_meta( $id, '_meetlib_mail_fails', $nfail );
	$GLOBALS['T']['mail_ok'] = true;
	ok( meetlib_notify( $id ), "45 sent after $nfail failure(s)" );
	eq( last_mail()['attach'], $attached ? array( $file ) : array(), "45 after $nfail failure(s) the file is " . ( $attached ? 'attached' : 'left off' ) );
	has( last_mail()['body'], $attached ? 'brief.pdf (attached to this email)' : 'brief.pdf (this file could not be emailed', "45 after $nfail failure(s) the email says so" );
	ok( ! is_file( $file ), "45 after $nfail failure(s) the file is deleted once sent" );
	eq( get_post_meta( $id, '_meetlib_file', true ), '', "45 after $nfail failure(s) the path is cleared" );
}
t_reset();
update_option( 'meetlib_gcal_key', '' );
$GLOBALS['T']['mail_ok'] = false;
$id = submit( 0 );
$file = t_held_file();
update_post_meta( $id, '_meetlib_file', $file );
update_post_meta( $id, '_meetlib_file_name', 'brief.pdf' );
for ( $i = 0; $i < 4; $i++ ) { meetlib_notify( $id ); }
eq( (int) get_post_meta( $id, '_meetlib_mail_fails', true ), 5, '45 five attempts counted' );
eq( get_post_meta( $id, '_meetlib_mail_next', true ), '', '45 no sixth attempt is scheduled' );
ok( ! is_file( $file ), '45 the held file is deleted after the last attempt' );
has( meetlib_status_short( $id ), 'EMAIL FAILED', '45 the list shows the failure' );
ok( (bool) preg_grep( '/could not be emailed/', meetlib_health() ), '45 the admin warning stays' );
$GLOBALS['T']['mail_ok'] = true;
meetlib_sweep( 3, true );
eq( count( mails() ), 0, '45 the sweep does not retry after the last attempt' );

// 46. Appointment times: event zone over calendar zone, bad zones and dates, summer time.
eq( meetlib_gcal_when( array( 'dateTime' => '2026-07-01T16:00:00Z' ), 'America/New_York' ), 'Wednesday, July 1 at 12:00 pm', '46 summer time in the calendar zone' );
eq( meetlib_gcal_when( array( 'dateTime' => '2026-11-13T16:00:00Z', 'timeZone' => 'America/Los_Angeles' ), 'America/New_York' ), 'Friday, November 13 at 8:00 am', '46 the event\'s own zone wins' );
eq( meetlib_gcal_when( array( 'dateTime' => '2026-11-13T16:00:00Z', 'timeZone' => 'Not/AZone' ), 'America/New_York' ), 'Friday, November 13 at 11:00 am', '46 an unknown event zone falls back to the calendar\'s' );
eq( meetlib_gcal_when( array( 'dateTime' => '2026-11-13T16:00:00Z' ), 'Not/AZone' ), 'Friday, November 13 at 4:00 pm', '46 an unknown calendar zone falls back to the offset' );
eq( meetlib_gcal_when( array( 'dateTime' => 'not a date' ), 'America/New_York' ), 'not a date', '46 an unreadable time is shown as sent' );
eq( meetlib_gcal_when( array( 'date' => 'garbage' ) ), 'garbage', '46 an unreadable all-day date is shown as sent' );
eq( meetlib_gcal_when( 'junk' ), '', '46 a non-array start is empty' );

// 47. Patron and Google text is escaped where it becomes HTML: the event description and the email-link page.
$h = meetlib_gcal_details_html( array( 'email' => STU, 'help' => "line one\n<img src=x onerror=alert(1)>", 'course' => 'A & B', 'due' => '  ', 'meeting' => 'On Zoom' ), 'f"<.pdf (sent to you by email)' );
lacks( $h, '<img', '47 description: tags in answers are escaped' );
has( $h, '&lt;img src=x onerror=alert(1)&gt;', '47 description: escaped text is kept' );
has( $h, 'line one<br>', '47 description: line breaks become <br>' );
has( $h, 'A &amp; B', '47 description: ampersand escaped' );
has( $h, '<b>Assignment due:</b> (none given)', '47 description: blank answer shown as none given' );
has( $h, 'f&quot;&lt;.pdf', '47 description: attachment name escaped' );
$page = meetlib_attach_page( array( 'state' => 'ask', 'msg' => '', 'email' => '<b>e</b>@x', 'help' => '<script>x</script>' . str_repeat( 'z', 400 ), 'when' => '<i>w</i>', 'as' => '"><svg onload=1>', 'link' => '' ), 'https://example.edu/a' );
foreach ( array( '<script>', '<svg', '<i>w', '<b>e' ) as $x ) { lacks( $page, $x, '47 attach page escapes ' . $x ); }
has( $page, '&quot;&gt;&lt;svg onload=1&gt;', '47 attach page shows the booking address as text' );
if ( function_exists( 'mb_strlen' ) ) { // the page only shortens when mbstring is loaded
	has( $page, str_repeat( 'z', 50 ) . '…', '47 attach page shortens a long question' );
	lacks( $page, str_repeat( 'z', 300 ), '47 attach page does not show all of it' );
}

// 48. Slugs (must match slug() in both JS blocks: ASCII only, runs of anything else become one hyphen).
eq( meetlib_slug( "  Dr. Jane O'Brien-Smith " ), 'dr-jane-o-brien-smith', '48 punctuation and spaces' );
eq( meetlib_slug( 'José Núñez' ), 'jos-n-ez', '48 non-ASCII letters are separators, as in JS' );
eq( meetlib_slug( '---' ), '', '48 nothing left' );
eq( meetlib_slug( null ), '', '48 null' );

// 49. Earlier versions: the last 5 replaced rosters are kept, newest first; saving the same roster adds none.
t_reset();
for ( $i = 1; $i <= 7; $i++ ) { meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => "P$i" ) ) ) ) ); }
$bk = get_option( 'meetlib_librarians_backup' );
eq( count( $bk ), 5, '49 five versions kept' );
eq( $bk[0]['rows'][0]['name'], 'P6', '49 newest first' );
eq( $bk[4]['rows'][0]['name'], 'P2', '49 oldest kept is five saves back' );
eq( $bk[0]['by'], 'tester', '49 records who saved' );
meetlib_save_roster( new WP_REST_Request( array( 'librarians' => array( array( 'name' => 'P7' ) ) ) ) );
eq( get_option( 'meetlib_librarians_backup' ), $bk, '49 saving an unchanged roster adds no version' );

echo $GLOBALS['fails'] ? "\n{$GLOBALS['fails']} FAILED, {$GLOBALS['passes']} passed\n" : "All {$GLOBALS['passes']} checks passed\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
