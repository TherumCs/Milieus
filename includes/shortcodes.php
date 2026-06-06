<?php
/**
 * Milieus by Therum — shortcodes for embedding signup/login/status inline.
 *
 *   [milieus_register group="friends"]
 *       Renders the group's registration form inline (no full page chrome,
 *       sits inside whatever theme/page builder layout it's dropped into).
 *
 *   [milieus_login group="friends"]
 *       Branded sign-in form, same logic as /login/{slug} but inline.
 *
 *   [milieus_member_status]
 *       For logged-in users — shows their group memberships and expiry.
 *       For logged-out users — shows nothing (or a sign-in link if href= set).
 *
 *   [milieus_member_count group="friends"]
 *       Plain-text count of current members in a group. Useful for
 *       social proof: "Join 1,247 Friends".
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'milieus_register',     'milieus_sc_register' );
add_shortcode( 'milieus_login',        'milieus_sc_login' );
add_shortcode( 'milieus_member_status','milieus_sc_member_status' );
add_shortcode( 'milieus_member_count', 'milieus_sc_member_count' );

/**
 * [milieus_register group="x"] — inline registration form. Renders nothing
 * if the group doesn't exist or its reg link isn't enabled. POST submissions
 * are handled by /register/{slug}; this shortcode just renders the form.
 */
