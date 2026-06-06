<?php
/**
 * Minimal WP function stubs for headless unit tests.
 *
 * These exist only when the test bootstrap loads the plugin without WP
 * present. They're no-ops or sensible defaults — enough to let the pure
 * data layer load and run. For tests that need richer behavior, override
 * with Brain Monkey functions() in the test class.
 */

if ( ! function_exists( 'add_action' ) )  { function add_action( ...$a )  { return true; } }
if ( ! function_exists( 'add_filter' ) )  { function add_filter( ...$a )  { return true; } }
if ( ! function_exists( 'do_action' ) )   { function do_action( ...$a )   { return null; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		if ( is_object( $args ) ) $args = get_object_vars( $args );
		if ( ! is_array( $args ) ) $args = [];
		return array_merge( $defaults, $args );
	}
}
if ( ! function_exists( '__' ) )                { function __( $s, $d = null )         { return $s; } }
if ( ! function_exists( '_n' ) )                { function _n( $s, $p, $n, $d = null ) { return $n === 1 ? $s : $p; } }
if ( ! function_exists( 'esc_html__' ) )        { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) )          { function esc_html( $s )              { return $s; } }
if ( ! function_exists( 'esc_attr' ) )          { function esc_attr( $s )              { return $s; } }
if ( ! function_exists( 'esc_url' ) )           { function esc_url( $s )               { return $s; } }
if ( ! function_exists( 'sanitize_key' ) )      { function sanitize_key( $s )          { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) )    { function sanitize_title( $s )        { return strtolower( preg_replace( '/[^a-z0-9-]/i', '-', (string) $s ) ); } }
if ( ! function_exists( 'get_option' ) )        { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) )     { function update_option( $k, $v )     { return true; } }
if ( ! function_exists( 'delete_option' ) )     { function delete_option( $k )         { return true; } }
if ( ! function_exists( 'get_user_meta' ) )     { function get_user_meta( ...$a )      { return ''; } }
if ( ! function_exists( 'update_user_meta' ) )  { function update_user_meta( ...$a )   { return true; } }
if ( ! function_exists( 'delete_user_meta' ) )  { function delete_user_meta( ...$a )   { return true; } }
if ( ! function_exists( 'get_userdata' ) )      { function get_userdata( $id )         { return null; } }
if ( ! function_exists( 'get_users' ) )         { function get_users( $args = [] )     { return []; } }
if ( ! function_exists( 'add_role' ) )          { function add_role( ...$a )           { return null; } }
if ( ! function_exists( 'remove_role' ) )       { function remove_role( $key )         { return null; } }
if ( ! function_exists( 'wp_roles' ) )          { function wp_roles()                  { return (object) [ 'roles' => [] ]; } }
