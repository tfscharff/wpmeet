<?php
/**
 * Plugin Name:       MCW Meet With a Librarian
 * Description:        No-code "Meet With a Librarian" directory: staff manage librarians and their Google appointment booking links under Meet With a Librarian in wp-admin and click Save. Show the directory anywhere with the [meet_a_librarian] shortcode. Replaces LibCal appointments.
 * Version:           1.2.0
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MCW_LIB_OPTION', 'mcw_librarians' );
define( 'MCW_LIB_PAGE_OPTION', 'mcw_lib_page_url' );
define( 'MCW_LIB_CPT', 'mcw_request' );

/** URL of the page that hosts [meet_a_librarian]; base for the per-librarian widget deep links. */
function mcw_lib_page_url() {
	$u = get_option( MCW_LIB_PAGE_OPTION );
	$u = is_string( $u ) ? trim( $u ) : '';
	return '' !== $u ? $u : home_url( '/meet-with-a-librarian/' );
}

/** Slug for a librarian name, matched byte-for-byte in PHP and JS (ASCII lowercase + hyphens). */
function mcw_lib_slug( $name ) {
	$s = strtolower( (string) $name );
	$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
	return trim( $s, '-' );
}

/* ------------------------------------------------------------------ *
 *  Booking-form config  (single source of truth for JS + PHP)
 * ------------------------------------------------------------------ */

/** The patron questions — defined once, identical for every librarian. */
function mcw_lib_form_fields() {
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

function mcw_lib_email_domains() { return array( 'wheatoncollege.edu', 'wheatonma.edu' ); }
function mcw_lib_upload_exts()   { return array( 'pdf', 'doc', 'docx', 'txt', 'rtf', 'png', 'jpg', 'jpeg', 'gif' ); }
function mcw_lib_max_upload_bytes() { return 20 * 1024 * 1024; }

/** Normalize a Google appointment-schedule link to its inline embed URL, or '' if not embeddable. */
function mcw_lib_embed_url( $booking ) {
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

function mcw_lib_default() {
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

function mcw_lib_get() {
	$v = get_option( MCW_LIB_OPTION );
	if ( empty( $v ) || ! is_array( $v ) ) { $v = mcw_lib_default(); }
	return array_values( $v );
}

/** Whitelist + validate incoming rows so only clean data is stored. */
function mcw_lib_sanitize( $in ) {
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
	register_rest_route( 'mcw-librarians/v1', '/librarians', array(
		array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( mcw_lib_get() );
			},
			'permission_callback' => '__return_true',
		),
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $req ) {
				$body = $req->get_json_params();
				if ( ! is_array( $body ) || ! isset( $body['librarians'] ) || ! is_array( $body['librarians'] ) ) {
					return new WP_Error( 'mcw_bad_data', 'Invalid librarian data.', array( 'status' => 400 ) );
				}
				update_option( MCW_LIB_OPTION, mcw_lib_sanitize( $body['librarians'] ) );
				if ( isset( $body['pageUrl'] ) ) {
					update_option( MCW_LIB_PAGE_OPTION, esc_url_raw( trim( (string) $body['pageUrl'] ) ) );
				}
				return rest_ensure_response( array( 'ok' => true ) );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		),
	) );

	register_rest_route( 'mcw-librarians/v1', '/request', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // patrons are not logged in; hardened in the handler
		'callback'            => 'mcw_lib_handle_request',
	) );
} );

/* ------------------------------------------------------------------ *
 *  Booking-form submission handler
 * ------------------------------------------------------------------ */

