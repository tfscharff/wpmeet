<?php
/**
 * Plugin Name:       Meet With a Librarian
 * Description:        No-code "Meet With a Librarian" directory: staff manage librarians and their Google appointment booking links under Meet With a Librarian in wp-admin and click Save. Show the directory anywhere with the [meet_a_librarian] shortcode. Replaces LibCal appointments.
 * Version:           2.5.0
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MEETLIB_OPTION', 'meetlib_librarians' );
define( 'MEETLIB_PAGE_OPTION', 'meetlib_page_url' );
define( 'MEETLIB_CPT', 'meetlib_request' );
define( 'MEETLIB_DB_VERSION', 3 );
define( 'MEETLIB_BACKUP_OPTION', 'meetlib_librarians_backup' );
define( 'MEETLIB_GCAL_OPTION', 'meetlib_gcal_key' );
define( 'MEETLIB_GCAL_MARKER', 'Meet With a Librarian request' );

/**
 * Storage upgrades, run once each on init before the CPT is registered.
 * To 2 (from 1.x, which stored everything under a legacy key prefix): move the roster + page
 * options, the request CPT, and its post meta to the current names.
 * To 3 (plugin 2.5): nothing is rewritten; only the background-work flag is raised.
 */
add_action( 'init', 'meetlib_migrate', 5 );
function meetlib_migrate() {
	$have = (int) get_option( 'meetlib_db_version' );
	if ( $have >= MEETLIB_DB_VERSION ) { return; }
	// 3 (plugin 2.5): requests still waiting from 2.4 carry no `_meetlib_gcal_next`; raise the work flag so meetlib_sweep adopts them.
	update_option( 'meetlib_work', 1 );
	if ( $have >= 2 ) {
		update_option( 'meetlib_db_version', MEETLIB_DB_VERSION );
		return;
	}
	global $wpdb;
	$legacy = 'mcw';
	foreach ( array( $legacy . '_librarians' => MEETLIB_OPTION, $legacy . '_lib_page_url' => MEETLIB_PAGE_OPTION ) as $old => $new ) {
		$v = get_option( $old, null );
		if ( null !== $v && false === get_option( $new, false ) ) { update_option( $new, $v ); }
		delete_option( $old );
	}
	$wpdb->update( $wpdb->posts, array( 'post_type' => MEETLIB_CPT ), array( 'post_type' => $legacy . '_request' ) );
	foreach ( array( 'first', 'last', 'email', 'help', 'course', 'due', 'meeting', 'librarian', 'attach_url' ) as $k ) {
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 SET pm.meta_key = %s WHERE pm.meta_key = %s AND p.post_type = %s",
			'_meetlib_' . $k, '_' . $legacy . '_' . $k, MEETLIB_CPT
		) );
	}
	wp_cache_flush();
	update_option( 'meetlib_db_version', MEETLIB_DB_VERSION );
}

/** URL of the page that hosts [meet_a_librarian]; base for the per-librarian widget deep links. */
function meetlib_page_url() {
	$u = get_option( MEETLIB_PAGE_OPTION );
	$u = is_string( $u ) ? trim( $u ) : '';
	return '' !== $u ? $u : home_url( '/meet-with-a-librarian/' );
}

/** Slug for a librarian name, matched byte-for-byte in PHP and JS (ASCII lowercase + hyphens). */
function meetlib_slug( $name ) {
	$s = strtolower( (string) $name );
	$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
	return trim( $s, '-' );
}

/* ------------------------------------------------------------------ *
 *  Booking-form config  (single source of truth for JS + PHP)
 * ------------------------------------------------------------------ */

/** The patron questions — defined once, identical for every librarian. */
function meetlib_form_fields() {
	return array(
		// Name is not asked: Google's booking form already collects it. Email is kept to match the booking.
		array( 'key' => 'email',   'label' => 'Your email',                                    'type' => 'email',    'required' => true,  'help' => 'Your wheatoncollege.edu or wheatonma.edu address. Use the same one when you book your time.' ),
		array( 'key' => 'help',    'label' => 'How can I help you?',                           'type' => 'textarea', 'required' => true,  'help' => '' ),
		array( 'key' => 'course',  'label' => 'Is this related to a course? If so, which one?', 'type' => 'text',     'required' => true,  'help' => '' ),
		array( 'key' => 'due',     'label' => 'If related to a course, when is the assignment due?', 'type' => 'text', 'required' => false, 'help' => '' ),
		array( 'key' => 'meeting', 'label' => 'Would you like to meet in person or on Zoom?',  'type' => 'radio',    'required' => true,  'help' => '',
			'options' => array( 'In person in my office', 'In person in a fully accessible space', 'On Zoom' ) ),
		array( 'key' => 'attachment', 'label' => 'Attachment (optional — e.g. assignment instructions)', 'type' => 'file', 'required' => false, 'help' => 'PDF, Word, text, or image. Max ' . meetlib_max_upload_label() . '. It is emailed to the librarian and not kept on this website.' ),
	);
}

/** Longest answer accepted for a field type, in characters (enforced in the browser and on the server). */
function meetlib_max_chars( $type ) { return 'textarea' === $type ? 5000 : 300; }

/** A field definition from meetlib_form_fields() by key, or null. */
function meetlib_field( $key ) {
	foreach ( meetlib_form_fields() as $f ) {
		if ( $f['key'] === $key ) { return $f; }
	}
	return null;
}

function meetlib_email_domains() { return array( 'wheatoncollege.edu', 'wheatonma.edu' ); }

/** Shown under the calendar: where to go when no slot is soon enough. */
function meetlib_urgent_email() { return 'librarians_research@wheatoncollege.edu'; }

/** Allowed attachment types (wp_handle_upload format). The extension list is derived from this. */
function meetlib_upload_mimes() {
	return array(
		'pdf'      => 'application/pdf',
		'doc'      => 'application/msword',
		'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'txt'      => 'text/plain',
		'rtf'      => 'application/rtf',
		'png'      => 'image/png',
		'jpg|jpeg' => 'image/jpeg',
		'gif'      => 'image/gif',
	);
}
function meetlib_upload_exts() {
	$out = array();
	foreach ( array_keys( meetlib_upload_mimes() ) as $k ) { $out = array_merge( $out, explode( '|', $k ) ); }
	return $out;
}
/**
 * Largest attachment accepted: 10 MB (it travels as an email attachment), or less if this server's
 * PHP upload limit is lower. Above that limit PHP discards the whole submission before the plugin sees it.
 */
function meetlib_max_upload_bytes() {
	$cap = 10 * 1024 * 1024;
	$srv = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
	return $srv > 0 ? min( $cap, $srv ) : $cap;
}
function meetlib_max_upload_label() {
	$mb = meetlib_max_upload_bytes() / ( 1024 * 1024 );
	return ( $mb >= 1 ? (string) floor( $mb ) : (string) round( $mb, 1 ) ) . ' MB';
}

/**
 * Normalize a Google appointment-schedule link to its inline embed URL, or '' if not embeddable.
 * Only calendar.google.com/calendar/appointments/... pages support the ?gv=true inline embed
 * (not the short calendar.app.google links). A /u/0/ account segment copied from the librarian's
 * own address bar is dropped: it means "my first signed-in account" and is wrong for patrons.
 */
function meetlib_embed_url( $booking ) {
	$booking = trim( (string) $booking );
	if ( '' === $booking ) { return ''; }
	$booking = preg_replace( '#(calendar\.google\.com/calendar)/u/\d+/(appointments)#i', '$1/$2', $booking );
	if ( false === strpos( $booking, 'calendar.google.com/calendar/appointments' ) ) { return ''; }
	if ( false !== strpos( $booking, 'gv=true' ) ) { return $booking; }
	$hash = '';
	$at   = strpos( $booking, '#' );
	if ( false !== $at ) { $hash = substr( $booking, $at ); $booking = substr( $booking, 0, $at ); }
	return $booking . ( ( false === strpos( $booking, '?' ) ) ? '?gv=true' : '&gv=true' ) . $hash;
}

/* ------------------------------------------------------------------ *
 *  Data
 * ------------------------------------------------------------------ */

function meetlib_default() {
	return array(
		array(
			'name'     => 'Jenny Castel',
			'pronouns' => 'she/her',
			'title'    => 'Instruction & Technology Librarian',
			'liaison'  => 'Liaison to Social Sciences Division',
			'email'    => 'castel_jenny@wheatoncollege.edu',
			'photo'    => 'https://libapps.s3.amazonaws.com/customers/7342/images/Jenny2024.png',
			'profile'  => 'https://researchguides.wheatoncollege.edu/prf.php?id=6ff91da0-5c6c-11ee-8db8-127d3674933b',
			'booking'  => '',
		),
		array(
			'name'     => 'Jodi Devine',
			'pronouns' => 'she/her',
			'title'    => 'Instruction & Design Librarian',
			'liaison'  => 'Liaison to Sciences and Mathematics Division',
			'email'    => 'devine_jodi@wheatoncollege.edu',
			'photo'    => 'https://d2jv02qf7xgjwx.cloudfront.net/customers/7342/images/Jodi.png',
			'profile'  => 'https://researchguides.wheatoncollege.edu/prf.php?account_id=424182',
			'booking'  => '',
		),
		array(
			'name'     => 'Cary Gouldin',
			'pronouns' => 'she/her',
			'title'    => 'Humanities & Student Success Librarian',
			'liaison'  => 'Liaison to Humanities and Creative Arts Division',
			'email'    => 'gouldin_cary@wheatoncollege.edu',
			'photo'    => 'https://libapps.s3.amazonaws.com/customers/7342/images/Cary2024.png',
			'profile'  => 'https://researchguides.wheatoncollege.edu/prf.php?account_id=205782',
			'booking'  => '',
		),
	);
}

function meetlib_get() {
	$v = get_option( MEETLIB_OPTION );
	if ( empty( $v ) || ! is_array( $v ) ) { $v = meetlib_default(); }
	return array_values( $v );
}

/** Roster as served by GET /librarians: stored fields plus the computed scheduler `embed` URL. */
function meetlib_public_roster() {
	$out = array();
	foreach ( meetlib_get() as $lib ) {
		$lib['embed'] = meetlib_embed_url( isset( $lib['booking'] ) ? $lib['booking'] : '' );
		$out[]        = $lib;
	}
	return $out;
}

/** Whitelist + validate incoming rows so only clean data is stored. */
function meetlib_sanitize( $in ) {
	$out = array();
	if ( ! is_array( $in ) ) { return $out; }
	foreach ( $in as $row ) {
		if ( ! is_array( $row ) ) { continue; }
		$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
		if ( '' === $name ) { continue; } // a librarian must at least have a name
		$out[] = array(
			'name'     => $name,
			'pronouns' => isset( $row['pronouns'] ) ? sanitize_text_field( $row['pronouns'] ) : '',
			'title'    => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
			'liaison'  => isset( $row['liaison'] ) ? sanitize_text_field( $row['liaison'] ) : '',
			'email'    => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
			'photo'    => isset( $row['photo'] ) ? esc_url_raw( trim( $row['photo'] ) ) : '',
			'profile'  => isset( $row['profile'] ) ? esc_url_raw( trim( $row['profile'] ) ) : '',
			'booking'  => isset( $row['booking'] ) ? esc_url_raw( trim( $row['booking'] ) ) : '',
		);
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 *  REST API   (GET = public read for the widget, POST = save)
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'meet-a-librarian/v1', '/librarians', array(
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( meetlib_public_roster() );
			},
			'permission_callback' => '__return_true',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'meetlib_save_roster',
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
	) );

	register_rest_route( 'meet-a-librarian/v1', '/request', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // patrons are not logged in; hardened in the handler
		'callback'            => 'meetlib_handle_request',
	) );
} );

/**
 * Save the roster from the editor. An empty roster is refused: the editor sends one when its list
 * failed to load, and saving it would replace every booking link with the blank defaults. The
 * previous roster is kept in MEETLIB_BACKUP_OPTION (last 5) and can be restored from the editor page.
 */
function meetlib_save_roster( WP_REST_Request $req ) {
	$body = $req->get_json_params();
	if ( ! is_array( $body ) || ! isset( $body['librarians'] ) || ! is_array( $body['librarians'] ) ) {
		return new WP_Error( 'meetlib_bad_data', 'Invalid librarian data.', array( 'status' => 400 ) );
	}
	$rows = meetlib_sanitize( $body['librarians'] );
	if ( empty( $rows ) ) {
		return new WP_Error( 'meetlib_empty', 'Nothing was saved: add at least one librarian with a name.', array( 'status' => 400 ) );
	}
	meetlib_backup_roster( $rows );
	update_option( MEETLIB_OPTION, $rows );
	if ( isset( $body['pageUrl'] ) ) {
		update_option( MEETLIB_PAGE_OPTION, esc_url_raw( trim( (string) $body['pageUrl'] ) ) );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

/** Keep the roster as it is now (before $new replaces it) so a bad save can be undone. */
function meetlib_backup_roster( $new ) {
	$cur = get_option( MEETLIB_OPTION );
	if ( empty( $cur ) || ! is_array( $cur ) ) { return; }
	$cur = array_values( $cur );
	if ( $cur === $new ) { return; }
	$all = get_option( MEETLIB_BACKUP_OPTION );
	$all = is_array( $all ) ? $all : array();
	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	array_unshift( $all, array(
		't'    => time(),
		'by'   => ( $user && ! empty( $user->user_login ) ) ? (string) $user->user_login : '',
		'rows' => $cur,
		'page' => (string) get_option( MEETLIB_PAGE_OPTION ),
	) );
	update_option( MEETLIB_BACKUP_OPTION, array_slice( $all, 0, 5 ), false );
}

/**
 * Scripts before 2.5 sent a `wp_rest` nonce as `_wpnonce`. WordPress core rejects a stale one with
 * "Cookie check failed" before the handler runs, so a booking page served from a page cache for more
 * than a day could not submit at all. The form is public and anonymous, so for this one route a stale
 * nonce is simply treated as "not logged in".
 */
add_filter( 'rest_authentication_errors', 'meetlib_rest_stale_nonce', 200 );
function meetlib_rest_stale_nonce( $result ) {
	if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) { return $result; }
	$route = ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) $_SERVER['REQUEST_URI'] ) : '';
	if ( false === strpos( $route . ' ' . $uri, 'meet-a-librarian/v1/request' ) ) { return $result; }
	wp_set_current_user( 0 );
	return true;
}

/* ------------------------------------------------------------------ *
 *  Booking-form submission handler
 * ------------------------------------------------------------------ */

