<?php
/**
 * Milieus by Therum — wp-admin Dashboard widget.
 *
 * Surfaces the two things admins want to know at a glance:
 *   1. New members this week (with group + source)
 *   2. Memberships expiring in the next 7 days
 *
 * Both pull live from user_meta — no extra storage.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_dashboard_setup', function() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	wp_add_dashboard_widget(
		'milieus_dashboard_widget',
		__( 'Milieus · Member activity', 'milieus' ),
		'milieus_dashboard_widget_render'
	);
} );

function milieus_dashboard_widget_render(): void {
	$groups = milieus_get_groups();
	if ( ! $groups ) {
		echo '<p>' . esc_html__( 'No custom groups yet.', 'milieus' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=milieus-roles' ) ) . '">' . esc_html__( 'Create one →', 'milieus' ) . '</a></p>';
		return;
	}

	// ── Recent sign-ups ────────────────────────────────────────────────
	$since = time() - 7 * DAY_IN_SECONDS;
	$recent = [];
	foreach ( $groups as $key => $g ) {
		$q = new WP_User_Query( [
			'meta_query' => [[
				'key'     => MILIEUS_META_ASSIGNED . $key,
				'value'   => $since,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			]],
			'fields' => [ 'ID', 'display_name', 'user_email' ],
			'number' => 50,
		] );
		foreach ( $q->get_results() as $u ) {
			$recent[] = [
				'user'   => $u,
				'group'  => $g,
				'when'   => (int) get_user_meta( $u->ID, MILIEUS_META_ASSIGNED . $key, true ),
				'source' => (string) get_user_meta( $u->ID, MILIEUS_META_SOURCE . $key, true ) ?: 'manual',
			];
		}
	}
	usort( $recent, fn( $a, $b ) => $b['when'] - $a['when'] );
	$recent = array_slice( $recent, 0, 5 );

	// ── Expiring soon ──────────────────────────────────────────────────
	$soon_until = time() + 7 * DAY_IN_SECONDS;
	$soon = [];
	foreach ( $groups as $key => $g ) {
		$q = new WP_User_Query( [
			'meta_query' => [
				[ 'key' => MILIEUS_META_EXPIRES . $key, 'value' => time(),       'compare' => '>',  'type' => 'NUMERIC' ],
				[ 'key' => MILIEUS_META_EXPIRES . $key, 'value' => $soon_until,  'compare' => '<=', 'type' => 'NUMERIC' ],
			],
			'fields' => [ 'ID', 'display_name', 'user_email' ],
			'number' => 50,
		] );
		foreach ( $q->get_results() as $u ) {
			$soon[] = [
				'user'    => $u,
				'group'   => $g,
				'expires' => (int) get_user_meta( $u->ID, MILIEUS_META_EXPIRES . $key, true ),
			];
		}
	}
	usort( $soon, fn( $a, $b ) => $a['expires'] - $b['expires'] );
	$soon = array_slice( $soon, 0, 5 );

	$pending = function_exists( 'milieus_pending_count' ) ? milieus_pending_count() : 0;
	?>
	<style>
		.mw-stats{display:flex;gap:14px;margin-bottom:14px}
		.mw-stat{flex:1;background:#f4f4f3;padding:10px 12px;border-radius:8px}
		.mw-stat-num{font:700 22px/1 system-ui;margin-bottom:4px}
		.mw-stat-label{font-size:11px;color:#57534e;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
		.mw-list h4{margin:14px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#57534e}
		.mw-list ul{margin:0;padding:0;list-style:none;font-size:13px}
		.mw-list li{padding:6px 0;border-bottom:1px solid #e7e5e4;display:flex;justify-content:space-between;gap:8px}
		.mw-list li:last-child{border-bottom:0}
		.mw-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
		.mw-meta{color:#a8a29e;font-size:11px}
	</style>
	<div class="mw-stats">
		<div class="mw-stat">
			<div class="mw-stat-num"><?php echo count( $recent ); ?></div>
			<div class="mw-stat-label"><?php esc_html_e( 'New this week', 'milieus' ); ?></div>
		</div>
		<div class="mw-stat">
			<div class="mw-stat-num"><?php echo count( $soon ); ?></div>
			<div class="mw-stat-label"><?php esc_html_e( 'Expiring ≤7d', 'milieus' ); ?></div>
		</div>
		<?php if ( $pending ): ?>
		<div class="mw-stat" style="background:color-mix(in srgb,#2563eb 10%,#f4f4f3)">
			<div class="mw-stat-num"><?php echo (int) $pending; ?></div>
			<div class="mw-stat-label"><a href="<?php echo esc_url( admin_url( 'admin.php?page=milieus-approvals' ) ); ?>"><?php esc_html_e( 'Pending →', 'milieus' ); ?></a></div>
		</div>
		<?php endif; ?>
	</div>

	<div class="mw-list">
		<h4><?php esc_html_e( 'Recent sign-ups', 'milieus' ); ?></h4>
		<?php if ( ! $recent ): ?>
			<p class="mw-meta"><?php esc_html_e( 'No sign-ups in the last 7 days.', 'milieus' ); ?></p>
		<?php else: ?>
			<ul>
				<?php foreach ( $recent as $r ): ?>
				<li>
					<span><span class="mw-dot" style="background:<?php echo esc_attr( $r['group']['color'] ?? '#2563eb' ); ?>"></span><strong><?php echo esc_html( $r['user']->display_name ?: $r['user']->user_email ); ?></strong> · <?php echo esc_html( $r['group']['name'] ); ?></span>
					<span class="mw-meta"><?php echo esc_html( human_time_diff( $r['when'] ) ); ?> ago · <?php echo esc_html( $r['source'] ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h4><?php esc_html_e( 'Expiring this week', 'milieus' ); ?></h4>
		<?php if ( ! $soon ): ?>
			<p class="mw-meta"><?php esc_html_e( 'No memberships expiring soon.', 'milieus' ); ?></p>
		<?php else: ?>
			<ul>
				<?php foreach ( $soon as $r ): ?>
				<li>
					<span><span class="mw-dot" style="background:<?php echo esc_attr( $r['group']['color'] ?? '#2563eb' ); ?>"></span><strong><?php echo esc_html( $r['user']->display_name ?: $r['user']->user_email ); ?></strong> · <?php echo esc_html( $r['group']['name'] ); ?></span>
					<span class="mw-meta"><?php echo esc_html( milieus_humanize_expiry( $r['expires'] ) ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}