function mcw_lib_handle_request( WP_REST_Request $req ) {
	// 1. Anti-bot: nonce + honeypot.
	if ( ! wp_verify_nonce( $req->get_param( '_wpnonce' ), 'wp_rest' ) ) {
		return new WP_Error( 'mcw_nonce', 'Your session expired. Please reload the page and try again.', array( 'status' => 403 ) );
	}
	if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) { // honeypot must stay empty
		return new WP_Error( 'mcw_bot', 'Submission blocked.', array( 'status' => 400 ) );
	}

	// 2. Resolve the target librarian by roster index.
	$libs = mcw_lib_get();
	$idx  = (int) $req->get_param( 'librarian' );
	if ( ! isset( $libs[ $idx ] ) || empty( $libs[ $idx ]['booking'] ) ) {
		return new WP_Error( 'mcw_lib', 'That librarian is not available for booking.', array( 'status' => 400 ) );
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
	foreach ( mcw_lib_form_fields() as $f ) {
		if ( 'file' === $f['type'] ) { continue; }
		if ( $f['required'] && '' === trim( $data[ $f['key'] ] ) ) {
			return new WP_Error( 'mcw_required', 'Please fill in every required field.', array( 'status' => 400 ) );
		}
	}
	if ( ! is_email( $data['email'] ) ) {
		return new WP_Error( 'mcw_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
	}
	$domain = strtolower( substr( strrchr( $data['email'], '@' ), 1 ) );
	if ( ! in_array( $domain, mcw_lib_email_domains(), true ) ) {
		return new WP_Error( 'mcw_domain', 'Please use your wheatoncollege.edu or wheatonma.edu email address.', array( 'status' => 400 ) );
	}
	$allowed_meetings = array( 'In person in my office', 'In person in a fully accessible space', 'On Zoom' );
	if ( ! in_array( $data['meeting'], $allowed_meetings, true ) ) {
		return new WP_Error( 'mcw_meeting', 'Please choose a meeting option.', array( 'status' => 400 ) );
	}

	// 5. Optional file upload.
	$attach_url = '';
	$files      = $req->get_file_params();
	if ( ! empty( $files['attachment'] ) && ! empty( $files['attachment']['name'] ) && UPLOAD_ERR_NO_FILE !== $files['attachment']['error'] ) {
		$file = $files['attachment'];
		if ( $file['size'] > mcw_lib_max_upload_bytes() ) {
			return new WP_Error( 'mcw_size', 'That file is larger than 20 MB. Please attach a smaller file.', array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, mcw_lib_upload_exts(), true ) ) {
			return new WP_Error( 'mcw_type', 'That file type is not allowed. Use PDF, Word, text, or an image.', array( 'status' => 400 ) );
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
			return new WP_Error( 'mcw_upload', 'The attachment could not be saved. Please try again without it.', array( 'status' => 400 ) );
		}
		$attach_url = $moved['url'];
	}

	// 6. Persist the record.
	$post_id = wp_insert_post( array(
		'post_type'   => MCW_LIB_CPT,
		'post_status' => 'publish',
		'post_title'  => $data['first'] . ' ' . $data['last'] . ' — ' . $lib['name'],
	), true );
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'mcw_save', 'Something went wrong. Please email the librarian directly.', array( 'status' => 500 ) );
	}
	$meta = array(
		'_mcw_first' => $data['first'], '_mcw_last' => $data['last'], '_mcw_email' => $data['email'],
		'_mcw_help' => $data['help'], '_mcw_course' => $data['course'], '_mcw_due' => $data['due'],
		'_mcw_meeting' => $data['meeting'], '_mcw_librarian' => $lib['name'], '_mcw_attach_url' => $attach_url,
	);
	foreach ( $meta as $k => $v ) { update_post_meta( $post_id, $k, $v ); }

	// 7. Email the librarian (best-effort; failure does not fail the request).
	mcw_lib_notify( $lib, $data, $attach_url );

	// 8. Success — hand back the embed URL for the Google step.
	return rest_ensure_response( array(
		'ok'       => true,
		'embedUrl' => mcw_lib_embed_url( $lib['booking'] ),
		'booking'  => esc_url_raw( $lib['booking'] ),
	) );
}

/** Email the librarian a formatted copy of the request. Skipped (with a log) if the librarian has no email on file. */
function mcw_lib_notify( $lib, $data, $attach_url ) {
	$to = mcw_lib_librarian_email( $lib );
	if ( ! $to ) {
		error_log( 'mcw-meet-a-librarian: no email for librarian "' . $lib['name'] . '"; request stored as CPT only.' );
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
		error_log( 'mcw-meet-a-librarian: wp_mail failed for request from ' . $data['email'] . '; stored as CPT.' );
	}
}

/** Librarian notification address from the roster `email` field, or '' if unknown/invalid. */
function mcw_lib_librarian_email( $lib ) {
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
		'mcw-meet-a-librarian',
		'mcw_lib_admin_page',
		'dashicons-groups',
		31
	);
	// Add the requests list AFTER the editor so the editor remains the first (default) submenu.
	add_submenu_page(
		'mcw-meet-a-librarian',
		'Appointment Requests',
		'Appointment Requests',
		'edit_pages',
		'edit.php?post_type=' . MCW_LIB_CPT
	);
} );