/** Count one hit in $bucket; false once more than $max arrive within $window seconds. */
function meetlib_rate_ok( $bucket, $max, $window ) {
	$key = 'meetlib_rl_' . md5( $bucket );
	$n   = (int) get_transient( $key );
	if ( $n >= $max ) { return false; }
	set_transient( $key, $n + 1, $window );
	return true;
}

/** The JSON a successful submission returns. embedUrl/booking are for pages still running the 2.0 script from a cache. */
function meetlib_request_response( $lib, $status ) {
	return rest_ensure_response( array(
		'ok'       => true,
		'calendar' => $status,
		'embedUrl' => meetlib_embed_url( $lib['booking'] ),
		'booking'  => esc_url_raw( $lib['booking'] ),
	) );
}

function meetlib_handle_request( WP_REST_Request $req ) {
	// 1. Anti-bot: honeypot (`website` is the pre-2.5 field name, still sent by cached pages).
	foreach ( array( 'meetlib_hp', 'website' ) as $hp ) {
		if ( '' !== trim( (string) $req->get_param( $hp ) ) ) {
			return new WP_Error( 'meetlib_bot', 'Submission blocked.', array( 'status' => 400 ) );
		}
	}

	// When an upload exceeds PHP's post_max_size, PHP discards every field, not just the file.
	// Say so, instead of the confusing "librarian not available" that the empty request would produce.
	if ( null === $req->get_param( 'email' ) && null === $req->get_param( 'librarian' )
		&& isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 1024 * 1024 ) {
		return new WP_Error( 'meetlib_size', 'That attachment is too large for this website. Please try again with a smaller file, or without it.', array( 'status' => 413 ) );
	}

	// 2. Resolve the target librarian by roster index, cross-checked against the name slug so a
	//    roster edited while the patron had the page open can't route the request to someone else.
	$libs = meetlib_get();
	$raw  = $req->get_param( 'librarian' );
	$idx  = is_numeric( $raw ) ? (int) $raw : -1;
	$want = meetlib_slug( (string) $req->get_param( 'librarianSlug' ) );
	if ( '' !== $want && ( ! isset( $libs[ $idx ] ) || meetlib_slug( $libs[ $idx ]['name'] ) !== $want ) ) {
		$idx = -1;
		foreach ( $libs as $j => $l ) {
			if ( meetlib_slug( $l['name'] ) === $want ) { $idx = $j; break; }
		}
	}
	if ( ! isset( $libs[ $idx ] ) || empty( $libs[ $idx ]['booking'] ) ) {
		return new WP_Error( 'meetlib_unavailable', 'That librarian is not available for booking. Please reload the page and try again.', array( 'status' => 400 ) );
	}
	$lib = $libs[ $idx ];

	// 3. Collect + sanitize text answers.
	$data = array(
		'email'   => sanitize_email( (string) $req->get_param( 'email' ) ),
		'help'    => sanitize_textarea_field( (string) $req->get_param( 'help' ) ),
		'course'  => sanitize_text_field( (string) $req->get_param( 'course' ) ),
		'due'     => sanitize_text_field( (string) $req->get_param( 'due' ) ),
		'meeting' => sanitize_text_field( (string) $req->get_param( 'meeting' ) ),
	);

	// 4. Server-side validation (mirror the client).
	foreach ( meetlib_form_fields() as $f ) {
		if ( 'file' === $f['type'] ) { continue; }
		if ( $f['required'] && '' === trim( $data[ $f['key'] ] ) ) {
			return new WP_Error( 'meetlib_required', 'Please fill in every required field.', array( 'status' => 400 ) );
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $data[ $f['key'] ], 'UTF-8' ) : strlen( $data[ $f['key'] ] );
		if ( $len > meetlib_max_chars( $f['type'] ) + 200 ) { // slack: browsers and PHP count line breaks and some characters differently
			return new WP_Error( 'meetlib_long', 'One of your answers is too long. Please shorten it and try again.', array( 'status' => 400 ) );
		}
	}
	if ( ! is_email( $data['email'] ) ) {
		return new WP_Error( 'meetlib_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
	}
	$domain = strtolower( substr( strrchr( $data['email'], '@' ), 1 ) );
	if ( ! in_array( $domain, meetlib_email_domains(), true ) ) {
		return new WP_Error( 'meetlib_domain', 'Please use your wheatoncollege.edu or wheatonma.edu email address.', array( 'status' => 400 ) );
	}
	$meeting_field = meetlib_field( 'meeting' );
	if ( ! $meeting_field || ! in_array( $data['meeting'], $meeting_field['options'], true ) ) {
		return new WP_Error( 'meetlib_meeting', 'Please choose a meeting option.', array( 'status' => 400 ) );
	}

	// 5. The same answers sent twice within 10 minutes (double click, retry after a slow reply) are one request.
	$files    = $req->get_file_params();
	$has_file = ! empty( $files['attachment'] ) && ! empty( $files['attachment']['name'] ) && UPLOAD_ERR_NO_FILE !== (int) $files['attachment']['error'];
	$dupe_key = 'meetlib_dupe_' . md5( wp_json_encode( array( $data, meetlib_slug( $lib['name'] ), $has_file ? array( (string) $files['attachment']['name'], (int) $files['attachment']['size'] ) : null ) ) );
	$dupe     = (int) get_transient( $dupe_key );
	if ( $dupe && MEETLIB_CPT === get_post_type( $dupe ) ) {
		return meetlib_request_response( $lib, (string) get_post_meta( $dupe, '_meetlib_gcal', true ) );
	}

	// 6. Rate limits. Generous, because a campus can share one address; they only stop floods.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	if ( ! meetlib_rate_ok( 'ip|' . $ip, 30, 10 * MINUTE_IN_SECONDS ) || ! meetlib_rate_ok( 'em|' . strtolower( $data['email'] ), 8, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'meetlib_rate', 'Too many requests in a short time. Please wait a few minutes and try again, or email ' . meetlib_urgent_email() . '.', array( 'status' => 429 ) );
	}

	// 7. Optional attachment. It is held in a private, unguessable folder only until it has been
	//    emailed to the librarian (meetlib_notify), then deleted. Nothing is kept on the site.
	$held = array( 'file' => '', 'name' => '' );
	if ( $has_file ) {
		$file = $files['attachment'];
		if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] || (int) $file['size'] > meetlib_max_upload_bytes() ) {
			return new WP_Error( 'meetlib_size', 'That file is larger than ' . meetlib_max_upload_label() . '. Please attach a smaller file.', array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, meetlib_upload_exts(), true ) ) {
			return new WP_Error( 'meetlib_type', 'That file type is not allowed. Use PDF, Word, text, or an image.', array( 'status' => 400 ) );
		}
		$held = meetlib_hold_upload( $file );
		if ( '' === $held['file'] ) {
			return new WP_Error( 'meetlib_upload', 'The attachment could not be saved. Please try again without it.', array( 'status' => 400 ) );
		}
	}

	// 8. Persist the record.
	$now     = time();
	$post_id = wp_insert_post( array(
		'post_type'   => MEETLIB_CPT,
		'post_status' => 'publish',
		'post_title'  => $data['email'] . ' — ' . $lib['name'],
	), true );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		meetlib_remove_held_file( $held['file'] );
		return new WP_Error( 'meetlib_save', 'Something went wrong. Please email the librarian directly.', array( 'status' => 500 ) );
	}
	$calendar = meetlib_librarian_email( $lib );
	$status   = meetlib_initial_status( $calendar, $data['email'] );
	$meta     = array(
		'_meetlib_email'      => $data['email'],
		'_meetlib_help'       => $data['help'],
		'_meetlib_course'     => $data['course'],
		'_meetlib_due'        => $data['due'],
		'_meetlib_meeting'    => $data['meeting'],
		'_meetlib_librarian'  => $lib['name'],
		'_meetlib_calendar'   => $calendar,
		'_meetlib_sent'       => $now,
		'_meetlib_file'       => $held['file'],
		'_meetlib_file_name'  => $held['name'],
		'_meetlib_when'       => '',
		'_meetlib_gcal_tries' => 0,
		'_meetlib_gcal'       => $status,
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }
	set_transient( $dupe_key, $post_id, 10 * MINUTE_IN_SECONDS );

	// 9. With the Calendar integration watching, the librarian's email is held until the booking
	//    appears (meetlib_gcal_retry sends it), so nobody is emailed about a request that never
	//    became an appointment. Google is not contacted here: the patron cannot have booked yet,
	//    and the form must not depend on Google being reachable. Otherwise the email goes now.
	if ( 'pending' === $status ) {
		$delays = meetlib_gcal_retry_delays();
		meetlib_gcal_schedule( $post_id, $now + $delays[0] );
	} else {
		meetlib_notify( $post_id );
	}

	return meetlib_request_response( $lib, $status );
}

/** What happens to a new request: `pending` = watch the calendar and hold the email; anything else = email now. */
function meetlib_initial_status( $calendar, $patron_email ) {
	if ( ! meetlib_gcal_enabled() ) { return 'off'; }
	if ( '' === (string) $calendar ) { return 'no_calendar'; }
	// A librarian trying the form with their own address: their address is on every event they own, so it can never identify a booking.
	if ( 0 === strcasecmp( (string) $calendar, (string) $patron_email ) ) { return 'self'; }
	if ( meetlib_gcal_flagged( $calendar ) ) { return 'no_access'; }
	return 'pending';
}

/**
 * Move an uploaded attachment into uploads/meetlib-private/<random>/ and return its path and name.
 * The random folder makes the address unguessable for the short time the file exists.
 */
function meetlib_hold_upload( $file ) {
	$out = array( 'file' => '', 'name' => '' );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$sub    = '/meetlib-private/' . bin2hex( random_bytes( 16 ) );
	$filter = function ( $dirs ) use ( $sub ) {
		$dirs['subdir'] = $sub;
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		return $dirs;
	};
	add_filter( 'upload_dir', $filter );
	$moved = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => meetlib_upload_mimes() ) );
	remove_filter( 'upload_dir', $filter );
	if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) { return $out; }
	$root = dirname( dirname( $moved['file'] ) );
	if ( ! file_exists( $root . '/.htaccess' ) ) { @file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" ); } // phpcs:ignore
	if ( ! file_exists( $root . '/index.html' ) ) { @file_put_contents( $root . '/index.html', '' ); } // phpcs:ignore
	$out['file'] = $moved['file'];
	$out['name'] = basename( $moved['file'] );
	return $out;
}

/** Delete a held attachment and its random folder. Refuses any path outside meetlib-private. */
function meetlib_remove_held_file( $path ) {
	$path = (string) $path;
	if ( '' === $path || false === strpos( str_replace( '\\', '/', $path ), '/meetlib-private/' ) ) { return; }
	if ( is_file( $path ) ) { @unlink( $path ); } // phpcs:ignore
	$dir = dirname( $path );
	if ( 'meetlib-private' !== basename( $dir ) && is_dir( $dir ) ) { @rmdir( $dir ); } // phpcs:ignore
}

/** Drop a request's held attachment (after it has been emailed, or when the request is deleted). */
function meetlib_delete_file( $post_id ) {
	$path = (string) get_post_meta( $post_id, '_meetlib_file', true );
	if ( '' === $path ) { return; }
	meetlib_remove_held_file( $path );
	update_post_meta( $post_id, '_meetlib_file', '' );
}
add_action( 'before_delete_post', function ( $post_id ) {
	if ( MEETLIB_CPT === get_post_type( $post_id ) ) { meetlib_delete_file( $post_id ); }
} );

/**
 * Email the librarian the request, built entirely from the stored record so it can be retried.
 * Sent once (`_meetlib_notified`). The wording depends on `_meetlib_gcal`: `added` = booking found
 * (includes the time); `not_found` = watched for a day, nothing matched; `no_access`/`error` = the
 * calendar could not be checked; anything else = no integration, so a booking is expected.
 * The patron's attachment rides along as a real email attachment and is deleted once sent.
 * A failed send is retried by meetlib_sweep (5 attempts; the last 3 without the attachment, in case
 * its size is what the mail server refuses). With no librarian email on file it goes to the shared inbox.
 * Returns true when the email has been sent.
 */