function milieus_sc_register( $atts = [] ): string {
	$atts = shortcode_atts( [ 'group' => '', 'title' => '', 'lede' => '' ], $atts, 'milieus_register' );
	$key  = sanitize_key( $atts['group'] );
	$group = milieus_get_group( $key );
	if ( ! $group || empty( $group['reg']['enabled'] ) ) {
		return '<!-- milieus_register: group missing or reg disabled -->';
	}

	$reg = $group['reg'];
	$color = $reg['color'] ?: '#2563eb';
	$heading = $atts['title'] ?: ( $reg['heading'] ?: sprintf( __( 'Join %s', 'milieus' ), $group['name'] ) );
	$lede    = $atts['lede']  ?: ( $reg['lede']    ?: __( 'Create your account to get started.', 'milieus' ) );
	$button  = $reg['button'] ?: __( 'Create account →', 'milieus' );
	$action  = home_url( '/register/' . $reg['slug'] );
	$nonce   = wp_create_nonce( 'milieus_register_' . $reg['slug'] );
	$h = fn( $s ) => esc_html( (string) $s );

	$extras_html = '';
	foreach ( (array) $reg['extras'] as $ek ) {
		$label = milieus_extra_field_label( $ek );
		$type  = $ek === 'phone' ? 'tel' : 'text';
		$extras_html .= '<label>' . $h( $label ) . '<input type="' . $type . '" name="extra_' . esc_attr( $ek ) . '"></label>';
	}

	ob_start();
	?>
	<div class="milieus-inline-card" style="--m-accent:<?php echo $h( $color ); ?>">
		<?php milieus_inline_card_styles(); ?>
		<?php if ( ! empty( $reg['logo'] ) ): ?>
			<img class="m-logo" src="<?php echo esc_url( $reg['logo'] ); ?>" alt="">
		<?php elseif ( ! empty( $reg['brand'] ) ): ?>
			<div class="m-brand"><?php echo $h( $reg['brand'] ); ?></div>
		<?php endif; ?>
		<?php if ( $heading ): ?><h2><?php echo $h( $heading ); ?></h2><?php endif; ?>
		<?php if ( $lede ): ?><p class="m-lede"><?php echo $h( $lede ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" autocomplete="on">
			<input type="hidden" name="milieus_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<?php echo function_exists( 'milieus_honeypot_field' ) ? milieus_honeypot_field() : ''; ?>
			<?php echo $extras_html; ?>
			<label>Email <input type="email" name="email" required autocomplete="email"></label>
			<label>Password <input type="password" name="password" required autocomplete="new-password" minlength="8"></label>
			<button type="submit"><?php echo $h( $button ); ?></button>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * [milieus_login group="x"] — inline branded login form.
 */
function milieus_sc_login( $atts = [] ): string {
	$atts = shortcode_atts( [ 'group' => '', 'title' => '' ], $atts, 'milieus_login' );
	$key  = sanitize_key( $atts['group'] );
	$group = milieus_get_group( $key );
	if ( ! $group || empty( $group['reg']['enabled'] ) ) {
		return '<!-- milieus_login: group missing or reg disabled -->';
	}
	$reg = $group['reg'];
	$color = $reg['color'] ?: '#2563eb';
	$heading = $atts['title'] ?: sprintf( __( 'Sign in to %s', 'milieus' ), $group['name'] );
	$action  = home_url( '/login/' . $reg['slug'] );
	$nonce   = wp_create_nonce( 'milieus_login_' . $reg['slug'] );
	$h = fn( $s ) => esc_html( (string) $s );

	ob_start();
	?>
	<div class="milieus-inline-card" style="--m-accent:<?php echo $h( $color ); ?>">
		<?php milieus_inline_card_styles(); ?>
		<?php if ( ! empty( $reg['brand'] ) ): ?><div class="m-brand"><?php echo $h( $reg['brand'] ); ?></div><?php endif; ?>
		<h2><?php echo $h( $heading ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action ); ?>" autocomplete="on">
			<input type="hidden" name="milieus_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label>Email <input type="email" name="email" required autocomplete="email"></label>
			<label>Password <input type="password" name="password" required autocomplete="current-password"></label>
			<label class="m-remember"><input type="checkbox" name="remember" value="1" checked> Remember me</label>
			<button type="submit"><?php esc_html_e( 'Sign in →', 'milieus' ); ?></button>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * [milieus_member_status] — show the current user's group memberships.
 */
function milieus_sc_member_status( $atts = [] ): string {
	$atts = shortcode_atts( [
		'logged_out_text' => __( 'Sign in to see your memberships.', 'milieus' ),
		'logged_out_href' => '',
	], $atts, 'milieus_member_status' );

	if ( ! is_user_logged_in() ) {
		if ( $atts['logged_out_href'] ) {
			return '<p class="milieus-status"><a href="' . esc_url( $atts['logged_out_href'] ) . '">' . esc_html( $atts['logged_out_text'] ) . '</a></p>';
		}
		return '<p class="milieus-status">' . esc_html( $atts['logged_out_text'] ) . '</p>';
	}

	$uid    = get_current_user_id();
	$groups = milieus_get_groups();
	$mine   = [];
	foreach ( $groups as $key => $g ) {
		$assigned = (int) get_user_meta( $uid, MILIEUS_META_ASSIGNED . $key, true );
		if ( ! $assigned ) continue;
		$expires = (int) get_user_meta( $uid, MILIEUS_META_EXPIRES . $key, true );
		$mine[] = [ 'group' => $g, 'assigned' => $assigned, 'expires' => $expires ];
	}
	if ( ! $mine ) return '<p class="milieus-status">' . esc_html__( "You're not in any groups.", 'milieus' ) . '</p>';

	$h = fn( $s ) => esc_html( (string) $s );
	ob_start();
	?>
	<div class="milieus-status">
		<?php milieus_inline_card_styles(); ?>
		<ul style="list-style:none;margin:0;padding:0">
			<?php foreach ( $mine as $r ):
				$exp = $r['expires'] === 0
					? __( 'Permanent', 'milieus' )
					: sprintf( __( 'Expires %s', 'milieus' ), wp_date( get_option( 'date_format' ), $r['expires'] ) );
			?>
			<li style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #e7e5e4">
				<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $r['group']['color'] ?? '#2563eb' ); ?>"></span>
				<strong style="flex:1"><?php echo $h( $r['group']['name'] ); ?></strong>
				<span style="font-size:12px;color:#57534e"><?php echo $h( $exp ); ?></span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * [milieus_member_count group="x"] — plain number, for social proof.
 */
function milieus_sc_member_count( $atts = [] ): string {
	$atts = shortcode_atts( [ 'group' => '', 'format' => 'plain' ], $atts, 'milieus_member_count' );
	$key  = sanitize_key( $atts['group'] );
	if ( ! milieus_get_group( $key ) ) return '0';
	$count = (int) count_users()['avail_roles'][ $key ] ?? 0;
	if ( $atts['format'] === 'pretty' ) return esc_html( number_format_i18n( $count ) );
	return (string) $count;
}

/**
 * Inline-card CSS shared by [milieus_register] and [milieus_login]. Output
 * once per page (tracked with a static flag) so it doesn't repeat.
 */
function milieus_inline_card_styles(): void {
	static $printed = false;
	if ( $printed ) return;
	$printed = true;
	?>
	<style>
	.milieus-inline-card{max-width:400px;background:#fff;border:1px solid #e7e5e4;border-radius:14px;padding:32px 28px;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,system-ui,sans-serif;color:#1c1917;box-shadow:0 1px 3px rgba(0,0,0,.04)}
	.milieus-inline-card .m-brand{font:700 12px/1 system-ui;letter-spacing:.18em;color:#a8a29e;text-transform:uppercase;margin-bottom:14px}
	.milieus-inline-card .m-logo{height:32px;margin-bottom:14px;display:block}
	.milieus-inline-card h2{font-size:20px;font-weight:700;margin:0 0 6px;letter-spacing:-.01em}
	.milieus-inline-card .m-lede{color:#57534e;margin:0 0 18px;font-size:14px}
	.milieus-inline-card form{display:flex;flex-direction:column;gap:12px}
	.milieus-inline-card label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#57534e;text-transform:uppercase;letter-spacing:.06em}
	.milieus-inline-card .m-remember{flex-direction:row;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-weight:500;color:#1c1917}
	.milieus-inline-card input[type=email],.milieus-inline-card input[type=password],.milieus-inline-card input[type=text],.milieus-inline-card input[type=tel]{padding:10px 12px;border:1px solid #e7e5e4;border-radius:8px;font:14px/1.5 inherit;color:#1c1917;background:#fff;outline:none}
	.milieus-inline-card input:focus{border-color:var(--m-accent,#2563eb);box-shadow:0 0 0 3px color-mix(in srgb,var(--m-accent,#2563eb) 18%,transparent)}
	.milieus-inline-card button{margin-top:4px;padding:11px 18px;border-radius:8px;border:1px solid var(--m-accent,#2563eb);background:var(--m-accent,#2563eb);color:#fff;font:600 13px/1 inherit;cursor:pointer}
	</style>
	<?php
}
