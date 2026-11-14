<?php
/** In-memory stand-ins for WordPress and Google Calendar, shared by run.php and ui.php. */

/* ---------------- in-memory WordPress ---------------- */

$GLOBALS['T'] = array();
function t_reset() {
	$GLOBALS['T'] = array(
		'options' => array(), 'transients' => array(), 'posts' => array(), 'meta' => array(), 'next_id' => 100,
		'mails' => array(), 'mail_ok' => true, 'cron' => array(),
		'g' => array( 'skew' => 0, 'fail' => 0, 'patch_fail' => 0, 'cals' => array(), 'calls' => array() ),
	);
	unset( $GLOBALS['meetlib_gcal_skew'] );
	update_option( 'meetlib_gcal_key', json_encode( array( 'client_email' => 'svc@proj.iam.gserviceaccount.com', 'private_key' => 'x' ) ) );
	set_transient( 'meetlib_gcal_token_' . md5( 'svc@proj.iam.gserviceaccount.com' ), 'tok', 3600 );
	update_option( 'meetlib_librarians', array(
		array( 'name' => 'Cary Gouldin', 'pronouns' => '', 'title' => '', 'liaison' => '', 'email' => 'cary@wheatoncollege.edu', 'photo' => '', 'profile' => '', 'booking' => 'https://calendar.google.com/calendar/appointments/schedules/AAA' ),
		array( 'name' => 'Jodi Devine', 'pronouns' => '', 'title' => '', 'liaison' => '', 'email' => '', 'photo' => '', 'profile' => '', 'booking' => 'https://calendar.app.google/short' ),
	) );
	$GLOBALS['T']['g']['cals']['cary@wheatoncollege.edu'] = array( 'role' => 'writer', 'tz' => 'America/New_York', 'events' => array() );
}