function meetlib_notify( $post_id ) {
	$post_id = (int) $post_id;
	if ( MEETLIB_CPT !== get_post_type( $post_id ) ) { return false; }
	if ( get_post_meta( $post_id, '_meetlib_notified', true ) ) { return true; }
	$m = array();
	foreach ( array( 'email', 'help', 'course', 'due', 'meeting', 'librarian', 'calendar', 'gcal', 'when', 'event_link', 'file', 'file_name', 'attach_url' ) as $k ) {
		$m[ $k ] = (string) get_post_meta( $post_id, '_meetlib_' . $k, true );
	}
	$sent = (int) get_post_meta( $post_id, '_meetlib_sent', true );
	if ( ! $sent ) { $sent = (int) get_post_time( 'U', true, $post_id ); }
	$ago   = ( time() - $sent > 2 * MINUTE_IN_SECONDS ) ? human_time_diff( $sent, time() ) . ' ago' : '';
	$fails = (int) get_post_meta( $post_id, '_meetlib_mail_fails', true );

	$to    = $m['calendar'];
	$intro = array();
	if ( ! is_email( $to ) ) {
		$to      = meetlib_urgent_email();
		$intro[] = 'No email address is on file for ' . $m['librarian'] . ' in the Meet With a Librarian directory, so this request came to the shared inbox. Please pass it on.';
		$intro[] = '';
	}

	if ( 'added' === $m['gcal'] && '' !== $m['when'] ) {
		$subject = 'Appointment booked: ' . $m['when'] . ' — ' . $m['email'];
		$intro[] = 'Booked for: ' . $m['when'];
		$intro[] = 'Patron: ' . $m['email'] . ' (their name is on the Google booking)';
		$outro   = array( 'These details are also in the appointment\'s description on your Google Calendar.' );
		if ( '' !== $m['event_link'] ) { $outro[] = 'Open the appointment: ' . $m['event_link']; }
	} elseif ( 'not_found' === $m['gcal'] ) {
		$subject = 'Appointment request, no booking found — ' . $m['email'];
		$intro[] = 'Patron: ' . $m['email'];
		$outro   = array(
			'This patron filled in the request form' . ( $ago ? ' ' . $ago : '' ) . ', but no booking under that email address has appeared on your calendar.',
			'They may not have picked a time, or they may have booked with a different email address.',
		);
		$twin = meetlib_matched_twin( $post_id, $m['email'], $m['calendar'], $sent );
		if ( '' !== $twin ) {
			$outro[] = '';
			$outro[] = 'The same patron sent another request that was matched to their appointment on ' . $twin . '. This one may be a duplicate of it or an update to it.';
		}
		$hints = get_post_meta( $post_id, '_meetlib_hints', true );
		if ( is_array( $hints ) && $hints ) {
			$outro[] = '';
			$outro[] = 'Booked on your calendar shortly after this form was sent, under a different address (possibly this patron):';
			foreach ( $hints as $h ) { $outro[] = '  - ' . $h; }
		}
	} elseif ( 'no_access' === $m['gcal'] || 'error' === $m['gcal'] ) {
		$subject = 'Appointment request — ' . $m['email'];
		$intro[] = 'Patron: ' . $m['email'] . ' (their name is on the Google booking)';
		$outro   = array(
			$ago
				? 'The patron was asked to pick a time on your Google appointment page right after sending this, ' . $ago . '. Check your calendar for their booking.'
				: 'The patron picks a time on your Google appointment page right after sending this; expect a booking shortly.',
			'The website could not reach your Google Calendar, so these answers were not added to the appointment itself. Your site administrator can check this under Meet With a Librarian > Calendar Integration.',
		);
	} else {
		$subject = 'Appointment request — ' . $m['email'];
		$intro[] = 'Patron: ' . $m['email'] . ' (their name is on the Google booking)';
		$outro   = array( 'The patron picks a time on your Google appointment page right after sending this; expect a booking shortly.' );
	}

	$attach = array();
	if ( '' !== $m['file'] && is_file( $m['file'] ) && $fails < 2 ) {
		$attach[] = $m['file'];
		$att_line = $m['file_name'] . ' (attached to this email)';
	} elseif ( '' !== $m['file_name'] ) {
		$att_line = $m['file_name'] . ' (this file could not be emailed; please ask the patron to send it to you)';
	} elseif ( '' !== $m['attach_url'] ) {
		$att_line = $m['attach_url'];
	} else {
		$att_line = '(none)';
	}

	$lines = array_merge( $intro, array(
		'',
		'How can I help you?',
		$m['help'],
		'',
		'Course: ' . ( '' !== $m['course'] ? $m['course'] : '(none given)' ),
		'Assignment due: ' . ( '' !== $m['due'] ? $m['due'] : '(none given)' ),
		'Meeting preference: ' . $m['meeting'],
		'Attachment: ' . $att_line,
		'',
	), $outro );
	$headers = is_email( $m['email'] ) ? array( 'Reply-To: ' . $m['email'] ) : array();

	if ( wp_mail( $to, $subject, implode( "\n", $lines ), $headers, $attach ) ) {
		update_post_meta( $post_id, '_meetlib_notified', current_time( 'mysql' ) );
		delete_post_meta( $post_id, '_meetlib_mail_next' );
		meetlib_delete_file( $post_id );
		return true;
	}
	$fails++;
	update_post_meta( $post_id, '_meetlib_mail_fails', $fails );
	error_log( 'meet-a-librarian: wp_mail failed (attempt ' . $fails . ') for request ' . $post_id . '; the request is stored under Appointment Requests.' );
	if ( $fails < 5 ) {
		update_post_meta( $post_id, '_meetlib_mail_next', time() + $fails * 10 * MINUTE_IN_SECONDS );
		update_option( 'meetlib_work', 1 );
	} else {
		delete_post_meta( $post_id, '_meetlib_mail_next' );
		meetlib_delete_file( $post_id );
	}
	return false;
}

/** Appointment time of another request by the same patron to the same librarian that WAS matched, within a day of $sent; '' if none. */
function meetlib_matched_twin( $post_id, $email, $calendar, $sent ) {
	$ids = get_posts( array(
		'post_type'      => MEETLIB_CPT,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 5,
		'post__not_in'   => array( (int) $post_id ),
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_meetlib_email', 'value' => $email ),
			array( 'key' => '_meetlib_calendar', 'value' => $calendar ),
			array( 'key' => '_meetlib_gcal', 'value' => 'added' ),
		),
	) );
	foreach ( (array) $ids as $id ) {
		$t = (int) get_post_meta( $id, '_meetlib_sent', true );
		if ( ! $t ) { $t = (int) get_post_time( 'U', true, $id ); }
		$when = (string) get_post_meta( $id, '_meetlib_when', true );
		if ( '' !== $when && abs( $t - $sent ) <= DAY_IN_SECONDS ) { return $when; }
	}
	return '';
}

/** Librarian notification address from the roster `email` field, or '' if unknown/invalid. */
function meetlib_librarian_email( $lib ) {
	if ( ! empty( $lib['email'] ) && is_email( $lib['email'] ) ) { return $lib['email']; }
	return '';
}

/* ------------------------------------------------------------------ *
 *  Optional Google Calendar integration
 *
 *  A site admin pastes a Google service-account JSON key; each librarian shares their
 *  calendar with the service account ("Make changes to events"). After a patron submits
 *  the form, the plugin finds the patron's newly booked event on the librarian's calendar
 *  (calendar ID = roster email) and appends the answers to its description.
 *  With no key saved, every function here is a no-op and the plugin is email-only.
 * ------------------------------------------------------------------ */

/** The saved service-account key ({client_email, private_key}), or null. */
function meetlib_gcal_key() {
	$raw = get_option( MEETLIB_GCAL_OPTION );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$k = json_decode( $raw, true );
	if ( ! is_array( $k ) || empty( $k['client_email'] ) || empty( $k['private_key'] ) ) { return null; }
	return $k;
}

function meetlib_gcal_enabled() {
	return function_exists( 'openssl_sign' ) && null !== meetlib_gcal_key();
}

function meetlib_b64url( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }

/** OAuth access token for the service account (JWT bearer grant), cached in a transient. */
function meetlib_gcal_token() {
	$key = meetlib_gcal_key();
	if ( ! $key ) { return new WP_Error( 'meetlib_gcal_off', 'Calendar integration is not configured.' ); }
	$cache = 'meetlib_gcal_token_' . md5( $key['client_email'] );
	$tok   = get_transient( $cache );
	if ( is_string( $tok ) && '' !== $tok ) { return $tok; }

	$now    = time();
	$head   = meetlib_b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = meetlib_b64url( wp_json_encode( array(
		'iss'   => $key['client_email'],
		'scope' => 'https://www.googleapis.com/auth/calendar.events',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	) ) );
	$pk  = openssl_pkey_get_private( $key['private_key'] );
	$sig = '';
	if ( ! $pk || ! openssl_sign( $head . '.' . $claims, $sig, $pk, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'meetlib_gcal_key', 'The saved service-account key could not be used. Paste the JSON key again.' );
	}
	$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'timeout' => 10,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $head . '.' . $claims . '.' . meetlib_b64url( $sig ),
		),
	) );
	if ( is_wp_error( $res ) ) { return $res; } // network trouble: temporary
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['access_token'] ) ) {
		if ( $code >= 500 || 429 === $code ) {
			return new WP_Error( 'meetlib_gcal_http', 'Google sign-in is temporarily unavailable (HTTP ' . $code . ').', array( 'status' => $code ) );
		}
		$why = ( is_array( $body ) && ! empty( $body['error_description'] ) ) ? ': ' . sanitize_text_field( (string) $body['error_description'] ) : '';
		return new WP_Error( 'meetlib_gcal_auth', 'Google rejected the service-account key' . $why . '. The key may have been deleted in Google Cloud.' );
	}
	$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
	set_transient( $cache, $body['access_token'], max( 60, $ttl - 300 ) );
	return $body['access_token'];
}

/**
 * Call the Calendar v3 API. Returns the decoded JSON array or WP_Error (data: status = HTTP code,
 * reason = Google's error reason). Also records how far this server's clock is from Google's
 * ($GLOBALS['meetlib_gcal_skew'], seconds; positive = this server is ahead).
 */
function meetlib_gcal_api( $method, $path, $query = array(), $body = null, $headers = array() ) {
	$tok = meetlib_gcal_token();
	if ( is_wp_error( $tok ) ) { return $tok; }
	$url = 'https://www.googleapis.com/calendar/v3' . $path;
	if ( $query ) { $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); }
	$args = array(
		'method'  => $method,
		'timeout' => 10,
		'headers' => array_merge( array( 'Authorization' => 'Bearer ' . $tok ), $headers ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) { return $res; }
	$date = wp_remote_retrieve_header( $res, 'date' );
	$gts  = is_string( $date ) && '' !== $date ? strtotime( $date ) : 0;
	if ( $gts ) { $GLOBALS['meetlib_gcal_skew'] = time() - $gts; }
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		$reason = ( is_array( $data ) && isset( $data['error']['errors'][0]['reason'] ) ) ? (string) $data['error']['errors'][0]['reason'] : '';
		if ( 401 === $code ) { // the cached token was revoked; get a fresh one next time
			$key = meetlib_gcal_key();
			if ( $key ) { delete_transient( 'meetlib_gcal_token_' . md5( $key['client_email'] ) ); }
		}
		return new WP_Error( 'meetlib_gcal_http', 'Google Calendar returned HTTP ' . $code . ( $reason ? ' (' . $reason . ')' : '' ) . '.', array( 'status' => $code, 'reason' => $reason ) );
	}
	return is_array( $data ) ? $data : array();
}

/**
 * Is a failed call permanent until someone fixes the setup (`no_access`: key rejected, calendar not
 * shared) or worth trying again later (`error`: network, Google outage, rate limit)?
 */
function meetlib_gcal_fail_kind( $err ) {
	if ( in_array( $err->get_error_code(), array( 'meetlib_gcal_off', 'meetlib_gcal_key', 'meetlib_gcal_auth' ), true ) ) { return 'no_access'; }
	$d      = $err->get_error_data();
	$status = ( is_array( $d ) && isset( $d['status'] ) ) ? (int) $d['status'] : 0;
	$reason = ( is_array( $d ) && isset( $d['reason'] ) ) ? (string) $d['reason'] : '';
	if ( false !== stripos( $reason, 'ratelimit' ) || false !== stripos( $reason, 'quota' ) ) { return 'error'; }
	return in_array( $status, array( 401, 403, 404 ), true ) ? 'no_access' : 'error';
}

/**
 * Calendars the integration could not use, as calendar => { t, msg }. A flagged calendar is not
 * watched for 6 hours (its requests are emailed straight away instead of waiting a day for a
 * booking the plugin cannot see), then tried again, so it recovers by itself once re-shared.
 */
function meetlib_gcal_problems() {
	$p = get_option( 'meetlib_gcal_problems' );
	return is_array( $p ) ? $p : array();
}
function meetlib_gcal_flag( $calendar, $msg ) {
	$p = meetlib_gcal_problems();
	$k = strtolower( (string) $calendar );
	$p[ $k ] = array( 't' => time(), 'msg' => (string) $msg );
	update_option( 'meetlib_gcal_problems', $p, false );
}
function meetlib_gcal_unflag( $calendar ) {
	$p = meetlib_gcal_problems();
	$k = strtolower( (string) $calendar );
	if ( isset( $p[ $k ] ) ) { unset( $p[ $k ] ); update_option( 'meetlib_gcal_problems', $p, false ); }
}
function meetlib_gcal_flagged( $calendar ) {
	$p = meetlib_gcal_problems();
	$k = strtolower( (string) $calendar );
	return isset( $p[ $k ]['t'] ) && (int) $p[ $k ]['t'] > time() - 6 * HOUR_IN_SECONDS;
}

function meetlib_gcal_events_path( $calendar_id ) {
	return '/calendars/' . rawurlencode( $calendar_id ) . '/events';
}

/** The answers as HTML for the event description (Google renders appointment descriptions as HTML). */
function meetlib_gcal_details_html( $data, $attachment = '' ) {
	$none = '(none given)';
	$rows = array(
		'Email'               => $data['email'],
		'How can I help you?' => $data['help'],
		'Course'              => $data['course'],
		'Assignment due'      => $data['due'],
		'Meeting preference'  => $data['meeting'],
	);
	$h = '<b>' . esc_html( MEETLIB_GCAL_MARKER ) . '</b>';
	foreach ( $rows as $label => $v ) {
		$h .= '<br><b>' . esc_html( $label ) . ':</b> ' . nl2br( esc_html( '' !== trim( $v ) ? $v : $none ), false );
	}
	if ( '' !== (string) $attachment ) {
		$h .= '<br><b>Attachment:</b> ' . esc_html( $attachment );
	}
	return $h;
}

/**
 * Every event on the calendar that changed since the form was sent, as { items, timeZone, accessRole }
 * or WP_Error. Deliberately no free-text `q` search (its index can lag behind brand-new events) and
 * no "upcoming only" filter (a patron can book hours later, for a slot that has passed by the last check).
 */
function meetlib_gcal_list_since( $calendar_id, $since ) {
	$query = array(
		'timeMin'    => gmdate( 'Y-m-d\TH:i:s\Z', $since - HOUR_IN_SECONDS ),
		'updatedMin' => gmdate( 'Y-m-d\TH:i:s\Z', $since - 20 * MINUTE_IN_SECONDS ), // wider than any clock difference we accept
		'maxResults' => 250,
	);
	$out = array( 'items' => array(), 'timeZone' => '', 'accessRole' => '' );
	for ( $page = 0; $page < 5; $page++ ) {
		$r = meetlib_gcal_api( 'GET', meetlib_gcal_events_path( $calendar_id ), $query );
		if ( is_wp_error( $r ) ) { return $r; }
		if ( isset( $r['items'] ) && is_array( $r['items'] ) ) { $out['items'] = array_merge( $out['items'], $r['items'] ); }
		if ( isset( $r['timeZone'] ) ) { $out['timeZone'] = (string) $r['timeZone']; }
		if ( isset( $r['accessRole'] ) ) { $out['accessRole'] = (string) $r['accessRole']; }
		if ( empty( $r['nextPageToken'] ) ) { break; }
		$query['pageToken'] = (string) $r['nextPageToken'];
	}
	return $out;
}

/**
 * Choose the patron's booking among $items, or null. Pure (no I/O) so it can be tested.
 *
 * A booking is an event that (1) was created at or after $earliest, the moment the form was sent
 * in Google's clock: patrons can only book after sending, so anything older is not theirs;
 * (2) lists the patron's email as a guest other than the calendar owner/organizer; (3) is not
 * cancelled, not recurring, and not already tagged with MEETLIB_GCAL_MARKER. Of several, the OLDEST
 * wins, so two forms sent back to back by one patron each take their own booking instead of swapping.
 * A missed match fails safe (a "no booking found" email); a wrong match would write one patron's
 * answers into someone else's event, where its guests can read them. Hence no fuzzy matching.
 *
 * $hints collects up to 3 bookings made within 45 minutes of the form under a different address
 * (a patron signed in to a personal Google account). They are only ever shown to the librarian.
 */
