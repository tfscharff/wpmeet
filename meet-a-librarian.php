<?php
/**
 * Plugin Name:       Meet With a Librarian
 * Description:        No-code "Meet With a Librarian" directory: staff manage librarians and their Google appointment booking links under Meet With a Librarian in wp-admin and click Save. Show the directory anywhere with the [meet_a_librarian] shortcode. Replaces LibCal appointments.
 * Version:           2.0.0
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
		array( 'key' => 'first',   'label' => 'First name',                                    'type' => 'text',     'required' => true,  'help' => '' ),
		array( 'key' => 'last',    'label' => 'Last name',                                     'type' => 'text',     'required' => true,  'help' => '' ),
		array( 'key' => 'email',   'label' => 'Email',                                         'type' => 'email',    'required' => true,  'help' => 'Use your wheatoncollege.edu or wheatonma.edu address.' ),
		array( 'key' => 'help',    'label' => 'How can I help you?',                           'type' => 'textarea', 'required' => true,  'help' => '' ),
		array( 'key' => 'course',  'label' => 'Is this related to a course? If so, which one?', 'type' => 'text',     'required' => true,  'help' => '' ),
		array( 'key' => 'due',     'label' => 'If related to a course, when is the assignment due?', 'type' => 'text', 'required' => false, 'help' => '' ),
		array( 'key' => 'meeting', 'label' => 'Would you like to meet in person or on Zoom?',  'type' => 'radio',    'required' => true,  'help' => '',
			'options' => array( 'In person in my office', 'In person in a fully accessible space', 'On Zoom' ) ),
		array( 'key' => 'attachment', 'label' => 'Attachment (optional — e.g. assignment instructions)', 'type' => 'file', 'required' => false, 'help' => 'PDF, Word, text, or image. Max 20 MB.' ),
	);
}

function meetlib_email_domains() { return array( 'wheatoncollege.edu', 'wheatonma.edu' ); }
function meetlib_upload_exts()   { return array( 'pdf', 'doc', 'docx', 'txt', 'rtf', 'png', 'jpg', 'jpeg', 'gif' ); }
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
				return rest_ensure_response( meetlib_get() );
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

	// 2. Resolve the target librarian by roster index.
	$libs = meetlib_get();
	$idx  = (int) $req->get_param( 'librarian' );
	if ( ! isset( $libs[ $idx ] ) || empty( $libs[ $idx ]['booking'] ) ) {
		return new WP_Error( 'meetlib_unavailable', 'That librarian is not available for booking.', array( 'status' => 400 ) );
	}
	$lib = $libs[ $idx ];

	// 3. Collect + sanitize text answers.
	$data = array(
		'first'   => sanitize_text_field( (string) $req->get_param( 'first' ) ),
		'last'    => sanitize_text_field( (string) $req->get_param( 'last' ) ),
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
	$allowed_meetings = array( 'In person in my office', 'In person in a fully accessible space', 'On Zoom' );
	if ( ! in_array( $data['meeting'], $allowed_meetings, true ) ) {
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
			'mimes'     => array(
				'pdf'      => 'application/pdf',
				'doc'      => 'application/msword',
				'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'txt'      => 'text/plain',
				'rtf'      => 'application/rtf',
				'png'      => 'image/png',
				'jpg|jpeg' => 'image/jpeg',
				'gif'      => 'image/gif',
			),
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
		'post_title'  => $data['first'] . ' ' . $data['last'] . ' — ' . $lib['name'],
	), true );
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'meetlib_save', 'Something went wrong. Please email the librarian directly.', array( 'status' => 500 ) );
	}
	$meta = array(
		'_meetlib_first' => $data['first'], '_meetlib_last' => $data['last'], '_meetlib_email' => $data['email'],
		'_meetlib_help' => $data['help'], '_meetlib_course' => $data['course'], '_meetlib_due' => $data['due'],
		'_meetlib_meeting' => $data['meeting'], '_meetlib_librarian' => $lib['name'], '_meetlib_attach_url' => $attach_url,
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }

	// 7. Email the librarian (best-effort; failure does not fail the request).
	meetlib_notify( $lib, $data, $attach_url );

	// 8. Success — hand back the embed URL for the Google step.
	return rest_ensure_response( array(
		'ok'       => true,
		'embedUrl' => meetlib_embed_url( $lib['booking'] ),
		'booking'  => esc_url_raw( $lib['booking'] ),
	) );
}

/** Email the librarian a formatted copy of the request. Skipped (with a log) if the librarian has no email on file. */
function meetlib_notify( $lib, $data, $attach_url ) {
	$to = meetlib_librarian_email( $lib );
	if ( ! $to ) {
		error_log( 'meet-a-librarian: no email for librarian "' . $lib['name'] . '"; request stored as CPT only.' );
		return;
	}
	$subject = 'Appointment request — ' . $data['first'] . ' ' . $data['last'];
	$lines   = array(
		'From: ' . $data['first'] . ' ' . $data['last'] . ' <' . $data['email'] . '>',
		'',
		'How can I help you?',
		$data['help'],
		'',
		'Course: ' . ( '' !== $data['course'] ? $data['course'] : '(none given)' ),
		'Assignment due: ' . ( '' !== $data['due'] ? $data['due'] : '(none given)' ),
		'Meeting preference: ' . $data['meeting'],
		'Attachment: ' . ( $attach_url ? $attach_url : '(none)' ),
		'',
		'The patron will pick a date/time on your Google appointment page next.',
	);
	$headers = array( 'Reply-To: ' . $data['first'] . ' ' . $data['last'] . ' <' . $data['email'] . '>' );
	if ( ! wp_mail( $to, $subject, implode( "\n", $lines ), $headers ) ) {
		error_log( 'meet-a-librarian: wp_mail failed for request from ' . $data['email'] . '; stored as CPT.' );
	}
}

