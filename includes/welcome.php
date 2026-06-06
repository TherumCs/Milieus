<?php
/**
 * Milieus by Therum — post-signup welcome screen.
 *
 * After /register/{slug} successfully creates an account, if the group has
 * welcome_enabled = true the user lands on a one-screen "you're in" page
 * (matched to the group's brand color + background) before continuing to
 * the configured redirect URL.
 *
 * The welcome screen is sticky (a transient flag on the user) and is shown
 * once. Hitting /welcome/{slug} after that just redirects to the group's
 * redirect URL.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_WELCOME_QV   = 'milieus_welcome_slug';
const MILIEUS_WELCOME_FLAG = '_milieus_welcome_pending_';

add_action( 'init', function() {
	add_rewrite_rule( '^welcome/([^/]+)/?$', 'index.php?' . MILIEUS_WELCOME_QV . '=$matches[1]', 'top' );
} );
add_filter( 'query_vars', function( $vars ) { $vars[] = MILIEUS_WELCOME_QV; return $vars; } );
add_action( 'template_redirect', 'milieus_handle_welcome' );

/**
 * Hook into the registration processor: when welcome_enabled, redirect to
 * /welcome/{slug} instead of straight to reg.redirect. We hijack via filter
 * inside registration.php by checking this function before redirecting.
 */
function milieus_welcome_redirect_for( array $group ): ?string {
	if ( empty( $group['reg']['welcome_enabled'] ) ) return null;
	$uid = get_current_user_id();
	if ( $uid ) {
		update_user_meta( $uid, MILIEUS_WELCOME_FLAG . $group['key'], time() );
	}
	return home_url( '/welcome/' . $group['reg']['slug'] );
}

function milieus_handle_welcome(): void {
	$slug = sanitize_title( (string) get_query_var( MILIEUS_WELCOME_QV, '' ) );
	if ( ! $slug ) return;

	$group = milieus_group_by_reg_slug( $slug );
	if ( ! $group ) {
		status_header( 404 );
		nocache_headers();
		wp_die( 'Welcome page not found.', 'Not found', [ 'response' => 404 ] );
	}

	// Show the welcome page once; subsequent visits go straight to redirect.
	$uid = get_current_user_id();
	$flag = MILIEUS_WELCOME_FLAG . $group['key'];
	if ( $uid && ! get_user_meta( $uid, $flag, true ) ) {
		wp_safe_redirect( $group['reg']['redirect'] ?: home_url( '/' ) );
		exit;
	}
	if ( $uid ) delete_user_meta( $uid, $flag );

	milieus_render_welcome( $group );
	exit;
}

function milieus_render_welcome( array $group ): void {
	$reg = $group['reg'];
	$color = $reg['color'] ?: '#2563eb';
	$bg = milieus_render_bg_style( $reg );
	$site_name = get_bloginfo( 'name' );

	$heading = $reg['welcome_heading'] ?: sprintf( __( "You're in — welcome to %s!", 'milieus' ), $group['name'] );
	$body    = $reg['welcome_body']    ?: __( "Here's what your membership unlocks. Take a look around — we've sent a confirmation to your inbox.", 'milieus' );
	$cta     = $reg['welcome_cta']     ?: __( 'Continue →', 'milieus' );
	$redirect = $reg['redirect'] ?: home_url( '/' );

	$h = fn( $s ) => esc_html( (string) $s );

	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . $h( $heading ) . ' · ' . $h( $site_name ) . '</title>';
	?>
<style>
:root{--m-accent:<?php echo $h( $color ); ?>;--m-text:#1c1917;--m-text-2:#57534e;--m-text-3:#a8a29e;--m-border:#e7e5e4;--m-surface:#fff}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;color:var(--m-text)}
body{<?php echo $bg; ?>display:grid;place-items:center;padding:40px 20px}
.m-card{background:var(--m-surface);border:1px solid var(--m-border);border-radius:14px;padding:48px 36px;width:100%;max-width:480px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.08);animation:m-fade .4s ease-out}
@keyframes m-fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.m-icon{width:64px;height:64px;border-radius:50%;background:color-mix(in srgb,var(--m-accent) 12%,transparent);color:var(--m-accent);display:grid;place-items:center;margin:0 auto 20px;font-size:32px;line-height:1}
.m-card h1{font-size:24px;font-weight:700;margin:0 0 10px;letter-spacing:-.015em}
.m-card p{color:var(--m-text-2);margin:0 0 24px;font-size:15px;line-height:1.55}
.m-cta{display:inline-block;padding:13px 28px;background:var(--m-accent);color:#fff;text-decoration:none;border-radius:8px;font:600 14px/1 inherit;transition:filter .15s}
.m-cta:hover{filter:brightness(1.1)}
.m-benefits{margin:0 auto 28px;max-width:320px;text-align:left;padding:16px 20px;background:#fafaf9;border-radius:8px;font-size:13px;color:var(--m-text-2)}
.m-benefits ul{margin:0;padding:0 0 0 20px}
.m-benefits li{margin:4px 0}
</style>
</head><body>
<main class="m-card">
	<div class="m-icon">✓</div>
	<h1><?php echo $h( $heading ); ?></h1>
	<p><?php echo wp_kses_post( wpautop( $body ) ); ?></p>
	<?php $benefits = milieus_render_welcome_benefits( $group ); if ( $benefits ): ?>
		<div class="m-benefits"><?php echo $benefits; ?></div>
	<?php endif; ?>
	<a class="m-cta" href="<?php echo esc_url( $redirect ); ?>"><?php echo $h( $cta ); ?></a>
</main>
</body></html>
	<?php
}

function milieus_render_welcome_benefits( array $group ): string {
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
	$out = '<strong style="display:block;margin-bottom:6px;color:#1c1917">What you get:</strong><ul>';
	foreach ( $rows as $r ) $out .= '<li>' . esc_html( $r ) . '</li>';
	$out .= '</ul>';
	return $out;
}