function mcw_lib_admin_page() {
	$root  = esc_url_raw( rest_url( 'mcw-librarians/v1/librarians' ) );
	$nonce = wp_create_nonce( 'wp_rest' );

	echo '<div class="wrap"><h1>Meet With a Librarian</h1>';
	echo '<p style="max-width:680px;color:#555">Add each librarian and paste their Google appointment <strong>booking link</strong>, then click <strong>Save &amp; publish</strong>. Changes go live on the website immediately — no files, no code. Show the directory on any page with the shortcode <code>[meet_a_librarian]</code>.</p>';

	echo '<script>window.MCW_LIB=' . wp_json_encode( array(
		'root'    => $root,
		'nonce'   => $nonce,
		'pageUrl' => mcw_lib_page_url(),
	) ) . ';</script>';

	echo mcw_lib_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

/** The editor UI + JS. Nowdoc => no PHP interpolation of $ in JS. */
function mcw_lib_editor_markup() {
	return <<<'HTML'
<style>
  #mcwlib{--accent:#00539b;--line:#dcdcdc;--muted:#666;max-width:820px;font-size:14px}
  #mcwlib .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:14px 0;position:relative}
  #mcwlib .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 14px}
  #mcwlib .full{grid-column:1/3}
  #mcwlib label{display:block;font-weight:600;font-size:.82rem;color:var(--muted);margin-bottom:3px}
  #mcwlib input[type=text]{width:100%;font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:#fff;box-sizing:border-box}
  #mcwlib .num{font-weight:700;color:var(--accent);margin-bottom:6px}
  #mcwlib .b{cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:7px;font-weight:600;font-size:.9rem}
  #mcwlib .b.sec{background:#fff;color:var(--accent)}
  #mcwlib .b.ghost{background:#fff;color:var(--muted);border-color:var(--line)}
  #mcwlib .b.tiny{padding:3px 9px;font-size:.8rem;border-radius:5px;position:absolute;top:12px;right:14px}
  #mcwlib .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
  #mcwlib .status{font-weight:600}
  #mcwlib .hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}
  #mcwlib .widget{margin-top:12px;border-top:1px dashed var(--line);padding-top:10px}
  #mcwlib textarea.code{width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.75rem;line-height:1.4;padding:8px;border:1px solid var(--line);border-radius:6px;background:#f7f7f8;box-sizing:border-box;resize:vertical;min-height:66px}
  #mcwlib .widget .row{display:flex;align-items:center;gap:10px;margin-top:6px;flex-wrap:wrap}
  #mcwlib .muted{font-size:.8rem;color:var(--muted)}
  #mcwlib .pagecard label{margin-bottom:4px}
</style>

<div id="mcwlib">
  <div class="card pagecard">
    <label for="mcwlib-page">Booking page address <span class="muted">(the page that shows the <code>[meet_a_librarian]</code> directory — used in each librarian's widget link)</span></label>
    <input id="mcwlib-page" type="text" placeholder="https://library.wheatoncollege.edu/meet-with-a-librarian/">
  </div>
  <div id="mcwlib-rows"></div>
  <div class="bar"><button type="button" class="b sec" id="mcwlib-add">+ Add a librarian</button></div>
  <div class="bar">
    <button type="button" class="b" id="mcwlib-save">Save &amp; publish</button>
    <span class="status" id="mcwlib-status"></span>
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
  var wrap=document.getElementById("mcwlib-rows");wrap.innerHTML="";
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
function flash(msg,bad){var s=document.getElementById("mcwlib-status");s.textContent=msg;s.style.color=bad?"#b42318":"#1a7f37";}

var pageInput=document.getElementById("mcwlib-page");
pageInput.oninput=function(){pageUrl=pageInput.value;};
document.getElementById("mcwlib-add").onclick=function(){libs.push({name:"",pronouns:"",title:"",liaison:"",email:"",photo:"",profile:"",booking:""});render();};
document.getElementById("mcwlib-save").onclick=function(){
  flash("Saving…");
  fetch(MCW_LIB.root,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":MCW_LIB.nonce},body:JSON.stringify({librarians:libs,pageUrl:pageUrl})})
    .then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})
    .then(function(){saved=clone(libs);savedPage=pageUrl;render();flash("Saved — it's live on the website. ✓");})
    .catch(function(){flash("Save failed — please try again or reload the page.",true);});
};

pageUrl=savedPage=(MCW_LIB.pageUrl||"");
pageInput.value=pageUrl;
fetch(MCW_LIB.root).then(function(r){return r.json();}).then(function(d){libs=Array.isArray(d)?d:[];saved=clone(libs);render();}).catch(function(){render();});
})();
</script>
HTML;
}

