<?php
/**
 * Milieus by Therum — REST API at /wp-json/milieus/v1/.
 *
 * Endpoints (all require manage_options):
 *
 *   GET    /groups                       — list all custom groups
 *   GET    /groups/(?P<key>[\w-]+)       — single group
 *   GET    /groups/(?P<key>[\w-]+)/members
 *                                        — list members (?page=1&per_page=25&search=foo)
 *   POST   /groups/(?P<key>[\w-]+)/members
 *                                        — body: { user_id|email, source? }
 *   DELETE /groups/(?P<key>[\w-]+)/members/(?P<user_id>\d+)
 *                                        — revoke a member
 *   POST   /groups/(?P<key>[\w-]+)/members/(?P<user_id>\d+)/extend
 *                                        — body: { seconds }
 *
 * Auth: standard WP REST auth (application passwords work great here).
 * Permissions: manage_options across the board for v1; can split later.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function() {
	$ns = 'milieus/v1';
	$can = function() { return current_user_can( 'manage_options' ); };

	register_rest_route( $ns, '/groups', [
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function() {
			$out = [];
			foreach ( milieus_get_groups() as $key => $g ) {
				$out[] = milieus_rest_group_shape( $key, $g );
			}
			return rest_ensure_response( $out );
		},
	] );

	register_rest_route( $ns, '/groups/(?P<key>[\w\-]+)', [
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function( WP_REST_Request $req ) {
			$g = milieus_get_group( sanitize_key( $req['key'] ) );
			if ( ! $g ) return new WP_Error( 'milieus_not_found', 'Group not found', [ 'status' => 404 ] );
			return rest_ensure_response( milieus_rest_group_shape( $g['key'], $g ) );
		},
	] );

	register_rest_route( $ns, '/groups/(?P<key>[\w\-]+)/members', [
		[
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function( WP_REST_Request $req ) {
				$key = sanitize_key( $req['key'] );
				if ( ! milieus_get_group( $key ) ) return new WP_Error( 'milieus_not_found', 'Group not found', [ 'status' => 404 ] );
				$page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
				$search = (string) $req->get_param( 'search' );
				return rest_ensure_response( milieus_list_members( $key, $page, $search ) );
			},
		],
		[
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function( WP_REST_Request $req ) {
				$key = sanitize_key( $req['key'] );
				if ( ! milieus_get_group( $key ) ) return new WP_Error( 'milieus_not_found', 'Group not found', [ 'status' => 404 ] );
				$uid = (int) $req->get_param( 'user_id' );
				if ( ! $uid && ( $email = sanitize_email( (string) $req->get_param( 'email' ) ) ) ) {
					$u = get_user_by( 'email', $email );
					$uid = $u ? $u->ID : 0;
				}
				if ( ! $uid ) return new WP_Error( 'milieus_user_required', 'user_id or email required', [ 'status' => 400 ] );
				$source = sanitize_key( (string) $req->get_param( 'source' ) ) ?: 'api';
				if ( ! milieus_assign_member( $uid, $key, $source ) ) {
					return new WP_Error( 'milieus_assign_failed', 'Could not assign', [ 'status' => 500 ] );
				}
				$u = get_userdata( $uid );
				return rest_ensure_response( milieus_format_member_row( $u, $key ) );
			},
		],
	] );

	register_rest_route( $ns, '/groups/(?P<key>[\w\-]+)/members/(?P<user_id>\d+)', [
		'methods'             => 'DELETE',
		'permission_callback' => $can,
		'callback'            => function( WP_REST_Request $req ) {
			$key = sanitize_key( $req['key'] );
			$uid = (int) $req['user_id'];
			if ( ! get_userdata( $uid ) ) return new WP_Error( 'milieus_user_not_found', 'User not found', [ 'status' => 404 ] );
			if ( ! milieus_get_group( $key ) ) return new WP_Error( 'milieus_group_not_found', 'Group not found', [ 'status' => 404 ] );
			$ok = milieus_revoke_member( $uid, $key );
			return rest_ensure_response( [ 'revoked' => $ok ] );
		},
	] );

	register_rest_route( $ns, '/groups/(?P<key>[\w\-]+)/members/(?P<user_id>\d+)/extend', [
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function( WP_REST_Request $req ) {
			$key = sanitize_key( $req['key'] );
			$uid = (int) $req['user_id'];
			if ( ! get_userdata( $uid ) ) return new WP_Error( 'milieus_user_not_found', 'User not found', [ 'status' => 404 ] );
			if ( ! milieus_get_group( $key ) ) return new WP_Error( 'milieus_group_not_found', 'Group not found', [ 'status' => 404 ] );
			$seconds = (int) $req->get_param( 'seconds' );
			if ( $seconds <= 0 ) return new WP_Error( 'milieus_bad_seconds', 'seconds must be > 0', [ 'status' => 400 ] );
			$new = milieus_extend_member( $uid, $key, $seconds );
			return rest_ensure_response( [ 'expires_at' => $new ] );
		},
	] );
} );

/**
 * Cache count_users() per request — the call scans the entire users table,
 * so running it once per group in a loop is an N+1 on a very expensive query.
 */
function milieus_cached_role_counts(): array {
	static $counts = null;
	if ( $counts === null ) {
		$counts = count_users()['avail_roles'] ?? [];
	}
	return $counts;
}

function milieus_rest_group_shape( string $key, array $g ): array {
	return [
		'key'             => $key,
		'name'            => $g['name'] ?? $key,
		'color'           => $g['color'] ?? '#2563eb',
		'caps'            => $g['caps'] ?? [],
		'discount'        => (float) ( $g['discount'] ?? 0 ),
		'expires_at'      => (int) ( $g['expires_at'] ?? 0 ),
		'member_duration' => $g['member_duration'] ?? [ 'value' => 0, 'unit' => 'days' ],
		'reg'             => wp_parse_args( $g['reg'] ?? [], milieus_reg_defaults() ),
		'member_count'    => (int) ( milieus_cached_role_counts()[ $key ] ?? 0 ),
	];
}
