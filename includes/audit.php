<?php
/**
 * Milieus by Therum — audit log.
 *
 * Append-only record of every membership change. Useful for support,
 * compliance ("when did Ada lose VIP?"), and debugging webhook deliveries.
 *
 * Schema (custom table {prefix}_milieus_audit):
 *   id           bigint  PK
 *   ts           int     unix timestamp
 *   event        varchar event name (member.assigned, member.revoked, ...)
 *   user_id      bigint  affected user
 *   group_key    varchar group key (or '' if N/A)
 *   actor_id     bigint  user who did the action (0 if system/cron)
 *   source       varchar manual | link | csv | api | purchase | cron | system
 *   note         text    free-form context (JSON of relevant fields)
 *
 * Viewer at Milieus → Audit log — filter by user, group, event; CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_AUDIT_TABLE_VERSION = 1;

function milieus_audit_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'milieus_audit';
}

/**
 * Create the table. Called from the activation hook in milieus.php.
 * Returns true on success, false if dbDelta couldn't create the table —
 * the caller surfaces an admin notice so the failure isn't silent.
 */
function milieus_audit_install_schema(): bool {
	global $wpdb;
	$table = milieus_audit_table();
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts INT UNSIGNED NOT NULL,
		event VARCHAR(64) NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		group_key VARCHAR(64) NOT NULL DEFAULT '',
		actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		source VARCHAR(32) NOT NULL DEFAULT '',
		note TEXT NULL,
		PRIMARY KEY  (id),
		KEY ts (ts),
		KEY user_id (user_id),
		KEY group_key (group_key),
		KEY event (event)
	) {$charset};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$wpdb->suppress_errors( true );
	dbDelta( $sql );
	$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	$wpdb->suppress_errors( false );
	if ( $exists ) {
		update_option( 'milieus_audit_table_version', MILIEUS_AUDIT_TABLE_VERSION );
		delete_option( 'milieus_audit_install_failed' );
		return true;
	}
	update_option( 'milieus_audit_install_failed', $wpdb->last_error ?: 'unknown' );
	return false;
}

/**
 * Show an admin notice if the audit table didn't install. The plugin still
 * works without it — logging silently no-ops — but the user should know.
 */
add_action( 'admin_notices', function() {
	$err = get_option( 'milieus_audit_install_failed' );
	if ( ! $err || ! current_user_can( 'manage_options' ) ) return;
	?>
	<div class="notice notice-error is-dismissible">
		<p><strong>Milieus:</strong> the audit log table couldn't be created. Audit + history features will be unavailable. Database error: <code><?php echo esc_html( $err ); ?></code></p>
		<p>Most common cause: the DB user lacks <code>CREATE TABLE</code>. Grant the privilege and re-activate the plugin, or run <code>milieus_audit_install_schema()</code> from WP-CLI.</p>
	</div>
	<?php
} );

/**
 * Make audit log writes safe even when the table doesn't exist.
 */
function milieus_audit_table_exists(): bool {
	global $wpdb;
	static $cache = null;
	if ( $cache !== null ) return $cache;
	$cache = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", milieus_audit_table() ) );
	return $cache;
}

function milieus_audit_log( string $event, int $user_id = 0, string $group_key = '', string $source = '', array $note = [] ): void {
	if ( ! milieus_audit_table_exists() ) return;
	global $wpdb;
	$wpdb->insert( milieus_audit_table(), [
		'ts'        => time(),
		'event'     => $event,
		'user_id'   => $user_id,
		'group_key' => $group_key,
		'actor_id'  => get_current_user_id(),
		'source'    => $source,
		'note'      => $note ? wp_json_encode( $note ) : null,
	], [ '%d', '%s', '%d', '%s', '%d', '%s', '%s' ] );
}

// ── Listeners ────────────────────────────────────────────────────────

add_action( 'milieus_member_assigned', function( $uid, $key, $source, $is_new ) {
	milieus_audit_log( $is_new ? 'member.assigned' : 'member.refreshed', (int) $uid, (string) $key, (string) $source );
}, 10, 4 );

add_action( 'milieus_member_revoked', function( $uid, $key ) {
	// `doing_action` checks what's CURRENTLY executing — `did_action` returns
	// the count of times it ever fired in this request, which would
	// misattribute admin-initiated revokes that happen after a cron run.
	$source = ( function_exists( 'doing_action' ) && doing_action( 'milieus_expire_sweep' ) ) ? 'cron' : 'admin';
	milieus_audit_log( 'member.revoked', (int) $uid, (string) $key, $source );
}, 10, 2 );

