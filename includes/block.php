<?php
/**
 * Milieus by Therum — Gutenberg block: Registration Form.
 *
 * Lightweight server-rendered block — same output as the [milieus_register]
 * shortcode, but with a proper block-editor sidebar for picking the group
 * and previewing inline.
 *
 * Uses register_block_type with a render_callback (no separate build step
 * required). The editor script is plain ES5, registered inline.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'init', function() {
	// Register the editor-side script.
	wp_register_script(
		'milieus-register-block',
		MILIEUS_URL . 'assets/block.js',
		[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ],
		MILIEUS_VERSION,
		true
	);

	// Pass the list of groups to the editor so the dropdown is populated.
	$reg_groups = [];
	$all_groups = [];
	foreach ( milieus_get_groups() as $key => $g ) {
		$all_groups[] = [ 'value' => $key, 'label' => $g['name'] ];
		if ( ! empty( $g['reg']['enabled'] ) ) {
			$reg_groups[] = [ 'value' => $key, 'label' => $g['name'] ];
		}
	}
	wp_localize_script( 'milieus-register-block', 'MilieusBlock', [
		'groups'    => $reg_groups,
		'allGroups' => $all_groups,
	] );

	// ── Register block ────────────────────────────────────────────
	register_block_type( 'milieus/register', [
		'editor_script'   => 'milieus-register-block',
		'render_callback' => 'milieus_block_register_render',
		'attributes'      => [
			'group' => [ 'type' => 'string', 'default' => '' ],
			'title' => [ 'type' => 'string', 'default' => '' ],
			'lede'  => [ 'type' => 'string', 'default' => '' ],
		],
		'supports' => [ 'align' => [ 'wide', 'full' ], 'spacing' => [ 'margin' => true, 'padding' => true ] ],
	] );

	// ── Login block ───────────────────────────────────────────────
	register_block_type( 'milieus/login', [
		'editor_script'   => 'milieus-register-block',
		'render_callback' => 'milieus_block_login_render',
		'attributes'      => [
			'group' => [ 'type' => 'string', 'default' => '' ],
			'title' => [ 'type' => 'string', 'default' => '' ],
		],
		'supports' => [ 'align' => [ 'wide', 'full' ], 'spacing' => [ 'margin' => true, 'padding' => true ] ],
	] );

	// ── Member Status block ───────────────────────────────────────
	register_block_type( 'milieus/member-status', [
		'editor_script'   => 'milieus-register-block',
		'render_callback' => 'milieus_block_member_status_render',
		'attributes'      => [
			'logged_out_text' => [ 'type' => 'string', 'default' => '' ],
			'logged_out_href' => [ 'type' => 'string', 'default' => '' ],
		],
		'supports' => [ 'align' => [ 'wide', 'full' ], 'spacing' => [ 'margin' => true, 'padding' => true ] ],
	] );

	// ── Member Count block ────────────────────────────────────────
	register_block_type( 'milieus/member-count', [
		'editor_script'   => 'milieus-register-block',
		'render_callback' => 'milieus_block_member_count_render',
		'attributes'      => [
			'group'  => [ 'type' => 'string', 'default' => '' ],
			'format' => [ 'type' => 'string', 'default' => 'pretty' ],
		],
		'supports' => [ 'align' => true, 'spacing' => [ 'margin' => true ] ],
	] );
} );

function milieus_block_register_render( array $attrs ): string {
	if ( empty( $attrs['group'] ) ) {
		return '<div style="padding:24px;border:1px dashed #d6d3d1;border-radius:8px;color:#a8a29e;text-align:center">Pick a group in the block sidebar.</div>';
	}
	return milieus_sc_register( $attrs );
}

function milieus_block_login_render( array $attrs ): string {
	if ( empty( $attrs['group'] ) ) {
		return '<div style="padding:24px;border:1px dashed #d6d3d1;border-radius:8px;color:#a8a29e;text-align:center">Pick a group in the block sidebar.</div>';
	}
	return milieus_sc_login( $attrs );
}

function milieus_block_member_status_render( array $attrs ): string {
	if ( is_admin() && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<div style="padding:24px;border:1px dashed #d6d3d1;border-radius:8px;color:#a8a29e;text-align:center">Member Status — shows the current user\'s active groups on the front end.</div>';
	}
	return milieus_sc_member_status( $attrs );
}

function milieus_block_member_count_render( array $attrs ): string {
	if ( empty( $attrs['group'] ) ) {
		return '<div style="padding:24px;border:1px dashed #d6d3d1;border-radius:8px;color:#a8a29e;text-align:center">Pick a group in the block sidebar.</div>';
	}
	return '<span class="milieus-member-count">' . milieus_sc_member_count( $attrs ) . '</span>';
}
