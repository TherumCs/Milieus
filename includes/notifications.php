<?php
/**
 * Milieus by Therum — email notifications.
 *
 * Four events fire emails, each toggleable from Milieus → Settings:
 *
 *   admin_signup       — new sign-up via /register/{slug}, emails admin
 *   member_welcome     — welcome email to the new member (brand-colored)
 *   member_expiring    — N days before membership expires
 *   admin_approval     — new pending user awaiting approval
 *
 * All emails are HTML, use the group's brand color and heading text where
 * relevant, and live behind do_action hooks the rest of the plugin fires.
 *
 * Settings stored at option milieus_notifications:
 *   admin_signup    bool
 *   member_welcome  bool
 *   member_expiring bool
 *   admin_approval  bool
 *   admin_to        string (email address, defaults to admin_email option)
 *   from_name       string (defaults to site name)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_NOTIF_OPTION = 'milieus_notifications';

function milieus_notifications_defaults(): array {
	return [
		'admin_signup'    => true,
		'member_welcome'  => true,
		'member_expiring' => true,
		'admin_approval'  => true,
		'admin_to'        => get_option( 'admin_email' ),
		'from_name'       => get_bloginfo( 'name' ),
	];
}

function milieus_notifications_settings(): array {
	return wp_parse_args( (array) get_option( MILIEUS_NOTIF_OPTION, [] ), milieus_notifications_defaults() );
}

// ── Listeners ────────────────────────────────────────────────────────

add_action( 'milieus_member_assigned', function( $uid, $key, $source, $is_new ) {
	$s = milieus_notifications_settings();
	$group = milieus_get_group( $key );
	if ( ! $group || ! $is_new ) return;

	if ( $s['member_welcome'] ) milieus_send_welcome( (int) $uid, $group );
	if ( $s['admin_signup'] && $source === 'link' ) milieus_send_admin_signup( (int) $uid, $group );
}, 10, 4 );

add_action( 'milieus_member_expiring_soon', function( $uid, $key, $expires ) {
	$s = milieus_notifications_settings();
	if ( ! $s['member_expiring'] ) return;
	$group = milieus_get_group( $key );
	if ( ! $group ) return;
	milieus_send_expiring( (int) $uid, $group, (int) $expires );
}, 10, 3 );

// Approval inbox triggers
add_action( 'milieus_pending_created', function( $uid, $key ) {
	$s = milieus_notifications_settings();
	if ( ! $s['admin_approval'] ) return;
	$group = milieus_get_group( $key );
	if ( ! $group ) return;
	milieus_send_admin_approval( (int) $uid, $group );
}, 10, 2 );

// ── Senders ──────────────────────────────────────────────────────────

function milieus_send_welcome( int $uid, array $group ): void {
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	$reg = wp_parse_args( $group['reg'] ?? [], milieus_reg_defaults() );
	$color = $group['color'] ?? '#2563eb';
	$heading = $reg['heading'] ?: sprintf( __( 'Welcome to %s', 'milieus' ), $group['name'] );
	$body = milieus_email_shell( $heading, $color,
		'<p>' . esc_html( $reg['lede'] ?: __( "You're in. Here's what your membership unlocks.", 'milieus' ) ) . '</p>'
		. milieus_member_benefit_list( $group )
		. '<p style="margin-top:24px"><a href="' . esc_url( wp_login_url() ) . '" style="display:inline-block;padding:11px 18px;background:' . esc_attr( $color ) . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Sign in →</a></p>'
	);
	milieus_send_email( $u->user_email, $heading, $body );
}

function milieus_send_admin_signup( int $uid, array $group ): void {
	$s = milieus_notifications_settings();
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	$subj = sprintf( '[%s] New %s sign-up · %s', $s['from_name'], $group['name'], $u->user_email );
	$body = milieus_email_shell( 'New sign-up', $group['color'] ?? '#2563eb',
		'<p><strong>' . esc_html( $u->display_name ?: $u->user_login ) . '</strong> just joined <strong>' . esc_html( $group['name'] ) . '</strong>.</p>'
		. '<p>Email: <a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></p>'
		. '<p style="margin-top:24px"><a href="' . esc_url( admin_url( 'admin.php?page=milieus-roles' ) ) . '" style="color:' . esc_attr( $group['color'] ?? '#2563eb' ) . '">Manage in admin →</a></p>'
	);
	milieus_send_email( $s['admin_to'], $subj, $body );
}

function milieus_send_admin_approval( int $uid, array $group ): void {
	$s = milieus_notifications_settings();
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	$subj = sprintf( '[%s] Approval needed · %s wants to join %s', $s['from_name'], $u->user_email, $group['name'] );
	$body = milieus_email_shell( 'Approval needed', $group['color'] ?? '#2563eb',
		'<p><strong>' . esc_html( $u->user_email ) . '</strong> signed up for <strong>' . esc_html( $group['name'] ) . '</strong> and is awaiting your approval.</p>'
		. '<p style="margin-top:24px"><a href="' . esc_url( admin_url( 'admin.php?page=milieus-approvals' ) ) . '" style="display:inline-block;padding:11px 18px;background:' . esc_attr( $group['color'] ?? '#2563eb' ) . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:600">Review pending →</a></p>'
	);
	milieus_send_email( $s['admin_to'], $subj, $body );
}

function milieus_send_expiring( int $uid, array $group, int $expires ): void {
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	$days_left = max( 1, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
	$heading = sprintf( __( 'Your %s membership expires soon', 'milieus' ), $group['name'] );
	$body = milieus_email_shell( $heading, $group['color'] ?? '#2563eb',
		'<p>Your membership in <strong>' . esc_html( $group['name'] ) . '</strong> expires in <strong>' . $days_left . ' day' . ( $days_left === 1 ? '' : 's' ) . '</strong>.</p>'
		. '<p>Contact us to extend, or do nothing — you\'ll keep access until ' . esc_html( wp_date( 'F j, Y', $expires ) ) . '.</p>'
	);
	milieus_send_email( $u->user_email, $heading, $body );
}

// ── Helpers ──────────────────────────────────────────────────────────

function milieus_email_shell( string $heading, string $color, string $inner_html ): string {
	$site = esc_html( get_bloginfo( 'name' ) );
	return '<!doctype html><html><body style="margin:0;padding:0;background:#fafaf9;font:14px/1.55 -apple-system,BlinkMacSystemFont,Segoe UI,Inter,sans-serif;color:#1c1917">'
		. '<div style="max-width:560px;margin:40px auto;background:#fff;border:1px solid #e7e5e4;border-radius:14px;padding:36px 32px;box-shadow:0 8px 32px rgba(0,0,0,.04)">'
		. '<div style="font:700 11px/1 system-ui;letter-spacing:.18em;color:#a8a29e;text-transform:uppercase;margin-bottom:16px">' . $site . '</div>'
		. '<h1 style="font-size:22px;font-weight:700;margin:0 0 6px;color:#1c1917;letter-spacing:-.01em">' . esc_html( $heading ) . '</h1>'
		. '<div style="color:#57534e;line-height:1.6">' . $inner_html . '</div>'
		. '<div style="margin-top:32px;padding-top:18px;border-top:1px solid #e7e5e4;font-size:12px;color:#a8a29e">Sent by ' . $site . '. <a href="' . esc_url( home_url() ) . '" style="color:' . esc_attr( $color ) . '">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a></div>'
		. '</div></body></html>';
}

function milieus_member_benefit_list( array $group ): string {
	$rows = [];
	if ( ! empty( $group['discount'] ) && (float) $group['discount'] > 0 ) {
		$rows[] = sprintf( __( '%s%% off every order', 'milieus' ), rtrim( rtrim( number_format( (float) $group['discount'], 2 ), '0' ), '.' ) );
	}
	$dur = $group['member_duration'] ?? [];
	if ( ! empty( $dur['value'] ) ) {
		$rows[] = sprintf( __( 'Membership active for %1$d %2$s', 'milieus' ), $dur['value'], $dur['unit'] );
	} else {
		$rows[] = __( 'Lifetime membership', 'milieus' );
	}
	if ( ! $rows ) return '';
	$out = '<ul style="margin:14px 0;padding:0 0 0 20px;color:#1c1917">';
	foreach ( $rows as $r ) $out .= '<li style="margin:4px 0">' . esc_html( $r ) . '</li>';
	$out .= '</ul>';
	return $out;
}

function milieus_send_email( string $to, string $subject, string $html_body ): bool {
	$s = milieus_notifications_settings();
	$from = sprintf( '%s <%s>', $s['from_name'], $s['admin_to'] );
	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $from,
	];
	return wp_mail( $to, $subject, $html_body, $headers );
}

// ── Settings page (Milieus → Settings) ───────────────────────────────

add_action( 'admin_menu', function() {
	add_submenu_page(
		'milieus-roles',
		__( 'Settings', 'milieus' ),
		__( 'Settings', 'milieus' ),
		'manage_options',
		'milieus-settings',
		'milieus_render_settings_page'
	);
}, 30 );

add_action( 'admin_post_milieus_save_settings', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_settings' );
	update_option( MILIEUS_NOTIF_OPTION, [
		'admin_signup'    => ! empty( $_POST['admin_signup'] ),
		'member_welcome'  => ! empty( $_POST['member_welcome'] ),
		'member_expiring' => ! empty( $_POST['member_expiring'] ),
		'admin_approval'  => ! empty( $_POST['admin_approval'] ),
		'admin_to'        => sanitize_email( $_POST['admin_to'] ?? '' ) ?: get_option( 'admin_email' ),
		'from_name'       => sanitize_text_field( $_POST['from_name'] ?? '' ) ?: get_bloginfo( 'name' ),
	] );
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-settings&flash=saved' ) );
	exit;
} );

function milieus_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	$s = milieus_notifications_settings();
	$flash = sanitize_key( $_GET['flash'] ?? '' );
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Settings', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Email notifications for new sign-ups, welcomes, expiry reminders, and pending approvals. All sent via wp_mail() using your group\'s brand color.', 'milieus' ); ?></p>
		</div>
		<?php if ( $flash === 'saved' ): ?><div class="th-flash th-flash-ok">✓ Settings saved.</div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="milieus_save_settings">
			<?php wp_nonce_field( 'milieus_settings' ); ?>

			<div class="th-settings-card">
				<h3><?php esc_html_e( 'Email notifications', 'milieus' ); ?></h3>
				<p class="th-settings-card-sub"><?php esc_html_e( 'Pick which events trigger emails.', 'milieus' ); ?></p>

				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'Welcome new members', 'milieus' ); ?><small><?php esc_html_e( 'Branded welcome email to anyone newly added to a group.', 'milieus' ); ?></small></div>
					<div><label><input type="checkbox" name="member_welcome" <?php checked( $s['member_welcome'] ); ?>> <?php esc_html_e( 'Enabled', 'milieus' ); ?></label></div>
				</div>

				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'Notify me on new sign-ups', 'milieus' ); ?><small><?php esc_html_e( 'Plain admin notice when someone joins via a /register/{slug} link.', 'milieus' ); ?></small></div>
					<div><label><input type="checkbox" name="admin_signup" <?php checked( $s['admin_signup'] ); ?>> <?php esc_html_e( 'Enabled', 'milieus' ); ?></label></div>
				</div>

				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'Expiry reminders', 'milieus' ); ?><small><?php esc_html_e( 'Email the member 3 days before their membership expires (filterable via milieus_expiry_reminder_days).', 'milieus' ); ?></small></div>
					<div><label><input type="checkbox" name="member_expiring" <?php checked( $s['member_expiring'] ); ?>> <?php esc_html_e( 'Enabled', 'milieus' ); ?></label></div>
				</div>

				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'Approval requests', 'milieus' ); ?><small><?php esc_html_e( 'Notify admins when someone is awaiting approval.', 'milieus' ); ?></small></div>
					<div><label><input type="checkbox" name="admin_approval" <?php checked( $s['admin_approval'] ); ?>> <?php esc_html_e( 'Enabled', 'milieus' ); ?></label></div>
				</div>
			</div>

			<div class="th-settings-card">
				<h3><?php esc_html_e( 'Sender', 'milieus' ); ?></h3>
				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'From name', 'milieus' ); ?></div>
					<div><input class="th-input" type="text" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" style="max-width:360px"></div>
				</div>
				<div class="th-settings-row">
					<div class="th-settings-row-label"><?php esc_html_e( 'Admin notification address', 'milieus' ); ?></div>
					<div><input class="th-input" type="email" name="admin_to" value="<?php echo esc_attr( $s['admin_to'] ); ?>" style="max-width:360px"></div>
				</div>
			</div>

			<p style="margin-top:14px;display:flex;gap:10px;align-items:center">
				<button type="submit" class="th-button th-button-primary"><?php esc_html_e( 'Save settings', 'milieus' ); ?></button>
				<button type="button" class="th-button" id="milieus-test-email"><?php esc_html_e( '✉ Send test email', 'milieus' ); ?></button>
				<span id="milieus-test-email-result" style="font-size:12px;color:var(--tx3)"></span>
			</p>
		</form>
		<script>
		(function(){
			var btn = document.getElementById('milieus-test-email');
			var res = document.getElementById('milieus-test-email-result');
			btn.addEventListener('click', function() {
				btn.disabled = true;
				res.textContent = <?php echo wp_json_encode( __( 'Sending…', 'milieus' ) ); ?>;
				res.style.color = 'var(--tx3)';
				var fd = new FormData();
				fd.append('action', 'milieus_test_email');
				fd.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'milieus_test_email' ) ); ?>);
				fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled = false;
						if (j && j.success) {
							res.textContent = '✓ ' + (j.data.msg || 'Sent');
							res.style.color = 'var(--ok,#16a34a)';
						} else {
							res.textContent = '✗ ' + ((j && j.data) || 'Failed');
							res.style.color = 'var(--err,#dc2626)';
						}
					}).catch(function(){
						btn.disabled = false;
						res.textContent = '✗ Network error';
						res.style.color = 'var(--err,#dc2626)';
					});
			});
		})();
		</script>
	</div></div>
	<?php
}

// ── AJAX: send test email ───────────────────────────────────────────

add_action( 'wp_ajax_milieus_test_email', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_test_email' );

	$s    = milieus_notifications_settings();
	$to   = $s['admin_to'];
	$subj = sprintf( '[%s] Milieus test email', $s['from_name'] );
	$body = milieus_email_shell(
		__( 'Test email', 'milieus' ),
		'#2563eb',
		'<p>' . esc_html__( 'If you can read this, your Milieus email settings are working correctly.', 'milieus' ) . '</p>'
		. '<p style="color:#a8a29e;font-size:12px">' . esc_html( sprintf( __( 'Sent to %s at %s', 'milieus' ), $to, wp_date( 'g:i a · M j, Y' ) ) ) . '</p>'
	);
	$sent = milieus_send_email( $to, $subj, $body );
	if ( $sent ) {
		wp_send_json_success( [ 'msg' => sprintf( __( 'Sent to %s', 'milieus' ), $to ) ] );
	} else {
		wp_send_json_error( __( 'wp_mail() returned false — check your mail configuration.', 'milieus' ) );
	}
} );
