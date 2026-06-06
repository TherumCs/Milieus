<?php
/**
 * Milieus by Therum — admin page (Users → Member Groups).
 *
 * Renders the groups overview, the default-group selector, and the group
 * editor — bundles, caps, group lifetime, member duration, custom
 * registration link + design customizer, WC discount, and the members tab.
 *
 * All markup uses the th-* classes for back-compat with existing CSS; the
 * v1.1.0 stylesheet recolors them to the stone+blue palette and adds new
 * components (segmented controls, member tables, reg card preview).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN MENU + ASSETS
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
	// Top-level sidebar entry. Sits above Comments by default (position 25.5
	// → just before Appearance) so it's findable without scrolling. Icon is
	// the WP built-in groups dashicon — no asset to ship.
	add_menu_page(
		__( 'Milieus', 'milieus' ),                  // page title (browser tab)
		__( 'Milieus', 'milieus' ),                  // sidebar label
		'manage_options',
		'milieus-roles',
		'milieus_render_roles_page',
		'dashicons-groups',
		25.5
	);
	// First submenu = same page, but with a friendlier label. WP duplicates
	// the parent label as the first submenu by default; overriding it gives
	// us "Milieus → Member Groups" instead of "Milieus → Milieus".
	add_submenu_page(
		'milieus-roles',
		__( 'Member Groups', 'milieus' ),
		__( 'Member Groups', 'milieus' ),
		'manage_options',
		'milieus-roles',
		'milieus_render_roles_page'
	);
	// Milieus has its own top-level menu — no need to mirror under Users.
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
//  LAYOUT HELPERS
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
			<?php echo $control_html; // built by trusted helpers below ?>
		</div>
	</div>
	<?php
}

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

/**
 * Pretty-print a group's expiry for the overview table.
 */
