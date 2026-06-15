<?php
declare(strict_types=1);

namespace Brocode\ImageOptimizer\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

use function Brocode\ImageOptimizer\defaultSettings;
use function Brocode\ImageOptimizer\needsSidecar;
use function Brocode\ImageOptimizer\sidecarPath;

final class DefaultSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_all_expected_keys(): void
    {
        $defaults = defaultSettings();

        $this->assertArrayHasKey('enable_webp', $defaults);
        $this->assertArrayHasKey('enable_avif', $defaults);
        $this->assertArrayHasKey('webp_quality', $defaults);
        $this->assertArrayHasKey('avif_quality', $defaults);
    }

    public function test_both_formats_enabled_by_default(): void
    {
        $defaults = defaultSettings();

        $this->assertSame(1, $defaults['enable_webp']);
        $this->assertSame(1, $defaults['enable_avif']);
    }

    public function test_quality_values_are_within_valid_range(): void
    {
        $defaults = defaultSettings();

        $this->assertGreaterThanOrEqual(10, $defaults['webp_quality']);
        $this->assertLessThanOrEqual(100, $defaults['webp_quality']);
        $this->assertGreaterThanOrEqual(10, $defaults['avif_quality']);
        $this->assertLessThanOrEqual(100, $defaults['avif_quality']);
    }

    public function test_sidecar_path_appends_format_to_full_filename(): void
    {
        $this->assertSame(
            '/uploads/2024/01/photo.jpg.webp',
            sidecarPath('/uploads/2024/01/photo.jpg', 'webp')
        );
    }

    public function test_sidecar_path_works_for_avif(): void
    {
        $this->assertSame(
            '/uploads/2024/01/photo.png.avif',
            sidecarPath('/uploads/2024/01/photo.png', 'avif')
        );
    }

    public function test_needs_sidecar_when_sidecar_missing(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'brio_src_');
        $this->assertTrue(needsSidecar($source, $source . '.webp'));
        unlink($source);
    }

    public function test_no_sidecar_needed_when_sidecar_is_up_to_date(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'brio_src_');
        $sidecar = $source . '.webp';
        file_put_contents($sidecar, '');
        // Sidecar mtime matches source — not older, so no regeneration needed.
        touch($sidecar, filemtime($source));

        $this->assertFalse(needsSidecar($source, $sidecar));

        unlink($source);
        unlink($sidecar);
    }

    public function test_needs_sidecar_when_source_is_newer(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'brio_src_');
        $sidecar = $source . '.webp';
        file_put_contents($sidecar, '');
        // Force source mtime one second ahead of the sidecar.
        touch($sidecar, filemtime($source) - 1);

        $this->assertTrue(needsSidecar($source, $sidecar));

        unlink($source);
        unlink($sidecar);
    }
}
