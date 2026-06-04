<?php
/**
 * Milieus by Therum — admin page (Users → Roles).
 *
 * Mirrors the Therum OS roles-engine surface: a Therum-OS-style page wrapper
 * with an "Admin · Roles" eyebrow, title, sub, and three settings groups —
 * New user defaults, Roles overview, Custom roles (role builder).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN MENU + ASSETS
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
	add_users_page(
		__( 'Roles', 'milieus' ),
		__( 'Roles', 'milieus' ),
		'manage_options',
		'milieus-roles',
		'milieus_render_roles_page'
	);
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'milieus-roles' ) return;

	$css = MILIEUS_DIR . 'assets/admin.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'milieus-admin', MILIEUS_URL . 'assets/admin.css', [], filemtime( $css ) );
	}

	$js = MILIEUS_DIR . 'assets/admin.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'milieus-admin', MILIEUS_URL . 'assets/admin.js', [], filemtime( $js ), true );
	}
} );


// ═════════════════════════════════════════════════════════════════════════════
//  INTERNAL LAYOUT HELPERS (replacements for th_settings_group / th_setting_row)
// ═════════════════════════════════════════════════════════════════════════════

function milieus_settings_group( string $title, string $desc, callable $body ): void {
	?>
	<div class="th-settings-card">
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( $desc ): ?><p class="th-settings-card-sub"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
		<?php call_user_func( $body ); ?>
	</div>
	<?php
}

function milieus_setting_row( string $label, string $desc, string $control_html ): void {
	?>
	<div class="th-settings-row">
		<div class="th-settings-row-label">
			<?php echo esc_html( $label ); ?>
			<?php if ( $desc ): ?><small><?php echo esc_html( $desc ); ?></small><?php endif; ?>
		</div>
		<div class="th-settings-row-control">
			<?php echo $control_html; // controls built by trusted helpers below ?>
		</div>
	</div>
	<?php
}

/**
 * Auto-saving <select> bound to a Milieus AJAX endpoint.
 *
 * @param string $name    Option key the select represents.
 * @param string $current Currently selected value.
 * @param array  $opts    value => label map.
 */
