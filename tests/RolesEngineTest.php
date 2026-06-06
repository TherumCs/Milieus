<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the pure data layer in includes/roles-engine.php.
 * These exercise data shapes and helpers — no DB, no HTTP.
 */
final class RolesEngineTest extends TestCase
{
	protected function tearDown(): void
	{
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_group_defaults_has_expected_keys(): void
	{
		$d = milieus_group_defaults();
		foreach ( [ 'key', 'name', 'color', 'bundles', 'caps', 'discount', 'expires_at', 'member_duration', 'reg', 'updated' ] as $k ) {
			self::assertArrayHasKey( $k, $d, "missing $k from group defaults" );
		}
		self::assertSame( 0.0, $d['discount'] );
		self::assertSame( 0, $d['expires_at'] );
		self::assertSame( 0, $d['member_duration']['value'] );
	}

	public function test_reg_defaults_has_expected_keys(): void
	{
		$r = milieus_reg_defaults();
		foreach ( [ 'slug','enabled','logo','heading','lede','brand','color','button','extras','redirect','approval','max_signups','signup_count','bg_kind','bg_solid','bg_grad_1','bg_grad_2','bg_grad_dir','bg_image','bg_dim','bg_blur','welcome_enabled','welcome_heading','welcome_body','welcome_cta' ] as $k ) {
			self::assertArrayHasKey( $k, $r, "missing reg.$k" );
		}
		self::assertFalse( $r['enabled'] );
		self::assertFalse( $r['approval'] );
		self::assertSame( 'solid', $r['bg_kind'] );
	}

	public function test_resolve_caps_merges_bundles_and_individual(): void
	{
		$caps = milieus_resolve_caps( [ 'read' ], [ 'edit_posts', 'level_1' ] );
		self::assertArrayHasKey( 'read',       $caps );
		self::assertArrayHasKey( 'level_0',    $caps ); // from the read bundle
		self::assertArrayHasKey( 'edit_posts', $caps );
		self::assertArrayHasKey( 'level_1',    $caps );
		self::assertTrue( $caps['read'] );
	}

	public function test_resolve_caps_ignores_unknown_bundle(): void
	{
		$caps = milieus_resolve_caps( [ 'definitely_not_real' ], [ 'manage_options' ] );
		self::assertSame( [ 'manage_options' => true ], $caps );
	}

	public function test_reserved_roles_includes_administrator(): void
	{
		self::assertContains( 'administrator', milieus_reserved_roles() );
		self::assertContains( 'subscriber',   milieus_reserved_roles() );
	}
}
