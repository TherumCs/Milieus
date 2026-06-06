<?php
/**
 * Milieus by Therum — approval inbox.
 *
 * When a group has reg.approval=on, /register/{slug} creates the user with
 * the default WP role and sets _milieus_pending_group user meta. They're
 * "pending" until an admin promotes them via this screen, at which point
 * milieus_assign_member runs and they land in the group.
 *
 * Lives under Milieus → Approvals. Reject removes the pending flag without
 * touching the user record.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
	$pending = milieus_pending_count();
	$label   = __( 'Approvals', 'milieus' );
	if ( $pending > 0 ) {
		$label .= ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . esc_html( $pending ) . '</span></span>';
	}
	add_submenu_page(
		'milieus-roles',
		__( 'Approvals', 'milieus' ),
		$label, // allow HTML for the badge
		'manage_options',
		'milieus-approvals',
		'milieus_render_approvals_page'
	);
}, 15 );

function milieus_pending_count(): int {
	$q = new WP_User_Query( [
		'meta_query' => [
			[ 'key' => MILIEUS_PENDING_KEY, 'compare' => 'EXISTS' ],
		],
		'fields' => 'ID',
		'number' => 1,
	] );
	return (int) $q->get_total();
}

function milieus_pending_users(): array {
	$q = new WP_User_Query( [
		'meta_query' => [
			[ 'key' => MILIEUS_PENDING_KEY, 'compare' => 'EXISTS' ],
		],
		'orderby' => 'registered',
		'order'   => 'DESC',
		'number'  => 200,
	] );
	return $q->get_results();
}

// admin-post handlers
add_action( 'admin_post_milieus_approve', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_approvals' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	$pending = (string) get_user_meta( $uid, MILIEUS_PENDING_KEY, true );
	if ( $uid && $pending ) {
		milieus_assign_member( $uid, $pending, 'link' );
		delete_user_meta( $uid, MILIEUS_PENDING_KEY );
		// Fire welcome email if notifications module is loaded.
		do_action( 'milieus_member_approved', $uid, $pending );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-approvals&flash=approved' ) );
	exit;
} );

add_action( 'admin_post_milieus_reject', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_approvals' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	if ( $uid ) delete_user_meta( $uid, MILIEUS_PENDING_KEY );
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-approvals&flash=rejected' ) );
	exit;
} );

function milieus_render_approvals_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

	$users = milieus_pending_users();
	$flash = sanitize_key( $_GET['flash'] ?? '' );
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Approvals', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Users who registered via a group with the approval gate on. Approve to add them to the group; reject to leave them as a default-role user.', 'milieus' ); ?></p>
		</div>

		<?php if ( $flash === 'approved' ): ?><div class="th-flash th-flash-ok">✓ Approved.</div>
		<?php elseif ( $flash === 'rejected' ): ?><div class="th-flash th-flash-ok">✓ Rejected — user kept on default role.</div>
		<?php endif; ?>

		<?php if ( ! $users ): ?>
			<div class="th-settings-card"><p style="margin:0;color:var(--tx3)"><?php esc_html_e( 'Nothing pending. New sign-ups via approval-gated registration links will appear here.', 'milieus' ); ?></p></div>
		<?php else: ?>
			<div class="th-settings-card">
				<table class="th-roles-table">
					<thead><tr>
						<th><?php esc_html_e( 'User', 'milieus' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Wants to join', 'milieus' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Registered', 'milieus' ); ?></th>
						<th style="width:220px;text-align:right"></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $users as $u ):
							$group_key = (string) get_user_meta( $u->ID, MILIEUS_PENDING_KEY, true );
							$group = milieus_get_group( $group_key );
							$gname = $group ? $group['name'] : $group_key;
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong><br>
								<span class="th-roles-caps"><?php echo esc_html( $u->user_email ); ?></span>
							</td>
							<td><span class="th-tag th-tag-custom"><?php echo esc_html( $gname ); ?></span></td>
							<td class="th-roles-caps"><?php echo esc_html( wp_date( 'M j, Y · g:i a', strtotime( $u->user_registered ) ) ); ?></td>
							<td style="text-align:right">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="milieus_approve">
									<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
									<?php wp_nonce_field( 'milieus_approvals' ); ?>
									<button class="th-button th-button-primary"><?php esc_html_e( 'Approve', 'milieus' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Reject this user?')">
									<input type="hidden" name="action" value="milieus_reject">
									<input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
									<?php wp_nonce_field( 'milieus_approvals' ); ?>
									<button class="th-button" style="color:var(--err)"><?php esc_html_e( 'Reject', 'milieus' ); ?></button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div></div>
	<?php
}
