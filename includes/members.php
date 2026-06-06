<?php
/**
 * Milieus by Therum — member-list helpers + AJAX endpoints for the
 * Members tab inside each group's editor.
 *
 * Endpoints (all guarded by manage_options + milieus_members nonce):
 *
 *   wp_ajax_milieus_members_list   → page through the group's members
 *   wp_ajax_milieus_members_search → typeahead search users not in the group
 *   wp_ajax_milieus_members_add    → assign one user
 *   wp_ajax_milieus_members_bulk   → revoke / extend many at once
 *   wp_ajax_milieus_members_csv    → import emails from a CSV blob
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_MEMBERS_PER_PAGE = 25;

/**
 * Get the members of a group as a structured list for the admin UI.
 *
 * @return array{ rows:array, total:int }
 */
function milieus_list_members( string $role_key, int $page = 1, string $search = '' ): array {
	$args = [
		'role'    => $role_key,
		'number'  => MILIEUS_MEMBERS_PER_PAGE,
		'offset'  => max( 0, ( $page - 1 ) * MILIEUS_MEMBERS_PER_PAGE ),
		'orderby' => 'registered',
		'order'   => 'DESC',
	];
	if ( $search ) {
		$args['search']         = '*' . esc_attr( $search ) . '*';
		$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
	}

	$query = new WP_User_Query( $args );
	$rows  = [];
	foreach ( $query->get_results() as $u ) {
		$rows[] = milieus_format_member_row( $u, $role_key );
	}

	return [
		'rows'  => $rows,
		'total' => (int) $query->get_total(),
	];
}

function milieus_format_member_row( WP_User $u, string $role_key ): array {
	$assigned = (int) get_user_meta( $u->ID, MILIEUS_META_ASSIGNED . $role_key, true );
	$expires  = (int) get_user_meta( $u->ID, MILIEUS_META_EXPIRES  . $role_key, true );
	$source   = (string) get_user_meta( $u->ID, MILIEUS_META_SOURCE . $role_key, true ) ?: 'manual';
	return [
		'id'           => $u->ID,
		'name'         => $u->display_name ?: $u->user_login,
		'email'        => $u->user_email,
		'avatar_url'   => get_avatar_url( $u->ID, [ 'size' => 48 ] ),
		'assigned_at'  => $assigned,
		'assigned_label'=> $assigned ? wp_date( 'M j, Y', $assigned ) : '—',
		'expires_at'   => $expires,
		'expires_label'=> milieus_humanize_expiry( $expires ),
		'expires_tier' => milieus_expiry_tier( $expires ),
		'source'       => $source,
	];
}

function milieus_humanize_expiry( int $ts ): string {
	if ( $ts === 0 ) return __( 'Permanent', 'milieus' );
	$delta = $ts - time();
	if ( $delta <= 0 ) return __( 'expired', 'milieus' );
	if ( $delta < DAY_IN_SECONDS )    return __( 'today', 'milieus' );
	if ( $delta < 2 * DAY_IN_SECONDS )return __( 'tomorrow', 'milieus' );
	$days = (int) ceil( $delta / DAY_IN_SECONDS );
	/* translators: %d: days. */
	return sprintf( __( 'in %d days', 'milieus' ), $days );
}

function milieus_expiry_tier( int $ts ): string {
	if ( $ts === 0 ) return 'permanent';
	$delta = $ts - time();
	if ( $delta <= 0 )                       return 'expired';
	if ( $delta <= 7 * DAY_IN_SECONDS )      return 'urgent';
	if ( $delta <= 30 * DAY_IN_SECONDS )     return 'soon';
	return 'ok';
}

// ── AJAX endpoints ──────────────────────────────────────────────

add_action( 'wp_ajax_milieus_members_list', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_members', 'nonce' );
	$key   = sanitize_key( $_POST['key'] ?? '' );
	$page  = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$search= sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
	wp_send_json_success( milieus_list_members( $key, $page, $search ) );
} );

add_action( 'wp_ajax_milieus_members_search', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_members', 'nonce' );
	$key   = sanitize_key( $_POST['key'] ?? '' );
	$q     = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	if ( strlen( $q ) < 2 ) wp_send_json_success( [] );

	$query = new WP_User_Query( [
		'search'         => '*' . esc_attr( $q ) . '*',
		'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
		'role__not_in'   => [ $key ],
		'number'         => 8,
	] );
	$out = [];
	foreach ( $query->get_results() as $u ) {
		$out[] = [
			'id'    => $u->ID,
			'name'  => $u->display_name ?: $u->user_login,
			'email' => $u->user_email,
			'role'  => $u->roles[0] ?? 'subscriber',
		];
	}
	wp_send_json_success( $out );
} );

add_action( 'wp_ajax_milieus_members_add', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_members', 'nonce' );
	$key = sanitize_key( $_POST['key'] ?? '' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	if ( ! $key || ! $uid ) wp_send_json_error( 'missing args' );

	$ok = milieus_assign_member( $uid, $key, 'manual' );
	if ( ! $ok ) wp_send_json_error( 'could not assign' );

	$u = get_userdata( $uid );
	wp_send_json_success( milieus_format_member_row( $u, $key ) );
} );

