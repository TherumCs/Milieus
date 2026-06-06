<?php
/**
 * Milieus by Therum — public custom registration pages.
 *
 * Each group can expose a public URL like /register/{slug}. Visitors hitting
 * it see a Therum-styled sign-in card customized per group (logo, heading,
 * lede, brand color, button text, extra fields, page background). Submitting
 * creates a WordPress user who lands directly in this group with the group's
 * default member duration (handled by milieus_assign_member).
 *
 * Routes are registered as rewrite rules so the URL is clean. The matched
 * slug is exposed via a query var (milieus_reg_slug) and the template loader
 * intercepts the request before WordPress renders anything else.
 *
 * Flow:
 *   GET  /register/{slug}             → render form (or sold-out / closed page)
 *   POST /register/{slug}             → process submission, create user, redirect
 *
 * Submission is validated, rate-limited by max_signups, and (optionally)
 * gated by an admin approval flag — when approval is on, the user is
 * created with the WP default role and a meta flag; an admin can promote
 * them into the group later.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const MILIEUS_REG_QV     = 'milieus_reg_slug';
const MILIEUS_LOGIN_QV   = 'milieus_login_slug';
const MILIEUS_PENDING_KEY = '_milieus_pending_group';

add_action( 'init', 'milieus_register_rewrites' );
add_filter( 'query_vars', function( $vars ) {
	$vars[] = MILIEUS_REG_QV;
	$vars[] = MILIEUS_LOGIN_QV;
	return $vars;
} );
add_action( 'template_redirect', 'milieus_handle_registration' );
add_action( 'template_redirect', 'milieus_handle_branded_login' );

function milieus_register_rewrites(): void {
	add_rewrite_rule( '^register/([^/]+)/?$', 'index.php?' . MILIEUS_REG_QV . '=$matches[1]',  'top' );
	add_rewrite_rule( '^login/([^/]+)/?$',    'index.php?' . MILIEUS_LOGIN_QV . '=$matches[1]', 'top' );
}

/**
 * /login/{slug} — branded sign-in page that wears the group's
 * registration design (logo, color, background). Successful login
 * redirects to the group's configured reg.redirect, falling back to home.
 */
function milieus_handle_branded_login(): void {
	$slug = sanitize_title( (string) get_query_var( MILIEUS_LOGIN_QV, '' ) );
	if ( ! $slug ) return;

	$group = milieus_group_by_reg_slug( $slug );
	if ( ! $group ) {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Login link not found.', 'milieus' ), 'Not found', [ 'response' => 404 ] );
	}

	$errors = [];
	$prefill = '';
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'milieus_login_' . $group['reg']['slug'], 'milieus_nonce' );
		$creds = [
			'user_login'    => sanitize_text_field( $_POST['email'] ?? '' ),
			'user_password' => (string) ( $_POST['password'] ?? '' ),
			'remember'      => ! empty( $_POST['remember'] ),
		];
		$prefill = $creds['user_login'];
		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			$errors[] = __( 'Email or password did not match.', 'milieus' );
		} else {
			wp_safe_redirect( $group['reg']['redirect'] ?: home_url( '/' ) );
			exit;
		}
	}

	milieus_render_branded_login( $group, $errors, $prefill );
	exit;
}

function milieus_handle_registration(): void {
	$slug = sanitize_title( (string) get_query_var( MILIEUS_REG_QV, '' ) );
	if ( ! $slug ) return;

	$group = milieus_group_by_reg_slug( $slug );
	if ( ! $group ) {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Registration link not found.', 'milieus' ), 'Not found', [ 'response' => 404 ] );
	}

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		milieus_process_registration( $group );
		return;
	}

	milieus_render_registration_page( $group );
	exit;
}

/**
 * Validate + create user, then redirect to the configured success URL or
 * back to the form with errors.
 */