/** Librarian notification address from the roster `email` field, or '' if unknown/invalid. */
function meetlib_librarian_email( $lib ) {
	if ( ! empty( $lib['email'] ) && is_email( $lib['email'] ) ) { return $lib['email']; }
	return '';
}

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
} );

function meetlib_admin_page() {
	$root  = esc_url_raw( rest_url( 'meet-a-librarian/v1/librarians' ) );
	$nonce = wp_create_nonce( 'wp_rest' );

	echo '<div class="wrap"><h1>Meet With a Librarian</h1>';
	echo '<p style="max-width:680px;color:#555">Add each librarian and paste their Google appointment <strong>booking link</strong>, then click <strong>Save &amp; publish</strong>. Changes go live on the website immediately — no files, no code. Show the directory on any page with the shortcode <code>[meet_a_librarian]</code>.</p>';

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
    var rm=el("button","b ghost tiny");rm.type="button";rm.textContent="remove";rm.onclick=function(){libs.splice(i,1);render();};card.appendChild(rm);
    var grid=el("div","grid");
    FIELDS.forEach(function(f){
      var cell=el("div",f.full?"full":"");
      var lab=el("label");lab.textContent=f.label;
      var inp=el("input");inp.type="text";inp.value=lib[f.k]||"";
      inp.oninput=function(){lib[f.k]=inp.value;if(f.k==="name")render();};
      cell.appendChild(lab);cell.appendChild(inp);grid.appendChild(cell);
    });
    card.appendChild(grid);
    card.appendChild(widgetBox(lib));
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
		'meetlib_meeting'   => 'Meeting',
		'meetlib_email'     => 'Email',
		'date'          => 'Submitted',
	);
} );

add_action( 'manage_' . MEETLIB_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	$map = array( 'meetlib_librarian' => '_meetlib_librarian', 'meetlib_meeting' => '_meetlib_meeting', 'meetlib_email' => '_meetlib_email' );
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
	);
	echo '<table class="widefat striped" style="max-width:780px;margin-top:12px"><tbody>';
	foreach ( $rows as $label => $key ) {
		$val = get_post_meta( $post->ID, $key, true );
		echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
	}
	$att = get_post_meta( $post->ID, '_meetlib_attach_url', true );
	if ( $att ) {
		echo '<tr><th>Attachment</th><td><a href="' . esc_url( $att ) . '" target="_blank" rel="noopener">Download</a></td></tr>';
	}
	echo '</tbody></table>';
}, 10, 1 );

