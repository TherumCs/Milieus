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
 */
function milieus_audit_install_schema(): void {
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
	dbDelta( $sql );
	update_option( 'milieus_audit_table_version', MILIEUS_AUDIT_TABLE_VERSION );
}

function milieus_audit_log( string $event, int $user_id = 0, string $group_key = '', string $source = '', array $note = [] ): void {
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
	milieus_audit_log( 'member.revoked', (int) $uid, (string) $key, did_action( 'milieus_expire_sweep' ) ? 'cron' : 'admin' );
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
				<input type="number" class="th-input" name="user" placeholder="User ID" value="<?php echo $filter_user ?: ''; ?>" style="width:130px">
				<button class="th-button"><?php esc_html_e( 'Filter', 'milieus' ); ?></button>
				<a class="th-link-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=milieus-audit' ) ); ?>"><?php esc_html_e( 'Reset', 'milieus' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-left:auto">
					<input type="hidden" name="action" value="milieus_audit_export">
					<?php wp_nonce_field( 'milieus_audit' ); ?>
					<button class="th-button">⤓ <?php esc_html_e( 'Export CSV', 'milieus' ); ?></button>
				</form>
			</form>
		</div>

		<table class="th-roles-table" style="margin-top:14px">
			<thead><tr>
				<th style="width:170px"><?php esc_html_e( 'When', 'milieus' ); ?></th>
				<th style="width:170px"><?php esc_html_e( 'Event', 'milieus' ); ?></th>
				<th><?php esc_html_e( 'User', 'milieus' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'Group', 'milieus' ); ?></th>
				<th style="width:140px"><?php esc_html_e( 'Source', 'milieus' ); ?></th>
				<th style="width:140px"><?php esc_html_e( 'Actor', 'milieus' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $rows ): ?>
					<tr><td colspan="6" style="text-align:center;color:var(--tx3);padding:20px"><?php esc_html_e( 'No entries match these filters.', 'milieus' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ):
					$u     = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
					$actor = $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
					$gname = $r->group_key && isset( $groups[ $r->group_key ] ) ? $groups[ $r->group_key ]['name'] : $r->group_key;
				?>
				<tr>
					<td class="th-roles-caps"><?php echo esc_html( wp_date( 'M j, Y · g:i a', (int) $r->ts ) ); ?></td>
					<td><code style="font-size:11px"><?php echo esc_html( $r->event ); ?></code></td>
					<td><?php echo $u ? '<strong>' . esc_html( $u->display_name ?: $u->user_login ) . '</strong> · <span class="th-roles-caps">' . esc_html( $u->user_email ) . '</span>' : '—'; ?></td>
					<td><?php echo esc_html( $gname ?: '—' ); ?></td>
					<td class="th-roles-caps"><?php echo esc_html( $r->source ?: '—' ); ?></td>
					<td class="th-roles-caps"><?php echo $actor ? esc_html( $actor->display_name ) : ( $r->actor_id ? '#' . $r->actor_id : esc_html__( 'system', 'milieus' ) ); ?></td>
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
	</div></div>
	<?php
}