add_action( 'wp_ajax_milieus_members_bulk', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_members', 'nonce' );

	$key    = sanitize_key( $_POST['key'] ?? '' );
	$action = sanitize_key( $_POST['bulk_action'] ?? '' );
	$ids    = array_map( 'absint', (array) ( $_POST['user_ids'] ?? [] ) );
	if ( ! $key || ! $action || ! $ids ) wp_send_json_error( 'missing args' );

	$count = 0;
	foreach ( $ids as $uid ) {
		switch ( $action ) {
			case 'revoke':
				if ( milieus_revoke_member( $uid, $key ) ) $count++;
				break;
			case 'extend_30':
				if ( milieus_extend_member( $uid, $key, 30 * DAY_IN_SECONDS ) ) $count++;
				break;
			case 'reset_expiry':
				$g = milieus_get_group( $key );
				$dur = $g['member_duration'] ?? [ 'value' => 0, 'unit' => 'days' ];
				$new = $dur['value'] > 0 ? milieus_duration_to_ts( time(), $dur['value'], $dur['unit'] ) : 0;
				update_user_meta( $uid, MILIEUS_META_EXPIRES . $key, $new );
				$count++;
				break;
		}
	}
	wp_send_json_success( [ 'count' => $count ] );
} );

add_action( 'wp_ajax_milieus_members_csv', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_members', 'nonce' );

	$key = sanitize_key( $_POST['key'] ?? '' );
	$blob = (string) wp_unslash( $_POST['csv'] ?? '' );
	if ( ! $key || ! $blob ) wp_send_json_error( 'missing args' );

	$added = $skipped = 0;
	$lines = preg_split( '/\r\n|\r|\n/', $blob );
	foreach ( $lines as $line ) {
		$email = trim( strtok( $line, ',' ) );
		if ( ! is_email( $email ) ) { $skipped++; continue; }
		$u = get_user_by( 'email', $email );
		if ( ! $u ) { $skipped++; continue; }
		if ( milieus_assign_member( $u->ID, $key, 'csv' ) ) $added++;
		else $skipped++;
	}
	wp_send_json_success( [ 'added' => $added, 'skipped' => $skipped ] );
} );

/**
 * Stream all members of a group as a CSV download. Not AJAX — uses
 * admin-post.php so the browser saves the file directly.
 */
add_action( 'admin_post_milieus_members_export', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_members_export' );

	$key = sanitize_key( $_GET['key'] ?? '' );
	$group = milieus_get_group( $key );
	if ( ! $group ) wp_die( 'unknown group' );

	$users = get_users( [ 'role' => $key, 'orderby' => 'registered', 'order' => 'DESC' ] );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="milieus-' . $key . '-' . gmdate( 'Ymd' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'email', 'name', 'joined_at', 'expires_at', 'source' ] );
	foreach ( $users as $u ) {
		$assigned = (int) get_user_meta( $u->ID, MILIEUS_META_ASSIGNED . $key, true );
		$expires  = (int) get_user_meta( $u->ID, MILIEUS_META_EXPIRES  . $key, true );
		$source   = (string) get_user_meta( $u->ID, MILIEUS_META_SOURCE . $key, true ) ?: 'manual';
		fputcsv( $out, [
			$u->user_email,
			$u->display_name ?: $u->user_login,
			$assigned ? gmdate( 'Y-m-d H:i:s', $assigned ) : '',
			$expires  ? gmdate( 'Y-m-d H:i:s', $expires )  : '',
			$source,
		] );
	}
	fclose( $out );
	exit;
} );

/**
 * Duplicate a group with a "(copy)" suffix on the name. Keeps everything
 * except registration slug (would collide) and signup count.
 */
add_action( 'wp_ajax_milieus_group_duplicate', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_role', 'nonce' );

	$src_key = sanitize_key( $_POST['key'] ?? '' );
	$src = milieus_get_group( $src_key );
	if ( ! $src ) wp_send_json_error( 'unknown group' );

	$new_name = $src['name'] . ' (copy)';
	$new_key  = sanitize_key( $src_key . '_copy' );
	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();
	$base = $new_key; $i = 2;
	while ( isset( $wp_roles->roles[ $new_key ] ) ) {
		$new_key = $base . '_' . $i; $i++;
	}

	$caps = [];
	foreach ( $src['caps'] as $c ) $caps[ $c ] = true;
	add_role( $new_key, $new_name, $caps );

	$copy = $src;
	$copy['key']  = $new_key;
	$copy['name'] = $new_name;
	$copy['reg']['slug']         = ''; // can't share a slug
	$copy['reg']['enabled']      = false;
	$copy['reg']['signup_count'] = 0;
	$copy['updated']             = time();
	milieus_save_group( $new_key, $copy );

	wp_send_json_success( [ 'key' => $new_key, 'name' => $new_name ] );
} );