function meetlib_gcal_pick( $items, $email, $earliest, &$hints = array(), $calendar_tz = '' ) {
	$hints   = array();
	$best    = null;
	$best_ts = 0;
	foreach ( (array) $items as $ev ) {
		if ( ! is_array( $ev ) || empty( $ev['id'] ) ) { continue; }
		if ( isset( $ev['status'] ) && 'cancelled' === $ev['status'] ) { continue; }
		if ( ! empty( $ev['recurrence'] ) || ! empty( $ev['recurringEventId'] ) ) { continue; }
		$desc = isset( $ev['description'] ) ? (string) $ev['description'] : '';
		if ( false !== strpos( $desc, MEETLIB_GCAL_MARKER ) ) { continue; }
		$ts = ! empty( $ev['created'] ) ? strtotime( (string) $ev['created'] ) : false;
		if ( ! $ts || $ts < $earliest ) { continue; }
		$match = false;
		$other = '';
		foreach ( ( isset( $ev['attendees'] ) && is_array( $ev['attendees'] ) ) ? $ev['attendees'] : array() as $a ) {
			if ( ! empty( $a['self'] ) || ! empty( $a['organizer'] ) || ! empty( $a['resource'] ) || empty( $a['email'] ) ) { continue; }
			if ( 0 === strcasecmp( (string) $a['email'], (string) $email ) ) { $match = true; break; }
			if ( '' === $other ) { $other = (string) $a['email']; }
		}
		if ( $match ) {
			if ( null === $best || $ts < $best_ts ) { $best = $ev; $best_ts = $ts; }
		} elseif ( '' !== $other && $ts <= $earliest + 45 * MINUTE_IN_SECONDS && count( $hints ) < 3 ) {
			$hints[] = trim( meetlib_gcal_when( isset( $ev['start'] ) ? $ev['start'] : array(), $calendar_tz ) . ' (booked as ' . $other . ')' );
		}
	}
	return $best;
}

/**
 * Find the patron's booking on the librarian's calendar and append the answers to its description.
 * Returns { status, when, link, hints }; status is added | not_found | error | no_access |
 * no_calendar | off. `when` is the appointment start as readable text in the calendar's time zone.
 * $since is when the form was sent (Unix time on this server).
 */
function meetlib_gcal_tag( $calendar_id, $data, $attachment, $since ) {
	$out = array( 'status' => 'off', 'when' => '', 'link' => '', 'hints' => array() );
	if ( ! meetlib_gcal_enabled() ) { return $out; }
	if ( '' === (string) $calendar_id ) { $out['status'] = 'no_calendar'; return $out; }

	$list = meetlib_gcal_list_since( $calendar_id, (int) $since );
	if ( is_wp_error( $list ) ) {
		$out['status'] = meetlib_gcal_fail_kind( $list );
		error_log( 'meet-a-librarian: calendar lookup failed for ' . $calendar_id . ': ' . $list->get_error_message() );
		if ( 'no_access' === $out['status'] ) { meetlib_gcal_flag( $calendar_id, $list->get_error_message() ); }
		return $out;
	}
	if ( ! in_array( $list['accessRole'], array( 'writer', 'owner' ), true ) ) {
		meetlib_gcal_flag( $calendar_id, 'The calendar is shared, but without "Make changes to events".' );
		$out['status'] = 'no_access';
		return $out;
	}
	meetlib_gcal_unflag( $calendar_id );

	// The form's send time is this server's clock; Google stamps `created` with its own. Convert, within reason.
	$skew     = isset( $GLOBALS['meetlib_gcal_skew'] ) ? (int) $GLOBALS['meetlib_gcal_skew'] : 0;
	$skew     = max( -15 * MINUTE_IN_SECONDS, min( 15 * MINUTE_IN_SECONDS, $skew ) );
	$earliest = (int) $since - $skew;

	$hints = array();
	$best  = meetlib_gcal_pick( $list['items'], $data['email'], $earliest, $hints, $list['timeZone'] );
	$out['hints'] = $hints;
	if ( ! $best ) { $out['status'] = 'not_found'; return $out; }

	// If-Match: if anything else changed the event since we read it, Google refuses and we look again later.
	$desc  = isset( $best['description'] ) ? trim( (string) $best['description'] ) : '';
	$patch = meetlib_gcal_api(
		'PATCH',
		meetlib_gcal_events_path( $calendar_id ) . '/' . rawurlencode( $best['id'] ),
		array( 'sendUpdates' => 'none' ),
		array( 'description' => ( '' !== $desc ? $desc . '<br><br>' : '' ) . meetlib_gcal_details_html( $data, $attachment ) ),
		! empty( $best['etag'] ) ? array( 'If-Match' => (string) $best['etag'] ) : array()
	);
	if ( is_wp_error( $patch ) ) {
		$d    = $patch->get_error_data();
		$code = ( is_array( $d ) && isset( $d['status'] ) ) ? (int) $d['status'] : 0;
		error_log( 'meet-a-librarian: could not update event on ' . $calendar_id . ': ' . $patch->get_error_message() );
		// 404/410/412: the event moved or changed under us; that is not a sharing problem.
		$out['status'] = in_array( $code, array( 404, 410, 412 ), true ) ? 'error' : meetlib_gcal_fail_kind( $patch );
		if ( 'no_access' === $out['status'] ) { meetlib_gcal_flag( $calendar_id, $patch->get_error_message() ); }
		return $out;
	}
	$out['status'] = 'added';
	$out['when']   = meetlib_gcal_when( isset( $best['start'] ) ? $best['start'] : array(), $list['timeZone'] );
	$out['link']   = ! empty( $best['htmlLink'] ) ? esc_url_raw( (string) $best['htmlLink'] ) : '';
	return $out;
}

/**
 * Readable start time of a Calendar event in the event's own time zone (the librarian's), not the
 * site's: many WordPress installs are left on UTC, and changing that setting affects the whole site.
 * Preference: the event's own timeZone, then the calendar's ($calendar_tz, the events list's
 * top-level timeZone), then the offset in dateTime, which Google may send as UTC ("…Z").
 */
function meetlib_gcal_when( $start, $calendar_tz = '' ) {
	$start = is_array( $start ) ? $start : array();
	if ( ! empty( $start['dateTime'] ) ) {
		$raw = (string) $start['dateTime'];
		try {
			$dt = new DateTime( $raw );
			$tz = $dt->getTimezone();
			foreach ( array( isset( $start['timeZone'] ) ? (string) $start['timeZone'] : '', (string) $calendar_tz ) as $name ) {
				if ( '' === $name ) { continue; }
				try { $tz = new DateTimeZone( $name ); break; } catch ( Exception $e ) { /* try the next */ }
			}
			return wp_date( 'l, F j \a\t g:i a', $dt->getTimestamp(), $tz );
		} catch ( Exception $e ) {
			return $raw;
		}
	}
	if ( ! empty( $start['date'] ) ) { // all-day event: a calendar date, no time zone
		$ts = strtotime( (string) $start['date'] . ' 00:00:00 UTC' );
		return $ts ? wp_date( 'l, F j', $ts, new DateTimeZone( 'UTC' ) ) : (string) $start['date'];
	}
	return '';
}

/** Seconds after the form submission at which to look for the booking. Patrons book right after sending. */
function meetlib_gcal_retry_delays() {
	return array( 3 * MINUTE_IN_SECONDS, 10 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
}

/** Queue the next calendar check for a request. WP-cron is the main trigger; meetlib_sweep is the safety net. */
function meetlib_gcal_schedule( $post_id, $at ) {
	update_post_meta( $post_id, '_meetlib_gcal_next', (int) $at );
	update_option( 'meetlib_work', 1 );
	wp_schedule_single_event( (int) $at, 'meetlib_gcal_retry', array( (int) $post_id ) );
}

/** One worker at a time per request (WP-cron and the sweep can overlap). Stale locks expire after 5 minutes. */
function meetlib_lock( $post_id ) {
	$l = (int) get_post_meta( $post_id, '_meetlib_lock', true );
	if ( $l && $l > time() - 5 * MINUTE_IN_SECONDS ) { return false; }
	if ( $l ) { delete_post_meta( $post_id, '_meetlib_lock' ); }
	return (bool) add_post_meta( $post_id, '_meetlib_lock', time(), true );
}
function meetlib_unlock( $post_id ) { delete_post_meta( $post_id, '_meetlib_lock' ); }

/**
 * A scheduled calendar check (see meetlib_gcal_retry_delays): tag the booking once it exists, then
 * send the held librarian email. When the delays run out with no match, the librarian gets a clearly
 * labelled "no booking found" email instead, so answers are never lost. A calendar that cannot be
 * used at all (`no_access`) ends the wait immediately: the email goes out now rather than in a day.
 */
add_action( 'meetlib_gcal_retry', 'meetlib_gcal_retry', 10, 1 );
function meetlib_gcal_retry( $post_id, $force = false ) {
	$post_id = (int) $post_id;
	if ( MEETLIB_CPT !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) { return; }
	if ( 'pending' !== get_post_meta( $post_id, '_meetlib_gcal', true ) ) { return; }
	// A leftover cron event for a check the sweep already ran must not use up the next attempt early.
	if ( ! $force && (int) get_post_meta( $post_id, '_meetlib_gcal_next', true ) > time() + 30 ) { return; }
	if ( ! meetlib_lock( $post_id ) ) { return; }

	$data = array();
	foreach ( array( 'email', 'help', 'course', 'due', 'meeting' ) as $k ) {
		$data[ $k ] = (string) get_post_meta( $post_id, '_meetlib_' . $k, true );
	}
	$calendar = (string) get_post_meta( $post_id, '_meetlib_calendar', true );
	$since    = (int) get_post_meta( $post_id, '_meetlib_sent', true );
	if ( ! $since ) { $since = (int) get_post_time( 'U', true, $post_id ); }
	$name = (string) get_post_meta( $post_id, '_meetlib_file_name', true );
	$note = '' !== $name ? $name . ' (sent to you by email)' : (string) get_post_meta( $post_id, '_meetlib_attach_url', true );

	$r      = meetlib_gcal_tag( $calendar, $data, $note, $since );
	$tries  = (int) get_post_meta( $post_id, '_meetlib_gcal_tries', true ) + 1;
	$delays = meetlib_gcal_retry_delays();
	update_post_meta( $post_id, '_meetlib_gcal_tries', $tries );
	if ( in_array( $r['status'], array( 'not_found', 'error' ), true ) && isset( $delays[ $tries ] ) ) {
		// Delays are measured from the submission; never sooner than a minute from now.
		meetlib_gcal_schedule( $post_id, max( time() + MINUTE_IN_SECONDS, $since + $delays[ $tries ] ) );
		meetlib_unlock( $post_id );
		return;
	}
	update_post_meta( $post_id, '_meetlib_gcal', $r['status'] );
	update_post_meta( $post_id, '_meetlib_when', $r['when'] );
	update_post_meta( $post_id, '_meetlib_event_link', $r['link'] );
	update_post_meta( $post_id, '_meetlib_hints', $r['hints'] );
	delete_post_meta( $post_id, '_meetlib_gcal_next' );
	meetlib_unlock( $post_id );
	meetlib_notify( $post_id );
}

/**
 * Safety net for sites where WP-cron does not fire (loopback requests blocked, DISABLE_WP_CRON with
 * no real cron): run overdue calendar checks and email retries. Called at the end of ordinary page
 * loads (meetlib_maybe_sweep) and from the "Run waiting checks now" button ($force = every waiting
 * request, due or not). Clears the `meetlib_work` flag once nothing is waiting.
 */
function meetlib_sweep( $limit = 3, $force = false ) {
	$now  = time();
	$base = array( 'post_type' => MEETLIB_CPT, 'post_status' => 'publish', 'fields' => 'ids', 'orderby' => 'date', 'order' => 'ASC' );
	$due  = get_posts( array_merge( $base, array(
		'posts_per_page' => $limit,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_meetlib_gcal', 'value' => 'pending' ),
			array(
				'relation' => 'OR',
				array( 'key' => '_meetlib_gcal_next', 'value' => $force ? $now + YEAR_IN_SECONDS : $now - 2 * MINUTE_IN_SECONDS, 'compare' => '<=', 'type' => 'NUMERIC' ),
				array( 'key' => '_meetlib_gcal_next', 'compare' => 'NOT EXISTS' ),
			),
		),
	) ) );
	foreach ( (array) $due as $id ) { meetlib_gcal_retry( (int) $id, true ); }

	$mail = get_posts( array_merge( $base, array(
		'posts_per_page' => $limit,
		'meta_query'     => array( array( 'key' => '_meetlib_mail_next', 'value' => $force ? $now + YEAR_IN_SECONDS : $now, 'compare' => '<=', 'type' => 'NUMERIC' ) ),
	) ) );
	foreach ( (array) $mail as $id ) { meetlib_notify( (int) $id ); }

	$left = get_posts( array_merge( $base, array(
		'posts_per_page' => 1,
		'meta_query'     => array(
			'relation' => 'OR',
			array( 'key' => '_meetlib_gcal', 'value' => 'pending' ),
			array( 'key' => '_meetlib_mail_next', 'compare' => 'EXISTS' ),
		),
	) ) );
	if ( empty( $left ) ) { update_option( 'meetlib_work', 0 ); }
	return count( (array) $due ) + count( (array) $mail );
}

add_action( 'shutdown', 'meetlib_maybe_sweep', 999 );
function meetlib_maybe_sweep() {
	if ( ! get_option( 'meetlib_work' ) || get_transient( 'meetlib_sweep_lock' ) ) { return; }
	set_transient( 'meetlib_sweep_lock', 1, 2 * MINUTE_IN_SECONDS );
	if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); } // the visitor's page is already complete
	ignore_user_abort( true );
	meetlib_sweep();
}

