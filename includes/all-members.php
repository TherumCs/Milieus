<?php
/**
 * Milieus by Therum — All Members directory.
 *
 * A single page showing every user on the site with their Milieus group
 * memberships, sortable and filterable. Lives at Milieus → All Members.
 *
 * Columns: User (name + email), Groups (color-coded tags), Joined, Expires,
 * Source, WP Role. Supports: search by name/email, filter by group, sort by
 * column, pagination.
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
}, 12 ); // just after Member Groups (default 10)

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'milieus-all-members' ) return;
	$css = MILIEUS_DIR . 'assets/admin.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'milieus-admin', MILIEUS_URL . 'assets/admin.css', [], filemtime( $css ) );
	}
} );

function milieus_render_all_members_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

	$groups     = milieus_get_groups();
	$group_keys = array_keys( $groups );

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

	// Filter by group = filter by role.
	if ( $filter_group && isset( $groups[ $filter_group ] ) ) {
		$args['role'] = $filter_group;
	}

	$query = new WP_User_Query( $args );
	$users = $query->get_results();
	$total = (int) $query->get_total();
	$pages = max( 1, (int) ceil( $total / $per ) );

	// ── Precompute per-user group membership ────────────────────────────
	// For each displayed user, check which Milieus groups they belong to.
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
		return $order === 'ASC' ? ' ▲' : ' ▼';
	};

	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'All Members', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Every user on this site and which Milieus groups they belong to.', 'milieus' ); ?> · <code><?php echo (int) $total; ?> <?php esc_html_e( 'users', 'milieus' ); ?></code></p>
		</div>

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

		<!-- Members table -->
		<table class="th-roles-table" style="margin-top:14px">
			<thead><tr>
				<th style="width:260px"><a href="<?php echo esc_url( $sort_url( 'display_name' ) ); ?>" style="text-decoration:none;color:inherit"><?php esc_html_e( 'User', 'milieus' ); echo $sort_icon( 'display_name' ); ?></a></th>
				<th><?php esc_html_e( 'Groups', 'milieus' ); ?></th>
				<th style="width:120px"><a href="<?php echo esc_url( $sort_url( 'registered' ) ); ?>" style="text-decoration:none;color:inherit"><?php esc_html_e( 'Joined', 'milieus' ); echo $sort_icon( 'registered' ); ?></a></th>
				<th style="width:120px"><?php esc_html_e( 'Expires', 'milieus' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Source', 'milieus' ); ?></th>
				<th style="width:120px"><a href="<?php echo esc_url( $sort_url( 'user_email' ) ); ?>" style="text-decoration:none;color:inherit"><?php esc_html_e( 'WP Role', 'milieus' ); ?></a></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $users ): ?>
					<tr><td colspan="6" style="text-align:center;color:var(--tx3);padding:20px"><?php esc_html_e( 'No users match these filters.', 'milieus' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $u ):
					$memberships = $user_groups[ $u->ID ] ?? [];
					$avatar = get_avatar_url( $u->ID, [ 'size' => 36 ] );
					$wp_role = ! empty( $u->roles ) ? ucfirst( str_replace( '_', ' ', $u->roles[0] ) ) : '—';

					// For Expires + Source columns, show the earliest-expiring membership.
					$earliest = null;
					foreach ( $memberships as $m ) {
						if ( $earliest === null || ( $m['expires'] > 0 && ( $earliest['expires'] === 0 || $m['expires'] < $earliest['expires'] ) ) ) {
							$earliest = $m;
						}
					}
				?>
				<tr>
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
						<?php if ( $memberships ): ?>
							<div style="display:flex;flex-wrap:wrap;gap:4px">
								<?php foreach ( $memberships as $m ): ?>
									<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:color-mix(in srgb,<?php echo esc_attr( $m['color'] ); ?> 10%,#fafaf9);border:1px solid color-mix(in srgb,<?php echo esc_attr( $m['color'] ); ?> 22%,transparent);border-radius:999px;font-size:11px;font-weight:600;color:<?php echo esc_attr( $m['color'] ); ?>">
										<span style="width:6px;height:6px;border-radius:50%;background:<?php echo esc_attr( $m['color'] ); ?>"></span>
										<?php echo esc_html( $m['name'] ); ?>
									</span>
								<?php endforeach; ?>
							</div>
						<?php else: ?>
							<span style="color:var(--tx3);font-size:12px">—</span>
						<?php endif; ?>
					</td>
					<td class="th-roles-caps">
						<?php
						if ( $earliest ) {
							echo esc_html( wp_date( 'M j, Y', $earliest['assigned'] ) );
						} else {
							echo esc_html( wp_date( 'M j, Y', strtotime( $u->user_registered ) ) );
						}
						?>
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
					<td class="th-roles-caps"><?php echo esc_html( $earliest['source'] ?? '—' ); ?></td>
					<td class="th-roles-caps"><?php echo esc_html( $wp_role ); ?></td>
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
	<?php
}
