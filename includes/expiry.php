<?php
/**
 * Milieus by Therum — group + member expiry sweep.
 *
 * Two expiry timelines run independently:
 *
 *   GROUP lifetime    — the group itself auto-deletes on a date. Members
 *                       are reassigned to the site default role.
 *   MEMBER duration   — each user's membership in a group expires after N
 *                       time units from when they joined. On expiry, the
 *                       user is removed from the group (but the group lives).
 *
 * Both are swept by milieus_expire_sweep, hooked to a daily WP-cron event
 * scheduled at activation. The sweep is also runnable on demand:
 *
 *   do_action( 'milieus_expire_sweep' );
 *
 * Member-level state is stored as user meta. Each (user, role) assignment
 * gets its own pair of meta values prefixed _milieus_assigned_/_milieus_expires_.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_META_ASSIGNED  = '_milieus_assigned_';
const MILIEUS_META_EXPIRES   = '_milieus_expires_';
const MILIEUS_META_SOURCE    = '_milieus_source_';   // manual | link | csv | invite

add_action( 'milieus_expire_sweep', 'milieus_run_expire_sweep' );
add_action( 'milieus_expire_sweep', 'milieus_run_expiry_reminders' );

/**
 * Find memberships expiring within N days and fire an action per user/group
 * so the notifications module (or any listener) can send a reminder. We
 * stamp a per-(user,group) flag so each reminder fires at most once.
 */
function milieus_run_expiry_reminders(): int {
	$days = (int) apply_filters( 'milieus_expiry_reminder_days', 3 );
	if ( $days <= 0 ) return 0;
	$until = time() + $days * DAY_IN_SECONDS;
	$flag  = '_milieus_reminder_sent_';
	$count = 0;
	foreach ( milieus_get_groups() as $key => $g ) {
		$users = get_users( [
			'meta_query' => [
				[ 'key' => MILIEUS_META_EXPIRES . $key, 'value' => time(), 'compare' => '>',  'type' => 'NUMERIC' ],
				[ 'key' => MILIEUS_META_EXPIRES . $key, 'value' => $until, 'compare' => '<=', 'type' => 'NUMERIC' ],
			],
			'fields' => 'ID',
		] );
		foreach ( $users as $uid ) {
			if ( get_user_meta( $uid, $flag . $key, true ) ) continue;
			$expires = (int) get_user_meta( $uid, MILIEUS_META_EXPIRES . $key, true );
			do_action( 'milieus_member_expiring_soon', (int) $uid, $key, $expires );
			update_user_meta( $uid, $flag . $key, time() );
			$count++;
		}
	}
	return $count;
}

/**
 * Run both sweeps. Safe to call repeatedly; idempotent per role/user.
 *
 * @return array{ groups_deleted:int, members_revoked:int }
 */