/** Human-readable label for a request's `_meetlib_gcal` status. */
function meetlib_gcal_status_label( $status ) {
	$map = array(
		'added'       => 'Booking found; answers added to the event and the librarian emailed',
		'pending'     => 'Waiting for the booking to appear on the calendar; the librarian is emailed once it does',
		'not_found'   => 'No matching booking within a day; the librarian was emailed a "no booking found" note',
		'no_access'   => 'The calendar could not be used (not shared with the integration, or the key was rejected); emailed without matching a booking',
		'error'       => 'The calendar could not be reached; emailed without matching a booking',
		'no_calendar' => 'Librarian has no email on file; sent to the shared inbox instead',
		'self'        => 'Sent from the librarian\'s own address (a test); emailed without watching the calendar',
		'off'         => 'Calendar integration off; emailed without waiting for a booking',
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : '';
}

/** Short status for the requests list, including whether the librarian's email went out. */
function meetlib_status_short( $post_id ) {
	$status = (string) get_post_meta( $post_id, '_meetlib_gcal', true );
	$map    = array(
		'added'     => 'Booked',
		'pending'   => 'Waiting for booking',
		'not_found' => 'No booking found',
		'no_access' => 'Calendar not checked',
		'error'     => 'Calendar not checked',
	);
	$label = isset( $map[ $status ] ) ? $map[ $status ] : 'Request';
	if ( 'pending' === $status ) { return $label; }
	if ( get_post_meta( $post_id, '_meetlib_notified', true ) ) { return $label . ' · emailed'; }
	$fails = (int) get_post_meta( $post_id, '_meetlib_mail_fails', true );
	if ( $fails >= 5 ) { return $label . ' · EMAIL FAILED'; }
	if ( $fails > 0 ) { return $label . ' · email failed, retrying'; }
	// Records from before 2.5 did not track this reliably; say nothing rather than guess.
	return get_post_meta( $post_id, '_meetlib_sent', true ) ? $label . ' · email not sent' : $label;
}

/** Count requests matching a meta query (for the health checks). */
function meetlib_count( $meta_query ) {
	$q = new WP_Query( array(
		'post_type'      => MEETLIB_CPT,
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'meta_query'     => $meta_query,
	) );
	return (int) $q->found_posts;
}

/** Plain-language warnings about anything that is silently not working. Shown on the plugin's admin screens. */
function meetlib_health() {
	$out = array();
	if ( meetlib_gcal_enabled() ) {
		$names = array();
		foreach ( meetlib_get() as $lib ) {
			$e = strtolower( meetlib_librarian_email( $lib ) );
			if ( '' !== $e ) { $names[ $e ] = $lib['name']; }
		}
		foreach ( meetlib_gcal_problems() as $cal => $p ) {
			if ( ! isset( $names[ $cal ] ) || empty( $p['t'] ) || (int) $p['t'] < time() - 7 * DAY_IN_SECONDS ) { continue; }
			$out[] = 'The website could not use ' . $names[ $cal ] . '\'s Google Calendar ' . human_time_diff( (int) $p['t'], time() ) . ' ago: ' . ( isset( $p['msg'] ) ? $p['msg'] : '' )
				. ' Their requests are still emailed, but answers are not added to appointments. Check it under Calendar Integration.';
		}
		$late = meetlib_count( array(
			'relation' => 'AND',
			array( 'key' => '_meetlib_gcal', 'value' => 'pending' ),
			array( 'key' => '_meetlib_gcal_next', 'value' => time() - 30 * MINUTE_IN_SECONDS, 'compare' => '<=', 'type' => 'NUMERIC' ),
		) );
		if ( $late > 0 ) {
			$out[] = $late . ' appointment request(s) are overdue for a calendar check, so their emails to the librarian are being held. Background tasks may not be running on this site. Open Calendar Integration and click "Run waiting checks now".';
		}
	}
	$failed = meetlib_count( array(
		'relation' => 'AND',
		array( 'key' => '_meetlib_mail_fails', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
		array( 'key' => '_meetlib_notified', 'compare' => 'NOT EXISTS' ),
	) );
	if ( $failed > 0 ) {
		$out[] = $failed . ' appointment request(s) could not be emailed to the librarian. They are listed under Appointment Requests (status "email failed"). This usually means the website cannot send email; ask your web host or IT about WordPress email (SMTP).';
	}
	return $out;
}

add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! current_user_can( 'edit_pages' ) ) { return; }
	if ( false === strpos( (string) $screen->id, 'meet-a-librarian' ) && MEETLIB_CPT !== $screen->post_type ) { return; }
	foreach ( meetlib_health() as $msg ) {
		echo '<div class="notice notice-warning"><p><strong>Meet With a Librarian:</strong> ' . esc_html( $msg ) . '</p></div>';
	}
} );

/** Admin screen: save/remove the service-account key, check each librarian's calendar access, and see what is waiting. */
function meetlib_gcal_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$key  = meetlib_gcal_key();
	$self = admin_url( 'admin.php?page=meet-a-librarian-calendar' );

	echo '<div class="wrap"><h1>Calendar Integration</h1>';
	$msgs = array(
		'saved'   => array( 'success', 'Key saved.' ),
		'removed' => array( 'success', 'Key removed. The plugin is now email-only.' ),
		'invalid' => array( 'error', 'That is not a valid service-account JSON key. Paste the whole file you downloaded from Google Cloud.' ),
		'ran'     => array( 'success', 'Waiting requests were checked just now.' ),
		'purged'  => array( 'success', 'Saved attachment files were deleted.' ),
	);
	$m = isset( $_GET['meetlib_msg'] ) ? sanitize_key( $_GET['meetlib_msg'] ) : '';
	if ( isset( $msgs[ $m ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $msgs[ $m ][0] ) . '"><p>' . esc_html( $msgs[ $m ][1] ) . '</p></div>';
	}
	if ( ! function_exists( 'openssl_sign' ) ) {
		echo '<div class="notice notice-error"><p>This server\'s PHP has no OpenSSL extension, so the integration cannot run.</p></div>';
	}

	echo '<div style="max-width:760px">';
	echo '<p><strong>Optional.</strong> Without it, the plugin emails each request to the librarian straight away. With it, the patron\'s answers are also added to the appointment they booked on the librarian\'s Google Calendar, and the email is held until that booking appears.</p>';

	if ( $key ) {
		echo '<h2>Status: on</h2>';
		echo '<p>Service account: <code>' . esc_html( $key['client_email'] ) . '</code></p>';
		echo '<h3>What each librarian does (once)</h3><ol>'
			. '<li>Open Google Calendar &rarr; <strong>Settings</strong> (gear icon).</li>'
			. '<li>Under <strong>Settings for my calendars</strong>, click their own calendar (their name).</li>'
			. '<li>Under <strong>Share with specific people or groups</strong>, click <strong>Add people and groups</strong>, paste the service-account address above, and choose <strong>Make changes to events</strong> (if asked, &ldquo;see private events as busy&rdquo; is enough).</li>'
			. '</ol><p>Nothing else changes: booking links and the directory stay as they are. The email in each librarian\'s directory entry must be their Google Calendar address.</p>';

		echo '<h3>Calendar access</h3>';
		if ( isset( $_GET['meetlib_check'] ) && wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'meetlib_gcal_check' ) ) {
			echo '<table class="widefat striped"><thead><tr><th>Librarian</th><th>Calendar</th><th>Access</th></tr></thead><tbody>';
			foreach ( meetlib_get() as $lib ) {
				$cal = meetlib_librarian_email( $lib );
				if ( '' === $cal ) {
					$state = 'No email in the directory entry';
				} else {
					$r = meetlib_gcal_api( 'GET', meetlib_gcal_events_path( $cal ), array( 'maxResults' => 1 ) );
					if ( is_wp_error( $r ) ) {
						$data  = $r->get_error_data();
						$code  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
						$state = ( 404 === $code || 403 === $code ) ? 'Not shared yet' : 'Error: ' . $r->get_error_message();
						if ( 'no_access' === meetlib_gcal_fail_kind( $r ) ) { meetlib_gcal_flag( $cal, $r->get_error_message() ); }
					} elseif ( isset( $r['accessRole'] ) && in_array( $r['accessRole'], array( 'writer', 'owner' ), true ) ) {
						$state = '✓ Ready';
						meetlib_gcal_unflag( $cal );
					} else {
						$state = 'Shared, but needs "Make changes to events"';
						meetlib_gcal_flag( $cal, 'The calendar is shared, but without "Make changes to events".' );
					}
				}
				echo '<tr><td>' . esc_html( $lib['name'] ) . '</td><td>' . esc_html( $cal ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
			}
			echo '</tbody></table>';
			$skew = isset( $GLOBALS['meetlib_gcal_skew'] ) ? (int) $GLOBALS['meetlib_gcal_skew'] : 0;
			if ( abs( $skew ) > 2 * MINUTE_IN_SECONDS ) {
				echo '<p>Note: this server\'s clock is ' . esc_html( human_time_diff( time() - abs( $skew ), time() ) ) . ' ' . ( $skew > 0 ? 'ahead of' : 'behind' ) . ' Google\'s. The plugin allows for it, but your web host should correct the server time.</p>';
			}
		}
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'meetlib_check', '1', $self ), 'meetlib_gcal_check' ) ) . '">Check each librarian\'s calendar access</a></p>';

		$waiting = meetlib_count( array( array( 'key' => '_meetlib_gcal', 'value' => 'pending' ) ) );
		echo '<h3>Requests waiting for a booking</h3>';
		echo '<p>' . esc_html( $waiting ) . ' request(s) are waiting for the patron\'s booking to appear. The calendar is checked 3, 10 and 30 minutes, then 2, 6 and 24 hours after the form is sent; the librarian is emailed as soon as the booking is found, or after the last check if it never is.</p>';
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<p>WordPress\'s built-in scheduler is turned off on this site (<code>DISABLE_WP_CRON</code>). If your host does not run it another way, checks still happen whenever someone visits the site, just less punctually.</p>';
		}
		if ( $waiting > 0 ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=meetlib_gcal_run' ), 'meetlib_gcal_run' ) ) . '">Run waiting checks now</a></p>';
		}
	} else {
		echo '<h2>Status: off</h2>';
		echo '<h3>Set up (site admin, once)</h3><ol>'
			. '<li>In the Google Cloud console, create (or pick) a project and enable the <strong>Google Calendar API</strong>.</li>'
			. '<li>Create a <strong>service account</strong> (no roles needed), open it &rarr; <strong>Keys</strong> &rarr; <strong>Add key</strong> &rarr; <strong>JSON</strong>.</li>'
			. '<li>Paste the downloaded file\'s contents below and save. This page will then show the address each librarian shares their calendar with.</li>'
			. '</ol><p>The service account can only see calendars that librarians share with it. If Google will not let a librarian choose &ldquo;Make changes to events&rdquo; for it, the college\'s Google Workspace settings are blocking outside sharing and IT would need to allow it.</p>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="meetlib_gcal_save">';
	wp_nonce_field( 'meetlib_gcal_save' );
	echo '<p><label for="meetlib-gcal-key"><strong>' . ( $key ? 'Replace the' : 'Service-account' ) . ' JSON key</strong></label><br>';
	echo '<textarea id="meetlib-gcal-key" name="meetlib_gcal_key" rows="6" class="large-text code" autocomplete="off" spellcheck="false" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea></p>';
	echo '<p class="description">' . ( $key ? 'A key is saved. It is never shown again, so this box is always empty; leave it empty to keep the current key. ' : '' ) . 'The key is stored in the WordPress database. Anyone with database access could read it; it can only reach calendars shared with the service account.</p>';
	if ( $key ) {
		echo '<p><label><input type="checkbox" name="meetlib_gcal_remove" value="1"> Remove the key and turn the integration off</label></p>';
	}
	submit_button( 'Save' );
	echo '</form>';

	$old_files = meetlib_count( array( array( 'key' => '_meetlib_attach_url', 'value' => '', 'compare' => '!=' ) ) );
	if ( $old_files > 0 ) {
		echo '<h3>Attachment files saved by earlier versions</h3>';
		echo '<p>Since version 2.5, a patron\'s attachment is emailed to the librarian and not kept on the website. ' . esc_html( $old_files ) . ' older request(s) still have a file saved in the Media uploads folder. Deleting the files keeps the requests themselves; links to those files in emails already sent will stop working.</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=meetlib_purge_files' ), 'meetlib_purge_files' ) ) . '" onclick="return confirm(\'Delete the saved attachment files from older requests? This cannot be undone.\');">Delete these saved files</a></p>';
	}
	echo '</div></div>';
}

add_action( 'admin_post_meetlib_gcal_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'meetlib_gcal_save' );
	$back = admin_url( 'admin.php?page=meet-a-librarian-calendar' );
	$old  = meetlib_gcal_key();

	if ( ! empty( $_POST['meetlib_gcal_remove'] ) ) {
		delete_option( MEETLIB_GCAL_OPTION );
		delete_option( 'meetlib_gcal_problems' );
		if ( $old ) { delete_transient( 'meetlib_gcal_token_' . md5( $old['client_email'] ) ); }
		wp_safe_redirect( add_query_arg( 'meetlib_msg', 'removed', $back ) );
		exit;
	}

	$raw = isset( $_POST['meetlib_gcal_key'] ) ? trim( wp_unslash( $_POST['meetlib_gcal_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON key, validated below
	if ( '' === $raw ) { wp_safe_redirect( $back ); exit; }
	$k = json_decode( $raw, true );
	if ( ! is_array( $k ) || empty( $k['client_email'] ) || ! is_email( $k['client_email'] ) || empty( $k['private_key'] )
		|| ! function_exists( 'openssl_pkey_get_private' ) || ! openssl_pkey_get_private( $k['private_key'] ) ) {
		wp_safe_redirect( add_query_arg( 'meetlib_msg', 'invalid', $back ) );
		exit;
	}
	if ( $old ) { delete_transient( 'meetlib_gcal_token_' . md5( $old['client_email'] ) ); }
	update_option( MEETLIB_GCAL_OPTION, wp_json_encode( array( 'client_email' => $k['client_email'], 'private_key' => $k['private_key'] ) ), false );
	delete_option( 'meetlib_gcal_problems' ); // a new key gets a clean slate
	wp_safe_redirect( add_query_arg( 'meetlib_msg', 'saved', $back ) );
	exit;
} );

/** "Run waiting checks now": check every waiting request immediately instead of at its next scheduled time. */
add_action( 'admin_post_meetlib_gcal_run', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'meetlib_gcal_run' );
	meetlib_sweep( 25, true );
	wp_safe_redirect( add_query_arg( 'meetlib_msg', 'ran', admin_url( 'admin.php?page=meet-a-librarian-calendar' ) ) );
	exit;
} );

/** Delete attachment files that versions before 2.5 saved in the uploads folder. The requests stay. */
add_action( 'admin_post_meetlib_purge_files', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'meetlib_purge_files' );
	$up   = wp_get_upload_dir();
	$base = preg_replace( '#^https?:#i', '', (string) $up['baseurl'] );
	$root = realpath( (string) $up['basedir'] );
	$ids  = get_posts( array(
		'post_type'      => MEETLIB_CPT,
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 500,
		'meta_query'     => array( array( 'key' => '_meetlib_attach_url', 'value' => '', 'compare' => '!=' ) ),
	) );
	foreach ( (array) $ids as $id ) {
		$url = preg_replace( '#^https?:#i', '', (string) get_post_meta( $id, '_meetlib_attach_url', true ) );
		if ( $root && '' !== $base && 0 === strpos( $url, $base . '/' ) ) {
			$path = realpath( $root . substr( $url, strlen( $base ) ) );
			// Only ever a file inside the uploads folder.
			if ( $path && is_file( $path ) && 0 === strpos( str_replace( '\\', '/', $path ), str_replace( '\\', '/', $root ) . '/' ) ) { @unlink( $path ); } // phpcs:ignore
		}
		update_post_meta( $id, '_meetlib_file_name', basename( (string) wp_parse_url( 'http:' . $url, PHP_URL_PATH ) ) );
		delete_post_meta( $id, '_meetlib_attach_url' );
	}
	wp_safe_redirect( add_query_arg( 'meetlib_msg', 'purged', admin_url( 'admin.php?page=meet-a-librarian-calendar' ) ) );
	exit;
} );