function milieus_format_group_expiry( array $g ): string {
	$exp = (int) ( $g['expires_at'] ?? 0 );
	$dur = $g['member_duration'] ?? [ 'value' => 0, 'unit' => 'days' ];

	if ( $exp === 0 ) {
		$top = '<span class="th-tag-time">' . esc_html__( 'Permanent', 'milieus' ) . '</span>';
	} else {
		$tier  = milieus_expiry_tier( $exp );
		$class = 'th-tag-time';
		if ( $tier === 'soon' )    $class .= ' th-tag-time-soon';
		if ( $tier === 'urgent' )  $class .= ' th-tag-time-urgent';
		if ( $tier === 'expired' ) $class .= ' th-tag-time-expired';
		$top = '<span class="' . $class . '">' . esc_html( milieus_humanize_expiry( $exp ) ) . '</span>';
	}

	$bottom = '';
	if ( ! empty( $dur['value'] ) ) {
		/* translators: 1: value, 2: unit (days/weeks/...) */
		$bottom = '<br><small class="th-roles-caps">' . esc_html( sprintf( __( 'Members: %1$d %2$s', 'milieus' ), $dur['value'], $dur['unit'] ) ) . '</small>';
	}
	return $top . $bottom;
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

	$default_role  = get_option( 'default_role', 'subscriber' );
	$custom        = milieus_get_groups();
	$counts        = count_users()['avail_roles'] ?? [];
	$bundles       = milieus_capability_bundles();
	$nonce         = wp_create_nonce( 'milieus_role' );
	$members_nonce = wp_create_nonce( 'milieus_members' );
	$woo_active    = class_exists( 'WooCommerce' );

	$all_caps = [];
	foreach ( $wp_roles->roles as $role ) {
		foreach ( array_keys( $role['capabilities'] ) as $c ) $all_caps[ $c ] = true;
	}
	foreach ( $bundles as $b ) {
		foreach ( $b['caps'] as $c ) $all_caps[ $c ] = true;
	}
	$all_caps = array_keys( $all_caps );
	sort( $all_caps );

	$site_url = home_url( '/register/' );
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Milieus', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( "Membership management for WordPress by Therum. Create named groups with capabilities, WooCommerce discounts, branded registration links, expiry rules, and member directories — all from one place.", 'milieus' ); ?></p>
		</div>

		<?php milieus_render_tab_nav( 'milieus-roles' ); ?>

		<?php
		// ── New user defaults ─────────────────────────────────────────────
		milieus_settings_group(
			__( 'Default group for new sign-ups', 'milieus' ),
			__( 'Which group new users land in when they register through the standard form (without a custom link).', 'milieus' ),
			function() use ( $default_role, $wp_roles ) {
				$opts = [];
				foreach ( $wp_roles->roles as $key => $role ) {
					$opts[ $key ] = $role['name'];
				}
				milieus_setting_row(
					__( 'Default group', 'milieus' ),
					__( 'Capabilities new users start with.', 'milieus' ),
					milieus_select( 'default_role', $default_role, $opts )
				);
			}
		);

		// ── Groups overview ───────────────────────────────────────────────
		milieus_settings_group(
			__( 'All groups', 'milieus' ),
			__( "System groups (WordPress built-ins) and any custom groups you've created.", 'milieus' ),
			function() use ( $wp_roles, $counts, $custom, $woo_active ) {
				?>
				<div class="th-table-toolbar">
					<input type="text" class="th-input" data-groups-search placeholder="<?php esc_attr_e( 'Filter groups…', 'milieus' ); ?>" style="max-width:280px">
				</div>
				<table class="th-roles-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Group', 'milieus' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Users', 'milieus' ); ?></th>
							<th><?php esc_html_e( 'Key capabilities', 'milieus' ); ?></th>
							<th style="width:170px;"><?php esc_html_e( 'Registration link', 'milieus' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Expires', 'milieus' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Type', 'milieus' ); ?></th>
							<th style="width:70px;text-align:right;"></th>
						</tr>
					</thead>
					<tbody data-groups-tbody>
						<?php foreach ( $wp_roles->roles as $key => $role ):
							$cap_summary = [];
							if ( ! empty( $role['capabilities']['manage_options'] ) )      $cap_summary[] = __( 'Settings', 'milieus' );
							if ( ! empty( $role['capabilities']['manage_woocommerce'] ) )  $cap_summary[] = __( 'Shop', 'milieus' );
							if ( ! empty( $role['capabilities']['edit_others_posts'] ) )   $cap_summary[] = __( 'Edit any', 'milieus' );
							if ( ! empty( $role['capabilities']['publish_posts'] ) )       $cap_summary[] = __( 'Publish', 'milieus' );
							if ( ! empty( $role['capabilities']['edit_posts'] ) )          $cap_summary[] = __( 'Write', 'milieus' );
							if ( empty( $cap_summary ) ) $cap_summary[] = __( 'Read', 'milieus' );
							$is_custom = isset( $custom[ $key ] );
							$g         = $is_custom ? $custom[ $key ] : null;
							$discount  = $is_custom ? (float) ( $g['discount'] ?? 0 ) : 0;
							$reg_slug  = $is_custom && ! empty( $g['reg']['enabled'] ) ? ( $g['reg']['slug'] ?? '' ) : '';
							$color     = $is_custom ? ( $g['color'] ?? '#2563eb' ) : '#a8a29e';
							$signups   = $is_custom ? (int) ( $g['reg']['signup_count'] ?? 0 ) : 0;
						?>
						<tr data-role-row="<?php echo esc_attr( $key ); ?>" data-row-search="<?php echo esc_attr( strtolower( $role['name'] . ' ' . $key . ' ' . $reg_slug ) ); ?>">
							<td>
								<span class="th-color-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
								<?php if ( $is_custom ): ?>
									<a href="#" data-role-edit="<?php echo esc_attr( $key ); ?>" style="text-decoration:none;color:inherit"><strong><?php echo esc_html( $role['name'] ); ?></strong></a>
								<?php else: ?>
									<strong><?php echo esc_html( $role['name'] ); ?></strong>
								<?php endif; ?>
								<?php if ( $discount > 0 ): ?>
									<span class="th-pill">−<?php echo esc_html( rtrim( rtrim( number_format( $discount, 2 ), '0' ), '.' ) ); ?>% off</span>
								<?php endif; ?>
							</td>
							<td><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></td>
							<td class="th-roles-caps"><?php echo esc_html( implode( ' · ', $cap_summary ) ); ?></td>
							<td>
								<?php if ( $reg_slug ): ?>
									<code style="font-size:11px;color:var(--ac);"><?php echo esc_html( '/register/' . $reg_slug ); ?></code>
									<?php if ( $signups > 0 ): ?>
										<br><small class="th-roles-caps"><?php
											/* translators: %d: count */
											printf( esc_html( _n( '%d sign-up', '%d sign-ups', $signups, 'milieus' ) ), $signups );
										?></small>
									<?php endif; ?>
								<?php else: ?>
									<span class="th-roles-caps">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php echo $is_custom ? milieus_format_group_expiry( $g ) : '<span class="th-roles-caps">—</span>'; ?>
							</td>
							<td>
								<?php if ( $is_custom ): ?>
									<span class="th-tag th-tag-custom"><?php esc_html_e( 'Custom', 'milieus' ); ?></span>
								<?php else: ?>
									<span class="th-tag th-tag-system"><?php esc_html_e( 'System', 'milieus' ); ?></span>
								<?php endif; ?>
							</td>
							<td style="text-align:right;">
								<?php if ( $is_custom ): ?>
									<button type="button" class="th-link-btn" data-role-edit="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Edit', 'milieus' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
		);

		// ── Custom group builder + editor ─────────────────────────────────
		milieus_settings_group(
			__( 'Custom groups', 'milieus' ),
			__( 'Build your own groups. Each group gets capabilities from preset bundles plus any individual caps you mix in.', 'milieus' ),
			function() use ( $bundles, $all_caps, $custom, $woo_active, $nonce, $members_nonce, $site_url ) {
				?>
				<div class="th-role-builder" data-milieus-role-builder data-nonce="<?php echo esc_attr( $nonce ); ?>" data-members-nonce="<?php echo esc_attr( $members_nonce ); ?>" data-site-url="<?php echo esc_attr( $site_url ); ?>">
					<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
						<button type="button" class="th-button th-button-primary" data-role-new>＋ <?php esc_html_e( 'New group', 'milieus' ); ?></button>
						<div style="position:relative;display:inline-block" data-starter-wrap>
							<button type="button" class="th-button" data-starter-toggle><?php esc_html_e( '✦ Starter packs', 'milieus' ); ?></button>
							<div data-starter-menu style="display:none;position:absolute;left:0;top:100%;margin-top:6px;background:#fff;border:1px solid var(--bd,#e7e5e4);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:240px;z-index:50;padding:6px 0;font-size:13px">
								<?php foreach ( milieus_starter_packs() as $pk => $p ): ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=milieus-roles&milieus_template=' . $pk ) ); ?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:var(--tx,#1c1917)">
										<span style="width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $p['color'] ); ?>;flex-shrink:0"></span>
										<div>
											<strong><?php echo esc_html( $p['name'] ); ?></strong><br>
											<small style="color:var(--tx3,#a8a29e)"><?php echo esc_html( $p['reg']['lede'] ?? '' ); ?></small>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<script>
					(function(){
						var wrap = document.querySelector('[data-starter-wrap]');
						var btn = wrap.querySelector('[data-starter-toggle]');
						var menu = wrap.querySelector('[data-starter-menu]');
						btn.addEventListener('click', function(e) { e.stopPropagation(); menu.style.display = menu.style.display === 'none' ? 'block' : 'none'; });
						document.addEventListener('click', function(e) { if (!wrap.contains(e.target)) menu.style.display = 'none'; });
					})();
					</script>

					<div class="th-role-editor" data-role-editor hidden>
						<div class="th-role-editor-head">
							<h3 data-role-editor-title><?php esc_html_e( 'New group', 'milieus' ); ?></h3>
							<button type="button" class="th-role-close" data-role-cancel aria-label="<?php esc_attr_e( 'Cancel', 'milieus' ); ?>">×</button>
						</div>

						<input type="hidden" data-role-key value="">
						<input type="hidden" data-role-is-new value="1">

						<div class="th-role-field">
							<label><?php esc_html_e( 'Group name', 'milieus' ); ?></label>
							<input type="text" class="th-input" data-role-name placeholder="<?php esc_attr_e( 'Friends & Family', 'milieus' ); ?>" maxlength="60">
							<small><?php esc_html_e( 'Display name shown to admins and members. The internal key is auto-generated.', 'milieus' ); ?></small>
						</div>

						<div class="th-role-field">
							<label><?php esc_html_e( 'Color tag', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Shown as a dot next to the group name throughout the admin. Helpful when you have many groups.', 'milieus' ); ?></small>
							<div class="th-color-row" style="max-width:200px">
								<input type="color" data-role-color value="#2563eb">
								<input type="text" class="th-input" data-role-color-hex value="#2563eb" style="font-family:ui-monospace,Menlo,monospace">
							</div>
						</div>

						<div class="th-role-field">
							<label><?php esc_html_e( 'Capability bundles', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Click to toggle. Bundles add their caps to the group. Mix freely.', 'milieus' ); ?></small>
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

						<!-- ── Custom registration link ─────────────────────── -->
						<div class="th-role-field">
							<label><?php esc_html_e( 'Custom registration link', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Public URL that auto-assigns this group on sign-up. Share it with anyone you want to land in the group automatically — Friends & Family, VIP, contractors, beta testers.', 'milieus' ); ?></small>
							<div class="th-segmented" data-segmented="reg-enabled">
								<label class="is-active"><input type="radio" name="reg-enabled" value="off" checked data-reg-enabled-off><span><?php esc_html_e( 'Off', 'milieus' ); ?></span></label>
								<label><input type="radio" name="reg-enabled" value="on" data-reg-enabled-on><span><?php esc_html_e( 'Enable public link', 'milieus' ); ?></span></label>
							</div>
							<div class="th-seg-detail" data-reg-panel hidden>
								<div class="th-url-preview">
									<span class="prefix"><?php echo esc_html( $site_url ); ?></span>
									<input class="slug" type="text" data-reg-slug placeholder="friends">
									<button type="button" class="copy" data-reg-copy><?php esc_html_e( 'Copy link', 'milieus' ); ?></button>
								</div>

								<div class="th-reg-design">
									<div class="th-reg-controls">
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Brand mark', 'milieus' ); ?></label><input type="text" data-reg-brand placeholder="<?php esc_attr_e( 'YOUR BRAND', 'milieus' ); ?>"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Logo URL', 'milieus' ); ?><br><small style="font-weight:400;color:var(--tx3)"><?php esc_html_e( 'PNG/SVG, optional', 'milieus' ); ?></small></label><input type="url" data-reg-logo placeholder="https://…"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Heading', 'milieus' ); ?></label><input type="text" data-reg-heading placeholder="<?php esc_attr_e( 'Join Friends & Family', 'milieus' ); ?>"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Welcome message', 'milieus' ); ?></label><textarea data-reg-lede placeholder="<?php esc_attr_e( 'Get 20% off everything…', 'milieus' ); ?>"></textarea></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Brand color', 'milieus' ); ?></label>
											<div class="th-color-row">
												<input type="color" data-reg-color value="#2563eb">
												<input type="text" data-reg-color-hex value="#2563eb">
											</div>
										</div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Button text', 'milieus' ); ?></label><input type="text" data-reg-button placeholder="<?php esc_attr_e( 'Create account →', 'milieus' ); ?>"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Extra fields', 'milieus' ); ?></label>
											<div style="display:flex;flex-wrap:wrap;gap:6px">
												<?php foreach ( [ 'name' => 'Full name', 'company' => 'Company', 'phone' => 'Phone', 'referral' => 'Referral code', 'how-heard' => 'How did you hear?' ] as $ek => $el ): ?>
												<label class="th-cap-pill" data-reg-extra-pill><input type="checkbox" data-reg-extra="<?php echo esc_attr( $ek ); ?>" value="<?php echo esc_attr( $ek ); ?>"><span><?php echo esc_html( $el ); ?></span></label>
												<?php endforeach; ?>
											</div>
										</div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Page background', 'milieus' ); ?></label>
											<div>
												<div class="th-segmented" data-segmented="bg-kind" style="margin-bottom:8px">
													<label class="is-active"><input type="radio" name="bg-kind" value="solid" data-bg-kind="solid" checked><span><?php esc_html_e( 'Solid', 'milieus' ); ?></span></label>
													<label><input type="radio" name="bg-kind" value="gradient" data-bg-kind="gradient"><span><?php esc_html_e( 'Gradient', 'milieus' ); ?></span></label>
													<label><input type="radio" name="bg-kind" value="image" data-bg-kind="image"><span><?php esc_html_e( 'Image', 'milieus' ); ?></span></label>
												</div>
												<div data-bg-panel="solid">
													<div class="th-color-row">
														<input type="color" data-bg-solid value="#fafaf9">
														<input type="text" data-bg-solid-hex value="#fafaf9">
													</div>
												</div>
												<div data-bg-panel="gradient" hidden>
													<div class="th-color-row" style="margin-bottom:8px">
														<input type="color" data-bg-grad-1 value="#fde68a">
														<input type="color" data-bg-grad-2 value="#fca5a5">
														<select class="th-input" data-bg-grad-dir style="flex:1">
															<option value="135deg">↘ Diagonal</option>
															<option value="180deg">↓ Vertical</option>
															<option value="90deg">→ Horizontal</option>
															<option value="radial">◉ Radial</option>
														</select>
													</div>
												</div>
												<div data-bg-panel="image" hidden>
													<input type="url" data-bg-image placeholder="https://…">
													<div class="th-inline" style="margin-top:8px;gap:14px">
														<label style="font-size:12px"><input type="checkbox" data-bg-dim checked> <?php esc_html_e( 'Dim 30%', 'milieus' ); ?></label>
														<label style="font-size:12px"><input type="checkbox" data-bg-blur> <?php esc_html_e( 'Blur', 'milieus' ); ?></label>
													</div>
												</div>
											</div>
										</div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Redirect after sign-up', 'milieus' ); ?></label><input type="url" data-reg-redirect placeholder="https://…/thank-you"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Require approval', 'milieus' ); ?></label>
											<div class="th-segmented" style="margin:0">
												<label class="is-active"><input type="radio" name="reg-approval" value="off" checked><span><?php esc_html_e( 'No', 'milieus' ); ?></span></label>
												<label><input type="radio" name="reg-approval" value="on" data-reg-approval><span><?php esc_html_e( 'Yes', 'milieus' ); ?></span></label>
											</div>
										</div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Max sign-ups', 'milieus' ); ?></label>
											<div class="th-inline">
												<input type="number" min="0" data-reg-max-signups value="0" style="width:100px">
												<span class="th-unit"><?php esc_html_e( '0 = unlimited', 'milieus' ); ?></span>
											</div>
										</div>

										<div class="th-reg-ctrl" style="grid-template-columns:160px 1fr;border-top:1px solid var(--bd);padding-top:14px;margin-top:6px"><label><?php esc_html_e( 'Welcome screen', 'milieus' ); ?> <?php milieus_help( 'Show a confirmation screen between sign-up and the redirect — branded with your color and group benefits.' ); ?></label>
											<label style="font-size:13px;display:flex;align-items:center;gap:6px;font-weight:500;color:var(--tx)"><input type="checkbox" data-reg-welcome-enabled> <?php esc_html_e( 'Show a one-screen "you\'re in" before redirect', 'milieus' ); ?></label>
										</div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Welcome heading', 'milieus' ); ?></label><input type="text" data-reg-welcome-heading placeholder="<?php esc_attr_e( "You're in!", 'milieus' ); ?>"></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Welcome body', 'milieus' ); ?></label><textarea data-reg-welcome-body placeholder="<?php esc_attr_e( "Here's what your membership unlocks…", 'milieus' ); ?>"></textarea></div>
										<div class="th-reg-ctrl"><label><?php esc_html_e( 'Continue button text', 'milieus' ); ?></label><input type="text" data-reg-welcome-cta placeholder="<?php esc_attr_e( 'Continue →', 'milieus' ); ?>"></div>
									</div>

									<div>
										<div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--tx3);font-weight:600;margin-bottom:8px;text-align:center"><?php esc_html_e( 'Live preview', 'milieus' ); ?></div>
										<div class="th-reg-stage" data-reg-stage>
											<div class="th-reg-card" data-reg-card>
												<div class="brand" data-prev-brand>YOUR BRAND</div>
												<img class="brand-logo" data-prev-logo alt="" style="display:none">
												<h1 data-prev-heading><?php esc_html_e( 'Join the group', 'milieus' ); ?></h1>
												<p class="lede" data-prev-lede><?php esc_html_e( 'Create your account to get started.', 'milieus' ); ?></p>
												<form onsubmit="event.preventDefault()">
													<div data-prev-extras></div>
													<label><?php esc_html_e( 'Email', 'milieus' ); ?> <input type="email" value="you@example.com"></label>
													<label><?php esc_html_e( 'Password', 'milieus' ); ?> <input type="password" value="••••••••"></label>
													<button type="submit" data-prev-button><?php esc_html_e( 'Create account →', 'milieus' ); ?></button>
												</form>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- ── Group lifetime ───────────────────────────────── -->
						<div class="th-role-field">
							<label><?php esc_html_e( 'Group lifetime', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'When this group itself should stop existing. On expiry, the group is removed and all members revert to the default group.', 'milieus' ); ?></small>
							<div class="th-segmented" data-segmented="role-life">
								<label class="is-active"><input type="radio" name="role-life" value="forever" checked><span><?php esc_html_e( 'Forever', 'milieus' ); ?></span></label>
								<label><input type="radio" name="role-life" value="date" data-role-life-date><span><?php esc_html_e( 'Expires on date', 'milieus' ); ?></span></label>
							</div>
							<div class="th-seg-detail th-inline" hidden>
								<input type="date" class="th-input" data-role-expires-date>
								<span class="th-unit"><?php esc_html_e( '— group auto-deletes at end of day, UTC.', 'milieus' ); ?></span>
							</div>
						</div>

						<!-- ── Member duration ──────────────────────────────── -->
						<div class="th-role-field">
							<label><?php esc_html_e( 'Default member duration', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'How long each new member stays in this group. Use "Permanent" for ongoing groups; set a duration for trials, promo periods, or contractor access.', 'milieus' ); ?></small>
							<div class="th-segmented" data-segmented="member-life">
								<label class="is-active"><input type="radio" name="member-life" value="permanent" checked><span><?php esc_html_e( 'Permanent', 'milieus' ); ?></span></label>
								<label><input type="radio" name="member-life" value="duration" data-member-life-duration><span><?php esc_html_e( 'Time-limited', 'milieus' ); ?></span></label>
							</div>
							<div class="th-seg-detail th-inline" hidden>
								<input type="number" min="1" max="3650" data-duration-value value="30" class="th-input" style="width:90px">
								<select class="th-input" data-duration-unit>
									<option value="days"><?php esc_html_e( 'days', 'milieus' ); ?></option>
									<option value="weeks"><?php esc_html_e( 'weeks', 'milieus' ); ?></option>
									<option value="months" selected><?php esc_html_e( 'months', 'milieus' ); ?></option>
									<option value="years"><?php esc_html_e( 'years', 'milieus' ); ?></option>
								</select>
								<span class="th-unit"><?php esc_html_e( 'from joining, then reverts to default group.', 'milieus' ); ?></span>
							</div>
						</div>

						<!-- ── WC discount ──────────────────────────────────── -->
						<?php if ( $woo_active ): ?>
						<div class="th-role-field">
							<label><?php esc_html_e( 'WooCommerce discount', 'milieus' ); ?></label>
							<small><?php esc_html_e( 'Percentage off cart subtotal. Applied automatically at checkout for any member of this group. Use 0 to disable.', 'milieus' ); ?></small>
							<div style="display:flex;align-items:center;gap:8px;max-width:200px;">
								<input type="number" class="th-input" data-role-discount min="0" max="100" step="0.5" value="0" style="text-align:right;">
								<span style="font-size:14px;color:var(--tx2);font-weight:600;">%</span>
							</div>
						</div>
						<?php else: ?>
						<div class="th-role-field">
							<label><?php esc_html_e( 'WooCommerce discount', 'milieus' ); ?></label>
							<small style="color:var(--tx3);"><?php esc_html_e( "WooCommerce isn't active. Activate it to enable group-based pricing.", 'milieus' ); ?></small>
						</div>
						<input type="hidden" data-role-discount value="0">
						<?php endif; ?>

						<!-- ── Members tab (only when editing an existing group) ─── -->
						<div class="th-role-field" data-members-section hidden>
							<label><?php esc_html_e( 'Members', 'milieus' ); ?> <span style="color:var(--tx3);font-weight:400;text-transform:none;letter-spacing:0;margin-left:6px"><span data-members-count>0</span> <?php esc_html_e( 'in this group', 'milieus' ); ?></span></label>
							<small><?php esc_html_e( "Search users and add them to this group. Each gets the group's default duration on joining.", 'milieus' ); ?></small>

							<div class="th-members-toolbar">
								<div class="th-member-search">
									<input type="text" data-member-search placeholder="<?php esc_attr_e( 'Search users by name or email…', 'milieus' ); ?>" autocomplete="off">
									<div class="th-member-search-results" data-member-search-results hidden></div>
								</div>
								<a class="th-button" data-export-link href="#"><?php esc_html_e( '⤓ Export CSV', 'milieus' ); ?></a>
								<button type="button" class="th-button" data-csv-toggle><?php esc_html_e( '⤒ Import CSV', 'milieus' ); ?></button>
							</div>

							<div class="th-csv-panel" data-csv-panel hidden>
								<small style="display:block;margin-bottom:6px;color:var(--tx3)"><?php esc_html_e( 'Paste a list of emails (one per line, or comma-separated). Existing users are added to this group with the default member duration. Emails without a matching user are skipped.', 'milieus' ); ?></small>
								<textarea class="th-input" data-csv-blob rows="5" style="width:100%;font-family:ui-monospace,Menlo,monospace;font-size:12px" placeholder="ada@example.com&#10;grace@example.com&#10;…"></textarea>
								<div style="display:flex;gap:8px;margin-top:8px">
									<button type="button" class="th-button th-button-primary" data-csv-import><?php esc_html_e( 'Import', 'milieus' ); ?></button>
									<button type="button" class="th-button" data-csv-cancel><?php esc_html_e( 'Cancel', 'milieus' ); ?></button>
									<span data-csv-result style="font-size:12px;color:var(--tx2);align-self:center"></span>
								</div>
							</div>

							<div class="th-bulk-bar" data-bulk-bar hidden>
								<strong data-bulk-count>0</strong> <?php esc_html_e( 'selected', 'milieus' ); ?>
								<div class="spacer"></div>
								<button type="button" class="th-button" data-bulk-action="extend_30"><?php esc_html_e( 'Extend +30 days', 'milieus' ); ?></button>
								<button type="button" class="th-button" data-bulk-action="reset_expiry"><?php esc_html_e( 'Reset expiry', 'milieus' ); ?></button>
								<button type="button" class="th-button" data-bulk-action="revoke" style="color:var(--err)"><?php esc_html_e( 'Remove from group', 'milieus' ); ?></button>
							</div>

							<table class="th-member-table">
								<thead>
									<tr>
										<th class="check"><input type="checkbox" data-check-all></th>
										<th><?php esc_html_e( 'User', 'milieus' ); ?></th>
										<th style="width:120px"><?php esc_html_e( 'Joined', 'milieus' ); ?></th>
										<th style="width:130px"><?php esc_html_e( 'Expires', 'milieus' ); ?></th>
										<th style="width:90px"><?php esc_html_e( 'Source', 'milieus' ); ?></th>
										<th style="width:80px;text-align:right"></th>
									</tr>
								</thead>
								<tbody data-members-tbody>
									<tr><td colspan="6" style="text-align:center;color:var(--tx3);padding:20px"><?php esc_html_e( 'Loading members…', 'milieus' ); ?></td></tr>
								</tbody>
							</table>
							<div data-members-pagination style="margin-top:10px;display:flex;gap:6px;align-items:center"></div>
						</div>

						<div class="th-role-actions">
							<span class="th-role-result" data-role-result></span>
							<button type="button" class="th-button" data-role-duplicate hidden><?php esc_html_e( 'Duplicate', 'milieus' ); ?></button>
							<button type="button" class="th-button" data-role-cancel><?php esc_html_e( 'Cancel', 'milieus' ); ?></button>
							<button type="button" class="th-button" data-role-delete style="color:var(--err)" hidden><?php esc_html_e( 'Delete group', 'milieus' ); ?></button>
							<button type="button" class="th-button th-button-primary" data-role-save><?php esc_html_e( 'Save group', 'milieus' ); ?></button>
						</div>
					</div>
				</div>

				<?php
				$roles_payload = [];
				foreach ( $custom as $k => $r ) {
					$roles_payload[ $k ] = [
						'key'             => $k,
						'name'            => $r['name'] ?? $k,
						'color'           => $r['color'] ?? '#2563eb',
						'bundles'         => $r['bundles'] ?? [],
						'caps'            => $r['caps'] ?? [],
						'discount'        => (float) ( $r['discount'] ?? 0 ),
						'expires_at'      => (int) ( $r['expires_at'] ?? 0 ),
						'member_duration' => $r['member_duration'] ?? [ 'value' => 0, 'unit' => 'days' ],
						'reg'             => wp_parse_args( $r['reg'] ?? [], milieus_reg_defaults() ),
					];
				}
				?>
				<script>
					window.MilieusRoles = <?php echo wp_json_encode( $roles_payload ); ?>;
					window.MilieusAjax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					window.MilieusSiteUrl = <?php echo wp_json_encode( $site_url ); ?>;
					window.MilieusExportBase = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
					window.MilieusExportNonce = <?php echo wp_json_encode( wp_create_nonce( 'milieus_members_export' ) ); ?>;
				</script>
				<?php
			}
		);
		?>
	</div></div>
	<?php
}
