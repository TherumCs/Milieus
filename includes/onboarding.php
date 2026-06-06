<?php
/**
 * Milieus by Therum — onboarding, plugin meta, starter packs, help helper.
 *
 * Lumped together because they all serve the "first-run / discoverability"
 * job. None of them is large enough to deserve its own file.
 *
 * Provides:
 *   - Plugin row meta (Settings · Docs · Support links on the Plugins page)
 *   - First-activation admin notice ("Create your first group →")
 *   - Starter pack picker — preset group templates with sensible defaults
 *   - milieus_help( $text ) — small ? tooltip helper for inline field help
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Plugin row meta ──────────────────────────────────────────────────

add_filter( 'plugin_action_links_' . plugin_basename( MILIEUS_FILE ), function( $links ) {
	$mine = [
		'settings' => '<a href="' . esc_url( admin_url( 'admin.php?page=milieus-settings' ) ) . '">' . esc_html__( 'Settings', 'milieus' ) . '</a>',
		'groups'   => '<a href="' . esc_url( admin_url( 'admin.php?page=milieus-roles' ) ) . '">' . esc_html__( 'Groups', 'milieus' ) . '</a>',
	];
	return array_merge( $mine, $links );
} );

add_filter( 'plugin_row_meta', function( $meta, $file ) {
	if ( $file !== plugin_basename( MILIEUS_FILE ) ) return $meta;
	$meta[] = '<a href="https://github.com/TherumCs/Milieus" target="_blank" rel="noopener">' . esc_html__( 'Docs', 'milieus' ) . '</a>';
	$meta[] = '<a href="https://github.com/TherumCs/Milieus/issues" target="_blank" rel="noopener">' . esc_html__( 'Support', 'milieus' ) . '</a>';
	return $meta;
}, 10, 2 );

// ── First-activation flag + dismissible notice ───────────────────────

register_activation_hook( MILIEUS_FILE, function() {
	if ( false === get_option( 'milieus_onboarded' ) ) {
		add_option( 'milieus_onboarded', false );
	}
} );

add_action( 'admin_post_milieus_dismiss_onboarding', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_dismiss_onboarding' );
	update_option( 'milieus_onboarded', true );
	wp_safe_redirect( wp_get_referer() ?: admin_url() );
	exit;
} );

add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( get_option( 'milieus_onboarded' ) ) return;
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && strpos( (string) $screen->id, 'milieus' ) === false && $screen->base !== 'dashboard' && $screen->base !== 'plugins' ) return;

	$create_url = admin_url( 'admin.php?page=milieus-roles&milieus_template=friends-family' );
	$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=milieus_dismiss_onboarding' ), 'milieus_dismiss_onboarding' );
	?>
	<div class="notice notice-info" style="border-left-color:#2563eb">
		<p><strong>Milieus is ready.</strong> Group your users into Friends & Family, VIP, or Beta tiers — each with their own caps, registration link, and optional WooCommerce discount.</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Create your first group →', 'milieus' ); ?></a>
			<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'milieus' ); ?></a>
		</p>
	</div>
	<?php
} );

// ── Starter packs ────────────────────────────────────────────────────

/**
 * Predefined group templates that pre-fill the editor with sensible
 * defaults. Keyed by URL slug (passed via ?milieus_template=...).
 */
