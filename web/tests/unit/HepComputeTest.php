<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ai/HepComputeService.php';

/**
 * The HEP card measures the host before it asks which collectors to run.
 * These tests pin the planning gates (not a benchmark) and the formatting that
 * the card shows, without depending on the test machine's current load.
 */
final class HepComputeTest extends TestCase
{
    private const GB = 1073741824;

    private function metrics(array $overrides = []): array
    {
        return array_merge([
            'measured'      => true,
            'cores'         => 8,
            'load1'         => 0.4,
            'load_pct'      => 5,
            'mem_total'     => 16 * self::GB,
            'mem_available' => 16 * self::GB,
            'disk_total'    => 120 * self::GB,
            'disk_free'     => 100 * self::GB,
        ], $overrides);
    }

    public function testBothFitComfortablyOnAPublishedSizedHost(): void
    {
        $this->assertSame('ok', HepComputeService::assess($this->metrics(), 'both')['level']);
    }

    public function testBothAreTightAtTheFloor(): void
    {
        $this->assertSame('tight', HepComputeService::assess($this->metrics([
            'cores' => 4,
            'mem_available' => 8 * self::GB,
            'disk_free' => 40 * self::GB,
        ]), 'both')['level']);
    }

    public function testBothAreLowWhenDiskCannotHoldTwoRetentions(): void
    {
        $this->assertSame('low', HepComputeService::assess($this->metrics([
            'cores' => 4,
            'mem_available' => 5 * self::GB,
            'disk_free' => 13 * self::GB,
        ]), 'both')['level']);
    }

    public function testOneCollectorFitsButIsTightOnTheSameHost(): void
    {
        $m = $this->metrics([
            'cores' => 4,
            'mem_available' => 5 * self::GB,
            'disk_free' => 13 * self::GB,
        ]);
        $this->assertSame('tight', HepComputeService::assess($m, 'one')['level']);
    }

    public function testOneCollectorIsLowBelowTheFloor(): void
    {
        $this->assertSame('low', HepComputeService::assess($this->metrics([
            'cores' => 1, 'mem_available' => self::GB, 'disk_free' => 4 * self::GB,
        ]), 'one')['level']);
    }

    public function testCurrentLoadCanMakeAComfortableHostTight(): void
    {
        $this->assertSame('tight', HepComputeService::assess($this->metrics([
            'load_pct' => 70,
        ]), 'both')['level']);
    }

    public function testVeryHighLoadIsBelowTheFloor(): void
    {
        $this->assertSame('low', HepComputeService::assess($this->metrics([
            'load_pct' => 95,
        ]), 'both')['level']);
    }

    public function testSizesAreHumanReadable(): void
    {
        $this->assertSame('—', HepComputeService::human(0));
        $this->assertSame('512 MB', HepComputeService::human(512 * 1048576));
        $this->assertSame('1.5 GB', HepComputeService::human((int) (1.5 * self::GB)));
    }

    public function testMeasureReadsTheRealHostOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('this measurement reads /proc and is Linux-only');
        }
        $m = HepComputeService::measure();
        $this->assertTrue($m['measured']);
        $this->assertGreaterThanOrEqual(1, $m['cores']);
        $this->assertGreaterThan(0, $m['mem_total']);
        $this->assertGreaterThan(0, $m['disk_total']);
        $this->assertContains($m['one']['level'], ['ok', 'tight', 'low']);
        $this->assertContains($m['both']['level'], ['ok', 'tight', 'low']);
    }
}