/** Restore a roster backup (see meetlib_backup_roster). The roster being replaced is itself backed up first. */
add_action( 'admin_post_meetlib_restore', function () {
	if ( ! current_user_can( 'edit_pages' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'meetlib_restore' );
	$all = get_option( MEETLIB_BACKUP_OPTION );
	$i   = isset( $_GET['i'] ) ? (int) $_GET['i'] : -1;
	$ok  = 0;
	if ( is_array( $all ) && isset( $all[ $i ]['rows'] ) ) {
		$rows = meetlib_sanitize( $all[ $i ]['rows'] );
		if ( $rows ) {
			meetlib_backup_roster( $rows );
			update_option( MEETLIB_OPTION, $rows );
			$ok = 1;
		}
	}
	wp_safe_redirect( add_query_arg( 'meetlib_restored', $ok, admin_url( 'admin.php?page=meet-a-librarian' ) ) );
	exit;
} );

/* ------------------------------------------------------------------ *
 *  Admin editor page
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Meet With a Librarian',
		'Meet With a Librarian',
		'edit_pages',
		'meet-a-librarian',
		'meetlib_admin_page',
		'dashicons-groups',
		31
	);
	// Add the requests list AFTER the editor so the editor remains the first (default) submenu.
	add_submenu_page(
		'meet-a-librarian',
		'Appointment Requests',
		'Appointment Requests',
		'edit_pages',
		'edit.php?post_type=' . MEETLIB_CPT
	);
	add_submenu_page(
		'meet-a-librarian',
		'Calendar Integration',
		'Calendar Integration',
		'manage_options',
		'meet-a-librarian-calendar',
		'meetlib_gcal_page'
	);
} );

function meetlib_admin_page() {
	$root  = esc_url_raw( rest_url( 'meet-a-librarian/v1/librarians' ) );
	$nonce = wp_create_nonce( 'wp_rest' );

	echo '<div class="wrap"><h1>Meet With a Librarian</h1>';
	echo '<p style="max-width:680px;color:#555">Add each librarian and paste their Google appointment <strong>booking link</strong>, then click <strong>Save &amp; publish</strong>. Changes go live on the website immediately — no files, no code. Show the directory on any page with the shortcode <code>[meet_a_librarian]</code>.</p>';
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p style="max-width:680px;color:#555">Optional: add patrons\' answers to their Google Calendar appointments under <a href="' . esc_url( admin_url( 'admin.php?page=meet-a-librarian-calendar' ) ) . '">Calendar Integration</a> (currently <strong>' . ( meetlib_gcal_enabled() ? 'on' : 'off' ) . '</strong>).</p>';
	}

	echo '<script>window.MEETLIB=' . wp_json_encode( array(
		'root'    => $root,
		'nonce'   => $nonce,
		'pageUrl' => meetlib_page_url(),
	) ) . ';</script>';

	if ( isset( $_GET['meetlib_restored'] ) ) {
		echo ( '1' === $_GET['meetlib_restored'] )
			? '<div class="notice notice-success"><p>The earlier version was restored and is live on the website.</p></div>'
			: '<div class="notice notice-error"><p>That earlier version could not be restored.</p></div>';
	}

	echo meetlib_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput

	// Earlier versions of the directory (kept on every save), so a mistaken save can be undone.
	$backups = get_option( MEETLIB_BACKUP_OPTION );
	if ( is_array( $backups ) && $backups ) {
		echo '<h2 style="margin-top:28px">Earlier versions</h2>';
		echo '<p style="max-width:680px;color:#555">The directory as it was before each of the last ' . count( $backups ) . ' saves. Restoring one replaces the librarians and booking links shown above.</p><ul>';
		foreach ( $backups as $i => $b ) {
			if ( ! is_array( $b ) || empty( $b['rows'] ) || ! is_array( $b['rows'] ) ) { continue; }
			$names = array();
			foreach ( $b['rows'] as $row ) { if ( is_array( $row ) && isset( $row['name'] ) ) { $names[] = (string) $row['name']; } }
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=meetlib_restore&i=' . (int) $i ), 'meetlib_restore' );
			echo '<li>Replaced ' . esc_html( human_time_diff( isset( $b['t'] ) ? (int) $b['t'] : time(), time() ) ) . ' ago'
				. ( ! empty( $b['by'] ) ? ' by ' . esc_html( $b['by'] ) : '' ) . ': ' . esc_html( implode( ', ', $names ) )
				. ' &mdash; <a href="' . esc_url( $url ) . '" onclick="return confirm(\'Replace the current directory with this earlier version?\');">Restore</a></li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/** The editor UI + JS. Nowdoc => no PHP interpolation of $ in JS. */
function meetlib_editor_markup() {
	return <<<'HTML'
<style>
  #meetlib{--accent:#00539b;--line:#dcdcdc;--muted:#666;max-width:820px;font-size:14px}
  #meetlib .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:14px 0;position:relative}
  #meetlib .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
  #meetlib .full{grid-column:1/3}
  #meetlib label{display:block;font-weight:600;font-size:.82rem;color:var(--muted);margin-bottom:3px}
  #meetlib input[type=text]{width:100%;font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:#fff;box-sizing:border-box}
  #meetlib .num{font-weight:700;color:var(--accent);margin-bottom:6px}
  #meetlib .b{cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:7px;font-weight:600;font-size:.9rem}
  #meetlib .b.sec{background:#fff;color:var(--accent)}
  #meetlib .b.ghost{background:#fff;color:var(--muted);border-color:var(--line)}
  #meetlib .b.tiny{padding:3px 9px;font-size:.8rem;border-radius:5px;position:absolute;top:12px;right:14px}
  #meetlib .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
  #meetlib .status{font-weight:600}
  #meetlib .hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}
  #meetlib .widget{margin-top:12px;border-top:1px dashed var(--line);padding-top:10px}
  #meetlib textarea.code{width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.75rem;line-height:1.4;padding:8px;border:1px solid var(--line);border-radius:6px;background:#f7f7f8;box-sizing:border-box;resize:vertical;min-height:66px}
  #meetlib .widget .row{display:flex;align-items:center;gap:10px;margin-top:6px;flex-wrap:wrap}
  #meetlib .muted{font-size:.8rem;color:var(--muted)}
  #meetlib .pagecard label{margin-bottom:4px}
</style>

<div id="meetlib">
  <div class="card pagecard">
    <label for="meetlib-page">Booking page address <span class="muted">(the page that shows the <code>[meet_a_librarian]</code> directory — used in each librarian's widget link)</span></label>
    <input id="meetlib-page" type="text" placeholder="https://library.wheatoncollege.edu/meet-with-a-librarian/">
  </div>
  <div id="meetlib-rows"></div>
  <div class="bar"><button type="button" class="b sec" id="meetlib-add">+ Add a librarian</button></div>
  <div class="bar">
    <button type="button" class="b" id="meetlib-save">Save &amp; publish</button>
    <span class="status" id="meetlib-status"></span>
  </div>
  <p class="hint">The <strong>booking link</strong> comes from each librarian's Google Appointment Schedule ("Open booking page" &rarr; Share &rarr; Copy link). Leave it blank and that card shows "Booking link coming soon" until you add it.</p>
</div>

<script>
(function(){
"use strict";
var FIELDS=[
  {k:"name",label:"Name",full:false},
  {k:"pronouns",label:"Pronouns (e.g. she/her)",full:false},
  {k:"title",label:"Title",full:false},
  {k:"liaison",label:"Liaison / subtitle",full:false},
  {k:"booking",label:"Booking link (Google appointment page)",full:true},
  {k:"email",label:"Librarian email (for appointment-request notifications)",full:true},
  {k:"photo",label:"Photo URL",full:true},
  {k:"profile",label:"Profile URL",full:true}
];
var libs=[], saved=[], pageUrl="", savedPage="";
function el(t,c){var e=document.createElement(t);if(c)e.className=c;return e;}
function clone(o){return JSON.parse(JSON.stringify(o));}
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function slug(name){return String(name==null?"":name).toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}
function firstName(name){var n=String(name==null?"":name).trim().split(/\s+/)[0];return n||String(name||"");}
function widgetCode(name){
  var base=(savedPage||"").trim(), href=base+(base.indexOf("?")>-1?"&":"?")+"librarian="+slug(name);
  return '<a href="'+esc(href)+'" target="_blank" rel="noopener" '
    +'style="display:inline-block;font-family:-apple-system,BlinkMacSystemFont,&#39;Segoe UI&#39;,Roboto,Helvetica,Arial,sans-serif;'
    +'font-size:15px;font-weight:600;line-height:1.3;color:#ffffff;background:#00539b;text-decoration:none;padding:10px 18px;border-radius:8px;">'
    +'📅 Book an appointment with '+esc(firstName(name))+'</a>';
}
/* Mirrors meetlib_embed_url() in PHP: only these links can show the calendar inside the page. */
function bookingNote(v){
  v=String(v==null?"":v).trim();
  if(!v)return "";
  if(/calendar\.google\.com\/calendar\/(u\/\d+\/)?appointments/i.test(v))return "✓ Google appointment link: the calendar will appear on the booking page.";
  return "This is not a full Google appointment link (those start with https://calendar.google.com/calendar/appointments/), so patrons will get a button that opens it in a new tab instead of a calendar on the page.";
}
function isLive(name){var sl=slug(name);if(!sl)return false;return saved.some(function(s){return slug(s.name)===sl;});}
function widgetBox(lib){
  var box=el("div","widget");
  var lab=el("label");lab.textContent="Booking widget for this librarian's LibApps profile";box.appendChild(lab);
  if(!isLive(lib.name)){
    var m=el("p","muted");m.textContent="Click Save & publish to generate this librarian's widget code.";box.appendChild(m);
    return box;
  }
  var ta=el("textarea","code");ta.readOnly=true;ta.rows=3;ta.value=widgetCode(lib.name);
  ta.onclick=function(){ta.select();};
  box.appendChild(ta);
  var row=el("div","row");
  var copy=el("button","b sec");copy.type="button";copy.textContent="Copy code";
  copy.onclick=function(){
    var done=function(){copy.textContent="Copied ✓";setTimeout(function(){copy.textContent="Copy code";},1600);};
    if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(ta.value).then(done,function(){ta.select();document.execCommand("copy");done();});}
    else{ta.select();document.execCommand("copy");done();}
  };
  row.appendChild(copy);
  var hint=el("span","muted");hint.textContent="Paste into a LibApps/LibGuides profile box (HTML). Re-copy if you rename this librarian.";row.appendChild(hint);
  box.appendChild(row);
  return box;
}
function render(){
  var wrap=document.getElementById("meetlib-rows");wrap.innerHTML="";
  if(libs.length===0){var p=el("p","hint");p.textContent="No librarians yet — click “Add a librarian.”";wrap.appendChild(p);}
  libs.forEach(function(lib,i){
    var card=el("div","card");
    var num=el("div","num");num.textContent="Librarian "+(i+1);card.appendChild(num);
    var rm=el("button","b ghost tiny");rm.type="button";rm.textContent="remove";rm.setAttribute("aria-label","Remove librarian "+(i+1));rm.onclick=function(){libs.splice(i,1);render();};card.appendChild(rm);
    var grid=el("div","grid"), wb=null;
    FIELDS.forEach(function(f){
      var cell=el("div",f.full?"full":""), id="meetlib-"+i+"-"+f.k;
      var lab=el("label");lab.textContent=f.label;lab.htmlFor=id;
      var inp=el("input");inp.type="text";inp.id=id;inp.value=lib[f.k]||"";
      // Renaming only refreshes this card's widget box; a full re-render would steal focus mid-typing.
      var note=null;
      if(f.k==="booking"){note=el("p","hint");note.id=id+"-note";inp.setAttribute("aria-describedby",note.id);note.textContent=bookingNote(inp.value);}
      inp.oninput=function(){lib[f.k]=inp.value;if(note)note.textContent=bookingNote(inp.value);if(f.k==="name"&&wb){var nb=widgetBox(lib);card.replaceChild(nb,wb);wb=nb;}};
      cell.appendChild(lab);cell.appendChild(inp);if(note)cell.appendChild(note);grid.appendChild(cell);
    });
    card.appendChild(grid);
    wb=widgetBox(lib);card.appendChild(wb);
    wrap.appendChild(card);
  });
}
function flash(msg,bad){var s=document.getElementById("meetlib-status");s.textContent=msg;s.style.color=bad?"#b42318":"#1a7f37";}

var pageInput=document.getElementById("meetlib-page");
pageInput.oninput=function(){pageUrl=pageInput.value;};
document.getElementById("meetlib-add").onclick=function(){libs.push({name:"",pronouns:"",title:"",liaison:"",email:"",photo:"",profile:"",booking:""});render();};
var saveBtn=document.getElementById("meetlib-save"), loaded=false;
function dirty(){return loaded&&(JSON.stringify(libs)!==JSON.stringify(saved)||pageUrl!==savedPage);}
saveBtn.onclick=function(){
  /* Never save a list that did not load: it would replace every librarian and booking link. */
  if(!loaded){flash("The current list could not be loaded, so nothing was saved. Reload this page first.",true);return;}
  if(!libs.some(function(l){return String(l.name||"").trim();})){flash("Nothing was saved: add at least one librarian with a name.",true);return;}
  flash("Saving…");saveBtn.disabled=true;
  fetch(MEETLIB.root,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":MEETLIB.nonce},body:JSON.stringify({librarians:libs,pageUrl:pageUrl})})
    .then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};},function(){return{ok:r.ok,j:null};});})
    .then(function(res){
      saveBtn.disabled=false;
      if(!res.ok){flash(res.j&&res.j.message?res.j.message:"Save failed. Your changes are still on this screen; reload the page in another tab to sign in again, then click Save here.",true);return;}
      libs=libs.filter(function(l){return String(l.name||"").trim();});
      saved=clone(libs);savedPage=pageUrl;render();flash("Saved — it's live on the website. ✓");
    })
    .catch(function(){saveBtn.disabled=false;flash("Save failed: the website could not be reached. Your changes are still on this screen; try again.",true);});
};
window.addEventListener("beforeunload",function(e){if(dirty()){e.preventDefault();e.returnValue="";}});

