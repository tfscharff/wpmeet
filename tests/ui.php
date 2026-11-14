<?php
/**
 * Local preview of the patron booking page, for trying the form in a real browser without
 * WordPress or Google. Uses the same fakes as run.php; nothing leaves this computer.
 *
 *   php -S 127.0.0.1:8123 tests/ui.php      then open http://127.0.0.1:8123/
 *
 * The form posts to the real meetlib_handle_request(). The "calendar" is a local placeholder page.
 * Add ?fail=1 to the page address to make the server refuse submissions (to see the error state).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '0' );
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
function rest_url( $p = '' ) { return '/wp-json/' . $p; }
require dirname( __DIR__ ) . '/meet-a-librarian.php';

t_reset();
update_option( 'meetlib_librarians', array(
	array( 'name' => 'Cary Gouldin', 'pronouns' => 'she/her', 'title' => 'Humanities Librarian', 'liaison' => 'Liaison to Humanities', 'email' => 'cary@wheatoncollege.edu', 'photo' => '', 'profile' => '', 'booking' => 'https://calendar.google.com/calendar/appointments/schedules/AAA' ),
	array( 'name' => 'Jodi Devine', 'pronouns' => 'she/her', 'title' => 'Design Librarian', 'liaison' => '', 'email' => 'jodi@wheatoncollege.edu', 'photo' => '', 'profile' => '', 'booking' => 'https://calendar.app.google/short' ),
	array( 'name' => 'Jenny Castel', 'pronouns' => '', 'title' => 'Technology Librarian', 'liaison' => '', 'email' => '', 'photo' => '', 'profile' => '', 'booking' => '' ),
) );

$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/wp-json/meet-a-librarian/v1/librarians' === $path && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
	header( 'Content-Type: application/json' );
	$r = meetlib_save_roster( new WP_REST_Request( (array) json_decode( file_get_contents( 'php://input' ), true ) ) );
	if ( is_wp_error( $r ) ) { http_response_code( 400 ); echo json_encode( array( 'message' => $r->get_error_message() ) ); } else { echo json_encode( $r ); }
	return true;
}
if ( '/wp-json/meet-a-librarian/v1/librarians' === $path ) {
	if ( isset( $_GET['broken'] ) ) { http_response_code( 500 ); echo 'server error'; return true; }
	$rows = meetlib_public_roster();
	foreach ( $rows as &$r ) { if ( '' !== $r['embed'] ) { $r['embed'] = '/fake-calendar'; } }
	header( 'Content-Type: application/json' );
	echo json_encode( $rows );
	return true;
}
if ( '/wp-json/meet-a-librarian/v1/request' === $path ) {
	header( 'Content-Type: application/json' );
	if ( false !== strpos( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '', 'fail=1' ) ) {
		http_response_code( 500 );
		echo json_encode( array( 'code' => 'x', 'message' => 'Something went wrong. Please email the librarian directly.' ) );
		return true;
	}
	$r = meetlib_handle_request( new WP_REST_Request( $_POST, array() ) );
	if ( is_wp_error( $r ) ) {
		$d = $r->get_error_data();
		http_response_code( isset( $d['status'] ) ? $d['status'] : 500 );
		echo json_encode( array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
	} else {
		echo json_encode( $r );
	}
	return true;
}
if ( '/fake-calendar' === $path ) {
	echo '<!doctype html><meta charset="utf-8"><body style="font:16px sans-serif;padding:24px;background:#f3f6fb"><h2>Placeholder calendar</h2><p>Google\'s appointment schedule would appear here.</p><button>Tue 2:00 pm</button> <button>Wed 10:30 am</button></body>';
	return true;
}
if ( '/editor' === $path ) { // the wp-admin directory editor; ?loadfail=1 makes its list fail to load
	$root = '/wp-json/meet-a-librarian/v1/librarians' . ( isset( $_GET['loadfail'] ) ? '?broken=1' : '' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Editor (local preview)</title></head><body style="padding:24px;font-family:sans-serif">'
		. '<script>window.MEETLIB=' . json_encode( array( 'root' => $root, 'nonce' => 'x', 'pageUrl' => 'https://example.edu/meet/' ) ) . ';</script>'
		. meetlib_editor_markup()
		. ( isset( $_GET['autotest'] ) ? '<script src="/editor-autotest.js"></script>' : '' )
		. '</body></html>';
	return true;
}
if ( '/editor-autotest.js' === $path ) {
	header( 'Content-Type: application/javascript' );
	readfile( __DIR__ . '/editor-autotest.js' );
	return true;
}
if ( '/autotest.js' === $path ) {
	header( 'Content-Type: application/javascript' );
	readfile( __DIR__ . '/ui-autotest.js' );
	return true;
}
if ( '/' !== $path ) { http_response_code( 404 ); echo 'Not found'; return true; }

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Meet With a Librarian (local preview)</title></head>'
	. '<body style="margin:0;padding:24px;font-family:sans-serif"><h1>Meet With a Librarian</h1>'
	. meetlib_shortcode( array() )
	. ( isset( $_GET['autotest'] ) ? '<script src="/autotest.js"></script>' : '' )
	. '</body></html>';
return true;