function milieus_starter_packs(): array {
	return [
		'friends-family' => [
			'name'    => 'Friends & Family',
			'color'   => '#e83b3b',
			'bundles' => [ 'read', 'shop_customer' ],
			'discount' => 20,
			'member_duration' => [ 'value' => 0, 'unit' => 'days' ],
			'reg' => [
				'slug'    => 'friends',
				'enabled' => false,
				'heading' => 'Join Friends & Family',
				'lede'    => "You're family. 20% off every order, forever.",
				'button'  => 'Become a Friend →',
				'color'   => '#e83b3b',
			],
		],
		'vip' => [
			'name'    => 'VIP',
			'color'   => '#7c3aed',
			'bundles' => [ 'read', 'shop_customer' ],
			'discount' => 25,
			'member_duration' => [ 'value' => 0, 'unit' => 'days' ],
			'reg' => [
				'slug'    => 'vip',
				'enabled' => false,
				'heading' => 'VIP Access',
				'lede'    => 'Early access, exclusive products, 25% off.',
				'button'  => 'Become a VIP →',
				'color'   => '#7c3aed',
			],
		],
		'beta-testers' => [
			'name'    => 'Beta Testers',
			'color'   => '#10b981',
			'bundles' => [ 'read' ],
			'discount' => 0,
			'member_duration' => [ 'value' => 90, 'unit' => 'days' ],
			'reg' => [
				'slug'    => 'beta',
				'enabled' => false,
				'heading' => 'Join the Beta',
				'lede'    => 'Help shape what we build next. 90-day access to new features.',
				'button'  => 'Join the Beta →',
				'color'   => '#10b981',
				'approval'=> true,
			],
		],
		'trial' => [
			'name'    => '14-Day Trial',
			'color'   => '#f59e0b',
			'bundles' => [ 'read' ],
			'discount' => 0,
			'member_duration' => [ 'value' => 14, 'unit' => 'days' ],
			'reg' => [
				'slug'    => 'trial',
				'enabled' => false,
				'heading' => 'Start your free trial',
				'lede'    => '14 days, full access, no card required.',
				'button'  => 'Start trial →',
				'color'   => '#f59e0b',
			],
		],
	];
}

/**
 * Inject the selected starter pack into the page data so the JS picks
 * it up and pre-fills the editor on load.
 */
add_action( 'admin_footer-toplevel_page_milieus-roles', 'milieus_emit_starter_template' );
add_action( 'admin_footer-users_page_milieus-roles',    'milieus_emit_starter_template' );
function milieus_emit_starter_template(): void {
	$key = sanitize_key( $_GET['milieus_template'] ?? '' );
	if ( ! $key ) return;
	$packs = milieus_starter_packs();
	if ( ! isset( $packs[ $key ] ) ) return;
	$pack = $packs[ $key ];
	?>
	<script>
	(function() {
		var template = <?php echo wp_json_encode( $pack ); ?>;
		// Reuse the editor's openEditor() — but we need an object that
		// looks like a saved group. Fake out the bits openEditor expects.
		var stub = Object.assign({
			key: '', caps: [], expires_at: 0,
		}, template);
		stub.reg = Object.assign({
			enabled:false, slug:'', logo:'', brand:'', color:'#2563eb',
			button:'', extras:[], redirect:'', approval:false, max_signups:0,
			bg_kind:'solid', bg_solid:'#fafaf9', bg_grad_1:'#fde68a',
			bg_grad_2:'#fca5a5', bg_grad_dir:'135deg', bg_image:'',
			bg_dim:true, bg_blur:false, welcome_enabled:false,
			welcome_heading:'', welcome_body:'', welcome_cta:'',
		}, template.reg || {});
		// Defer until admin.js has bound openEditor (it's at the end of the file).
		setTimeout(function() {
			// Use the "New group" button to trigger open then patch values in.
			var btn = document.querySelector('[data-role-new]');
			if (btn) btn.click();
			// Re-call openEditor with the template via a custom path: set window flag,
			// then dispatch a custom event admin.js listens for.
			document.dispatchEvent(new CustomEvent('milieus:apply-template', { detail: stub }));
		}, 200);
	})();
	</script>
	<?php
}

// ── Inline help tooltip ──────────────────────────────────────────────

/**
 * Render a small ? icon with a hover tooltip. Usable inline anywhere in
 * the admin: <label>Foo <?php milieus_help( 'Foo is bar' ); ?></label>
 */
function milieus_help( string $text ): void {
	echo '<span class="th-help" tabindex="0" aria-label="' . esc_attr( $text ) . '"><span class="th-help-icon">?</span><span class="th-help-text">' . esc_html( $text ) . '</span></span>';
}