pageUrl=savedPage=(MEETLIB.pageUrl||"");
pageInput.value=pageUrl;
fetch(MEETLIB.root,{credentials:"same-origin",cache:"no-store"})
  .then(function(r){if(!r.ok)throw 0;return r.json();})
  .then(function(d){if(!Array.isArray(d))throw 0;libs=d.map(function(l){var o={};FIELDS.forEach(function(f){o[f.k]=l[f.k]||"";});return o;});saved=clone(libs);loaded=true;render();})
  .catch(function(){
    saveBtn.disabled=true;document.getElementById("meetlib-add").disabled=true;
    document.getElementById("meetlib-rows").innerHTML="";
    flash("The librarian list could not be loaded, so editing is turned off to protect it. Reload this page to try again.",true);
  });
})();
</script>
HTML;
}

/* ------------------------------------------------------------------ *
 *  Appointment requests  (CPT = durable record + wp-admin viewer)
 * ------------------------------------------------------------------ */

add_action( 'init', function () {
	register_post_type( MEETLIB_CPT, array(
		'labels' => array(
			'name'          => 'Appointment Requests',
			'singular_name' => 'Appointment Request',
			'menu_name'     => 'Appointment Requests',
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => false, // added manually in admin_menu so the editor stays the default submenu
		'capability_type' => 'page',
		'map_meta_cap'    => true,
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ), // records are created in code only
		'supports'        => array( 'title' ),
		'show_in_rest'    => false,
	) );
} );

/** List-table columns for the requests screen. */
add_filter( 'manage_' . MEETLIB_CPT . '_posts_columns', function ( $cols ) {
	return array(
		'cb'            => isset( $cols['cb'] ) ? $cols['cb'] : '',
		'title'         => 'Patron',
		'meetlib_librarian' => 'Librarian',
		'meetlib_status'    => 'Status',
		'meetlib_when'      => 'Appointment',
		'meetlib_meeting'   => 'Meeting',
		'meetlib_email'     => 'Email',
		'date'          => 'Submitted',
	);
} );

add_action( 'manage_' . MEETLIB_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	if ( 'meetlib_status' === $col ) {
		echo esc_html( meetlib_status_short( $post_id ) );
		return;
	}
	$map = array( 'meetlib_librarian' => '_meetlib_librarian', 'meetlib_when' => '_meetlib_when', 'meetlib_meeting' => '_meetlib_meeting', 'meetlib_email' => '_meetlib_email' );
	if ( isset( $map[ $col ] ) ) {
		echo esc_html( get_post_meta( $post_id, $map[ $col ], true ) );
	}
}, 10, 2 );