function milieus_run_expire_sweep(): array {
	$now = time();
	$groups_deleted  = 0;
	$members_revoked = 0;
	$default = get_option( 'default_role', 'subscriber' );

	foreach ( milieus_get_groups() as $key => $g ) {

		// ─── Group lifetime ─────────────────────────────────────────
		$expires_at = (int) ( $g['expires_at'] ?? 0 );
		if ( $expires_at > 0 && $expires_at <= $now ) {
			// Reassign members, then delete.
			$users = get_users( [ 'role' => $key, 'fields' => 'ID' ] );
			foreach ( $users as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$u->remove_role( $key );
					$u->add_role( $default );
					delete_user_meta( $uid, MILIEUS_META_ASSIGNED . $key );
					delete_user_meta( $uid, MILIEUS_META_EXPIRES . $key );
					delete_user_meta( $uid, MILIEUS_META_SOURCE . $key );
				}
			}
			remove_role( $key );
			milieus_delete_custom_role_meta( $key );
			$groups_deleted++;
			continue; // skip member sweep, group is gone
		}

		// ─── Member duration ────────────────────────────────────────
		// Find any user with a stored expiry on this group that's now past.
		$expired = get_users( [
			'meta_query' => [
				[
					'key'     => MILIEUS_META_EXPIRES . $key,
					'value'   => $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				],
				[
					'key'     => MILIEUS_META_EXPIRES . $key,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
			'fields' => 'ID',
		] );
		foreach ( $expired as $uid ) {
			milieus_revoke_member( (int) $uid, $key, $default );
			$members_revoked++;
		}
	}

	return [
		'groups_deleted'  => $groups_deleted,
		'members_revoked' => $members_revoked,
	];
}

/**
 * Add a user to a group with the group's default member duration.
 * Idempotent — re-adding the same user resets the assigned/expires meta.
 */
function milieus_assign_member( int $user_id, string $role_key, string $source = 'manual' ): bool {
	$u = get_userdata( $user_id );
	if ( ! $u ) return false;

	$group = milieus_get_group( $role_key );
	if ( ! $group ) return false;

	$is_new = empty( get_user_meta( $user_id, MILIEUS_META_ASSIGNED . $role_key, true ) );
	$u->add_role( $role_key );

	$now = time();
	update_user_meta( $user_id, MILIEUS_META_ASSIGNED . $role_key, $now );
	update_user_meta( $user_id, MILIEUS_META_SOURCE   . $role_key, $source );

	$dur = $group['member_duration'] ?? [];
	$value = (int) ( $dur['value'] ?? 0 );
	$unit  = $dur['unit']  ?? 'days';
	if ( $value > 0 ) {
		update_user_meta( $user_id, MILIEUS_META_EXPIRES . $role_key, milieus_duration_to_ts( $now, $value, $unit ) );
	} else {
		update_user_meta( $user_id, MILIEUS_META_EXPIRES . $role_key, 0 );
	}

	// Hooks for notifications / webhooks / audit log.
	do_action( 'milieus_member_assigned', $user_id, $role_key, $source, $is_new );
	return true;
}

/**
 * Remove a user from a group. Cleans meta. Reassigns to default role if
 * removing this leaves the user roleless.
 */
function milieus_revoke_member( int $user_id, string $role_key, ?string $default = null ): bool {
	$u = get_userdata( $user_id );
	if ( ! $u ) return false;
	$u->remove_role( $role_key );
	delete_user_meta( $user_id, MILIEUS_META_ASSIGNED . $role_key );
	delete_user_meta( $user_id, MILIEUS_META_EXPIRES . $role_key );
	delete_user_meta( $user_id, MILIEUS_META_SOURCE  . $role_key );
	if ( empty( $u->roles ) ) {
		$default = $default ?: get_option( 'default_role', 'subscriber' );
		$u->add_role( $default );
	}
	do_action( 'milieus_member_revoked', $user_id, $role_key );
	return true;
}

/**
 * Extend a user's membership in a group by N seconds. If they were permanent
 * (expires=0), this is a no-op. If their current expiry is in the past, the
 * extension starts from now; otherwise it adds to the existing expiry.
 */
function milieus_extend_member( int $user_id, string $role_key, int $seconds ): int {
	$current = (int) get_user_meta( $user_id, MILIEUS_META_EXPIRES . $role_key, true );
	if ( $current === 0 ) return 0; // permanent, nothing to extend
	$base = max( $current, time() );
	$new  = $base + $seconds;
	update_user_meta( $user_id, MILIEUS_META_EXPIRES . $role_key, $new );
	return $new;
}

/**
 * Convert (value, unit) duration to an absolute unix timestamp.
 */
function milieus_duration_to_ts( int $from, int $value, string $unit ): int {
	$map = [
		'days'   => DAY_IN_SECONDS,
		'weeks'  => WEEK_IN_SECONDS,
		'months' => MONTH_IN_SECONDS,
		'years'  => YEAR_IN_SECONDS,
	];
	$sec = $map[ $unit ] ?? DAY_IN_SECONDS;
	return $from + ( $value * $sec );
}
