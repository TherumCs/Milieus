<?php
/**
 * Milieus by Therum — outbound webhooks.
 *
 * Each saved webhook = { url, events[], secret, enabled }. When one of the
 * configured events fires, we POST a JSON body to the URL with an
 * X-Milieus-Signature header (HMAC-SHA256 of the body using the secret).
 *
 * Receivers verify by recomputing the HMAC over the raw body — same shape
 * Stripe, GitHub, etc. use, so existing libraries work out of the box.
 *
 * Events:
 *   member.assigned        member added to a group (manual, link, csv, api, purchase)
 *   member.revoked         removed (admin, expiry, refund, api)
 *   member.pending         registered via approval-gated link
 *   member.expiring_soon   reminder fired by the sweep
 *   purchase.confirmed     WC order completed and triggered an assignment
 *
 * Stored at option milieus_webhooks (array of records).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_WEBHOOKS_OPTION = 'milieus_webhooks';

function milieus_webhooks_all(): array {
	return (array) get_option( MILIEUS_WEBHOOKS_OPTION, [] );
}

function milieus_webhook_events(): array {
	return [
		'member.assigned',
		'member.revoked',
		'member.pending',
		'member.expiring_soon',
		'purchase.confirmed',
	];
}

// ── Listeners → dispatcher ───────────────────────────────────────────

add_action( 'milieus_member_assigned',      function( $uid, $key, $source, $is_new ) {
	milieus_webhooks_fire( 'member.assigned', [ 'user_id' => $uid, 'group' => $key, 'source' => $source, 'is_new' => (bool) $is_new ] );
}, 10, 4 );

add_action( 'milieus_member_revoked', function( $uid, $key ) {
	milieus_webhooks_fire( 'member.revoked', [ 'user_id' => $uid, 'group' => $key ] );
}, 10, 2 );

add_action( 'milieus_pending_created', function( $uid, $key ) {
	milieus_webhooks_fire( 'member.pending', [ 'user_id' => $uid, 'group' => $key ] );
}, 10, 2 );

add_action( 'milieus_member_expiring_soon', function( $uid, $key, $expires ) {
	milieus_webhooks_fire( 'member.expiring_soon', [ 'user_id' => $uid, 'group' => $key, 'expires_at' => $expires ] );
}, 10, 3 );

add_action( 'milieus_member_purchased', function( $uid, $key, $order_id, $product_id ) {
	milieus_webhooks_fire( 'purchase.confirmed', [ 'user_id' => $uid, 'group' => $key, 'order_id' => $order_id, 'product_id' => $product_id ] );
}, 10, 4 );

/**
 * Send the event to every webhook subscribed to it. Non-blocking — the HTTP
 * request is fired with blocking=false so site performance doesn't depend on
 * receiver speed.
 */
function milieus_webhooks_fire( string $event, array $payload ): void {
	$hooks = milieus_webhooks_all();
	if ( ! $hooks ) return;

	$body = wp_json_encode( [
		'event'     => $event,
		'timestamp' => time(),
		'site'      => home_url(),
		'data'      => $payload,
	] );

	foreach ( $hooks as $h ) {
		if ( empty( $h['enabled'] ) ) continue;
		if ( empty( $h['url'] ) ) continue;
		if ( ! in_array( $event, (array) ( $h['events'] ?? [] ), true ) ) continue;

		$signature = hash_hmac( 'sha256', $body, (string) ( $h['secret'] ?? '' ) );
		wp_remote_post( $h['url'], [
			'reject_unsafe_urls' => true,
			'blocking' => false,
			'timeout'  => 5,
			'headers'  => [
				'Content-Type'         => 'application/json',
				'X-Milieus-Event'      => $event,
				'X-Milieus-Signature'  => $signature,
				'User-Agent'           => 'Milieus/' . MILIEUS_VERSION,
			],
			'body'     => $body,
		] );
		do_action( 'milieus_webhook_sent', $h['url'], $event, $payload );
	}
}

// ── Settings page (Milieus → Webhooks) ───────────────────────────────

add_action( 'admin_menu', function() {
	add_submenu_page(
		'milieus-roles',
		__( 'Webhooks', 'milieus' ),
		__( 'Webhooks', 'milieus' ),
		'manage_options',
		'milieus-webhooks',
		'milieus_render_webhooks_page'
	);
}, 35 );

add_action( 'admin_post_milieus_webhooks_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	check_admin_referer( 'milieus_webhooks' );

	$incoming = (array) ( $_POST['hooks'] ?? [] );
	$valid_events = milieus_webhook_events();
	$out = [];
	foreach ( $incoming as $row ) {
		$url = esc_url_raw( $row['url'] ?? '' );
		if ( ! $url ) continue;
		if ( ! wp_http_validate_url( $url ) ) continue; // reject private/reserved IPs
		$events = array_values( array_intersect( $valid_events, (array) ( $row['events'] ?? [] ) ) );
		$secret = sanitize_text_field( $row['secret'] ?? '' );
		$enabled = ! empty( $row['enabled'] );
		$out[] = compact( 'url', 'events', 'secret', 'enabled' );
	}
	update_option( MILIEUS_WEBHOOKS_OPTION, $out );
	wp_safe_redirect( admin_url( 'admin.php?page=milieus-webhooks&flash=saved' ) );
	exit;
} );

function milieus_render_webhooks_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
	$hooks = milieus_webhooks_all();
	// Always render at least one empty row for adding new ones.
	if ( ! $hooks ) $hooks = [ [ 'url' => '', 'events' => [], 'secret' => wp_generate_password( 24, false ), 'enabled' => true ] ];
	$events = milieus_webhook_events();
	$flash  = sanitize_key( $_GET['flash'] ?? '' );
	?>
	<div class="wrap"><div class="th-cx">
		<div class="th-cx-head">
			<h1 class="th-cx-title"><?php esc_html_e( 'Webhooks', 'milieus' ); ?></h1>
			<p class="th-cx-sub">
				<?php esc_html_e( 'POST events to external URLs (Zapier, Make, n8n, your own server). Each request is HMAC-signed in the X-Milieus-Signature header — verify with the secret on the receiving side.', 'milieus' ); ?>
			</p>
		</div>

		<?php milieus_render_tab_nav( 'milieus-webhooks' ); ?>

		<?php if ( $flash === 'saved' ): ?><div class="th-flash th-flash-ok">✓ Saved.</div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="milieus-webhooks-form">
			<input type="hidden" name="action" value="milieus_webhooks_save">
			<?php wp_nonce_field( 'milieus_webhooks' ); ?>

			<div id="webhook-rows">
				<?php foreach ( $hooks as $i => $h ): ?>
					<?php milieus_render_webhook_row( $i, $h, $events ); ?>
				<?php endforeach; ?>
			</div>

			<p style="margin-top:14px;display:flex;gap:10px">
				<button type="button" class="th-button" id="milieus-add-webhook">+ <?php esc_html_e( 'Add webhook', 'milieus' ); ?></button>
				<button type="submit" class="th-button th-button-primary"><?php esc_html_e( 'Save webhooks', 'milieus' ); ?></button>
			</p>
		</form>

		<div class="th-settings-card" style="margin-top:24px">
			<h3><?php esc_html_e( 'Payload shape', 'milieus' ); ?></h3>
			<p class="th-settings-card-sub"><?php esc_html_e( 'Every event posts the same envelope.', 'milieus' ); ?></p>
<pre style="background:var(--sf2);padding:14px;border-radius:8px;font:12px/1.5 ui-monospace,Menlo,monospace;color:var(--tx2);margin:0">{
  "event":     "member.assigned",
  "timestamp": 1717612345,
  "site":      "https://example.com",
  "data": { "user_id": 42, "group": "friends", "source": "link", "is_new": true }
}</pre>
		</div>

		<script>
		(function(){
			var tpl = <?php echo wp_json_encode( milieus_capture_webhook_row( count( $hooks ), [ 'url' => '', 'events' => [], 'secret' => wp_generate_password( 24, false ), 'enabled' => true ], $events ) ); ?>;
			var idx = <?php echo (int) count( $hooks ); ?>;
			document.getElementById('milieus-add-webhook').addEventListener('click', function() {
				var html = tpl.replace(/__IDX__/g, idx);
				idx++;
				var wrap = document.createElement('div');
				wrap.innerHTML = html;
				document.getElementById('webhook-rows').appendChild(wrap.firstElementChild);
				bindWebhookRow(wrap.firstElementChild);
			});

			// Remove webhook row
			function bindWebhookRow(row) {
				var removeBtn = row.querySelector('.milieus-webhook-remove');
				if (removeBtn) {
					removeBtn.addEventListener('click', function() {
						if (document.querySelectorAll('.milieus-webhook-row').length <= 1) return;
						if (!confirm(<?php echo wp_json_encode( __( 'Remove this webhook?', 'milieus' ) ); ?>)) return;
						row.remove();
					});
				}
				var testBtn = row.querySelector('.milieus-webhook-test');
				if (testBtn) {
					testBtn.addEventListener('click', function() {
						var urlInput = row.querySelector('input[type=url]');
						var secretInput = row.querySelector('input[name*="[secret]"]');
						var url = urlInput ? urlInput.value.trim() : '';
						var secret = secretInput ? secretInput.value.trim() : '';
						var resultEl = row.querySelector('.milieus-webhook-test-result');
						if (!url) { resultEl.textContent = <?php echo wp_json_encode( __( 'Enter a URL first.', 'milieus' ) ); ?>; return; }
						testBtn.disabled = true;
						resultEl.textContent = <?php echo wp_json_encode( __( 'Sending…', 'milieus' ) ); ?>;
						resultEl.style.color = 'var(--tx3)';
						var fd = new FormData();
						fd.append('action', 'milieus_webhook_test');
						fd.append('_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'milieus_webhook_test' ) ); ?>);
						fd.append('url', url);
						fd.append('secret', secret);
						fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method:'POST', body:fd, credentials:'same-origin' })
							.then(function(r){ return r.json(); })
							.then(function(j){
								testBtn.disabled = false;
								if (j && j.success) {
									resultEl.textContent = '✓ ' + (j.data.msg || 'Sent');
									resultEl.style.color = 'var(--ok,#16a34a)';
								} else {
									resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
									resultEl.style.color = 'var(--err,#dc2626)';
								}
							}).catch(function(){
								testBtn.disabled = false;
								resultEl.textContent = '✗ Network error';
								resultEl.style.color = 'var(--err,#dc2626)';
							});
					});
				}
			}

			// Bind existing rows
			document.querySelectorAll('.milieus-webhook-row').forEach(bindWebhookRow);
		})();
		</script>
	</div></div>
	<?php
}

// ── AJAX: test ping ─────────────────────────────────────────────────

add_action( 'wp_ajax_milieus_webhook_test', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'milieus_webhook_test' );

	$url    = esc_url_raw( $_POST['url'] ?? '' );
	$secret = sanitize_text_field( $_POST['secret'] ?? '' );
	if ( ! $url ) wp_send_json_error( __( 'URL is required.', 'milieus' ) );
	if ( ! wp_http_validate_url( $url ) ) wp_send_json_error( __( 'URL is not allowed (private/reserved IP).', 'milieus' ) );

	$body = wp_json_encode( [
		'event'     => 'test.ping',
		'timestamp' => time(),
		'site'      => home_url(),
		'data'      => [ 'message' => 'This is a test ping from Milieus.' ],
	] );

	$signature = hash_hmac( 'sha256', $body, $secret );
	$response  = wp_remote_post( $url, [
		'reject_unsafe_urls' => true,
		'blocking' => true,
		'timeout'  => 10,
		'headers'  => [
			'Content-Type'        => 'application/json',
			'X-Milieus-Event'     => 'test.ping',
			'X-Milieus-Signature' => $signature,
			'User-Agent'          => 'Milieus/' . MILIEUS_VERSION,
		],
		'body'     => $body,
	] );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 200 && $code < 300 ) {
		wp_send_json_success( [ 'msg' => sprintf( __( 'HTTP %d — delivered.', 'milieus' ), $code ) ] );
	} else {
		wp_send_json_error( sprintf( __( 'HTTP %d — receiver did not accept the ping.', 'milieus' ), $code ) );
	}
} );

function milieus_render_webhook_row( int $i, array $h, array $events ): void {
	echo milieus_capture_webhook_row( $i, $h, $events );
}

function milieus_capture_webhook_row( int $i, array $h, array $events ): string {
	ob_start();
	$idx = $i === 0 && empty( $h['url'] ) ? '__IDX__' : $i;
	?>
	<div class="th-settings-card milieus-webhook-row" style="margin-bottom:10px">
		<div style="display:grid;grid-template-columns:1fr auto auto;gap:14px;align-items:start;margin-bottom:10px">
			<input class="th-input" name="hooks[<?php echo esc_attr( $idx ); ?>][url]" type="url" value="<?php echo esc_attr( $h['url'] ?? '' ); ?>" placeholder="https://hooks.example.com/milieus" required>
			<label style="font-size:13px;display:flex;align-items:center;gap:6px"><input type="checkbox" name="hooks[<?php echo esc_attr( $idx ); ?>][enabled]" value="1" <?php checked( ! empty( $h['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled', 'milieus' ); ?></label>
			<button type="button" class="th-link-btn danger milieus-webhook-remove" title="<?php esc_attr_e( 'Remove this webhook', 'milieus' ); ?>" style="color:var(--err,#dc2626);font-size:18px;padding:0 4px">&times;</button>
		</div>
		<div style="margin-bottom:10px">
			<label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tx2);margin-bottom:4px"><?php esc_html_e( 'Signing secret', 'milieus' ); ?></label>
			<input class="th-input" name="hooks[<?php echo esc_attr( $idx ); ?>][secret]" type="text" value="<?php echo esc_attr( $h['secret'] ?? '' ); ?>" style="font-family:ui-monospace,Menlo,monospace;font-size:12px;width:100%;max-width:520px">
		</div>
		<div style="margin-bottom:10px">
			<label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tx2);margin-bottom:6px"><?php esc_html_e( 'Events', 'milieus' ); ?></label>
			<div style="display:flex;flex-wrap:wrap;gap:8px">
				<?php foreach ( $events as $e ): ?>
				<label style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:var(--sf2);border-radius:999px;font-size:12px;cursor:pointer">
					<input type="checkbox" name="hooks[<?php echo esc_attr( $idx ); ?>][events][]" value="<?php echo esc_attr( $e ); ?>" <?php checked( in_array( $e, (array) ( $h['events'] ?? [] ), true ) ); ?>> <code style="font-size:11px"><?php echo esc_html( $e ); ?></code>
				</label>
				<?php endforeach; ?>
			</div>
		</div>
		<div style="display:flex;gap:8px;align-items:center">
			<button type="button" class="th-button milieus-webhook-test" data-idx="<?php echo esc_attr( $idx ); ?>"><?php esc_html_e( '⚡ Send test ping', 'milieus' ); ?></button>
			<span class="milieus-webhook-test-result" style="font-size:12px;color:var(--tx3)"></span>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