add_action( 'milieus_pending_created', function( $uid, $key ) {
	milieus_audit_log( 'member.pending', (int) $uid, (string) $key, 'link' );
}, 10, 2 );

add_action( 'milieus_member_expiring_soon', function( $uid, $key, $expires ) {
	milieus_audit_log( 'member.expiring_soon', (int) $uid, (string) $key, 'cron', [ 'expires_at' => (int) $expires ] );
}, 10, 3 );

add_action( 'milieus_member_purchased', function( $uid, $key, $order_id, $product_id ) {
	milieus_audit_log( 'purchase.confirmed', (int) $uid, (string) $key, 'purchase', [ 'order_id' => $order_id, 'product_id' => $product_id ] );
}, 10, 4 );

add_action( 'milieus_member_refunded', function( $uid, $key, $order_id ) {
	milieus_audit_log( 'purchase.refunded', (int) $uid, (string) $key, 'purchase', [ 'order_id' => $order_id ] );
}, 10, 3 );

// ── Viewer + CSV export ──────────────────────────────────────────────

add_action( 'admin_menu', function() {
	add_submenu_page(
		'milieus-roles',
		__( 'Audit log', 'milieus' ),
		__( 'Audit log', 'milieus' ),
		'manage_options',
		'milieus-audit',
		'milieus_render_audit_page'
	);
}, 40 );

add_action( 'admin_post_milieus_audit_export', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_audit' );

	global $wpdb;
	$rows = $wpdb->get_results( "SELECT * FROM " . milieus_audit_table() . " ORDER BY ts DESC LIMIT 10000", ARRAY_A );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="milieus-audit-' . gmdate( 'Ymd' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'ts', 'event', 'user_id', 'user_email', 'group', 'actor_id', 'source', 'note' ] );
	foreach ( $rows as $r ) {
		$u = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
		fputcsv( $out, [
			gmdate( 'Y-m-d H:i:s', (int) $r['ts'] ),
			$r['event'],
			$r['user_id'],
			$u ? $u->user_email : '',
			$r['group_key'],
			$r['actor_id'],
			$r['source'],
			$r['note'],
		] );
	}
	fclose( $out );
	exit;
} );

