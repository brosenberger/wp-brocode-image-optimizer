<?php
declare(strict_types=1);

namespace Brocode\ImageOptimizer\Tests\Integration;

use WP_UnitTestCase;

use function Brocode\ImageOptimizer\deactivate;
use function Brocode\ImageOptimizer\ensureScheduled;
use const Brocode\ImageOptimizer\CRON_HOOK;

final class SchedulingTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $timestamp = wp_next_scheduled(CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, CRON_HOOK);
        }
    }

    public function test_ensure_scheduled_registers_hourly_event(): void
    {
        ensureScheduled();

        $this->assertNotFalse(wp_next_scheduled(CRON_HOOK));
    }

    public function test_deactivate_removes_scheduled_event(): void
    {
        ensureScheduled();
        deactivate();

        $this->assertFalse(wp_next_scheduled(CRON_HOOK));
    }

    public function test_ensure_scheduled_is_idempotent(): void
    {
        ensureScheduled();
        ensureScheduled();

        $cron = _get_cron_array();
        $count = 0;
        foreach ($cron as $hooks) {
            if (isset($hooks[CRON_HOOK])) {
                $count++;
            }
        }

        $this->assertSame(1, $count);
    }
}
