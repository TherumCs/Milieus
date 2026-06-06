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

// ── Bulk approve / reject ───────────────────────────────────────────
add_action( 'admin_post_milieus_bulk_approval', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_approvals' );
	$action = sanitize_key( $_POST['bulk_action'] ?? '' );
	$ids    = array_map( 'absint', (array) ( $_POST['user_ids'] ?? [] ) );
	if ( ! $ids || ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=milieus-approvals' ) );
		exit;
	}

	$count = 0;
	foreach ( $ids as $uid ) {
		$pending = (string) get_user_meta( $uid, MILIEUS_PENDING_KEY, true );
		if ( ! $pending ) continue;
		if ( $action === 'approve' ) {
			milieus_assign_member( $uid, $pending, 'link' );
			do_action( 'milieus_member_approved', $uid, $pending );
		}
		delete_user_meta( $uid, MILIEUS_PENDING_KEY );
		$count++;
	}

	$flash = $action === 'approve' ? 'bulk_approved' : 'bulk_rejected';
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-approvals&flash=' . $flash . '&count=' . $count ) );
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

		<?php milieus_render_tab_nav( 'milieus-approvals' ); ?>

		<?php if ( $flash === 'approved' ): ?><div class="th-flash th-flash-ok">✓ Approved.</div>
		<?php elseif ( $flash === 'rejected' ): ?><div class="th-flash th-flash-ok">✓ Rejected — user kept on default role.</div>
		<?php elseif ( $flash === 'bulk_approved' ): ?><div class="th-flash th-flash-ok">✓ <?php printf( esc_html__( '%d user(s) approved.', 'milieus' ), (int) ( $_GET['count'] ?? 0 ) ); ?></div>
		<?php elseif ( $flash === 'bulk_rejected' ): ?><div class="th-flash th-flash-ok">✓ <?php printf( esc_html__( '%d user(s) rejected.', 'milieus' ), (int) ( $_GET['count'] ?? 0 ) ); ?></div>
		<?php endif; ?>

		<?php if ( ! $users ): ?>
			<div class="th-settings-card"><p style="margin:0;color:var(--tx3)"><?php esc_html_e( 'Nothing pending. New sign-ups via approval-gated registration links will appear here.', 'milieus' ); ?></p></div>
		<?php else: ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="milieus-bulk-approval-form">
				<input type="hidden" name="action" value="milieus_bulk_approval">
				<?php wp_nonce_field( 'milieus_approvals' ); ?>

				<!-- Bulk action bar -->
				<div id="milieus-approval-bulk" style="display:none;margin-bottom:10px;padding:10px 14px;background:color-mix(in srgb,#2563eb 6%,#fafaf9);border:1px solid color-mix(in srgb,#2563eb 18%,transparent);border-radius:10px;font-size:13px;display:none;align-items:center;gap:10px">
					<span><strong id="milieus-approval-bulk-count">0</strong> <?php esc_html_e( 'selected', 'milieus' ); ?></span>
					<button type="submit" name="bulk_action" value="approve" class="th-button th-button-primary"><?php esc_html_e( 'Approve selected', 'milieus' ); ?></button>
					<button type="submit" name="bulk_action" value="reject" class="th-button" style="color:var(--err)" onclick="return confirm('<?php echo esc_js( __( 'Reject all selected users?', 'milieus' ) ); ?>')"><?php esc_html_e( 'Reject selected', 'milieus' ); ?></button>
				</div>

			<div class="th-settings-card">
				<table class="th-roles-table">
					<thead><tr>
						<th style="width:40px"><input type="checkbox" id="milieus-approval-check-all"></th>
						<th><?php esc_html_e( 'User', 'milieus' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Wants to join', 'milieus' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Registered', 'milieus' ); ?></th>
						<th style="width:220px;text-align:right"></th>
					</tr></thead>
					<tbody>
						<?php
						$extra_keys = [ 'name', 'company', 'phone', 'referral', 'how-heard' ];
						$extra_labels = [
							'name'      => __( 'Full name', 'milieus' ),
							'company'   => __( 'Company', 'milieus' ),
							'phone'     => __( 'Phone', 'milieus' ),
							'referral'  => __( 'Referral code', 'milieus' ),
							'how-heard' => __( 'How did you hear?', 'milieus' ),
						];
						foreach ( $users as $u ):
							$group_key = (string) get_user_meta( $u->ID, MILIEUS_PENDING_KEY, true );
							$group = milieus_get_group( $group_key );
							$gname = $group ? $group['name'] : $group_key;
							// Collect extra registration fields.
							$extras = [];
							foreach ( $extra_keys as $ek ) {
								$v = (string) get_user_meta( $u->ID, 'milieus_' . $ek, true );
								if ( $v !== '' ) $extras[ $ek ] = $v;
							}
						?>
						<tr>
							<td><input type="checkbox" name="user_ids[]" value="<?php echo (int) $u->ID; ?>" class="milieus-approval-check"></td>
							<td>
								<strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong><br>
								<span class="th-roles-caps"><?php echo esc_html( $u->user_email ); ?></span>
								<?php if ( $extras ): ?>
									<div style="margin-top:6px;font-size:12px;color:var(--tx2)">
										<?php foreach ( $extras as $ek => $ev ): ?>
											<div style="margin-top:2px"><span style="color:var(--tx3);font-weight:600;text-transform:uppercase;font-size:10px;letter-spacing:.06em"><?php echo esc_html( $extra_labels[ $ek ] ?? $ek ); ?></span> <?php echo esc_html( $ev ); ?></div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
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
			</form>
			<script>
			(function(){
				var checkAll = document.getElementById('milieus-approval-check-all');
				var checks = document.querySelectorAll('.milieus-approval-check');
				var bulkBar = document.getElementById('milieus-approval-bulk');
				var bulkCount = document.getElementById('milieus-approval-bulk-count');
				function update() {
					var n = document.querySelectorAll('.milieus-approval-check:checked').length;
					bulkBar.style.display = n > 0 ? 'flex' : 'none';
					bulkCount.textContent = n;
				}
				checkAll.addEventListener('change', function() {
					checks.forEach(function(c){ c.checked = checkAll.checked; });
					update();
				});
				checks.forEach(function(c){ c.addEventListener('change', update); });
			})();
			</script>
		<?php endif; ?>
	</div></div>
	<?php
}