/* ------------------------------------------------------------------ *
 *  Front-end directory  [meet_a_librarian]
 * ------------------------------------------------------------------ */

add_shortcode( 'meet_a_librarian', 'meetlib_shortcode' );

function meetlib_shortcode( $atts ) {
	$root   = esc_url( rest_url( 'meet-a-librarian/v1/librarians' ) );
	$submit = esc_url( rest_url( 'meet-a-librarian/v1/request' ) );
	$config = array(
		'listUrl'   => $root,
		'submitUrl' => $submit,
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'fields'    => meetlib_form_fields(),
		'maxBytes'  => meetlib_max_upload_bytes(),
		'exts'      => meetlib_upload_exts(),
		'domains'   => meetlib_email_domains(),
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
  #meetlib-form-host .meetlib-f__radio{display:block;font-weight:400;margin:4px 0}
  #meetlib-form-host .meetlib-f__submit{background:var(--meetlib-accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font-weight:600;cursor:pointer}
  #meetlib-form-host .meetlib-f__cancel{background:none;border:0;color:var(--meetlib-muted);cursor:pointer;margin-left:10px;text-decoration:underline}
  #meetlib-form-host iframe{width:100%;height:720px;border:0}
  #meetlib-form-host .meetlib-f__note{background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;padding:12px 14px;margin-bottom:16px}
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
if(!mount||!window.MEETLIB_URL||!window.MEETLIB_FORM)return;
var CFG=window.MEETLIB_FORM, LIST=[];
var s=new Date();
var bust=""+s.getUTCFullYear()+(s.getUTCMonth()+1)+s.getUTCDate()+s.getUTCHours();
var url=MEETLIB_URL+(MEETLIB_URL.indexOf("?")>-1?"&":"?")+"v="+bust;
fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(render).catch(function(){
  mount.innerHTML='<p class="meetlib__error">The librarian directory is temporarily unavailable. Please email library@wheatoncollege.edu.</p>';
});
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function slug(name){return String(name==null?"":name).toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}
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
      ? '<button type="button" class="meetlib__book" data-i="'+i+'">Book an appointment</button>'
      : '<span class="meetlib__soon">Booking link coming soon</span>';
    html+='<div class="meetlib__card">'+photo+'<div class="meetlib__name">'+name+'</div>'+sub+action+'</div>';
  });
  html+='</div>';
  mount.innerHTML=html;
  Array.prototype.forEach.call(mount.querySelectorAll(".meetlib__book"),function(b){
    b.addEventListener("click",function(){openForm(parseInt(b.getAttribute("data-i"),10));});
  });
  var m=/[?&]librarian=([^&#]+)/.exec(location.search), want=m?decodeURIComponent(m[1]).toLowerCase():"";
  if(want){for(var wi=0;wi<LIST.length;wi++){if(LIST[wi].booking&&slug(LIST[wi].name)===want){openForm(wi);break;}}}
}
function fieldHtml(f){
  var req=f.required?' <span class="meetlib-f__req">*</span>':'';
  var help=f.help?'<div class="meetlib-f__help">'+esc(f.help)+'</div>':'';
  var err='<div class="meetlib-f__err" data-err="'+f.key+'" style="display:none"></div>';
  var ctl='';
  if(f.type==="textarea"){ctl='<textarea data-k="'+f.key+'"></textarea>';}
  else if(f.type==="radio"){ctl=(f.options||[]).map(function(o){
      return '<label class="meetlib-f__radio"><input type="radio" name="meetlib_'+f.key+'" value="'+esc(o)+'"> '+esc(o)+'</label>';}).join("");}
  else if(f.type==="file"){ctl='<input type="file" data-k="'+f.key+'">';}
  else{ctl='<input type="'+(f.type==="email"?"email":"text")+'" data-k="'+f.key+'">';}
  return '<div class="meetlib-f__field"><label>'+esc(f.label)+req+'</label>'+ctl+help+err+'</div>';
}
function openForm(i){
  var l=LIST[i];if(!l)return;
  var fields=CFG.fields.map(fieldHtml).join("");
  host.innerHTML='<div class="meetlib-f__note">Request an appointment with <strong>'+esc(l.name)+'</strong>. '
    +'Answer a few questions, then pick a time on the next screen.</div>'
    +'<form id="meetlib-f" novalidate>'+fields
    +'<input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">'
    +'<button type="submit" class="meetlib-f__submit">Continue to pick a time</button>'
    +'<button type="button" class="meetlib-f__cancel" id="meetlib-f-cancel">Cancel</button></form>';
  host.scrollIntoView({behavior:"smooth",block:"start"});
  document.getElementById("meetlib-f-cancel").addEventListener("click",function(){host.innerHTML="";});
  document.getElementById("meetlib-f").addEventListener("submit",function(e){e.preventDefault();submitForm(i);});
}
function getVal(k){var n=host.querySelector('[data-k="'+k+'"]');return n?n.value:"";}
function getRadio(k){var n=host.querySelector('input[name="meetlib_'+k+'"]:checked');return n?n.value:"";}
function showErr(k,msg){var e=host.querySelector('[data-err="'+k+'"]');if(e){e.textContent=msg;e.style.display=msg?"block":"none";}}
function submitForm(i){
  var l=LIST[i], ok=true;
  CFG.fields.forEach(function(f){showErr(f.key,"");});
  var fd=new FormData();
  fd.append("librarian",i);
  fd.append("website",(host.querySelector('[name="website"]')||{}).value||"");
  fd.append("_wpnonce",CFG.nonce);
  CFG.fields.forEach(function(f){
    if(f.type==="file"){
      var fn=host.querySelector('[data-k="'+f.key+'"]');
      if(fn&&fn.files&&fn.files[0]){
        var file=fn.files[0];
        if(file.size>CFG.maxBytes){showErr(f.key,"That file is larger than 20 MB.");ok=false;}
        var ext=(file.name.split(".").pop()||"").toLowerCase();
        if(CFG.exts.indexOf(ext)<0){showErr(f.key,"Use PDF, Word, text, or an image.");ok=false;}
        fd.append("attachment",file);
      }
      return;
    }
    var v=f.type==="radio"?getRadio(f.key):getVal(f.key);
    if(f.required&&!v.trim()){showErr(f.key,"This field is required.");ok=false;}
    if(f.key==="email"&&v){
      var dom=(v.split("@")[1]||"").toLowerCase();
      if(CFG.domains.indexOf(dom)<0){showErr(f.key,"Use your "+CFG.domains.join(" or ")+" email.");ok=false;}
    }
    fd.append(f.key,v);
  });
  if(!ok)return;
  var btn=host.querySelector(".meetlib-f__submit");btn.disabled=true;btn.textContent="Submitting…";
  fetch(CFG.submitUrl,{method:"POST",body:fd}).then(function(r){
    return r.json().then(function(j){return{ok:r.ok,j:j};});
  }).then(function(res){
    if(!res.ok){throw new Error(res.j&&res.j.message?res.j.message:"Submission failed.");}
    showScheduler(l,res.j);
  }).catch(function(err){
    btn.disabled=false;btn.textContent="Continue to pick a time";
    var top=host.querySelector(".meetlib-f__note");
    if(top){top.innerHTML='<strong>'+esc(err.message)+'</strong> If this keeps happening, email the librarian directly.';}
  });
}
function showScheduler(l,resp){
  var embed=resp&&resp.embedUrl;
  var book=(resp&&resp.booking)||l.booking;
  host.innerHTML='<div class="meetlib-f__note">Thanks! Your details were sent to <strong>'+esc(l.name)
    +'</strong>. Now pick a date and time below.</div>'
    +(embed
      ? '<iframe src="'+esc(embed)+'" title="Pick an appointment time"></iframe>'
      : '<p><a class="meetlib-f__submit" href="'+esc(book)+'" target="_blank" rel="noopener" style="display:inline-block;text-decoration:none">Pick your time on Google →</a></p>');
  host.scrollIntoView({behavior:"smooth",block:"start"});
}
})();
</script>
HTML;
}