function milieus_select( string $name, string $current, array $opts ): string {
	$nonce = wp_create_nonce( 'milieus_role' );
	$html  = '<select class="th-input" data-milieus-select="' . esc_attr( $name ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
	foreach ( $opts as $val => $label ) {
		$html .= '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$html .= '</select>';
	$html .= ' <span class="th-role-result" data-milieus-select-result style="margin-left:10px;"></span>';
	return $html;
}


// ═════════════════════════════════════════════════════════════════════════════
//  RENDER
// ═════════════════════════════════════════════════════════════════════════════

function milieus_render_roles_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'milieus' ) );
	}

	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();

	$default_role = get_option( 'default_role', 'subscriber' );
	$custom       = milieus_get_custom_roles();
	$counts       = count_users()['avail_roles'] ?? [];
	$bundles      = milieus_capability_bundles();
	$nonce        = wp_create_nonce( 'milieus_role' );
	$woo_active   = class_exists( 'WooCommerce' );

	$all_caps = [];
	foreach ( $wp_roles->roles as $role ) {
		foreach ( array_keys( $role['capabilities'] ) as $c ) {
			$all_caps[ $c ] = true;
		}
	}
	foreach ( $bundles as $b ) {
		foreach ( $b['caps'] as $c ) $all_caps[ $c ] = true;
	}
	$all_caps = array_keys( $all_caps );
	sort( $all_caps );
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<div class="th-cx-eyebrow"><?php esc_html_e( 'Admin · Roles', 'milieus' ); ?></div>
			<h1 class="th-cx-title"><?php esc_html_e( 'Roles', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Build custom user roles from capability bundles plus individual caps. Optional WooCommerce role-based pricing applies a discount % at checkout for any user with the role.', 'milieus' ); ?></p>
		</div>

		<?php
		milieus_settings_group(
			__( 'New user defaults', 'milieus' ),
			__( 'What role new users get when they register.', 'milieus' ),
			function() use ( $default_role, $wp_roles ) {
				$opts = [];
				foreach ( $wp_roles->roles as $key => $role ) {
					$opts[ $key ] = $role['name'];
				}
				milieus_setting_row(
					__( 'Default role', 'milieus' ),
					__( 'Capabilities new users start with.', 'milieus' ),
					milieus_select( 'default_role', $default_role, $opts )
				);
			}
		);

		milieus_settings_group(
			__( 'Roles overview', 'milieus' ),
			__( "Built-in WordPress roles and any custom roles you've created.", 'milieus' ),
			function() use ( $wp_roles, $counts, $custom ) {
				?>
				<table class="th-roles-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Role', 'milieus' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Users', 'milieus' ); ?></th>
							<th><?php esc_html_e( 'Key capabilities', 'milieus' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Type', 'milieus' ); ?></th>
							<th style="width:80px;text-align:right;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $wp_roles->roles as $key => $role ):
							$cap_summary = [];
							if ( ! empty( $role['capabilities']['manage_options'] ) )      $cap_summary[] = __( 'Settings', 'milieus' );
							if ( ! empty( $role['capabilities']['manage_woocommerce'] ) )  $cap_summary[] = __( 'Shop', 'milieus' );
							if ( ! empty( $role['capabilities']['edit_others_posts'] ) )   $cap_summary[] = __( 'Edit any', 'milieus' );
							if ( ! empty( $role['capabilities']['publish_posts'] ) )       $cap_summary[] = __( 'Publish', 'milieus' );
							if ( ! empty( $role['capabilities']['edit_posts'] ) )          $cap_summary[] = __( 'Write', 'milieus' );
							if ( empty( $cap_summary ) ) $cap_summary[] = __( 'Read', 'milieus' );
							$is_custom = isset( $custom[ $key ] );
							$discount  = $is_custom ? (float) ( $custom[ $key ]['discount'] ?? 0 ) : 0;
						?>
						<tr data-role-row="<?php echo esc_attr( $key ); ?>">
							<td>
								<strong><?php echo esc_html( $role['name'] ); ?></strong>
								<?php if ( $discount > 0 ): ?>
									<span style="display:inline-block;margin-left:6px;padding:2px 8px;background:color-mix(in srgb, var(--ac) 15%, transparent);color:var(--ac);border-radius:10px;font-size:10px;font-weight:600;">−<?php echo esc_html( rtrim( rtrim( number_format( $discount, 2 ), '0' ), '.' ) ); ?>% off</span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></td>
							<td class="th-roles-caps"><?php echo esc_html( implode( ' · ', $cap_summary ) ); ?></td>
							<td>
								<?php if ( $is_custom ): ?>
									<span style="font-size:11px;color:var(--ac);font-weight:600;"><?php esc_html_e( 'Custom', 'milieus' ); ?></span>
								<?php else: ?>
									<span style="font-size:11px;color:var(--tx3);"><?php esc_html_e( 'Built-in', 'milieus' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="text-align:right;">
								<?php if ( $is_custom ): ?>
									<button type="button" class="th-button" data-role-edit="<?php echo esc_attr( $key ); ?>" style="padding:5px 10px;font-size:11px;"><?php esc_html_e( 'Edit', 'milieus' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
		);

		milieus_settings_group(
			__( 'Custom roles', 'milieus' ),
			__( 'Build your own roles. Mix preset capability bundles with individual caps.', 'milieus' ),
			function() use ( $bundles, $all_caps, $custom, $woo_active, $nonce ) {
				?>
				<div class="th-role-builder" data-milieus-role-builder data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<button type="button" class="th-button th-button-primary" data-role-new>+ <?php esc_html_e( 'New custom role', 'milieus' ); ?></button>

					<div class="th-role-editor" data-role-editor hidden>
						<div class="th-role-editor-head">
							<h3 data-role-editor-title><?php esc_html_e( 'New custom role', 'milieus' ); ?></h3>
							<button type="button" class="th-role-close" data-role-cancel aria-label="<?php esc_attr_e( 'Cancel', 'milieus' ); ?>">×</button>
						</div>

						<input type="hidden" data-role-key value="">
						<input type="hidden" data-role-is-new value="1">

						<div class="th-role-field">
							<label><?php esc_html_e( 'Role name', 'milieus' ); ?></label>
							<input type="text" class="th-input" data-role-name placeholder="<?php esc_attr_e( 'Friends & Family', 'milieus' ); ?>" maxlength="60">
							<small><?php esc_html_e( 'Display name shown to admins. The internal key is auto-generated from this.', 'milieus' ); ?></small>
						</div>

						<div class="th-role-field">
							<label><?php esc_html_e( 'Capability bundles', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Click to toggle. Bundles add their caps to the role. Mix freely.', 'milieus' ); ?></small>
							<div class="th-bundle-grid">
								<?php foreach ( $bundles as $bk => $b ): ?>
								<label class="th-bundle-card" data-bundle-card>
									<input type="checkbox" data-bundle="<?php echo esc_attr( $bk ); ?>" value="<?php echo esc_attr( $bk ); ?>">
									<div class="th-bundle-name"><?php echo esc_html( $b['label'] ); ?></div>
									<div class="th-bundle-desc"><?php echo esc_html( $b['desc'] ); ?></div>
								</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="th-role-field">
							<label><?php esc_html_e( 'Individual capabilities', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Fine-grained caps on top of (or instead of) bundles. Search to filter.', 'milieus' ); ?></small>
							<input type="text" class="th-input" data-cap-search placeholder="<?php esc_attr_e( 'Filter capabilities…', 'milieus' ); ?>" style="margin-bottom:10px;">
							<div class="th-cap-grid" data-cap-grid>
								<?php foreach ( $all_caps as $cap ): ?>
								<label class="th-cap-pill" data-cap-pill data-cap-name="<?php echo esc_attr( $cap ); ?>">
									<input type="checkbox" data-cap="<?php echo esc_attr( $cap ); ?>" value="<?php echo esc_attr( $cap ); ?>">
									<span><?php echo esc_html( $cap ); ?></span>
								</label>
								<?php endforeach; ?>
							</div>
						</div>

						<?php if ( $woo_active ): ?>
						<div class="th-role-field">
							<label><?php esc_html_e( 'WooCommerce discount', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Percentage off cart subtotal. Applied automatically at checkout for any user with this role. Use 0 to disable.', 'milieus' ); ?></small>
							<div style="display:flex;align-items:center;gap:8px;max-width:200px;">
								<input type="number" class="th-input" data-role-discount min="0" max="100" step="0.5" value="0" style="text-align:right;">
								<span style="font-size:14px;color:var(--tx2);font-weight:600;">%</span>
							</div>
						</div>
						<?php else: ?>
						<div class="th-role-field">
							<label><?php esc_html_e( 'WooCommerce discount', 'milieus' ); ?></label>
							<small style="color:var(--tx3);"><?php esc_html_e( "WooCommerce isn't active. Activate it to enable role-based pricing for Friends & Family or VIP customers.", 'milieus' ); ?></small>
						</div>
						<input type="hidden" data-role-discount value="0">
						<?php endif; ?>

						<div class="th-role-actions">
							<span class="th-role-result" data-role-result></span>
							<button type="button" class="th-button" data-role-cancel><?php esc_html_e( 'Cancel', 'milieus' ); ?></button>
							<button type="button" class="th-button" data-role-delete style="color:var(--err);border-color:color-mix(in srgb, var(--err) 30%, transparent);" hidden><?php esc_html_e( 'Delete', 'milieus' ); ?></button>
							<button type="button" class="th-button th-button-primary" data-role-save><?php esc_html_e( 'Save role', 'milieus' ); ?></button>
						</div>
					</div>
				</div>

				<?php
				$roles_payload = [];
				foreach ( $custom as $k => $r ) {
					$roles_payload[ $k ] = [
						'key'      => $k,
						'name'     => $r['name'] ?? $k,
						'bundles'  => $r['bundles'] ?? [],
						'caps'     => $r['caps'] ?? [],
						'discount' => (float) ( $r['discount'] ?? 0 ),
					];
				}
				?>
				<script>
					window.MilieusRoles = <?php echo wp_json_encode( $roles_payload ); ?>;
					window.MilieusAjax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				</script>
				<?php
			}
		);
		?>
	</div></div>
	<?php
}