function milieus_process_registration( array $group ): void {
	check_admin_referer( 'milieus_register_' . $group['reg']['slug'], 'milieus_nonce' );

	$reg     = $group['reg'];
	$email   = sanitize_email( $_POST['email'] ?? '' );
	$pass    = (string) ( $_POST['password'] ?? '' );
	$errors  = [];

	// Spam defense (honeypot + per-IP rate limit) before any work.
	if ( function_exists( 'milieus_spam_check' ) ) {
		$spam = milieus_spam_check();
		if ( $spam ) {
			milieus_render_registration_page( $group, [ $spam ], [ 'email' => $email ] );
			exit;
		}
	}

	if ( ! is_email( $email ) )                            $errors[] = __( 'A valid email is required.', 'milieus' );
	if ( strlen( $pass ) < 8 )                             $errors[] = __( 'Password must be at least 8 characters.', 'milieus' );
	if ( email_exists( $email ) )                          $errors[] = __( 'An account with that email already exists.', 'milieus' );

	$max = (int) $reg['max_signups'];
	if ( $max > 0 && (int) $reg['signup_count'] >= $max ) {
		$errors[] = __( 'This invite link is full.', 'milieus' );
	}

	$extras_data = [];
	foreach ( (array) $reg['extras'] as $extra_key ) {
		$extras_data[ $extra_key ] = sanitize_text_field( wp_unslash( $_POST[ 'extra_' . $extra_key ] ?? '' ) );
	}

	if ( $errors ) {
		milieus_render_registration_page( $group, $errors, [ 'email' => $email, 'extras' => $extras_data ] );
		exit;
	}

	$uid = wp_create_user( $email, $pass, $email );
	if ( is_wp_error( $uid ) ) {
		milieus_render_registration_page( $group, [ $uid->get_error_message() ], [ 'email' => $email, 'extras' => $extras_data ] );
		exit;
	}

	// Save extras as user meta so they're available everywhere.
	foreach ( $extras_data as $k => $v ) {
		if ( $v !== '' ) update_user_meta( $uid, 'milieus_' . $k, $v );
	}
	if ( ! empty( $extras_data['name'] ) ) {
		wp_update_user( [ 'ID' => $uid, 'display_name' => $extras_data['name'] ] );
	}

	// Approval gate?
	if ( ! empty( $reg['approval'] ) ) {
		update_user_meta( $uid, MILIEUS_PENDING_KEY, $group['key'] );
		do_action( 'milieus_pending_created', $uid, $group['key'] );
	} else {
		milieus_assign_member( $uid, $group['key'], 'link' );
	}

	// Bump signup count.
	$g_now = milieus_get_group( $group['key'] );
	$g_now['reg']['signup_count'] = (int) ( $g_now['reg']['signup_count'] ?? 0 ) + 1;
	milieus_save_group( $group['key'], $g_now );

	// Auto-login (only if no approval gate).
	if ( empty( $reg['approval'] ) ) {
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid );
	}

	// Welcome page hijack — if enabled and we have a fresh user session,
	// route through /welcome/{slug} for a one-screen "you're in" before redirect.
	$welcome_url = function_exists( 'milieus_welcome_redirect_for' )
		? milieus_welcome_redirect_for( $group )
		: null;
	$redirect = esc_url_raw( $welcome_url ?: ( $reg['redirect'] ?: home_url( '/' ) ) );
	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Render the public registration page. Self-contained HTML — does not load
 * the theme. Uses the same chrome as Therum OS's sign-in card.
 */
function milieus_render_registration_page( array $group, array $errors = [], array $values = [] ): void {
	$reg = $group['reg'];
	$site_name = get_bloginfo( 'name' );

	// Resolved display values (group settings or sensible fallbacks).
	$brand    = $reg['brand']   ?: strtoupper( $site_name );
	$heading  = $reg['heading'] ?: sprintf( __( 'Join %s', 'milieus' ), $group['name'] );
	$lede     = $reg['lede']    ?: __( 'Create your account to get started.', 'milieus' );
	$button   = $reg['button']  ?: __( 'Create account →', 'milieus' );
	$color    = $reg['color']   ?: '#2563eb';
	$logo     = $reg['logo'];
	$bg       = milieus_render_bg_style( $reg );

	$nonce  = wp_create_nonce( 'milieus_register_' . $reg['slug'] );
	$h      = fn( $s ) => esc_html( (string) $s );
	$err_html = '';
	if ( $errors ) {
		$err_html = '<div class="m-err">' . implode( '<br>', array_map( $h, $errors ) ) . '</div>';
	}

	$extras_html = '';
	foreach ( (array) $reg['extras'] as $extra_key ) {
		$label = milieus_extra_field_label( $extra_key );
		$type  = $extra_key === 'phone' ? 'tel' : 'text';
		$val   = $h( $values['extras'][ $extra_key ] ?? '' );
		$extras_html .= "<label>{$h($label)}<input type=\"{$type}\" name=\"extra_{$extra_key}\" value=\"{$val}\"></label>";
	}

	$email_v = $h( $values['email'] ?? '' );

	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . $h( $heading ) . ' · ' . $h( $site_name ) . '</title>';
	?>
<style>
:root{--m-accent:<?php echo $h( $color ); ?>;--m-text:#1c1917;--m-text-2:#57534e;--m-text-3:#a8a29e;--m-border:#e7e5e4;--m-surface:#fff;--m-surface-2:#f4f4f3;--m-err:#dc2626;}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;color:var(--m-text);-webkit-font-smoothing:antialiased}
body{<?php echo $bg; ?>display:grid;place-items:center;padding:40px 20px}
.m-card{background:var(--m-surface);border:1px solid var(--m-border);border-radius:14px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 1px 3px rgba(0,0,0,.04),0 8px 32px rgba(0,0,0,.08)}
.m-brand{font:700 12px/1 system-ui;letter-spacing:.18em;color:var(--m-text-3);text-transform:uppercase;margin-bottom:16px}
.m-logo{height:32px;margin-bottom:16px;display:block}
.m-card h1{font-size:22px;font-weight:700;margin:0 0 6px;letter-spacing:-.01em}
.m-card .lede{color:var(--m-text-2);margin:0 0 22px;font-size:14px;line-height:1.5}
form{display:flex;flex-direction:column;gap:14px}
label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:var(--m-text-2);text-transform:uppercase;letter-spacing:.06em}
input{padding:10px 12px;border:1px solid var(--m-border);border-radius:8px;font:14px/1.5 inherit;color:var(--m-text);background:var(--m-surface);outline:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:var(--m-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--m-accent) 18%,transparent)}
button{margin-top:6px;padding:11px 18px;border-radius:8px;border:1px solid var(--m-accent);background:var(--m-accent);color:#fff;font:600 13px/1 inherit;cursor:pointer;transition:filter .15s}
button:hover{filter:brightness(1.1)}
.m-foot{margin-top:18px;font-size:12px;color:var(--m-text-3);text-align:center}
.m-foot a{color:var(--m-accent);text-decoration:none}
.m-foot a:hover{text-decoration:underline}
.m-err{background:color-mix(in srgb,var(--m-err) 8%,transparent);border:1px solid color-mix(in srgb,var(--m-err) 28%,transparent);color:var(--m-err);padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:13px}
</style>
</head><body>
<main class="m-card">
	<?php if ( $logo ) : ?>
		<img class="m-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo $h( $brand ); ?>">
	<?php else : ?>
		<div class="m-brand"><?php echo $h( $brand ); ?></div>
	<?php endif; ?>
	<h1><?php echo $h( $heading ); ?></h1>
	<p class="lede"><?php echo $h( $lede ); ?></p>
	<?php echo $err_html; ?>
	<form method="post" autocomplete="on">
		<input type="hidden" name="milieus_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<?php echo function_exists( 'milieus_honeypot_field' ) ? milieus_honeypot_field() : ''; ?>
		<?php echo $extras_html; ?>
		<label>Email <input type="email" name="email" required value="<?php echo $email_v; ?>" autocomplete="email"></label>
		<label>Password <input type="password" name="password" required autocomplete="new-password" minlength="8"></label>
		<button type="submit"><?php echo $h( $button ); ?></button>
	</form>
	<div class="m-foot"><?php esc_html_e( 'Already have an account?', 'milieus' ); ?> <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'milieus' ); ?></a></div>
</main>
</body></html>
	<?php
}

/**
 * Render the public branded login page. Reuses the registration card chrome.
 */
function milieus_render_branded_login( array $group, array $errors = [], string $prefill = '' ): void {
	$reg = $group['reg'];
	$site_name = get_bloginfo( 'name' );
	$brand   = $reg['brand']   ?: strtoupper( $site_name );
	$heading = sprintf( __( 'Sign in to %s', 'milieus' ), $group['name'] );
	$lede    = $reg['lede'] ?: __( 'Welcome back.', 'milieus' );
	$button  = __( 'Sign in →', 'milieus' );
	$color   = $reg['color'] ?: '#2563eb';
	$logo    = $reg['logo'];
	$bg      = milieus_render_bg_style( $reg );

	$nonce = wp_create_nonce( 'milieus_login_' . $reg['slug'] );
	$h = fn( $s ) => esc_html( (string) $s );
	$err_html = $errors ? '<div class="m-err">' . implode( '<br>', array_map( $h, $errors ) ) . '</div>' : '';
	$reg_url = home_url( '/register/' . $reg['slug'] );

	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<title>' . $h( $heading ) . ' · ' . $h( $site_name ) . '</title>';
	?>
<style>
:root{--m-accent:<?php echo $h( $color ); ?>;--m-text:#1c1917;--m-text-2:#57534e;--m-text-3:#a8a29e;--m-border:#e7e5e4;--m-surface:#fff;--m-surface-2:#f4f4f3;--m-err:#dc2626}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;color:var(--m-text)}
body{<?php echo $bg; ?>display:grid;place-items:center;padding:40px 20px}
.m-card{background:var(--m-surface);border:1px solid var(--m-border);border-radius:14px;padding:36px 32px;width:100%;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.08)}
.m-brand{font:700 12px/1 system-ui;letter-spacing:.18em;color:var(--m-text-3);text-transform:uppercase;margin-bottom:16px}
.m-logo{height:32px;margin-bottom:16px;display:block}
.m-card h1{font-size:22px;font-weight:700;margin:0 0 6px;letter-spacing:-.01em}
.m-card .lede{color:var(--m-text-2);margin:0 0 22px;font-size:14px;line-height:1.5}
form{display:flex;flex-direction:column;gap:14px}
label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:var(--m-text-2);text-transform:uppercase;letter-spacing:.06em}
.m-remember{flex-direction:row;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-weight:500;color:var(--m-text)}
input[type=email],input[type=password]{padding:10px 12px;border:1px solid var(--m-border);border-radius:8px;font:14px/1.5 inherit;color:var(--m-text);background:var(--m-surface);outline:none}
input:focus{border-color:var(--m-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--m-accent) 18%,transparent)}
button{margin-top:6px;padding:11px 18px;border-radius:8px;border:1px solid var(--m-accent);background:var(--m-accent);color:#fff;font:600 13px/1 inherit;cursor:pointer}
.m-foot{margin-top:18px;font-size:12px;color:var(--m-text-3);text-align:center}
.m-foot a{color:var(--m-accent);text-decoration:none}
.m-err{background:color-mix(in srgb,var(--m-err) 8%,transparent);border:1px solid color-mix(in srgb,var(--m-err) 28%,transparent);color:var(--m-err);padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:13px}
</style>
</head><body>
<main class="m-card">
	<?php if ( $logo ): ?><img class="m-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo $h( $brand ); ?>">
	<?php else: ?><div class="m-brand"><?php echo $h( $brand ); ?></div><?php endif; ?>
	<h1><?php echo $h( $heading ); ?></h1>
	<p class="lede"><?php echo $h( $lede ); ?></p>
	<?php echo $err_html; ?>
	<form method="post" autocomplete="on">
		<input type="hidden" name="milieus_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<label>Email <input type="email" name="email" required value="<?php echo $h( $prefill ); ?>" autocomplete="email"></label>
		<label>Password <input type="password" name="password" required autocomplete="current-password"></label>
		<label class="m-remember"><input type="checkbox" name="remember" value="1" checked> Remember me</label>
		<button type="submit"><?php echo $h( $button ); ?></button>
	</form>
	<div class="m-foot"><?php esc_html_e( "Don't have an account?", 'milieus' ); ?> <a href="<?php echo esc_url( $reg_url ); ?>"><?php esc_html_e( 'Sign up', 'milieus' ); ?></a></div>
</main>
</body></html>
	<?php
}

function milieus_extra_field_label( string $key ): string {
	$labels = [
		'name'      => __( 'Full name', 'milieus' ),
		'company'   => __( 'Company', 'milieus' ),
		'phone'     => __( 'Phone', 'milieus' ),
		'referral'  => __( 'Referral code', 'milieus' ),
		'how-heard' => __( 'How did you hear about us?', 'milieus' ),
	];
	return $labels[ $key ] ?? $key;
}

/**
 * Render the CSS `background:` declaration for the page body based on the
 * group's reg.bg_* settings.
 */
function milieus_render_bg_style( array $reg ): string {
	$kind = $reg['bg_kind'] ?? 'solid';
	switch ( $kind ) {
		case 'gradient':
			$c1 = $reg['bg_grad_1'] ?: '#fde68a';
			$c2 = $reg['bg_grad_2'] ?: '#fca5a5';
			$dir = $reg['bg_grad_dir'] ?: '135deg';
			// Whitelist safe gradient directions to prevent CSS injection.
			$safe_dirs = [ '0deg', '45deg', '90deg', '135deg', '180deg', '225deg', '270deg', '315deg', 'radial' ];
			if ( ! in_array( $dir, $safe_dirs, true ) ) $dir = '135deg';
			// Sanitize color values — strip anything that isn't a hex/rgb/hsl pattern.
			$c1 = preg_match( '/^[#a-zA-Z0-9(),.\s%]+$/', $c1 ) ? $c1 : '#fde68a';
			$c2 = preg_match( '/^[#a-zA-Z0-9(),.\s%]+$/', $c2 ) ? $c2 : '#fca5a5';
			if ( $dir === 'radial' ) {
				return "background:radial-gradient(circle at center, {$c1}, {$c2});";
			}
			return "background:linear-gradient({$dir}, {$c1}, {$c2});";
		case 'image':
			$url = esc_url_raw( $reg['bg_image'] ?? '' );
			if ( ! $url ) return 'background:#fafaf9;';
			$overlay = ! empty( $reg['bg_dim'] ) ? 'linear-gradient(rgba(0,0,0,.3),rgba(0,0,0,.3)), ' : '';
			$rule = "background:{$overlay}url('{$url}') center/cover no-repeat;";
			if ( ! empty( $reg['bg_blur'] ) ) {
				// CSS backdrop blur on body is fragile; this is the simplest version.
				$rule .= 'backdrop-filter:blur(8px);';
			}
			return $rule;
		case 'solid':
		default:
			$solid = $reg['bg_solid'] ?: '#fafaf9';
			$solid = preg_match( '/^[#a-zA-Z0-9(),.\s%]+$/', $solid ) ? $solid : '#fafaf9';
			return 'background:' . $solid . ';';
	}
}
