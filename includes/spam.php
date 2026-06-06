<?php
/**
 * Milieus by Therum — registration anti-spam.
 *
 * Two defenses, both transparent to legitimate users:
 *
 *   HONEYPOT  — invisible field named "milieus_email_confirm" (visually
 *               hidden via inline style, autocomplete="off", tabindex="-1").
 *               Bots fill every field they see; humans never see this one.
 *               Any non-empty value → silently reject.
 *
 *   RATE LIMIT — N sign-ups per IP per hour, tracked via a transient. Default
 *                is 5/hour; filter milieus_signup_rate_per_hour to change. The
 *                cap returns a 429 with a generic "try again later" message
 *                rather than letting bots probe the boundary.
 *
 * Filter `milieus_skip_spam_check` to bypass (e.g. for trusted CAPTCHA
 * integrations on top).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_HONEYPOT_FIELD = 'milieus_email_confirm';

/**
 * Called from milieus_process_registration() before account creation.
 * Returns null if OK, or a string error to surface back to the form.
 */
function milieus_spam_check(): ?string {
	if ( apply_filters( 'milieus_skip_spam_check', false ) ) return null;

	// Honeypot: must be empty.
	if ( ! empty( $_POST[ MILIEUS_HONEYPOT_FIELD ] ) ) {
		// Don't leak that we caught the trick — pretend everything is fine
		// but never create the account. We bail loud here; caller surfaces it.
		return __( 'Sign-up could not be completed. Please try again later.', 'milieus' );
	}

	// Rate limit.
	$ip = milieus_client_ip();
	if ( $ip ) {
		$key   = 'milieus_signup_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		$cap   = (int) apply_filters( 'milieus_signup_rate_per_hour', 5 );
		if ( $count >= $cap ) {
			status_header( 429 );
			return __( "Too many sign-ups from your network. Please try again later.", 'milieus' );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
	return null;
}

/**
 * Render the honeypot input. Called inline from registration + shortcode forms.
 */
function milieus_honeypot_field(): string {
	return '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">'
		. '<label for="' . esc_attr( MILIEUS_HONEYPOT_FIELD ) . '">Leave this field empty</label>'
		. '<input type="text" name="' . esc_attr( MILIEUS_HONEYPOT_FIELD ) . '" id="' . esc_attr( MILIEUS_HONEYPOT_FIELD ) . '" value="" autocomplete="off" tabindex="-1">'
		. '</div>';
}

/**
 * Best-effort client IP, going through trusted proxy headers when they look
 * sane. Conservative — falls back to REMOTE_ADDR if anything looks off.
 */
function milieus_client_ip(): string {
	$candidates = [];
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$first = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
		$candidates[] = $first;
	}
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) $candidates[] = $_SERVER['REMOTE_ADDR'];
	foreach ( $candidates as $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
	}
	return '';
}