class WP_Error {
	public $code; public $msg; public $data;
	function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
	function get_error_code() { return $this->code; }
	function get_error_message() { return $this->msg; }
	function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	public $p; public $f;
	function __construct( $p = array(), $f = array() ) { $this->p = $p; $this->f = $f; }
	function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; }
	function get_file_params() { return $this->f; }
	function get_json_params() { return $this->p; }
}
class WP_Query {
	public $found_posts = 0;
	function __construct( $args ) { $args['posts_per_page'] = -1; $this->found_posts = count( get_posts( $args ) ); }
}

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {}
function add_filter() {}
function remove_filter() {}
function add_shortcode() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['T']['options'] ) ? $GLOBALS['T']['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['T']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['T']['options'][ $k ] ); return true; }
function get_transient( $k ) { return isset( $GLOBALS['T']['transients'][ $k ] ) ? $GLOBALS['T']['transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['T']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['T']['transients'][ $k ] ); return true; }
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['T']['meta'][ $id ][ $k ] ) ? $GLOBALS['T']['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['T']['meta'][ $id ][ $k ] = $v; return true; }
function add_post_meta( $id, $k, $v, $unique = false ) { if ( $unique && isset( $GLOBALS['T']['meta'][ $id ][ $k ] ) ) { return false; } $GLOBALS['T']['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['T']['meta'][ $id ][ $k ] ); return true; }
function wp_insert_post( $a, $err = false ) { $id = $GLOBALS['T']['next_id']++; $GLOBALS['T']['posts'][ $id ] = array( 'type' => $a['post_type'], 'status' => $a['post_status'], 'time' => time(), 'title' => $a['post_title'] ); return $id; }
function get_post_type( $id ) { return isset( $GLOBALS['T']['posts'][ $id ] ) ? $GLOBALS['T']['posts'][ $id ]['type'] : false; }
function get_post_status( $id ) { return isset( $GLOBALS['T']['posts'][ $id ] ) ? $GLOBALS['T']['posts'][ $id ]['status'] : false; }
function get_post_time( $f, $gmt, $id ) { return $GLOBALS['T']['posts'][ $id ]['time']; }
function t_meta_match( $id, $q ) {
	if ( isset( $q['key'] ) ) {
		$has = isset( $GLOBALS['T']['meta'][ $id ][ $q['key'] ] );
		$cmp = isset( $q['compare'] ) ? $q['compare'] : '=';
		if ( 'EXISTS' === $cmp ) { return $has; }
		if ( 'NOT EXISTS' === $cmp ) { return ! $has; }
		if ( ! $has ) { return false; }
		$v = $GLOBALS['T']['meta'][ $id ][ $q['key'] ];
		$w = $q['value'];
		if ( isset( $q['type'] ) && 'NUMERIC' === $q['type'] ) { $v = (float) $v; $w = (float) $w; } else { $v = (string) $v; $w = (string) $w; }
		switch ( $cmp ) {
			case '=':  return $v === $w;
			case '!=': return $v !== $w;
			case '<=': return $v <= $w;
			case '>':  return $v > $w;
		}
		throw new Exception( 'unsupported compare ' . $cmp );
	}
	$or = isset( $q['relation'] ) && 'OR' === $q['relation'];
	$any = false; $all = true;
	foreach ( $q as $k => $sub ) {
		if ( 'relation' === $k ) { continue; }
		$r = t_meta_match( $id, $sub );
		$any = $any || $r; $all = $all && $r;
	}
	return $or ? $any : $all;
}
function get_posts( $a ) {
	$out = array();
	foreach ( $GLOBALS['T']['posts'] as $id => $p ) {
		if ( $p['type'] !== $a['post_type'] ) { continue; }
		if ( isset( $a['post_status'] ) && 'any' !== $a['post_status'] && $p['status'] !== $a['post_status'] ) { continue; }
		if ( ! empty( $a['post__not_in'] ) && in_array( $id, $a['post__not_in'], true ) ) { continue; }
		if ( ! empty( $a['meta_query'] ) && ! t_meta_match( $id, $a['meta_query'] ) ) { continue; }
		$out[] = $id;
	}
	$n = isset( $a['posts_per_page'] ) ? (int) $a['posts_per_page'] : 5;
	return $n > 0 ? array_slice( $out, 0, $n ) : $out;
}
function wp_mail( $to, $subject, $body, $headers = array(), $attach = array() ) {
	if ( ! $GLOBALS['T']['mail_ok'] ) { return false; }
	$GLOBALS['T']['mails'][] = compact( 'to', 'subject', 'body', 'headers', 'attach' );
	return true;
}
function wp_schedule_single_event( $at, $hook, $args ) { $GLOBALS['T']['cron'][] = array( $at, $hook, $args ); return true; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_email( $s ) { return trim( (string) $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]+$/i', (string) $s ); }
function wp_date( $f, $ts, $tz = null ) { $d = new DateTime( '@' . $ts ); $d->setTimezone( $tz ? $tz : new DateTimeZone( 'UTC' ) ); return $d->format( $f ); }
function human_time_diff( $a, $b ) { $m = (int) round( abs( $b - $a ) / 60 ); return $m >= 120 ? round( $m / 60 ) . ' hours' : $m . ' mins'; }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function rest_ensure_response( $x ) { return $x; }
function wp_max_upload_size() { return 64 * 1024 * 1024; }
function home_url( $p = '' ) { return 'https://example.edu' . $p; }
function wp_get_current_user() { return (object) array( 'user_login' => 'tester' ); }
function wp_set_current_user( $id ) {}

/* ---------------- fake Google Calendar ---------------- */

function t_iso( $ts ) { return gmdate( 'Y-m-d\TH:i:s', $ts ) . '.000Z'; }
/** Add an event. $created is in Google's clock. */
function t_event( $cal, $id, $created, $guests, $extra = array() ) {
	$att = array( array( 'email' => $cal, 'self' => true, 'organizer' => true ) );
	foreach ( (array) $guests as $g ) { $att[] = array( 'email' => $g ); }
	$GLOBALS['T']['g']['cals'][ $cal ]['events'][ $id ] = array_merge( array(
		'id' => $id, 'status' => 'confirmed', 'etag' => '"1"', 'created' => t_iso( $created ), 'updated' => t_iso( $created ),
		'htmlLink' => 'https://www.google.com/calendar/event?eid=' . $id, 'description' => '',
		'start' => array( 'dateTime' => '2026-11-17T19:00:00Z' ), 'attendees' => $att,
	), $extra );
}
function t_desc( $cal, $id ) { return $GLOBALS['T']['g']['cals'][ $cal ]['events'][ $id ]['description']; }
function t_reply( $code, $body ) {
	return array( 'code' => $code, 'body' => json_encode( $body ), 'headers' => array( 'date' => gmdate( 'D, d M Y H:i:s', time() - $GLOBALS['T']['g']['skew'] ) . ' GMT' ) );
}
function wp_remote_post( $url, $args ) { return t_reply( 200, array( 'access_token' => 'tok', 'expires_in' => 3600 ) ); }
function wp_remote_request( $url, $args ) {
	$g = &$GLOBALS['T']['g'];
	$g['calls'][] = $args['method'] . ' ' . $url;
	if ( $g['fail'] ) { return t_reply( $g['fail'], array( 'error' => array( 'errors' => array( array( 'reason' => 'backendError' ) ) ) ) ); }
	if ( ! preg_match( '#/calendars/([^/]+)/events(?:/([^?]+))?(?:\?(.*))?$#', $url, $m ) ) { return t_reply( 400, array() ); }
	$cal = rawurldecode( $m[1] );
	if ( ! isset( $g['cals'][ $cal ] ) ) { return t_reply( 404, array( 'error' => array( 'errors' => array( array( 'reason' => 'notFound' ) ) ) ) ); }
	$c = &$g['cals'][ $cal ];
	if ( 'GET' === $args['method'] ) {
		parse_str( isset( $m[3] ) ? $m[3] : '', $q );
		$min   = isset( $q['updatedMin'] ) ? strtotime( $q['updatedMin'] ) : 0;
		$items = array();
		foreach ( $c['events'] as $ev ) { if ( strtotime( $ev['updated'] ) >= $min ) { $items[] = $ev; } }
		return t_reply( 200, array( 'accessRole' => $c['role'], 'timeZone' => $c['tz'], 'items' => $items ) );
	}
	if ( 'PATCH' === $args['method'] ) {
		$id = rawurldecode( $m[2] );
		if ( 'writer' !== $c['role'] && 'owner' !== $c['role'] ) { return t_reply( 403, array( 'error' => array( 'errors' => array( array( 'reason' => 'forbidden' ) ) ) ) ); }
		if ( $g['patch_fail'] ) { return t_reply( $g['patch_fail'], array() ); }
		if ( isset( $args['headers']['If-Match'] ) && $args['headers']['If-Match'] !== $c['events'][ $id ]['etag'] ) { return t_reply( 412, array() ); }
		$b = json_decode( $args['body'], true );
		$c['events'][ $id ]['description'] = $b['description'];
		$c['events'][ $id ]['etag']        = '"' . ( (int) trim( $c['events'][ $id ]['etag'], '"' ) + 1 ) . '"';
		return t_reply( 200, $c['events'][ $id ] );
	}
	return t_reply( 400, array() );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }

