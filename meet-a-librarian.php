<?php
/**
 * Plugin Name:       Meet With a Librarian
 * Description:        No-code "Meet With a Librarian" directory: staff manage librarians and their Google appointment booking links under Meet With a Librarian in wp-admin and click Save. Show the directory anywhere with the [meet_a_librarian] shortcode. Replaces LibCal appointments.
 * Version:           2.4.1
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MEETLIB_OPTION', 'meetlib_librarians' );
define( 'MEETLIB_PAGE_OPTION', 'meetlib_page_url' );
define( 'MEETLIB_CPT', 'meetlib_request' );
define( 'MEETLIB_DB_VERSION', 2 );
define( 'MEETLIB_GCAL_OPTION', 'meetlib_gcal_key' );
define( 'MEETLIB_GCAL_MARKER', 'Meet With a Librarian request' );

/**
 * One-time upgrade from 1.x, which stored everything under a legacy key prefix:
 * move the roster + page options, the request CPT, and its post meta to the current names.
 * Runs on init before the CPT is registered.
 */
add_action( 'init', 'meetlib_migrate', 5 );
function meetlib_migrate() {
	if ( (int) get_option( 'meetlib_db_version' ) >= MEETLIB_DB_VERSION ) { return; }
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
		array( 'key' => 'attachment', 'label' => 'Attachment (optional — e.g. assignment instructions)', 'type' => 'file', 'required' => false, 'help' => 'PDF, Word, text, or image. Max 20 MB.' ),
	);
}

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
function meetlib_max_upload_bytes() { return 20 * 1024 * 1024; }

/** Normalize a Google appointment-schedule link to its inline embed URL, or '' if not embeddable. */
function meetlib_embed_url( $booking ) {
	$booking = trim( (string) $booking );
	if ( '' === $booking ) { return ''; }
	// Only Google Calendar appointment-schedule pages support the ?gv=true inline embed.
	if ( false === strpos( $booking, 'calendar.google.com/calendar/appointments' ) ) { return ''; }
	if ( false !== strpos( $booking, 'gv=true' ) ) { return $booking; }
	return $booking . ( ( false === strpos( $booking, '?' ) ) ? '?gv=true' : '&gv=true' );
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
			'callback'            => function ( WP_REST_Request $req ) {
				$body = $req->get_json_params();
				if ( ! is_array( $body ) || ! isset( $body['librarians'] ) || ! is_array( $body['librarians'] ) ) {
					return new WP_Error( 'meetlib_bad_data', 'Invalid librarian data.', array( 'status' => 400 ) );
				}
				update_option( MEETLIB_OPTION, meetlib_sanitize( $body['librarians'] ) );
				if ( isset( $body['pageUrl'] ) ) {
					update_option( MEETLIB_PAGE_OPTION, esc_url_raw( trim( (string) $body['pageUrl'] ) ) );
				}
				return rest_ensure_response( array( 'ok' => true ) );
			},
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

/* ------------------------------------------------------------------ *
 *  Booking-form submission handler
 * ------------------------------------------------------------------ */

function meetlib_handle_request( WP_REST_Request $req ) {
	// 1. Anti-bot: nonce + honeypot.
	if ( ! wp_verify_nonce( $req->get_param( '_wpnonce' ), 'wp_rest' ) ) {
		return new WP_Error( 'meetlib_nonce', 'Your session expired. Please reload the page and try again.', array( 'status' => 403 ) );
	}
	if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) { // honeypot must stay empty
		return new WP_Error( 'meetlib_bot', 'Submission blocked.', array( 'status' => 400 ) );
	}

	// 2. Resolve the target librarian by roster index, cross-checked against the name slug so a
	//    roster edited while the patron had the page open can't route the request to someone else.
	$libs = meetlib_get();
	$idx  = (int) $req->get_param( 'librarian' );
	$want = meetlib_slug( (string) $req->get_param( 'librarianSlug' ) );
	if ( '' !== $want && ( ! isset( $libs[ $idx ] ) || meetlib_slug( $libs[ $idx ]['name'] ) !== $want ) ) {
		$idx = -1;
		foreach ( $libs as $j => $l ) {
			if ( meetlib_slug( $l['name'] ) === $want ) { $idx = $j; break; }
		}
	}
	if ( ! isset( $libs[ $idx ] ) || empty( $libs[ $idx ]['booking'] ) ) {
		return new WP_Error( 'meetlib_unavailable', 'That librarian is not available for booking.', array( 'status' => 400 ) );
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

	// 5. Optional file upload.
	$attach_url = '';
	$files      = $req->get_file_params();
	if ( ! empty( $files['attachment'] ) && ! empty( $files['attachment']['name'] ) && UPLOAD_ERR_NO_FILE !== $files['attachment']['error'] ) {
		$file = $files['attachment'];
		if ( $file['size'] > meetlib_max_upload_bytes() ) {
			return new WP_Error( 'meetlib_size', 'That file is larger than 20 MB. Please attach a smaller file.', array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, meetlib_upload_exts(), true ) ) {
			return new WP_Error( 'meetlib_type', 'That file type is not allowed. Use PDF, Word, text, or an image.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$moved = wp_handle_upload( $file, array(
			'test_form' => false,
			'mimes'     => meetlib_upload_mimes(),
		) );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'meetlib_upload', 'The attachment could not be saved. Please try again without it.', array( 'status' => 400 ) );
		}
		$attach_url = $moved['url'];
	}

	// 6. Persist the record.
	$post_id = wp_insert_post( array(
		'post_type'   => MEETLIB_CPT,
		'post_status' => 'publish',
		'post_title'  => $data['email'] . ' — ' . $lib['name'],
	), true );
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'meetlib_save', 'Something went wrong. Please email the librarian directly.', array( 'status' => 500 ) );
	}
	$meta = array(
		'_meetlib_email' => $data['email'],
		'_meetlib_help' => $data['help'], '_meetlib_course' => $data['course'], '_meetlib_due' => $data['due'],
		'_meetlib_meeting' => $data['meeting'], '_meetlib_librarian' => $lib['name'], '_meetlib_attach_url' => $attach_url,
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }

	// 7. Optional: add the answers to the patron's event on the librarian's Google Calendar.
	//    The patron books AFTER sending the form, so this first look normally finds nothing;
	//    the scheduled retries (meetlib_gcal_retry) pick the booking up once it exists.
	$calendar = meetlib_librarian_email( $lib );
	$when     = '';
	$gcal     = meetlib_gcal_annotate( $calendar, $data, $attach_url, $when, time() );
	update_post_meta( $post_id, '_meetlib_calendar', $calendar );
	update_post_meta( $post_id, '_meetlib_when', $when );
	update_post_meta( $post_id, '_meetlib_gcal_tries', 0 );
	if ( 'not_found' === $gcal ) {
		$delays = meetlib_gcal_retry_delays();
		wp_schedule_single_event( time() + $delays[0], 'meetlib_gcal_retry', array( $post_id ) );
		$gcal = 'pending';
	}
	update_post_meta( $post_id, '_meetlib_gcal', $gcal );

	// 8. Email the librarian (best-effort; failure does not fail the request). When the integration
	//    is watching the calendar, hold the email until the booking appears so the librarian is never
	//    told about a request that no appointment follows; meetlib_gcal_retry sends it then.
	if ( 'pending' !== $gcal ) {
		meetlib_notify( $post_id, $calendar, $lib['name'], $data, $attach_url, $gcal, $when );
	}

	// 9. Success. embedUrl/booking are kept for pages still running the 2.0 script from a cache.
	return rest_ensure_response( array(
		'ok'       => true,
		'calendar' => $gcal,
		'embedUrl' => meetlib_embed_url( $lib['booking'] ),
		'booking'  => esc_url_raw( $lib['booking'] ),
	) );
}

/**
 * Email the librarian the request. Sent once per request (`_meetlib_notified`). The wording depends on
 * what the plugin knows: `added` = booking found (includes the time); `not_found` = the integration
 * watched for a day and no booking matched; anything else = no integration, so a booking is expected.
 * Skipped (with a log) if the librarian has no email on file.
 */
function meetlib_notify( $post_id, $to, $lib_name, $data, $attach_url, $gcal, $when = '' ) {
	if ( ! $to ) {
		error_log( 'meet-a-librarian: no email for librarian "' . $lib_name . '"; request stored as CPT only.' );
		return;
	}
	if ( $post_id && get_post_meta( $post_id, '_meetlib_notified', true ) ) { return; }

	if ( 'added' === $gcal && '' !== $when ) {
		$subject = 'Appointment booked: ' . $when . ' — ' . $data['email'];
		$intro   = array( 'Booked for: ' . $when, 'Patron: ' . $data['email'] . ' (their name is on the Google booking)' );
		$outro   = array( 'These details are also in the appointment\'s description on your Google Calendar.' );
	} elseif ( 'not_found' === $gcal ) {
		$subject = 'Appointment request, no booking found — ' . $data['email'];
		$intro   = array( 'Patron: ' . $data['email'] );
		$outro   = array(
			'This patron filled in the request form a day ago, but no matching booking has appeared on your calendar.',
			'They may not have picked a time, or they may have booked with a different email address.',
		);
	} else {
		$subject = 'Appointment request — ' . $data['email'];
		$intro   = array( 'Patron: ' . $data['email'] . ' (their name is on the Google booking)' );
		$outro   = array( 'The patron picks a time on your Google appointment page right after sending this; expect a booking shortly.' );
	}
	$lines = array_merge( $intro, array(
		'',
		'How can I help you?',
		$data['help'],
		'',
		'Course: ' . ( '' !== $data['course'] ? $data['course'] : '(none given)' ),
		'Assignment due: ' . ( '' !== $data['due'] ? $data['due'] : '(none given)' ),
		'Meeting preference: ' . $data['meeting'],
		'Attachment: ' . ( $attach_url ? $attach_url : '(none)' ),
		'',
	), $outro );
	$headers = array( 'Reply-To: ' . $data['email'] );
	if ( wp_mail( $to, $subject, implode( "\n", $lines ), $headers ) ) {
		if ( $post_id ) { update_post_meta( $post_id, '_meetlib_notified', current_time( 'mysql' ) ); }
	} else {
		error_log( 'meet-a-librarian: wp_mail failed for request from ' . $data['email'] . '; stored as CPT.' );
	}
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
		return new WP_Error( 'meetlib_gcal_key', 'The saved service-account private key could not be used.' );
	}
	$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'timeout' => 10,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $head . '.' . $claims . '.' . meetlib_b64url( $sig ),
		),
	) );
	if ( is_wp_error( $res ) ) { return $res; }
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['access_token'] ) ) {
		return new WP_Error( 'meetlib_gcal_auth', 'Google rejected the service-account key.' );
	}
	$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
	set_transient( $cache, $body['access_token'], max( 60, $ttl - 300 ) );
	return $body['access_token'];
}

/** Call the Calendar v3 API. Returns the decoded JSON array or WP_Error (data.status = HTTP code). */
function meetlib_gcal_api( $method, $path, $query = array(), $body = null ) {
	$tok = meetlib_gcal_token();
	if ( is_wp_error( $tok ) ) { return $tok; }
	$url = 'https://www.googleapis.com/calendar/v3' . $path;
	if ( $query ) { $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); }
	$args = array(
		'method'  => $method,
		'timeout' => 10,
		'headers' => array( 'Authorization' => 'Bearer ' . $tok ),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) { return $res; }
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'meetlib_gcal_http', 'Google Calendar returned HTTP ' . $code . '.', array( 'status' => $code ) );
	}
	return is_array( $data ) ? $data : array();
}

function meetlib_gcal_events_path( $calendar_id ) {
	return '/calendars/' . rawurlencode( $calendar_id ) . '/events';
}

/** The answers as HTML for the event description (Google renders appointment descriptions as HTML). */
function meetlib_gcal_details_html( $data, $attach_url ) {
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
	if ( $attach_url ) {
		$h .= '<br><b>Attachment:</b> <a href="' . esc_url( $attach_url ) . '">' . esc_html( $attach_url ) . '</a>';
	}
	return $h;
}

/**
 * Find the patron's upcoming event on the librarian's calendar and append the answers.
 * Returns: added | not_found | error | no_calendar | off. On `added`, $when receives the
 * appointment's start as readable local text (e.g. "Tuesday, November 11 at 2:00 pm").
 *
 * $since is the submission time (Unix). Patrons can only book after sending the form, so an event
 * created before then is never their booking. This, plus skipping the calendar owner's own guest
 * entry, keeps a librarian testing with their own address from annotating their other meetings.
 */
function meetlib_gcal_annotate( $calendar_id, $data, $attach_url, &$when = '', $since = 0 ) {
	if ( ! meetlib_gcal_enabled() ) { return 'off'; }
	if ( '' === (string) $calendar_id ) { return 'no_calendar'; }
	if ( 0 === strcasecmp( (string) $calendar_id, (string) $data['email'] ) ) { return 'not_found'; }
	$earliest = ( $since ? (int) $since : time() ) - 5 * MINUTE_IN_SECONDS; // slack for clock skew

	$path = meetlib_gcal_events_path( $calendar_id );
	$list = meetlib_gcal_api( 'GET', $path, array(
		'q'            => $data['email'],
		'timeMin'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'updatedMin'   => gmdate( 'Y-m-d\TH:i:s\Z', time() - 6 * HOUR_IN_SECONDS ),
		'singleEvents' => 'true',
		'maxResults'   => 50,
	) );
	if ( is_wp_error( $list ) ) {
		error_log( 'meet-a-librarian: calendar lookup failed for ' . $calendar_id . ': ' . $list->get_error_message() );
		return 'error';
	}

	// Newest-created upcoming event with the patron as a guest that we haven't already annotated.
	$best = null;
	$items = isset( $list['items'] ) && is_array( $list['items'] ) ? $list['items'] : array();
	foreach ( $items as $ev ) {
		if ( isset( $ev['status'] ) && 'cancelled' === $ev['status'] ) { continue; }
		$desc = isset( $ev['description'] ) ? (string) $ev['description'] : '';
		if ( false !== strpos( $desc, MEETLIB_GCAL_MARKER ) ) { continue; }
		$created = isset( $ev['created'] ) ? (string) $ev['created'] : '';
		$ts      = '' !== $created ? strtotime( $created ) : false;
		if ( ! $ts || $ts < $earliest ) { continue; }
		$guest = false;
		foreach ( ( isset( $ev['attendees'] ) && is_array( $ev['attendees'] ) ) ? $ev['attendees'] : array() as $a ) {
			if ( ! empty( $a['self'] ) || ! empty( $a['organizer'] ) ) { continue; }
			if ( isset( $a['email'] ) && 0 === strcasecmp( $a['email'], $data['email'] ) ) { $guest = true; break; }
		}
		if ( ! $guest ) { continue; }
		if ( null === $best || strcmp( $created, (string) $best['created'] ) > 0 ) {
			$best            = $ev;
			$best['created'] = $created;
		}
	}
	if ( ! $best ) { return 'not_found'; }

	$desc  = isset( $best['description'] ) ? trim( (string) $best['description'] ) : '';
	$patch = meetlib_gcal_api(
		'PATCH',
		$path . '/' . rawurlencode( $best['id'] ),
		array( 'sendUpdates' => 'none' ),
		array( 'description' => ( '' !== $desc ? $desc . '<br><br>' : '' ) . meetlib_gcal_details_html( $data, $attach_url ) )
	);
	if ( is_wp_error( $patch ) ) {
		error_log( 'meet-a-librarian: could not update event on ' . $calendar_id . ': ' . $patch->get_error_message() );
		return 'error';
	}
	if ( ! empty( $best['start']['dateTime'] ) ) {
		$ts   = strtotime( $best['start']['dateTime'] );
		$when = $ts ? wp_date( 'l, F j \a\t g:i a', $ts ) : (string) $best['start']['dateTime'];
	} elseif ( ! empty( $best['start']['date'] ) ) {
		$ts   = strtotime( $best['start']['date'] );
		$when = $ts ? wp_date( 'l, F j', $ts ) : (string) $best['start']['date'];
	}
	return 'added';
}

/** Seconds after the form submission at which to look for the booking. Patrons book right after sending. */
function meetlib_gcal_retry_delays() {
	return array( 5 * MINUTE_IN_SECONDS, 20 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, DAY_IN_SECONDS );
}

/**
 * Scheduled retries (see meetlib_gcal_retry_delays): annotate the booking once it exists, then send
 * the held librarian email. If the delays run out with no match, the librarian gets a clearly
 * labelled "no booking found" email instead, so answers aren't lost when a patron books with a
 * different address.
 */
add_action( 'meetlib_gcal_retry', 'meetlib_gcal_retry', 10, 1 );
function meetlib_gcal_retry( $post_id ) {
	$post_id = (int) $post_id;
	if ( MEETLIB_CPT !== get_post_type( $post_id ) || 'pending' !== get_post_meta( $post_id, '_meetlib_gcal', true ) ) { return; }
	$data = array();
	foreach ( array( 'email', 'help', 'course', 'due', 'meeting' ) as $k ) {
		$data[ $k ] = (string) get_post_meta( $post_id, '_meetlib_' . $k, true );
	}
	$calendar = (string) get_post_meta( $post_id, '_meetlib_calendar', true );
	$attach   = (string) get_post_meta( $post_id, '_meetlib_attach_url', true );
	$when     = '';
	$status   = meetlib_gcal_annotate( $calendar, $data, $attach, $when, (int) get_post_time( 'U', true, $post_id ) );
	$tries    = (int) get_post_meta( $post_id, '_meetlib_gcal_tries', true ) + 1;
	$delays   = meetlib_gcal_retry_delays();
	update_post_meta( $post_id, '_meetlib_gcal_tries', $tries );
	if ( in_array( $status, array( 'not_found', 'error' ), true ) && isset( $delays[ $tries ] ) ) {
		// Delays are measured from the submission, so wait only the difference.
		wp_schedule_single_event( time() + max( MINUTE_IN_SECONDS, $delays[ $tries ] - $delays[ $tries - 1 ] ), 'meetlib_gcal_retry', array( $post_id ) );
		update_post_meta( $post_id, '_meetlib_gcal', 'pending' );
		return;
	}
	update_post_meta( $post_id, '_meetlib_gcal', $status );
	update_post_meta( $post_id, '_meetlib_when', $when );
	meetlib_notify( $post_id, $calendar, (string) get_post_meta( $post_id, '_meetlib_librarian', true ), $data, $attach, $status, $when );
}

/** Human-readable label for a request's `_meetlib_gcal` status. */
function meetlib_gcal_status_label( $status ) {
	$map = array(
		'added'       => 'Booking found; answers added to the event and the librarian emailed',
		'pending'     => 'Waiting for the booking to appear on the calendar; the librarian is emailed once it does',
		'not_found'   => 'No matching booking within a day; the librarian was emailed a "no booking found" note',
		'error'       => 'Calendar not reachable (not shared with the integration?); emailed without waiting for a booking',
		'no_calendar' => 'Librarian has no email on file; not added, no email sent',
		'off'         => 'Calendar integration off; emailed without waiting for a booking',
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : '';
}

/** Admin screen: save/remove the service-account key and check each librarian's calendar access. */
function meetlib_gcal_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$key  = meetlib_gcal_key();
	$self = admin_url( 'admin.php?page=meet-a-librarian-calendar' );

	echo '<div class="wrap"><h1>Calendar Integration</h1>';
	$msgs = array(
		'saved'   => array( 'success', 'Key saved.' ),
		'removed' => array( 'success', 'Key removed. The plugin is now email-only.' ),
		'invalid' => array( 'error', 'That is not a valid service-account JSON key. Paste the whole file you downloaded from Google Cloud.' ),
	);
	$m = isset( $_GET['meetlib_msg'] ) ? sanitize_key( $_GET['meetlib_msg'] ) : '';
	if ( isset( $msgs[ $m ] ) ) {
		echo '<div class="notice notice-' . esc_attr( $msgs[ $m ][0] ) . '"><p>' . esc_html( $msgs[ $m ][1] ) . '</p></div>';
	}
	if ( ! function_exists( 'openssl_sign' ) ) {
		echo '<div class="notice notice-error"><p>This server\'s PHP has no OpenSSL extension, so the integration cannot run.</p></div>';
	}

	echo '<div style="max-width:760px">';
	echo '<p><strong>Optional.</strong> Without it, the plugin emails each request to the librarian, as before. With it, the patron\'s answers are also added to the description of the appointment they booked on the librarian\'s Google Calendar.</p>';

	if ( $key ) {
		echo '<h2>Status: on</h2>';
		echo '<p>Service account: <code>' . esc_html( $key['client_email'] ) . '</code></p>';
		echo '<h3>What each librarian does (once)</h3><ol>'
			. '<li>Open Google Calendar &rarr; <strong>Settings</strong> (gear icon).</li>'
			. '<li>Under <strong>Settings for my calendars</strong>, click their own calendar (their name).</li>'
			. '<li>Under <strong>Share with specific people or groups</strong>, click <strong>Add people and groups</strong>, paste the service-account address above, and choose <strong>Make changes to events</strong>.</li>'
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
					} elseif ( isset( $r['accessRole'] ) && in_array( $r['accessRole'], array( 'writer', 'owner' ), true ) ) {
						$state = '✓ Ready';
					} else {
						$state = 'Shared, but needs "Make changes to events"';
					}
				}
				echo '<tr><td>' . esc_html( $lib['name'] ) . '</td><td>' . esc_html( $cal ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'meetlib_check', '1', $self ), 'meetlib_gcal_check' ) ) . '">Check each librarian\'s calendar access</a></p>';
	} else {
		echo '<h2>Status: off</h2>';
		echo '<h3>Set up (site admin, once)</h3><ol>'
			. '<li>In the Google Cloud console, create (or pick) a project and enable the <strong>Google Calendar API</strong>.</li>'
			. '<li>Create a <strong>service account</strong> (no roles needed), open it &rarr; <strong>Keys</strong> &rarr; <strong>Add key</strong> &rarr; <strong>JSON</strong>.</li>'
			. '<li>Paste the downloaded file\'s contents below and save. This page will then show the address each librarian shares their calendar with.</li>'
			. '</ol><p>No Google Workspace admin access is needed: the service account can only see calendars that librarians share with it.</p>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="meetlib_gcal_save">';
	wp_nonce_field( 'meetlib_gcal_save' );
	echo '<p><label for="meetlib-gcal-key"><strong>' . ( $key ? 'Replace the' : 'Service-account' ) . ' JSON key</strong></label><br>';
	echo '<textarea id="meetlib-gcal-key" name="meetlib_gcal_key" rows="6" class="large-text code" autocomplete="off" spellcheck="false" placeholder="{ &quot;type&quot;: &quot;service_account&quot;, ... }"></textarea></p>';
	echo '<p class="description">The key is stored in the WordPress database and never shown again. Anyone with database access could read it; it can only reach calendars shared with the service account.</p>';
	if ( $key ) {
		echo '<p><label><input type="checkbox" name="meetlib_gcal_remove" value="1"> Remove the key and turn the integration off</label></p>';
	}
	submit_button( 'Save' );
	echo '</form></div></div>';
}

add_action( 'admin_post_meetlib_gcal_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.', 403 ); }
	check_admin_referer( 'meetlib_gcal_save' );
	$back = admin_url( 'admin.php?page=meet-a-librarian-calendar' );
	$old  = meetlib_gcal_key();

	if ( ! empty( $_POST['meetlib_gcal_remove'] ) ) {
		delete_option( MEETLIB_GCAL_OPTION );
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
	wp_safe_redirect( add_query_arg( 'meetlib_msg', 'saved', $back ) );
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

	echo meetlib_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
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
      inp.oninput=function(){lib[f.k]=inp.value;if(f.k==="name"&&wb){var nb=widgetBox(lib);card.replaceChild(nb,wb);wb=nb;}};
      cell.appendChild(lab);cell.appendChild(inp);grid.appendChild(cell);
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
document.getElementById("meetlib-save").onclick=function(){
  flash("Saving…");
  fetch(MEETLIB.root,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":MEETLIB.nonce},body:JSON.stringify({librarians:libs,pageUrl:pageUrl})})
    .then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})
    .then(function(){saved=clone(libs);savedPage=pageUrl;render();flash("Saved — it's live on the website. ✓");})
    .catch(function(){flash("Save failed — please try again or reload the page.",true);});
};

pageUrl=savedPage=(MEETLIB.pageUrl||"");
pageInput.value=pageUrl;
fetch(MEETLIB.root).then(function(r){return r.json();}).then(function(d){libs=Array.isArray(d)?d:[];saved=clone(libs);render();}).catch(function(){render();});
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
		'meetlib_when'      => 'Appointment',
		'meetlib_meeting'   => 'Meeting',
		'meetlib_email'     => 'Email',
		'date'          => 'Submitted',
	);
} );

add_action( 'manage_' . MEETLIB_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	$map = array( 'meetlib_librarian' => '_meetlib_librarian', 'meetlib_when' => '_meetlib_when', 'meetlib_meeting' => '_meetlib_meeting', 'meetlib_email' => '_meetlib_email' );
	if ( isset( $map[ $col ] ) ) {
		echo esc_html( get_post_meta( $post_id, $map[ $col ], true ) );
	}
}, 10, 2 );

/** Show all answers + attachment in the single-request edit screen (read-only). */
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
	$att = get_post_meta( $post->ID, '_meetlib_attach_url', true );
	if ( $att ) {
		echo '<tr><th>Attachment</th><td><a href="' . esc_url( $att ) . '" target="_blank" rel="noopener">Download</a></td></tr>';
	}
	$gcal = meetlib_gcal_status_label( get_post_meta( $post->ID, '_meetlib_gcal', true ) );
	if ( '' !== $gcal ) {
		echo '<tr><th>Calendar invite</th><td>' . esc_html( $gcal ) . '</td></tr>';
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
	$config = array(
		'listUrl'     => $root,
		'submitUrl'   => $submit,
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'fields'      => meetlib_form_fields(),
		'maxBytes'    => meetlib_max_upload_bytes(),
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
  mount.innerHTML='<p class="meetlib__error">The librarian directory is temporarily unavailable. Please email library@wheatoncollege.edu.</p>';
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
  if(f.type==="radio"){
    var opts=(f.options||[]).map(function(o,n){
      return '<label class="meetlib-f__radio"><input type="radio" name="meetlib_'+f.key+'" value="'+esc(o)+'"'+rq+'> '+esc(o)+'</label>';
    }).join("");
    return '<fieldset class="meetlib-f__field" id="'+id+'"'+desc+'><legend>'+esc(f.label)+req+'</legend>'+opts+help+err+'</fieldset>';
  }
  var ctl;
  if(f.type==="textarea"){ctl='<textarea id="'+id+'" data-k="'+f.key+'"'+rq+desc+'></textarea>';}
  else if(f.type==="file"){ctl='<input type="file" id="'+id+'" data-k="'+f.key+'" accept="'+esc((CFG.exts||[]).map(function(x){return "."+x;}).join(","))+'"'+desc+'>';}
  else{
    var ac={first:"given-name",last:"family-name",email:"email"}[f.key];
    ctl='<input type="'+(f.type==="email"?"email":"text")+'" id="'+id+'" data-k="'+f.key+'"'+(ac?' autocomplete="'+ac+'"':'')+rq+desc+'>';
  }
  return '<div class="meetlib-f__field"><label for="'+id+'">'+esc(f.label)+req+'</label>'+ctl+help+err+'</div>';
}
function focusEl(n){if(n){n.focus();if(n.scrollIntoView)n.scrollIntoView({behavior:"smooth",block:"start"});}}
function close(){host.innerHTML="";host.classList.remove("meetlib-f__wide");if(opener&&document.body.contains(opener))opener.focus();}
/* One screen, two panels: the short intake form and the librarian's Google scheduler. The
   scheduler sits behind a translucent lock until the form is sent, so patrons can see which
   dates have openings but cannot book without telling the librarian what they need. Booking
   happens inside Google's iframe, so form-first is the only order the plugin can enforce. */
function openBooking(i){
  var l=LIST[i];if(!l)return;
  var embed=l.embed||"";
  var cal=embed
    ? '<iframe id="meetlib-cal-ctl" src="'+esc(embed)+'" title="'+esc("Available appointment times with "+l.name)+'" tabindex="-1" aria-hidden="true"></iframe>'
    : '<p><a id="meetlib-cal-ctl" class="meetlib-f__submit" href="'+esc(l.booking)+'" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">Open '+esc(l.name)+'’s booking page<span class="meetlib-f__sr"> (opens in a new tab)</span></a></p>';
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
    +'<div class="meetlib-f__alert" id="meetlib-alert" role="alert"></div>'
    +'<p class="meetlib-f__help">Fields marked with <span class="meetlib-f__req">*</span> are required.</p>'
    +'<form id="meetlib-f" novalidate>'+CFG.fields.map(fieldHtml).join("")
    +'<div aria-hidden="true" style="position:absolute;left:-9999px"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
    +'<div class="meetlib-f__actions"><button type="submit" class="meetlib-f__submit">Send and pick a time</button>'
    +'<button type="button" class="meetlib-f__cancel" id="meetlib-cancel">Cancel</button></div></form>'
    +'</div></section>'
    +'<section aria-labelledby="meetlib-h3">'
    +'<h3 class="meetlib-f__h" id="meetlib-h3" tabindex="-1">2. Pick a time</h3>'
    +'<div class="meetlib-f__cal">'+cal
    +'<div class="meetlib-f__lock" id="meetlib-lock"><p>Send the form first, then pick your time here.</p></div>'
    +'</div>'+urgent
    +'</section></div>';
  byId("meetlib-cancel").addEventListener("click",close);
  byId("meetlib-f").addEventListener("submit",function(e){e.preventDefault();submitForm(i);});
  focusEl(byId("meetlib-h1"));
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
  var l=LIST[i], bad=null;
  byId("meetlib-alert").textContent="";
  CFG.fields.forEach(function(f){showErr(f.key,"");});
  function fail(k,msg){showErr(k,msg);if(!bad)bad=k;}
  var fd=new FormData();
  fd.append("librarian",i);
  fd.append("librarianSlug",slug(l.name));
  fd.append("website",(host.querySelector('[name="website"]')||{}).value||"");
  fd.append("_wpnonce",CFG.nonce);
  CFG.fields.forEach(function(f){
    if(f.type==="file"){
      var fn=host.querySelector('[data-k="'+f.key+'"]');
      if(fn&&fn.files&&fn.files[0]){
        var file=fn.files[0];
        var ext=(file.name.split(".").pop()||"").toLowerCase();
        if(file.size>CFG.maxBytes)fail(f.key,"That file is larger than 20 MB.");
        else if(CFG.exts.indexOf(ext)<0)fail(f.key,"Use PDF, Word, text, or an image.");
        fd.append("attachment",file);
      }
      return;
    }
    var v=f.type==="radio"?getRadio(f.key):getVal(f.key);
    if(f.required&&!v.trim())fail(f.key,"This field is required.");
    else if(f.key==="email"&&v){
      var dom=(v.split("@")[1]||"").toLowerCase();
      if(CFG.domains.indexOf(dom)<0)fail(f.key,"Use your "+CFG.domains.join(" or ")+" email.");
    }
    fd.append(f.key,v);
  });
  if(bad){focusEl(firstControl(bad));return;}
  var btn=host.querySelector('#meetlib-f button[type="submit"]');btn.disabled=true;btn.textContent="Sending…";
  fetch(CFG.submitUrl,{method:"POST",body:fd}).then(function(r){
    return r.json().then(function(j){return{ok:r.ok,j:j};},function(){return{ok:false,j:null};});
  }).then(function(res){
    if(!res.ok){throw new Error(res.j&&res.j.message?res.j.message:"Something went wrong sending your details.");}
    showDone(l,res.j);
  }).catch(function(err){
    btn.disabled=false;btn.textContent="Send and pick a time";
    var a=byId("meetlib-alert");
    a.innerHTML=esc(err.message)+' If this keeps happening, '
      +(l.email?'email <a href="mailto:'+esc(l.email)+'">'+esc(l.email)+'</a> directly.':'email the librarian directly.');
    focusEl(a);
  });
}
function showDone(l,resp){
  var lock=byId("meetlib-lock"), ctl=byId("meetlib-cal-ctl");
  if(lock&&lock.parentNode)lock.parentNode.removeChild(lock);
  if(ctl){ctl.removeAttribute("tabindex");ctl.removeAttribute("aria-hidden");}
  byId("meetlib-h2").textContent="1. Sent ✓";
  byId("meetlib-form-body").innerHTML='<p class="meetlib-f__note">Thanks! Now pick your date and time'+(ctl&&ctl.tagName==="IFRAME"?' on the calendar':'')+'. '
    +'Your answers reach <strong>'+esc(l.name)+'</strong> with your booking, so use the same email address when you book.</p>';
  focusEl(byId("meetlib-h3"));
}
})();
</script>
HTML;
}
