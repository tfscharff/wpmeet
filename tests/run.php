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
		'librarian' => '0', 'librarianSlug' => 'cary-gouldin', 'email' => 'student@wheatoncollege.edu',
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
const CAL = 'cary@wheatoncollege.edu';
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
t_event( CAL, 'staffmtg', $now - 9000, array( 'jenny@wheatoncollege.edu' ) );
t_event( CAL, 'oldmtg', $now - 9000, STU );
$GLOBALS['T']['g']['cals'][ CAL ]['events']['oldmtg']['updated'] = t_iso( $now - 100 ); // someone RSVPed just now
$id = submit( 600 );
meetlib_gcal_retry( $id );
eq( st( $id ), 'pending', '2 no match among older events' );
eq( t_desc( CAL, 'oldmtg' ), '', '2 older event with the patron as guest untouched' );
eq( t_desc( CAL, 'staffmtg' ), '', '2 staff meeting untouched' );

// 3. A librarian testing with their own address: emailed at once, calendar never searched.
t_reset();
t_event( CAL, 'curriculum', $now - 100, array( 'jenny@wheatoncollege.edu' ) );
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
$r = meetlib_handle_request( form( array( 'librarian' => '1', 'librarianSlug' => 'jodi-devine' ) ) );
ok( ! is_wp_error( $r ), '21 accepted' );
eq( last_mail()['to'], meetlib_urgent_email(), '21 sent to the shared inbox' );
has( last_mail()['body'], 'No email address is on file for Jodi Devine', '21 says why' );

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
$r = meetlib_handle_request( form( array( 'librarian' => '1', 'librarianSlug' => 'cary-gouldin' ) ) );
$id = $GLOBALS['T']['next_id'] - 1;
eq( get_post_meta( $id, '_meetlib_librarian', true ), 'Cary Gouldin', '23 routed by slug' );

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
eq( meetlib_embed_url( 'https://calendar.app.google/short' ), '', '27 short link is not embeddable' );
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

echo $GLOBALS['fails'] ? "\n{$GLOBALS['fails']} FAILED, {$GLOBALS['passes']} passed\n" : "All {$GLOBALS['passes']} checks passed\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
