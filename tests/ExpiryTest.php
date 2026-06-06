<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the duration → timestamp math in includes/expiry.php.
 */
final class ExpiryTest extends TestCase
{
	protected function tearDown(): void
	{
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_duration_to_ts_days(): void
	{
		$from = 1700000000;
		self::assertSame( $from + DAY_IN_SECONDS,  milieus_duration_to_ts( $from, 1,  'days' ) );
		self::assertSame( $from + 7 * DAY_IN_SECONDS, milieus_duration_to_ts( $from, 7,  'days' ) );
	}

	public function test_duration_to_ts_weeks(): void
	{
		$from = 1700000000;
		self::assertSame( $from + 2 * WEEK_IN_SECONDS, milieus_duration_to_ts( $from, 2, 'weeks' ) );
	}

	public function test_duration_to_ts_months_years(): void
	{
		$from = 1700000000;
		self::assertSame( $from + 3 * MONTH_IN_SECONDS, milieus_duration_to_ts( $from, 3, 'months' ) );
		self::assertSame( $from + 1 * YEAR_IN_SECONDS,  milieus_duration_to_ts( $from, 1, 'years' ) );
	}

	public function test_duration_to_ts_unknown_unit_falls_back_to_days(): void
	{
		$from = 1700000000;
		self::assertSame( $from + DAY_IN_SECONDS, milieus_duration_to_ts( $from, 1, 'fortnights' ) );
	}
}