/* ------------------------------------------------------------------ *
 *  Appointment requests  (CPT = durable record + wp-admin viewer)
 * ------------------------------------------------------------------ */

add_action( 'init', function () {
	register_post_type( MCW_LIB_CPT, array(
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
add_filter( 'manage_' . MCW_LIB_CPT . '_posts_columns', function ( $cols ) {
	return array(
		'cb'            => isset( $cols['cb'] ) ? $cols['cb'] : '',
		'title'         => 'Patron',
		'mcw_librarian' => 'Librarian',
		'mcw_meeting'   => 'Meeting',
		'mcw_email'     => 'Email',
		'date'          => 'Submitted',
	);
} );

add_action( 'manage_' . MCW_LIB_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	$map = array( 'mcw_librarian' => '_mcw_librarian', 'mcw_meeting' => '_mcw_meeting', 'mcw_email' => '_mcw_email' );
	if ( isset( $map[ $col ] ) ) {
		echo esc_html( get_post_meta( $post_id, $map[ $col ], true ) );
	}
}, 10, 2 );

/** Show all answers + attachment in the single-request edit screen (read-only). */
add_action( 'edit_form_after_title', function ( $post ) {
	if ( MCW_LIB_CPT !== $post->post_type ) { return; }
	$rows = array(
		'Librarian'          => '_mcw_librarian',
		'First name'         => '_mcw_first',
		'Last name'          => '_mcw_last',
		'Email'              => '_mcw_email',
		'How can I help you?' => '_mcw_help',
		'Course'             => '_mcw_course',
		'Assignment due'     => '_mcw_due',
		'Meeting preference' => '_mcw_meeting',
	);
	echo '<table class="widefat striped" style="max-width:780px;margin-top:12px"><tbody>';
	foreach ( $rows as $label => $key ) {
		$val = get_post_meta( $post->ID, $key, true );
		echo '<tr><th style="width:200px">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
	}
	$att = get_post_meta( $post->ID, '_mcw_attach_url', true );
	if ( $att ) {
		echo '<tr><th>Attachment</th><td><a href="' . esc_url( $att ) . '" target="_blank" rel="noopener">Download</a></td></tr>';
	}
	echo '</tbody></table>';
}, 10, 1 );

/* ------------------------------------------------------------------ *
 *  Front-end directory  [meet_a_librarian]
 * ------------------------------------------------------------------ */

add_shortcode( 'meet_a_librarian', 'mcw_lib_shortcode' );

function mcw_lib_shortcode( $atts ) {
	$root   = esc_url( rest_url( 'mcw-librarians/v1/librarians' ) );
	$submit = esc_url( rest_url( 'mcw-librarians/v1/request' ) );
	$config = array(
		'listUrl'   => $root,
		'submitUrl' => $submit,
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'fields'    => mcw_lib_form_fields(),
		'maxBytes'  => mcw_lib_max_upload_bytes(),
		'exts'      => mcw_lib_upload_exts(),
		'domains'   => mcw_lib_email_domains(),
	);
	$config_js = '<script>window.MCW_LIB_URL=' . wp_json_encode( $root )
		. ';window.MCW_LIB_FORM=' . wp_json_encode( $config ) . ';</script>';
	return mcw_lib_widget_markup() . $config_js . mcw_lib_widget_script();
}

function mcw_lib_widget_markup() {
	return <<<'HTML'
<div id="mcw-librarians" class="mcw-librarians" aria-live="polite">
  <p class="mcw-lib__loading">Loading librarians…</p>
</div>
<div id="mcw-lib-form-host"></div>
<style>
  #mcw-librarians{--mcw-accent:#00539b;--mcw-line:#1274B8;--mcw-text:#1a1a1a;--mcw-muted:#666;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--mcw-text)}
  #mcw-librarians .mcw-lib__grid{display:flex;flex-wrap:wrap;justify-content:center;gap:24px}
  #mcw-librarians .mcw-lib__card{flex:1 1 240px;max-width:300px;text-align:center;display:flex;flex-direction:column;align-items:center}
  #mcw-librarians .mcw-lib__card img{width:180px;height:180px;object-fit:cover;border-radius:8px}
  #mcw-librarians .mcw-lib__name{font-weight:700;margin-top:12px}
  #mcw-librarians .mcw-lib__title{font-size:.9rem;color:var(--mcw-muted);margin-top:4px}
  #mcw-librarians .mcw-lib__book{display:inline-block;margin-top:12px;background:var(--mcw-accent);color:#fff;
    text-decoration:none;padding:9px 16px;border-radius:7px;font-weight:600;font-size:.95rem}
  #mcw-librarians .mcw-lib__book:hover,#mcw-librarians .mcw-lib__book:focus{background:#003b71;color:#fff}
  #mcw-librarians .mcw-lib__soon{display:inline-block;margin-top:12px;color:var(--mcw-muted);font-size:.9rem;font-style:italic}
  #mcw-librarians .mcw-lib__loading,#mcw-librarians .mcw-lib__error{color:var(--mcw-muted);text-align:center}
  #mcw-librarians .mcw-lib__book{cursor:pointer;border:0}
  #mcw-lib-form-host{--mcw-accent:#00539b;--mcw-line:#c9c9c9;--mcw-muted:#666;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:24px auto}
  #mcw-lib-form-host .mcw-f__field{margin:14px 0}
  #mcw-lib-form-host label{display:block;font-weight:600;margin-bottom:4px}
  #mcw-lib-form-host input[type=text],#mcw-lib-form-host input[type=email],#mcw-lib-form-host textarea{
    width:100%;font:inherit;padding:8px 10px;border:1px solid var(--mcw-line);border-radius:6px;box-sizing:border-box}
  #mcw-lib-form-host textarea{min-height:90px;resize:vertical}
  #mcw-lib-form-host .mcw-f__help{font-size:.85rem;color:var(--mcw-muted);margin-top:3px}
  #mcw-lib-form-host .mcw-f__req{color:#b42318}
  #mcw-lib-form-host .mcw-f__err{color:#b42318;font-size:.85rem;margin-top:3px}
  #mcw-lib-form-host .mcw-f__radio{display:block;font-weight:400;margin:4px 0}
  #mcw-lib-form-host .mcw-f__submit{background:var(--mcw-accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font-weight:600;cursor:pointer}
  #mcw-lib-form-host .mcw-f__cancel{background:none;border:0;color:var(--mcw-muted);cursor:pointer;margin-left:10px;text-decoration:underline}
  #mcw-lib-form-host iframe{width:100%;height:720px;border:0}
  #mcw-lib-form-host .mcw-f__note{background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;padding:12px 14px;margin-bottom:16px}
</style>
HTML;
}

function mcw_lib_widget_script() {
	return <<<'HTML'
<script>
(function(){
"use strict";
var mount=document.getElementById("mcw-librarians");
var host=document.getElementById("mcw-lib-form-host");
if(!mount||!window.MCW_LIB_URL||!window.MCW_LIB_FORM)return;
var CFG=window.MCW_LIB_FORM, LIST=[];
var s=new Date();
var bust=""+s.getUTCFullYear()+(s.getUTCMonth()+1)+s.getUTCDate()+s.getUTCHours();
var url=MCW_LIB_URL+(MCW_LIB_URL.indexOf("?")>-1?"&":"?")+"v="+bust;
fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(render).catch(function(){
  mount.innerHTML='<p class="mcw-lib__error">The librarian directory is temporarily unavailable. Please email library@wheatoncollege.edu.</p>';
});
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function slug(name){return String(name==null?"":name).toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}
function render(list){
  LIST=Array.isArray(list)?list:[];
  if(LIST.length===0){mount.innerHTML='<p class="mcw-lib__error">No librarians are listed yet.</p>';return;}
  var html='<div class="mcw-lib__grid">';
  LIST.forEach(function(l,i){
    var name=esc(l.name)+(l.pronouns?' ('+esc(l.pronouns)+')':'');
    var img=l.photo?'<img src="'+esc(l.photo)+'" alt="'+esc(l.name)+'’s picture" width="180" height="180" loading="lazy">':'';
    var photo=l.profile?'<a href="'+esc(l.profile)+'">'+img+'</a>':img;
    var sub='';
    if(l.title)sub+='<div class="mcw-lib__title">'+esc(l.title)+'</div>';
    if(l.liaison)sub+='<div class="mcw-lib__title">'+esc(l.liaison)+'</div>';
    var action=l.booking
      ? '<button type="button" class="mcw-lib__book" data-i="'+i+'">Book an appointment</button>'
      : '<span class="mcw-lib__soon">Booking link coming soon</span>';
    html+='<div class="mcw-lib__card">'+photo+'<div class="mcw-lib__name">'+name+'</div>'+sub+action+'</div>';
  });
  html+='</div>';
  mount.innerHTML=html;
  Array.prototype.forEach.call(mount.querySelectorAll(".mcw-lib__book"),function(b){
    b.addEventListener("click",function(){openForm(parseInt(b.getAttribute("data-i"),10));});
  });
  var m=/[?&]librarian=([^&#]+)/.exec(location.search), want=m?decodeURIComponent(m[1]).toLowerCase():"";
  if(want){for(var wi=0;wi<LIST.length;wi++){if(LIST[wi].booking&&slug(LIST[wi].name)===want){openForm(wi);break;}}}
}
function fieldHtml(f){
  var req=f.required?' <span class="mcw-f__req">*</span>':'';
  var help=f.help?'<div class="mcw-f__help">'+esc(f.help)+'</div>':'';
  var err='<div class="mcw-f__err" data-err="'+f.key+'" style="display:none"></div>';
  var ctl='';
  if(f.type==="textarea"){ctl='<textarea data-k="'+f.key+'"></textarea>';}
  else if(f.type==="radio"){ctl=(f.options||[]).map(function(o){
      return '<label class="mcw-f__radio"><input type="radio" name="mcw_'+f.key+'" value="'+esc(o)+'"> '+esc(o)+'</label>';}).join("");}
  else if(f.type==="file"){ctl='<input type="file" data-k="'+f.key+'">';}
  else{ctl='<input type="'+(f.type==="email"?"email":"text")+'" data-k="'+f.key+'">';}
  return '<div class="mcw-f__field"><label>'+esc(f.label)+req+'</label>'+ctl+help+err+'</div>';
}
function openForm(i){
  var l=LIST[i];if(!l)return;
  var fields=CFG.fields.map(fieldHtml).join("");
  host.innerHTML='<div class="mcw-f__note">Request an appointment with <strong>'+esc(l.name)+'</strong>. '
    +'Answer a few questions, then pick a time on the next screen.</div>'
    +'<form id="mcw-f" novalidate>'+fields
    +'<input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">'
    +'<button type="submit" class="mcw-f__submit">Continue to pick a time</button>'
    +'<button type="button" class="mcw-f__cancel" id="mcw-f-cancel">Cancel</button></form>';
  host.scrollIntoView({behavior:"smooth",block:"start"});
  document.getElementById("mcw-f-cancel").addEventListener("click",function(){host.innerHTML="";});
  document.getElementById("mcw-f").addEventListener("submit",function(e){e.preventDefault();submitForm(i);});
}
function getVal(k){var n=host.querySelector('[data-k="'+k+'"]');return n?n.value:"";}
function getRadio(k){var n=host.querySelector('input[name="mcw_'+k+'"]:checked');return n?n.value:"";}
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
  var btn=host.querySelector(".mcw-f__submit");btn.disabled=true;btn.textContent="Submitting…";
  fetch(CFG.submitUrl,{method:"POST",body:fd}).then(function(r){
    return r.json().then(function(j){return{ok:r.ok,j:j};});
  }).then(function(res){
    if(!res.ok){throw new Error(res.j&&res.j.message?res.j.message:"Submission failed.");}
    showScheduler(l,res.j);
  }).catch(function(err){
    btn.disabled=false;btn.textContent="Continue to pick a time";
    var top=host.querySelector(".mcw-f__note");
    if(top){top.innerHTML='<strong>'+esc(err.message)+'</strong> If this keeps happening, email the librarian directly.';}
  });
}
function showScheduler(l,resp){
  var embed=resp&&resp.embedUrl;
  var book=(resp&&resp.booking)||l.booking;
  host.innerHTML='<div class="mcw-f__note">Thanks! Your details were sent to <strong>'+esc(l.name)
    +'</strong>. Now pick a date and time below.</div>'
    +(embed
      ? '<iframe src="'+esc(embed)+'" title="Pick an appointment time"></iframe>'
      : '<p><a class="mcw-f__submit" href="'+esc(book)+'" target="_blank" rel="noopener" style="display:inline-block;text-decoration:none">Pick your time on Google →</a></p>');
  host.scrollIntoView({behavior:"smooth",block:"start"});
}
})();
</script>
HTML;
}
