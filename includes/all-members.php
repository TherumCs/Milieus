<?php
/**
 * Milieus by Therum — All Members directory.
 *
 * A single page showing every user on the site with their Milieus group
 * memberships, sortable and filterable. Lives at Milieus → All Members.
 *
 * Each row has a 3-dot action menu: Add to group, Remove from group,
 * Edit user (links to WP user editor), Delete user (with confirmation).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
	add_submenu_page(
		'milieus-roles',
		__( 'All Members', 'milieus' ),
		__( 'All Members', 'milieus' ),
		'manage_options',
		'milieus-all-members',
		'milieus_render_all_members_page'
	);
}, 12 );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'milieus-all-members' ) return;
	$css = MILIEUS_DIR . 'assets/admin.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'milieus-admin', MILIEUS_URL . 'assets/admin.css', [], filemtime( $css ) );
	}
} );

// ── AJAX: add user to group ─────────────────────────────────────────────
add_action( 'wp_ajax_milieus_directory_add', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_directory', 'nonce' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	$key = sanitize_key( $_POST['group'] ?? '' );
	if ( ! $uid || ! $key ) wp_send_json_error( 'missing args' );
	if ( ! milieus_assign_member( $uid, $key, 'manual' ) ) wp_send_json_error( 'assign failed' );
	wp_send_json_success( [ 'user_id' => $uid, 'group' => $key ] );
} );

// ── AJAX: remove user from group ────────────────────────────────────────
add_action( 'wp_ajax_milieus_directory_remove', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_directory', 'nonce' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	$key = sanitize_key( $_POST['group'] ?? '' );
	if ( ! $uid || ! $key ) wp_send_json_error( 'missing args' );
	milieus_revoke_member( $uid, $key );
	wp_send_json_success( [ 'user_id' => $uid, 'group' => $key ] );
} );

// ── AJAX: delete user ──────────────────────────────────────────────────
add_action( 'wp_ajax_milieus_directory_delete', function() {
	if ( ! current_user_can( 'delete_users' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_directory', 'nonce' );
	$uid = (int) ( $_POST['user_id'] ?? 0 );
	if ( ! $uid ) wp_send_json_error( 'missing user_id' );

	// Never allow deleting yourself.
	if ( $uid === get_current_user_id() ) {
		wp_send_json_error( 'cannot delete yourself' );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( wp_delete_user( $uid ) ) {
		wp_send_json_success( [ 'user_id' => $uid ] );
	}
	wp_send_json_error( 'delete failed' );
} );

function milieus_render_all_members_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

	$groups     = milieus_get_groups();
	$nonce      = wp_create_nonce( 'milieus_directory' );
	$delete_nonce_base = 'delete-user_'; // WP's built-in pattern

	// ── Filters ─────────────────────────────────────────────────────────
	$search       = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$filter_group = sanitize_key( $_GET['group'] ?? '' );
	$orderby      = sanitize_key( $_GET['orderby'] ?? 'registered' );
	$order        = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
	if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) $order = 'DESC';

	$per  = 50;
	$page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

	// ── Build query ─────────────────────────────────────────────────────
	$args = [
		'number'  => $per,
		'offset'  => ( $page - 1 ) * $per,
		'orderby' => in_array( $orderby, [ 'registered', 'display_name', 'user_email' ], true ) ? $orderby : 'registered',
		'order'   => $order,
	];

	if ( $search ) {
		$args['search']         = '*' . esc_attr( $search ) . '*';
		$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
	}

	if ( $filter_group && isset( $groups[ $filter_group ] ) ) {
		$args['role'] = $filter_group;
	}

	$query = new WP_User_Query( $args );
	$users = $query->get_results();
	$total = (int) $query->get_total();
	$pages = max( 1, (int) ceil( $total / $per ) );

	// ── Precompute per-user group membership ────────────────────────────
	$user_groups = [];
	foreach ( $users as $u ) {
		$memberships = [];
		foreach ( $groups as $key => $g ) {
			$assigned = (int) get_user_meta( $u->ID, MILIEUS_META_ASSIGNED . $key, true );
			if ( ! $assigned ) continue;
			$memberships[] = [
				'key'      => $key,
				'name'     => $g['name'],
				'color'    => $g['color'] ?? '#2563eb',
				'assigned' => $assigned,
				'expires'  => (int) get_user_meta( $u->ID, MILIEUS_META_EXPIRES . $key, true ),
				'source'   => (string) get_user_meta( $u->ID, MILIEUS_META_SOURCE . $key, true ) ?: 'manual',
			];
		}
		$user_groups[ $u->ID ] = $memberships;
	}

	// ── Sort helper ─────────────────────────────────────────────────────
	$sort_url = function( string $col ) use ( $orderby, $order, $search, $filter_group ) {
		$new_order = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
		return add_query_arg( array_filter( [
			'page'    => 'milieus-all-members',
			'orderby' => $col,
			'order'   => $new_order,
			's'       => $search,
			'group'   => $filter_group,
		] ), admin_url( 'admin.php' ) );
	};
	$sort_icon = function( string $col ) use ( $orderby, $order ) {
		if ( $orderby !== $col ) return '';
		return $order === 'ASC' ? ' &#9650;' : ' &#9660;';
	};

	// Groups as JSON for the JS add-to-group picker.
	$groups_json = [];
	foreach ( $groups as $k => $g ) {
		$groups_json[] = [ 'key' => $k, 'name' => $g['name'], 'color' => $g['color'] ?? '#2563eb' ];
	}

	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'All Members', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Every user on this site and which Milieus groups they belong to.', 'milieus' ); ?> · <code><?php echo (int) $total; ?> <?php esc_html_e( 'users', 'milieus' ); ?></code></p>
		</div>

		<?php milieus_render_tab_nav( 'milieus-all-members' ); ?>

		<!-- Filters bar -->
		<div class="th-settings-card" style="margin-bottom:0">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<input type="hidden" name="page" value="milieus-all-members">
				<input class="th-input" type="search" name="s" placeholder="<?php esc_attr_e( 'Search name or email...', 'milieus' ); ?>" value="<?php echo esc_attr( $search ); ?>" style="min-width:220px">
				<select name="group" class="th-input">
					<option value=""><?php esc_html_e( 'All groups', 'milieus' ); ?></option>
					<?php foreach ( $groups as $k => $g ): ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter_group, $k ); ?>><?php echo esc_html( $g['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="th-button"><?php esc_html_e( 'Filter', 'milieus' ); ?></button>
				<a class="th-link-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=milieus-all-members' ) ); ?>"><?php esc_html_e( 'Reset', 'milieus' ); ?></a>
			</form>
		</div>

		<!-- Bulk action bar (hidden until checkboxes are checked) -->
		<div id="md-bulk-bar" style="display:none;margin-top:14px;padding:10px 14px;background:color-mix(in srgb,#2563eb 6%,#fafaf9);border:1px solid color-mix(in srgb,#2563eb 18%,transparent);border-radius:10px;font-size:13px;align-items:center;gap:10px">
			<span><strong id="md-bulk-count">0</strong> <?php esc_html_e( 'selected', 'milieus' ); ?></span>
			<div style="position:relative;display:inline-block" data-md-bulk-add-wrap>
				<button type="button" class="th-button" id="md-bulk-add-btn"><?php esc_html_e( 'Add to group ▾', 'milieus' ); ?></button>
				<div id="md-bulk-add-menu" style="display:none;position:absolute;left:0;top:100%;margin-top:4px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);min-width:180px;z-index:50;padding:6px 0">
					<?php foreach ( $groups as $k => $g ): ?>
						<button type="button" class="md-menu-item md-bulk-add-group" data-group="<?php echo esc_attr( $k ); ?>" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;align-items:center;gap:8px">
							<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $g['color'] ?? '#2563eb' ); ?>;flex-shrink:0"></span>
							<?php echo esc_html( $g['name'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="position:relative;display:inline-block" data-md-bulk-remove-wrap>
				<button type="button" class="th-button" id="md-bulk-remove-btn"><?php esc_html_e( 'Remove from group ▾', 'milieus' ); ?></button>
				<div id="md-bulk-remove-menu" style="display:none;position:absolute;left:0;top:100%;margin-top:4px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);min-width:180px;z-index:50;padding:6px 0">
					<?php foreach ( $groups as $k => $g ): ?>
						<button type="button" class="md-menu-item md-bulk-remove-group" data-group="<?php echo esc_attr( $k ); ?>" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;align-items:center;gap:8px">
							<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $g['color'] ?? '#2563eb' ); ?>;flex-shrink:0"></span>
							<?php echo esc_html( $g['name'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="margin-left:auto">
				<button type="button" class="th-button" id="md-bulk-delete-btn" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)"><?php esc_html_e( 'Delete selected', 'milieus' ); ?></button>
			</div>
		</div>

		<!-- Members table -->
		<table class="th-roles-table" style="margin-top:14px">
			<thead><tr>
				<th style="width:40px"><input type="checkbox" id="md-check-all"></th>
				<th style="width:260px"><a href="<?php echo esc_url( $sort_url( 'display_name' ) ); ?>" style="text-decoration:none;color:inherit"><?php esc_html_e( 'User', 'milieus' ); ?><?php echo $sort_icon( 'display_name' ); ?></a></th>
				<th><?php esc_html_e( 'Groups', 'milieus' ); ?></th>
				<th style="width:110px"><a href="<?php echo esc_url( $sort_url( 'registered' ) ); ?>" style="text-decoration:none;color:inherit"><?php esc_html_e( 'Joined', 'milieus' ); ?><?php echo $sort_icon( 'registered' ); ?></a></th>
				<th style="width:110px"><?php esc_html_e( 'Expires', 'milieus' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'WP Role', 'milieus' ); ?></th>
				<th style="width:44px"></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $users ): ?>
					<tr><td colspan="7" style="text-align:center;color:var(--tx3);padding:20px"><?php esc_html_e( 'No users match these filters.', 'milieus' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $u ):
					$memberships = $user_groups[ $u->ID ] ?? [];
					$avatar = get_avatar_url( $u->ID, [ 'size' => 36 ] );
					$wp_role = ! empty( $u->roles ) ? ucfirst( str_replace( '_', ' ', $u->roles[0] ) ) : '—';

					// For Expires column, show the earliest-expiring membership.
					$earliest = null;
					foreach ( $memberships as $m ) {
						if ( $earliest === null || ( $m['expires'] > 0 && ( $earliest['expires'] === 0 || $m['expires'] < $earliest['expires'] ) ) ) {
							$earliest = $m;
						}
					}

					// Groups user is NOT in (for "Add to group" submenu).
					$membership_keys = array_column( $memberships, 'key' );
					$available_groups = array_filter( $groups, fn( $k ) => ! in_array( $k, $membership_keys, true ), ARRAY_FILTER_USE_KEY );
				?>
				<tr data-uid="<?php echo (int) $u->ID; ?>">
					<td><input type="checkbox" class="md-row-check" data-uid="<?php echo (int) $u->ID; ?>"></td>
					<td>
						<div style="display:flex;align-items:center;gap:10px">
							<img src="<?php echo esc_url( $avatar ); ?>" width="36" height="36" style="border-radius:50%;flex-shrink:0" alt="">
							<div>
								<strong><?php echo esc_html( $u->display_name ?: $u->user_login ); ?></strong><br>
								<span class="th-roles-caps"><?php echo esc_html( $u->user_email ); ?></span>
							</div>
						</div>
					</td>
					<td>
						<div class="md-pills" style="display:flex;flex-wrap:wrap;gap:4px">
							<?php if ( $memberships ): ?>
								<?php foreach ( $memberships as $m ): ?>
									<span class="md-pill" data-group="<?php echo esc_attr( $m['key'] ); ?>" style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px 3px 10px;background:color-mix(in srgb,<?php echo esc_attr( $m['color'] ); ?> 10%,#fafaf9);border:1px solid color-mix(in srgb,<?php echo esc_attr( $m['color'] ); ?> 22%,transparent);border-radius:999px;font-size:11px;font-weight:600;color:<?php echo esc_attr( $m['color'] ); ?>">
										<span style="width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0"></span>
										<?php echo esc_html( $m['name'] ); ?>
										<button type="button" class="md-pill-x" data-uid="<?php echo (int) $u->ID; ?>" data-group="<?php echo esc_attr( $m['key'] ); ?>" title="<?php esc_attr_e( 'Remove from group', 'milieus' ); ?>" style="background:none;border:0;color:currentColor;cursor:pointer;font-size:13px;line-height:1;padding:0 0 0 2px;opacity:.5">&times;</button>
									</span>
								<?php endforeach; ?>
							<?php else: ?>
								<span style="color:var(--tx3);font-size:12px"><?php esc_html_e( 'No groups', 'milieus' ); ?></span>
							<?php endif; ?>
						</div>
					</td>
					<td class="th-roles-caps">
						<?php echo esc_html( wp_date( 'M j, Y', strtotime( $u->user_registered ) ) ); ?>
					</td>
					<td>
						<?php
						if ( $earliest && $earliest['expires'] > 0 ) {
							$tier = milieus_expiry_tier( $earliest['expires'] );
							$tier_color = match( $tier ) {
								'expired' => '#dc2626',
								'urgent'  => '#f59e0b',
								'soon'    => '#f59e0b',
								default   => 'var(--tx2)',
							};
							echo '<span style="font-size:12px;color:' . esc_attr( $tier_color ) . '">' . esc_html( milieus_humanize_expiry( $earliest['expires'] ) ) . '</span>';
						} elseif ( $earliest ) {
							echo '<span class="th-roles-caps">' . esc_html__( 'Permanent', 'milieus' ) . '</span>';
						} else {
							echo '<span style="color:var(--tx3)">—</span>';
						}
						?>
					</td>
					<td class="th-roles-caps"><?php echo esc_html( $wp_role ); ?></td>
					<td style="text-align:right;position:relative">
						<button type="button" class="md-menu-btn" title="<?php esc_attr_e( 'Actions', 'milieus' ); ?>" style="background:none;border:1px solid var(--bd);border-radius:6px;width:32px;height:32px;cursor:pointer;font-size:16px;color:var(--tx2);display:inline-flex;align-items:center;justify-content:center;transition:background .1s">&middot;&middot;&middot;</button>
						<div class="md-menu" style="display:none;position:absolute;right:0;top:36px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:200px;z-index:50;padding:6px 0;font-size:13px">
							<?php if ( $available_groups ): ?>
								<div class="md-menu-sub" style="position:relative">
									<button type="button" class="md-menu-item md-menu-sub-trigger" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;justify-content:space-between;align-items:center">
										<?php esc_html_e( 'Add to group', 'milieus' ); ?> <span style="font-size:11px;color:var(--tx3)">&#9654;</span>
									</button>
									<div class="md-submenu" style="display:none;position:absolute;left:100%;top:-6px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:180px;padding:6px 0;z-index:51">
										<?php foreach ( $available_groups as $k => $g ): ?>
											<button type="button" class="md-menu-item md-add-group" data-uid="<?php echo (int) $u->ID; ?>" data-group="<?php echo esc_attr( $k ); ?>" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;align-items:center;gap:8px">
												<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $g['color'] ?? '#2563eb' ); ?>;flex-shrink:0"></span>
												<?php echo esc_html( $g['name'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
							<?php if ( $memberships ): ?>
								<div class="md-menu-sub" style="position:relative">
									<button type="button" class="md-menu-item md-menu-sub-trigger" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;justify-content:space-between;align-items:center">
										<?php esc_html_e( 'Remove from group', 'milieus' ); ?> <span style="font-size:11px;color:var(--tx3)">&#9654;</span>
									</button>
									<div class="md-submenu" style="display:none;position:absolute;left:100%;top:-6px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);min-width:180px;padding:6px 0;z-index:51">
										<?php foreach ( $memberships as $m ): ?>
											<button type="button" class="md-menu-item md-remove-group" data-uid="<?php echo (int) $u->ID; ?>" data-group="<?php echo esc_attr( $m['key'] ); ?>" style="width:100%;text-align:left;background:none;border:0;padding:8px 14px;cursor:pointer;color:var(--tx);font:inherit;display:flex;align-items:center;gap:8px">
												<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $m['color'] ); ?>;flex-shrink:0"></span>
												<?php echo esc_html( $m['name'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
							<div style="border-top:1px solid var(--bd);margin:4px 0"></div>
							<a class="md-menu-item" href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $u->ID ) ); ?>" style="display:block;padding:8px 14px;color:var(--tx);text-decoration:none;font:inherit"><?php esc_html_e( 'Edit user', 'milieus' ); ?></a>
							<?php if ( (int) $u->ID !== get_current_user_id() ): ?>
								<a class="md-menu-item md-delete-user" href="<?php echo esc_url( wp_nonce_url( admin_url( 'users.php?action=delete&user=' . $u->ID ), 'bulk-users' ) ); ?>" style="display:block;padding:8px 14px;color:var(--err);text-decoration:none;font:inherit"><?php esc_html_e( 'Delete user', 'milieus' ); ?></a>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php if ( $pages > 1 ): ?>
		<div style="margin-top:14px;display:flex;gap:6px;align-items:center">
			<?php for ( $p = max( 1, $page - 3 ); $p <= min( $pages, $page + 3 ); $p++ ):
				$pargs = array_filter( [ 'page' => 'milieus-all-members', 'paged' => $p, 's' => $search, 'group' => $filter_group, 'orderby' => $orderby, 'order' => $order ] );
				$purl  = add_query_arg( $pargs, admin_url( 'admin.php' ) );
			?>
				<a class="th-button<?php echo $p === $page ? ' th-button-primary' : ''; ?>" href="<?php echo esc_url( $purl ); ?>"><?php echo (int) $p; ?></a>
			<?php endfor; ?>
			<span class="th-roles-caps" style="margin-left:8px"><?php printf( esc_html__( 'Page %1$d of %2$d', 'milieus' ), $page, $pages ); ?></span>
		</div>
		<?php endif; ?>
	</div></div>

	<style>
	.md-menu-btn:hover{background:var(--sf2)}
	.md-menu-item:hover{background:var(--sf2)}
	.md-menu-sub:hover>.md-submenu{display:block!important}
	.md-pill-x:hover{opacity:1!important}
	</style>
	<script>
	(function(){
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

		// 3-dot menu toggle.
		document.addEventListener('click', function(e) {
			var btn = e.target.closest('.md-menu-btn');
			// Close all other menus first.
			document.querySelectorAll('.md-menu').forEach(function(m) {
				if (!btn || m !== btn.nextElementSibling) m.style.display = 'none';
			});
			if (btn) {
				var menu = btn.nextElementSibling;
				menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
				e.stopPropagation();
			}
		});

		// Close menus on outside click.
		document.addEventListener('click', function() {
			document.querySelectorAll('.md-menu').forEach(function(m) { m.style.display = 'none'; });
		});

		// Prevent menu clicks from bubbling to the close handler.
		document.querySelectorAll('.md-menu').forEach(function(m) {
			m.addEventListener('click', function(e) { e.stopPropagation(); });
		});

		// Add to group.
		document.querySelectorAll('.md-add-group').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var uid = btn.dataset.uid, group = btn.dataset.group;
				btn.textContent = 'Adding...';
				post('milieus_directory_add', { user_id: uid, group: group }, function() {
					location.reload();
				});
			});
		});

		// Remove from group (3-dot submenu).
		document.querySelectorAll('.md-remove-group').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var uid = btn.dataset.uid, group = btn.dataset.group;
				btn.textContent = 'Removing...';
				post('milieus_directory_remove', { user_id: uid, group: group }, function() {
					location.reload();
				});
			});
		});

		// Inline pill X button — remove from group.
		document.querySelectorAll('.md-pill-x').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var uid = btn.dataset.uid, group = btn.dataset.group;
				var pill = btn.closest('.md-pill');
				pill.style.opacity = '.4';
				post('milieus_directory_remove', { user_id: uid, group: group }, function() {
					pill.remove();
					// If no pills left, show placeholder.
					var container = btn.closest('tr').querySelector('.md-pills');
					if (container && !container.querySelector('.md-pill')) {
						container.innerHTML = '<span style="color:var(--tx3);font-size:12px"><?php echo esc_js( __( 'No groups', 'milieus' ) ); ?></span>';
					}
				});
			});
		});

		// Delete user confirmation.
		document.querySelectorAll('.md-delete-user').forEach(function(a) {
			a.addEventListener('click', function(e) {
				if (!confirm('<?php echo esc_js( __( 'Delete this user? This cannot be undone.', 'milieus' ) ); ?>')) {
					e.preventDefault();
				}
			});
		});

		function post(action, data, cb) {
			data.action = action;
			data.nonce = nonce;
			var fd = new FormData();
			for (var k in data) fd.append(k, data[k]);
			fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(r) { if (cb) cb(r); })
				.catch(function() { alert(<?php echo wp_json_encode( __( 'Network error — please try again.', 'milieus' ) ); ?>); });
		}

		// ── Bulk actions ──────────────────────────────────────────────────
		var checkAll = document.getElementById('md-check-all');
		var bulkBar = document.getElementById('md-bulk-bar');
		var bulkCount = document.getElementById('md-bulk-count');

		function getCheckedIds() {
			return Array.prototype.slice.call(document.querySelectorAll('.md-row-check:checked')).map(function(c){ return c.dataset.uid; });
		}
		function updateBulkBar() {
			var ids = getCheckedIds();
			bulkBar.style.display = ids.length > 0 ? 'flex' : 'none';
			bulkCount.textContent = ids.length;
		}
		if (checkAll) {
			checkAll.addEventListener('change', function() {
				document.querySelectorAll('.md-row-check').forEach(function(c){ c.checked = checkAll.checked; });
				updateBulkBar();
			});
		}
		document.querySelectorAll('.md-row-check').forEach(function(c){
			c.addEventListener('change', updateBulkBar);
		});

		// Bulk add/remove menus
		function setupBulkMenu(btnId, menuId) {
			var btn = document.getElementById(btnId);
			var menu = document.getElementById(menuId);
			if (!btn || !menu) return;
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
			});
			document.addEventListener('click', function(e) {
				if (!btn.contains(e.target) && !menu.contains(e.target)) menu.style.display = 'none';
			});
		}
		setupBulkMenu('md-bulk-add-btn', 'md-bulk-add-menu');
		setupBulkMenu('md-bulk-remove-btn', 'md-bulk-remove-menu');

		// Sequential bulk helper — avoids overwhelming the server with parallel requests
		function bulkSequential(ids, action, extraData, statusEl, verb) {
			var i = 0;
			function next() {
				if (i >= ids.length) { location.reload(); return; }
				statusEl.textContent = verb + ' ' + (i + 1) + '/' + ids.length + '…';
				var data = { user_id: ids[i] };
				for (var k in extraData) data[k] = extraData[k];
				post(action, data, function() { i++; next(); });
			}
			next();
		}

		document.querySelectorAll('.md-bulk-add-group').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var ids = getCheckedIds();
				if (!ids.length) return;
				bulkSequential(ids, 'milieus_directory_add', { group: btn.dataset.group }, btn, <?php echo wp_json_encode( __( 'Adding', 'milieus' ) ); ?>);
			});
		});

		document.querySelectorAll('.md-bulk-remove-group').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var ids = getCheckedIds();
				if (!ids.length) return;
				if (!confirm(<?php echo wp_json_encode( sprintf( __( 'Remove %s user(s) from this group?', 'milieus' ), '" + ids.length + "' ) ); ?>)) return;
				bulkSequential(ids, 'milieus_directory_remove', { group: btn.dataset.group }, btn, <?php echo wp_json_encode( __( 'Removing', 'milieus' ) ); ?>);
			});
		});

		// Bulk delete — sequential to avoid overwhelming the server
		var bulkDeleteBtn = document.getElementById('md-bulk-delete-btn');
		if (bulkDeleteBtn) {
			bulkDeleteBtn.addEventListener('click', function() {
				var ids = getCheckedIds();
				if (!ids.length) return;
				var currentUserId = <?php echo wp_json_encode( (string) get_current_user_id() ); ?>;
				ids = ids.filter(function(id) { return id !== currentUserId; });
				if (!ids.length) {
					alert(<?php echo wp_json_encode( __( 'You cannot delete your own account.', 'milieus' ) ); ?>);
					return;
				}
				if (!confirm(<?php echo wp_json_encode( __( 'Permanently delete ', 'milieus' ) ); ?> + ids.length + <?php echo wp_json_encode( __( ' user(s)? This cannot be undone.', 'milieus' ) ); ?>)) return;
				bulkDeleteBtn.disabled = true;
				var failed = 0, i = 0;
				function deleteNext() {
					if (i >= ids.length) {
						if (failed > 0) alert(failed + <?php echo wp_json_encode( __( ' user(s) could not be deleted.', 'milieus' ) ); ?>);
						location.reload();
						return;
					}
					bulkDeleteBtn.textContent = <?php echo wp_json_encode( __( 'Deleting ', 'milieus' ) ); ?> + (i + 1) + '/' + ids.length + '…';
					post('milieus_directory_delete', { user_id: ids[i] }, function(r) {
						if (!r || !r.success) failed++;
						i++;
						deleteNext();
					});
				}
				deleteNext();
			});
		}
	})();
	</script>
	<?php
}