function milieus_render_audit_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );

	global $wpdb;
	$table = milieus_audit_table();

	// Filters
	$filter_event = sanitize_key( $_GET['event'] ?? '' );
	$filter_group = sanitize_key( $_GET['group'] ?? '' );
	$filter_user  = (int) ( $_GET['user'] ?? 0 );
	$per          = 50;
	$page         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$offset       = ( $page - 1 ) * $per;

	$where = [ '1=1' ]; $args = [];
	if ( $filter_event ) { $where[] = 'event = %s'; $args[] = $filter_event; }
	if ( $filter_group ) { $where[] = 'group_key = %s'; $args[] = $filter_group; }
	if ( $filter_user )  { $where[] = 'user_id = %d'; $args[] = $filter_user; }

	$sql_where = implode( ' AND ', $where );
	$total = (int) ( $args
		? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $args ) )
		: $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" )
	);

	$query = $args
		? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY ts DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per, $offset ] ) )
		: $wpdb->prepare( "SELECT * FROM {$table} ORDER BY ts DESC LIMIT %d OFFSET %d", $per, $offset );
	$rows = $wpdb->get_results( $query );

	$pages = max( 1, (int) ceil( $total / $per ) );
	$events = [ 'member.assigned', 'member.refreshed', 'member.revoked', 'member.pending', 'member.expiring_soon', 'purchase.confirmed', 'purchase.refunded' ];
	$groups = milieus_get_groups();
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Audit log', 'milieus' ); ?></h1>
			<p class="th-cx-sub"><?php esc_html_e( 'Append-only record of every membership change — who, what, when, from where.', 'milieus' ); ?> · <code><?php echo (int) $total; ?> <?php esc_html_e( 'entries', 'milieus' ); ?></code></p>
		</div>

		<div class="th-settings-card">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:0">
				<input type="hidden" name="page" value="milieus-audit">
				<select name="event" class="th-input"><option value=""><?php esc_html_e( 'All events', 'milieus' ); ?></option><?php foreach ( $events as $e ): ?><option value="<?php echo esc_attr( $e ); ?>" <?php selected( $filter_event, $e ); ?>><?php echo esc_html( $e ); ?></option><?php endforeach; ?></select>
				<select name="group" class="th-input"><option value=""><?php esc_html_e( 'All groups', 'milieus' ); ?></option><?php foreach ( $groups as $k => $g ): ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter_group, $k ); ?>><?php echo esc_html( $g['name'] ); ?></option><?php endforeach; ?></select>
				<div style="position:relative;display:inline-block">
					<input type="text" class="th-input" id="milieus-audit-user-search" placeholder="<?php esc_attr_e( 'Search user…', 'milieus' ); ?>" value="<?php echo esc_attr( $filter_user ? ( ( $fu = get_userdata( $filter_user ) ) ? $fu->display_name . ' (#' . $filter_user . ')' : '#' . $filter_user ) : '' ); ?>" style="width:200px" autocomplete="off">
					<input type="hidden" name="user" id="milieus-audit-user-id" value="<?php echo esc_attr( $filter_user ?: '' ); ?>">
					<div id="milieus-audit-user-results" style="display:none;position:absolute;left:0;top:100%;background:#fff;border:1px solid var(--bd);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.1);min-width:260px;z-index:50;max-height:240px;overflow-y:auto"></div>
				</div>
				<button class="th-button"><?php esc_html_e( 'Filter', 'milieus' ); ?></button>
				<a class="th-link-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=milieus-audit' ) ); ?>"><?php esc_html_e( 'Reset', 'milieus' ); ?></a>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:auto">
				<input type="hidden" name="action" value="milieus_audit_export">
				<?php wp_nonce_field( 'milieus_audit' ); ?>
				<button class="th-button">⤓ <?php esc_html_e( 'Export CSV', 'milieus' ); ?></button>
			</form>
		</div>

		<table class="th-roles-table" style="margin-top:14px">
			<thead><tr>
				<th style="width:170px"><?php esc_html_e( 'When', 'milieus' ); ?></th>
				<th style="width:150px"><?php esc_html_e( 'Event', 'milieus' ); ?></th>
				<th><?php esc_html_e( 'User', 'milieus' ); ?></th>
				<th style="width:140px"><?php esc_html_e( 'Group', 'milieus' ); ?></th>
				<th style="width:100px"><?php esc_html_e( 'Source', 'milieus' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Actor', 'milieus' ); ?></th>
				<th style="width:180px"><?php esc_html_e( 'Details', 'milieus' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $rows ): ?>
					<tr><td colspan="7" style="text-align:center;color:var(--tx3);padding:20px"><?php esc_html_e( 'No entries match these filters.', 'milieus' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ):
					$u     = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
					$actor = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
					$gname = $r->group_key && isset( $groups[ $r->group_key ] ) ? $groups[ $r->group_key ]['name'] : $r->group_key;
					$note_data = $r->note ? json_decode( $r->note, true ) : null;
				?>
				<tr>
					<td class="th-roles-caps"><?php echo esc_html( wp_date( 'M j, Y · g:i a', (int) $r->ts ) ); ?></td>
					<td><code style="font-size:11px"><?php echo esc_html( $r->event ); ?></code></td>
					<td><?php
						if ( $u ) {
							echo '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $u->ID ) ) . '" style="text-decoration:none;color:inherit"><strong>' . esc_html( $u->display_name ?: $u->user_login ) . '</strong></a> · <span class="th-roles-caps">' . esc_html( $u->user_email ) . '</span>';
						} else {
							echo '—';
						}
					?></td>
					<td><?php
						if ( $r->group_key && isset( $groups[ $r->group_key ] ) ) {
							echo '<a href="' . esc_url( admin_url( 'admin.php?page=milieus-roles#group-' . $r->group_key ) ) . '" style="text-decoration:none;color:inherit">' . esc_html( $gname ) . '</a>';
						} else {
							echo esc_html( $gname ?: '—' );
						}
					?></td>
					<td class="th-roles-caps"><?php echo esc_html( $r->source ?: '—' ); ?></td>
					<td class="th-roles-caps"><?php echo $actor ? '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $actor->ID ) ) . '" style="text-decoration:none;color:inherit">' . esc_html( $actor->display_name ) . '</a>' : ( $r->actor_id ? '#' . $r->actor_id : esc_html__( 'system', 'milieus' ) ); ?></td>
					<td class="th-roles-caps"><?php
						if ( $note_data ) {
							$bits = [];
							if ( isset( $note_data['order_id'] ) )   $bits[] = 'Order #' . (int) $note_data['order_id'];
							if ( isset( $note_data['product_id'] ) ) $bits[] = 'Product #' . (int) $note_data['product_id'];
							if ( isset( $note_data['expires_at'] ) ) $bits[] = 'Exp ' . esc_html( wp_date( 'M j', (int) $note_data['expires_at'] ) );
							if ( isset( $note_data['sub_id'] ) )     $bits[] = 'Sub #' . (int) $note_data['sub_id'];
							echo $bits ? esc_html( implode( ' · ', $bits ) ) : '<span style="color:var(--tx3)">—</span>';
						} else {
							echo '<span style="color:var(--tx3)">—</span>';
						}
					?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ): ?>
		<div style="margin-top:14px;display:flex;gap:6px;align-items:center">
			<?php for ( $p = max( 1, $page - 3 ); $p <= min( $pages, $page + 3 ); $p++ ):
				$args = array_filter( [ 'page' => 'milieus-audit', 'paged' => $p, 'event' => $filter_event, 'group' => $filter_group, 'user' => $filter_user ?: '' ] );
				$url = add_query_arg( $args, admin_url( 'admin.php' ) );
			?>
				<a class="th-button<?php echo $p === $page ? ' th-button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo (int) $p; ?></a>
			<?php endfor; ?>
		</div>
		<?php endif; ?>
		<script>
		(function(){
			var input = document.getElementById('milieus-audit-user-search');
			var hidden = document.getElementById('milieus-audit-user-id');
			var results = document.getElementById('milieus-audit-user-results');
			var timer = null;
			input.addEventListener('input', function() {
				clearTimeout(timer);
				var q = input.value.trim();
				if (q.length < 2) { results.style.display = 'none'; hidden.value = ''; return; }
				timer = setTimeout(function() {
					var fd = new FormData();
					fd.append('action', 'milieus_audit_user_search');
					fd.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'milieus_audit_user_search' ) ); ?>);
					fd.append('q', q);
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(j){
							if (!j || !j.success) return;
							results.innerHTML = '';
							if (!j.data.length) {
								results.innerHTML = '<div style="padding:10px 14px;color:var(--tx3);font-size:13px"><?php echo esc_js( __( 'No users found', 'milieus' ) ); ?></div>';
							} else {
								j.data.forEach(function(u) {
									var row = document.createElement('div');
									row.style.cssText = 'padding:8px 14px;cursor:pointer;font-size:13px;display:flex;align-items:center;gap:8px';
									row.innerHTML = '<strong>' + esc(u.name) + '</strong> <span style="color:var(--tx3)">' + esc(u.email) + '</span>';
									row.addEventListener('mouseenter', function(){ row.style.background='var(--sf2)'; });
									row.addEventListener('mouseleave', function(){ row.style.background=''; });
									row.addEventListener('click', function() {
										hidden.value = u.id;
										input.value = u.name + ' (#' + u.id + ')';
										results.style.display = 'none';
									});
									results.appendChild(row);
								});
							}
							results.style.display = 'block';
						});
				}, 200);
			});
			document.addEventListener('click', function(e) {
				if (!input.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
			});
			function esc(s) { var d=document.createElement('span'); d.textContent=s; return d.innerHTML; }
		})();
		</script>
	</div></div>
	<?php
}

// ── AJAX: user search for audit filter ──────────────────────────────

add_action( 'wp_ajax_milieus_audit_user_search', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_audit_user_search' );
	$q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
	if ( strlen( $q ) < 2 ) wp_send_json_success( [] );

	$query = new WP_User_Query( [
		'search'         => '*' . esc_attr( $q ) . '*',
		'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
		'number'         => 8,
		'fields'         => [ 'ID', 'display_name', 'user_email' ],
	] );
	$out = [];
	foreach ( $query->get_results() as $u ) {
		$out[] = [ 'id' => $u->ID, 'name' => $u->display_name ?: $u->user_email, 'email' => $u->user_email ];
	}
	wp_send_json_success( $out );
} );