/** Show all answers + what happened to the request in the single-request edit screen (read-only). */
add_action( 'edit_form_after_title', function ( $post ) {
	if ( MEETLIB_CPT !== $post->post_type ) { return; }
	$rows = array(
		'Librarian'          => '_meetlib_librarian',
		'First name'         => '_meetlib_first',
		'Last name'          => '_meetlib_last',
		'Email'              => '_meetlib_email',
		'How can I help you?' => '_meetlib_help',
		'Course'             => '_meetlib_course',
		'Assignment due'     => '_meetlib_due',
		'Meeting preference' => '_meetlib_meeting',
		'Appointment'        => '_meetlib_when',
	);
	echo '<table class="widefat striped" style="max-width:780px;margin-top:12px"><tbody>';
	foreach ( $rows as $label => $key ) {
		$val = get_post_meta( $post->ID, $key, true );
		// Name fields were dropped in 2.2 (Google's booking collects them); still shown for older records.
		if ( '' === (string) $val && in_array( $key, array( '_meetlib_first', '_meetlib_last' ), true ) ) { continue; }
		echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
	}
	$link = (string) get_post_meta( $post->ID, '_meetlib_event_link', true );
	if ( '' !== $link ) {
		echo '<tr><th>Calendar event</th><td><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">Open in Google Calendar</a> (works for the librarian whose calendar it is on)</td></tr>';
	}
	$att  = (string) get_post_meta( $post->ID, '_meetlib_attach_url', true );
	$name = (string) get_post_meta( $post->ID, '_meetlib_file_name', true );
	if ( '' !== $att ) {
		echo '<tr><th>Attachment</th><td><a href="' . esc_url( $att ) . '" target="_blank" rel="noopener">Download</a></td></tr>';
	} elseif ( '' !== $name ) {
		$held = '' !== (string) get_post_meta( $post->ID, '_meetlib_file', true );
		echo '<tr><th>Attachment</th><td>' . esc_html( $name ) . ' ' . ( $held ? '(held until the librarian\'s email is sent, then deleted)' : '(emailed to the librarian; not kept on the website)' ) . '</td></tr>';
	}
	$gcal = meetlib_gcal_status_label( get_post_meta( $post->ID, '_meetlib_gcal', true ) );
	if ( '' !== $gcal ) {
		echo '<tr><th>Calendar</th><td>' . esc_html( $gcal ) . '</td></tr>';
	}
	$notified = (string) get_post_meta( $post->ID, '_meetlib_notified', true );
	$fails    = (int) get_post_meta( $post->ID, '_meetlib_mail_fails', true );
	if ( '' !== $notified ) {
		echo '<tr><th>Email to librarian</th><td>Sent</td></tr>';
	} elseif ( $fails > 0 ) {
		echo '<tr><th>Email to librarian</th><td><strong>Could not be sent</strong> (' . esc_html( $fails ) . ' attempt' . ( 1 === $fails ? '' : 's' ) . ( $fails < 5 ? '; trying again' : '; given up. Please pass these answers on yourself' ) . ')</td></tr>';
	}
	$hints = get_post_meta( $post->ID, '_meetlib_hints', true );
	if ( is_array( $hints ) && $hints && 'not_found' === get_post_meta( $post->ID, '_meetlib_gcal', true ) ) {
		echo '<tr><th>Possible bookings</th><td>Booked shortly after this form, under a different address:<br>' . implode( '<br>', array_map( 'esc_html', $hints ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
}, 10, 1 );

/* ------------------------------------------------------------------ *
 *  Front-end directory  [meet_a_librarian]
 * ------------------------------------------------------------------ */

add_shortcode( 'meet_a_librarian', 'meetlib_shortcode' );

function meetlib_shortcode( $atts ) {
	// esc_url_raw, not esc_url: these go into JSON, where esc_url's &#038; would corrupt query strings.
	$root   = esc_url_raw( rest_url( 'meet-a-librarian/v1/librarians' ) );
	$submit = esc_url_raw( rest_url( 'meet-a-librarian/v1/request' ) );
	// No nonce: this page may be served from a page cache for days, and a stale nonce made every
	// submission fail. The endpoint is public and anonymous; see meetlib_handle_request for its guards.
	$config = array(
		'listUrl'     => $root,
		'submitUrl'   => $submit,
		'fields'      => meetlib_form_fields(),
		'maxBytes'    => meetlib_max_upload_bytes(),
		'maxLabel'    => meetlib_max_upload_label(),
		'maxChars'    => array( 'textarea' => meetlib_max_chars( 'textarea' ), 'text' => meetlib_max_chars( 'text' ) ),
		'exts'        => meetlib_upload_exts(),
		'domains'     => meetlib_email_domains(),
		'urgentEmail' => meetlib_urgent_email(),
	);
	$config_js = '<script>window.MEETLIB_URL=' . wp_json_encode( $root )
		. ';window.MEETLIB_FORM=' . wp_json_encode( $config ) . ';</script>';
	return meetlib_widget_markup() . $config_js . meetlib_widget_script();
}

function meetlib_widget_markup() {
	return <<<'HTML'
<div id="meetlib-librarians" class="meetlib-librarians" aria-live="polite">
  <p class="meetlib__loading">Loading librarians…</p>
</div>
<div id="meetlib-form-host"></div>
<style>
  #meetlib-librarians{--meetlib-accent:#00539b;--meetlib-line:#1274B8;--meetlib-text:#1a1a1a;--meetlib-muted:#666;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--meetlib-text)}
  #meetlib-librarians .meetlib__grid{display:flex;flex-wrap:wrap;justify-content:center;gap:24px}
  #meetlib-librarians .meetlib__card{flex:1 1 240px;max-width:300px;text-align:center;display:flex;flex-direction:column;align-items:center}
  #meetlib-librarians .meetlib__card img{width:180px;height:180px;object-fit:cover;border-radius:8px}
  #meetlib-librarians .meetlib__name{font-weight:700;margin-top:12px}
  #meetlib-librarians .meetlib__title{font-size:.9rem;color:var(--meetlib-muted);margin-top:4px}
  #meetlib-librarians .meetlib__book{display:inline-block;margin-top:12px;background:var(--meetlib-accent);color:#fff;
    text-decoration:none;padding:9px 16px;border-radius:7px;font-weight:600;font-size:.95rem}
  #meetlib-librarians .meetlib__book:hover,#meetlib-librarians .meetlib__book:focus{background:#003b71;color:#fff}
  #meetlib-librarians .meetlib__soon{display:inline-block;margin-top:12px;color:var(--meetlib-muted);font-size:.9rem;font-style:italic}
  #meetlib-librarians .meetlib__loading,#meetlib-librarians .meetlib__error{color:var(--meetlib-muted);text-align:center}
  #meetlib-librarians .meetlib__book{cursor:pointer;border:0}
  #meetlib-form-host{--meetlib-accent:#00539b;--meetlib-line:#c9c9c9;--meetlib-muted:#666;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:24px auto}
  #meetlib-form-host .meetlib-f__field{margin:14px 0}
  #meetlib-form-host label{display:block;font-weight:600;margin-bottom:4px}
  #meetlib-form-host input[type=text],#meetlib-form-host input[type=email],#meetlib-form-host textarea{
    width:100%;font:inherit;padding:8px 10px;border:1px solid var(--meetlib-line);border-radius:6px;box-sizing:border-box}
  #meetlib-form-host textarea{min-height:90px;resize:vertical}
  #meetlib-form-host .meetlib-f__help{font-size:.85rem;color:var(--meetlib-muted);margin-top:3px}
  #meetlib-form-host .meetlib-f__req{color:#b42318}
  #meetlib-form-host .meetlib-f__err{color:#b42318;font-size:.85rem;margin-top:3px}
  #meetlib-form-host .meetlib-f__err:empty{display:none}
  #meetlib-form-host fieldset.meetlib-f__field{border:0;padding:0;min-width:0}
  #meetlib-form-host legend{font-weight:600;margin-bottom:4px;padding:0}
  #meetlib-form-host .meetlib-f__radio{display:block;font-weight:400;margin:4px 0}
  #meetlib-form-host .meetlib-f__submit{display:inline-block;background:var(--meetlib-accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font-weight:600;font-size:1rem;cursor:pointer;text-decoration:none}
  #meetlib-form-host .meetlib-f__submit:hover{background:#003b71;color:#fff}
  #meetlib-form-host .meetlib-f__submit[disabled]{opacity:.7;cursor:wait}
  #meetlib-form-host .meetlib-f__cancel{background:none;border:0;padding:6px 4px;color:#555;font-size:1rem;cursor:pointer;text-decoration:underline}
  #meetlib-form-host button:focus-visible,#meetlib-form-host a:focus-visible,#meetlib-form-host input:focus-visible,#meetlib-form-host textarea:focus-visible{outline:3px solid var(--meetlib-accent);outline-offset:2px}
  #meetlib-form-host .meetlib-f__actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;margin-top:16px}
  #meetlib-form-host .meetlib-f__h{font-size:1.25rem;line-height:1.3;margin:0 0 10px}
  #meetlib-form-host .meetlib-f__h:focus{outline:none}
  #meetlib-form-host iframe{width:100%;height:720px;border:1px solid var(--meetlib-line);border-radius:8px}
  #meetlib-form-host .meetlib-f__note{background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;padding:12px 14px;margin-bottom:16px}
  #meetlib-form-host.meetlib-f__wide{max-width:1100px}
  #meetlib-form-host .meetlib-f__wrap{display:grid;gap:28px;align-items:start}
  @media (min-width:960px){#meetlib-form-host .meetlib-f__wrap{grid-template-columns:minmax(300px,400px) minmax(0,1fr)}}
  #meetlib-form-host h3.meetlib-f__h{font-size:1.1rem}
  #meetlib-form-host .meetlib-f__cal{position:relative}
  #meetlib-form-host .meetlib-f__lock{position:absolute;top:0;right:0;bottom:0;left:0;background:rgba(255,255,255,.62);border-radius:8px;display:flex;align-items:center;justify-content:center;padding:20px;text-align:center}
  #meetlib-form-host .meetlib-f__lock p{margin:0;max-width:320px;background:#fff;border:1px solid var(--meetlib-line);border-radius:8px;padding:12px 16px;font-weight:600;box-shadow:0 2px 10px rgba(0,0,0,.1)}
  #meetlib-form-host .meetlib-f__alt{font-size:.9rem;margin:8px 0 0}
  #meetlib-form-host .meetlib-f__alert:focus{outline:none}
  #meetlib-form-host .meetlib-f__urgent{margin:12px 0 0;padding:10px 14px;border-left:4px solid var(--meetlib-accent);background:#f5f7fa}
  #meetlib-form-host .meetlib-f__alert{background:#fef3f2;border:1px solid #fecdca;color:#b42318;border-radius:8px;padding:12px 14px;margin-bottom:16px}
  #meetlib-form-host .meetlib-f__alert:empty{display:none}
  #meetlib-form-host .meetlib-f__sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
  #meetlib-form-host [hidden]{display:none}
</style>
HTML;
}

function meetlib_widget_script() {
	return <<<'HTML'
<script>
(function(){
"use strict";
var mount=document.getElementById("meetlib-librarians");
var host=document.getElementById("meetlib-form-host");
if(!mount||!host||!window.MEETLIB_URL||!window.MEETLIB_FORM)return;
var CFG=window.MEETLIB_FORM, LIST=[], opener=null;
var s=new Date();
var bust=""+s.getUTCFullYear()+(s.getUTCMonth()+1)+s.getUTCDate()+s.getUTCHours();
var url=MEETLIB_URL+(MEETLIB_URL.indexOf("?")>-1?"&":"?")+"v="+bust;
fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(render).catch(function(){
  var to=CFG.urgentEmail||"";
  mount.innerHTML='<p class="meetlib__error">The librarian directory is temporarily unavailable. Please reload the page'+(to?', or email <a href="mailto:'+esc(to)+'">'+esc(to)+'</a>':'')+'.</p>';
});
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function slug(name){return String(name==null?"":name).toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}
function byId(id){return document.getElementById(id);}
function render(list){
  LIST=Array.isArray(list)?list:[];
  if(LIST.length===0){mount.innerHTML='<p class="meetlib__error">No librarians are listed yet.</p>';return;}
  var html='<div class="meetlib__grid">';
  LIST.forEach(function(l,i){
    var name=esc(l.name)+(l.pronouns?' ('+esc(l.pronouns)+')':'');
    var alt=l.profile?esc(l.name)+'’s profile':'';
    var img=l.photo?'<img src="'+esc(l.photo)+'" alt="'+alt+'" width="180" height="180" loading="lazy">':'';
    var photo=l.profile?'<a href="'+esc(l.profile)+'">'+img+'</a>':img;
    var sub='';
    if(l.title)sub+='<div class="meetlib__title">'+esc(l.title)+'</div>';
    if(l.liaison)sub+='<div class="meetlib__title">'+esc(l.liaison)+'</div>';
    var action=l.booking
      ? '<button type="button" class="meetlib__book" data-i="'+i+'">Book an appointment<span class="meetlib-f__sr"> with '+esc(l.name)+'</span></button>'
      : '<span class="meetlib__soon">Booking link coming soon</span>';
    html+='<div class="meetlib__card">'+photo+'<div class="meetlib__name">'+name+'</div>'+sub+action+'</div>';
  });
  html+='</div>';
  mount.innerHTML=html;
  Array.prototype.forEach.call(mount.querySelectorAll(".meetlib__book"),function(b){
    b.addEventListener("click",function(){opener=b;openBooking(parseInt(b.getAttribute("data-i"),10));});
  });
  var m=/[?&]librarian=([^&#]+)/.exec(location.search), want="";
  if(m){try{want=decodeURIComponent(m[1]).toLowerCase();}catch(e){want="";}}
  if(want){for(var wi=0;wi<LIST.length;wi++){if(LIST[wi].booking&&slug(LIST[wi].name)===want){openBooking(wi);break;}}}
}
function fieldHtml(f){
  var id="meetlib-f-"+f.key;
  var req=f.required?' <span class="meetlib-f__req" aria-hidden="true">*</span>':'';
  var helpId=f.help?id+'-help':'';
  var help=f.help?'<div class="meetlib-f__help" id="'+helpId+'">'+esc(f.help)+'</div>':'';
  var err='<div class="meetlib-f__err" id="'+id+'-err" data-err="'+f.key+'"></div>';
  var desc=' aria-describedby="'+(helpId?helpId+' ':'')+id+'-err"';
  var rq=f.required?' required':'';
  var max=(CFG.maxChars&&CFG.maxChars[f.type==="textarea"?"textarea":"text"])||0;
  var ml=max?' maxlength="'+max+'"':'';
  if(f.type==="radio"){
    var opts=(f.options||[]).map(function(o,n){
      return '<label class="meetlib-f__radio"><input type="radio" name="meetlib_'+f.key+'" value="'+esc(o)+'"'+rq+'> '+esc(o)+'</label>';
    }).join("");
    return '<fieldset class="meetlib-f__field" id="'+id+'"'+desc+'><legend>'+esc(f.label)+req+'</legend>'+opts+help+err+'</fieldset>';
  }
  var ctl;
  if(f.type==="textarea"){ctl='<textarea id="'+id+'" data-k="'+f.key+'"'+rq+ml+desc+'></textarea>';}
  else if(f.type==="file"){ctl='<input type="file" id="'+id+'" data-k="'+f.key+'" accept="'+esc((CFG.exts||[]).map(function(x){return "."+x;}).join(","))+'"'+desc+'>';}
  else{
    var ac={first:"given-name",last:"family-name",email:"email"}[f.key];
    ctl='<input type="'+(f.type==="email"?"email":"text")+'" id="'+id+'" data-k="'+f.key+'"'+(ac?' autocomplete="'+ac+'"':'')+rq+ml+desc+'>';
  }
  return '<div class="meetlib-f__field"><label for="'+id+'">'+esc(f.label)+req+'</label>'+ctl+help+err+'</div>';
}
function focusEl(n){if(n){n.focus();if(n.scrollIntoView)n.scrollIntoView({behavior:"smooth",block:"start"});}}
function close(){host.innerHTML="";host.classList.remove("meetlib-f__wide");if(opener&&document.body.contains(opener))opener.focus();}
/* A patron who reloads the page after sending the form (and before booking) gets the calendar
   back unlocked for 30 minutes, instead of being asked to send the same answers again. Kept in
   sessionStorage (this tab only); every access is wrapped because storage can be blocked. */
var REMEMBER_MS=30*60*1000;
function sentKey(l){return "meetlib_sent_"+slug(l.name);}
function remembered(l){
  try{
    var o=JSON.parse(window.sessionStorage.getItem(sentKey(l))||"null");
    if(o&&o.t&&(Date.now()-o.t)<REMEMBER_MS)return o;
  }catch(e){}
  return null;
}
function remember(l,email){try{window.sessionStorage.setItem(sentKey(l),JSON.stringify({t:Date.now(),email:email}));}catch(e){}}
function forget(l){try{window.sessionStorage.removeItem(sentKey(l));}catch(e){}}
/* One screen, two panels: the short intake form and the librarian's Google scheduler. The
   scheduler sits behind a translucent lock until the form is sent, so patrons can see which
   dates have openings but cannot book without telling the librarian what they need. Booking
   happens inside Google's iframe, so form-first is the only order the plugin can enforce.
   `fresh` forces the empty form even if one was sent a moment ago (a second, different request). */
function openBooking(i,fresh){
  var l=LIST[i];if(!l)return;
  var embed=l.embed||"";
  var cal=embed
    ? '<iframe id="meetlib-cal-ctl" src="'+esc(embed)+'" title="'+esc("Available appointment times with "+l.name)+'" tabindex="-1" aria-hidden="true"></iframe>'
    : '<p><a id="meetlib-cal-ctl" class="meetlib-f__submit" href="'+esc(l.booking)+'" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">Open '+esc(l.name)+'’s booking page<span class="meetlib-f__sr"> (opens in a new tab)</span></a></p>';
  /* Some browsers block Google's calendar inside another site (sign-in walls, strict privacy settings). */
  var alt=embed
    ? '<p class="meetlib-f__alt" id="meetlib-alt" hidden>Calendar not loading, or asking you to sign in? <a href="'+esc(l.booking)+'" target="_blank" rel="noopener">Open the booking page in a new tab<span class="meetlib-f__sr"> (opens in a new tab)</span></a>.</p>'
    : '';
  var urgent=CFG.urgentEmail
    ? '<p class="meetlib-f__urgent">Need an appointment within the next 24 hours? Email <a href="mailto:'+esc(CFG.urgentEmail)+'">'+esc(CFG.urgentEmail)+'</a>.</p>'
    : '';
  host.classList.add("meetlib-f__wide");
  host.innerHTML=
     '<h2 class="meetlib-f__h" id="meetlib-h1" tabindex="-1">Book an appointment with '+esc(l.name)+'</h2>'
    +'<p class="meetlib-f__note">Tell '+esc(l.name)+' what you’d like help with, then pick a date and time. '+(embed?'Available dates are shown on the calendar.':'')+'</p>'
    +'<div class="meetlib-f__wrap">'
    +'<section class="meetlib-f__form" aria-labelledby="meetlib-h2">'
    +'<h3 class="meetlib-f__h" id="meetlib-h2" tabindex="-1">1. What do you need?</h3>'
    +'<div id="meetlib-form-body">'
    +'<div class="meetlib-f__alert" id="meetlib-alert" role="alert" tabindex="-1"></div>'
    +'<p class="meetlib-f__help">Fields marked with <span class="meetlib-f__req">*</span> are required.</p>'
    +'<form id="meetlib-f" novalidate>'+CFG.fields.map(fieldHtml).join("")
    /* Honeypot. Deliberately not named like a real field ("website", "url"), which browser autofill can fill in. */
    +'<div aria-hidden="true" style="position:absolute;left:-9999px"><label>Leave this empty <input type="text" name="meetlib_hp" tabindex="-1" autocomplete="off"></label></div>'
    +'<div class="meetlib-f__actions"><button type="submit" class="meetlib-f__submit">Send and pick a time</button>'
    +'<button type="button" class="meetlib-f__cancel" id="meetlib-cancel">Cancel</button></div></form>'
    +'</div></section>'
    +'<section aria-labelledby="meetlib-h3">'
    +'<h3 class="meetlib-f__h" id="meetlib-h3" tabindex="-1">2. Pick a time</h3>'
    +'<div class="meetlib-f__cal">'+cal
    +'<div class="meetlib-f__lock" id="meetlib-lock"><p>Send the form first, then pick your time here.</p></div>'
    +'</div>'+alt+urgent
    +'</section></div>';
  byId("meetlib-cancel").addEventListener("click",close);
  byId("meetlib-f").addEventListener("submit",function(e){e.preventDefault();submitForm(i);});
  var was=fresh?null:remembered(l);
  if(was){showDone(i,was.email||"");}
  else{focusEl(byId("meetlib-h1"));}
}
function getVal(k){var n=host.querySelector('[data-k="'+k+'"]');return n?n.value:"";}
function getRadio(k){var n=host.querySelector('input[name="meetlib_'+k+'"]:checked');return n?n.value:"";}
function showErr(k,msg){
  var e=host.querySelector('[data-err="'+k+'"]');if(e)e.textContent=msg;
  Array.prototype.forEach.call(host.querySelectorAll('[data-k="'+k+'"],input[name="meetlib_'+k+'"]'),function(n){
    if(msg)n.setAttribute("aria-invalid","true");else n.removeAttribute("aria-invalid");
  });
}
function firstControl(k){return host.querySelector('[data-k="'+k+'"],input[name="meetlib_'+k+'"]');}
function submitForm(i){
  var l=LIST[i], bad=null, email="";
  byId("meetlib-alert").textContent="";
  CFG.fields.forEach(function(f){showErr(f.key,"");});
  function fail(k,msg){showErr(k,msg);if(!bad)bad=k;}
  var fd=new FormData();
  fd.append("librarian",i);
  fd.append("librarianSlug",slug(l.name));
  fd.append("meetlib_hp",(host.querySelector('[name="meetlib_hp"]')||{}).value||"");
  CFG.fields.forEach(function(f){
    if(f.type==="file"){
      var fn=host.querySelector('[data-k="'+f.key+'"]');
      if(fn&&fn.files&&fn.files[0]){
        var file=fn.files[0];
        var ext=(file.name.split(".").pop()||"").toLowerCase();
        if(file.size>CFG.maxBytes)fail(f.key,"That file is larger than "+(CFG.maxLabel||"the limit")+".");
        else if(CFG.exts.indexOf(ext)<0)fail(f.key,"Use PDF, Word, text, or an image.");
        fd.append("attachment",file);
      }
      return;
    }
    var v=f.type==="radio"?getRadio(f.key):getVal(f.key);
    if(f.key==="email"){v=v.trim();email=v;}
    if(f.required&&!v.trim())fail(f.key,"This field is required.");
    else if(f.key==="email"&&v){
      var dom=(v.split("@")[1]||"").toLowerCase();
      if(CFG.domains.indexOf(dom)<0)fail(f.key,"Use your "+CFG.domains.join(" or ")+" email.");
    }
    fd.append(f.key,v);
  });
  if(bad){focusEl(firstControl(bad));return;}
  var btn=host.querySelector('#meetlib-f button[type="submit"]');btn.disabled=true;btn.textContent="Sending…";
  var ctrl=window.AbortController?new AbortController():null;
  var timer=setTimeout(function(){if(ctrl)ctrl.abort();},120000);
  var opts={method:"POST",body:fd};
  if(ctrl)opts.signal=ctrl.signal;
  fetch(CFG.submitUrl,opts).then(function(r){
    return r.text().then(function(t){var j=null;try{j=JSON.parse(t);}catch(e){}return{ok:r.ok,status:r.status,j:j};});
  }).then(function(res){
    clearTimeout(timer);
    /* Any 2xx reply means the request was stored, even if something on the site garbled the reply. */
    if(!res.ok){
      var e=new Error(res.j&&res.j.message?res.j.message
        :(res.status===413?"That attachment is too large for this website. Try again with a smaller file, or without it."
        :"Something went wrong sending your details."));
      e.own=true;throw e;
    }
    remember(l,email);
    showDone(i,email);
  }).catch(function(err){
    clearTimeout(timer);
    btn.disabled=false;btn.textContent="Send and pick a time";
    var msg=err&&err.own?err.message
      :(err&&err.name==="AbortError"?"Sending took too long."
      :"Your details could not be sent.")+(err&&err.own?"":" Check your internet connection and try again.");
    var to=l.email||CFG.urgentEmail||"";
    var a=byId("meetlib-alert");
    a.innerHTML=esc(msg)+' If this keeps happening, '
      +(to?'email <a href="mailto:'+esc(to)+'">'+esc(to)+'</a> directly.':'email the librarian directly.');
    focusEl(a);
  });
}
function showDone(i,email){
  var l=LIST[i];if(!l)return;
  var lock=byId("meetlib-lock"), ctl=byId("meetlib-cal-ctl"), alt=byId("meetlib-alt");
  if(lock&&lock.parentNode)lock.parentNode.removeChild(lock);
  if(ctl){ctl.removeAttribute("tabindex");ctl.removeAttribute("aria-hidden");}
  if(alt)alt.hidden=false;
  byId("meetlib-h2").textContent="1. Sent ✓";
  /* The booking is matched to these answers by email address, and Google may prefill a personal account. */
  var same=email
    ? 'Book with <strong>'+esc(email)+'</strong>. If Google fills in a different address, change it to this one, or your answers will not reach '+esc(l.name)+' with your booking.'
    : 'Use the same email address when you book, so your answers reach '+esc(l.name)+' with your booking.';
  byId("meetlib-form-body").innerHTML='<p class="meetlib-f__note">Thanks! Now pick your date and time'+(ctl&&ctl.tagName==="IFRAME"?' on the calendar':'')+'. '+same+'</p>'
    +'<p class="meetlib-f__help">Need a second appointment about something else? <button type="button" class="meetlib-f__cancel" id="meetlib-again">Send a different request</button></p>';
  byId("meetlib-again").addEventListener("click",function(){forget(l);openBooking(i,true);});
  focusEl(byId("meetlib-h3"));
}
})();
</script>
HTML;
}
