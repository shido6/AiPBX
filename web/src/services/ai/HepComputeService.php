<?php
/**
 * How much room does this server actually have for a correlation collector?
 *
 * The HEP card used to present HOMER/VoIPmonitor before saying what they cost.
 * This service measures the machine first — CPU cores (including a cgroup
 * quota), the one-minute load, available RAM and free disk on the data
 * partition — and turns that into a conservative one/both verdict.
 *
 * The thresholds are planning gates, not a benchmark. They are deliberately
 * below the published component sizes: HOMER 7's guide sizes its ingest,
 * database and web parts separately (8 vCPU/16 GB/100 GB, 4/16/1 TB, 2/8/40),
 * and VoIPmonitor recommends 8–16 GB for a small full deployment. A real
 * deployment still has to be validated with its own traffic.
 *
 * Reading /proc and statvfs is cheap and happens once per page view. Nothing
 * here writes, runs a shell or changes a setting.
 */

final class HepComputeService
{
    public const DISK_PATH = '/var/lib/aipbx';
    private const GB = 1073741824;

    /** Below this a local collector should not be placed on the PBX. */
    private const ONE_FLOOR = ['cores' => 2, 'mem' => 2 * self::GB, 'disk' => 10 * self::GB];

    /** Comfortable headroom for one local collector. */
    private const ONE_COMFORT = ['cores' => 4, 'mem' => 8 * self::GB, 'disk' => 40 * self::GB];

    /** Below this both collectors together do not fit safely. */
    private const BOTH_FLOOR = ['cores' => 4, 'mem' => 8 * self::GB, 'disk' => 40 * self::GB];

    /** Comfortable headroom for HOMER and VoIPmonitor together. */
    private const BOTH_COMFORT = ['cores' => 8, 'mem' => 16 * self::GB, 'disk' => 100 * self::GB];

    /** @return array{measured:bool, cores:int, load1:?float, load_pct:?int,
     *                mem_total:int, mem_available:int, disk_total:int, disk_free:int,
     *                one:array{level:string}, both:array{level:string}} */
    public static function measure(): array
    {
        $cores = self::hostCores();
        $memory = self::memory();
        $disk = self::disk();
        $limits = self::cgroupLimits();

        if ($limits['cores'] !== null) {
            $cores = max(1, min($cores, $limits['cores']));
        }
        if ($limits['mem_total'] !== null && $limits['mem_total'] > 0) {
            $memory['total'] = min($memory['total'], $limits['mem_total']);
            $memory['available'] = min($memory['available'], max(0, $limits['mem_total'] - $limits['mem_used']));
        }

        $load1 = self::load1();
        $loadPct = ($load1 !== null && $cores > 0) ? (int) round($load1 / $cores * 100) : null;
        $measured = $cores > 0 && $memory['total'] > 0 && $disk['total'] > 0;

        $out = [
            'measured'      => $measured,
            'cores'         => $cores,
            'load1'         => $load1,
            'load_pct'      => $loadPct,
            'mem_total'     => $memory['total'],
            'mem_available' => $memory['available'],
            'disk_total'    => $disk['total'],
            'disk_free'     => $disk['free'],
        ];
        $out['one'] = self::assess($out, 'one');
        $out['both'] = self::assess($out, 'both');
        return $out;
    }

    /**
     * @param array{cores:int, load_pct:?int, mem_available:int, disk_free:int} $m
     * @return array{level:string} ok = headroom, tight = fits with little, low = below floor
     */
    public static function assess(array $m, string $kind): array
    {
        $floor = $kind === 'both' ? self::BOTH_FLOOR : self::ONE_FLOOR;
        $comfort = $kind === 'both' ? self::BOTH_COMFORT : self::ONE_COMFORT;
        $load = $m['load_pct'] ?? 0;

        if ($m['cores'] < $floor['cores'] || $m['mem_available'] < $floor['mem']
                || $m['disk_free'] < $floor['disk'] || $load > 90) {
            return ['level' => 'low'];
        }
        if ($m['cores'] < $comfort['cores'] || $m['mem_available'] < $comfort['mem']
                || $m['disk_free'] < $comfort['disk'] || $load > 60) {
            return ['level' => 'tight'];
        }
        return ['level' => 'ok'];
    }

    public static function human(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        if ($bytes >= self::GB) {
            return number_format($bytes / self::GB, 1, '.', '') . ' GB';
        }
        return number_format($bytes / 1048576, 0, '.', '') . ' MB';
    }

    private static function hostCores(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        if (is_string($info) && preg_match_all('/^processor\s*:/m', $info, $m)) {
            return max(1, count($m[0]));
        }
        return 1;
    }

    /** @return array{total:int, available:int} bytes */
    private static function memory(): array
    {
        $out = ['total' => 0, 'available' => 0];
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)(?:\s+kB)?$/', $line, $m)) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $out['total'] = $values['MemTotal'] ?? 0;
        if (isset($values['MemAvailable'])) {
            $out['available'] = $values['MemAvailable'];
        } else {
            $out['available'] = ($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0);
        }
        return $out;
    }

    /** @return array{total:int, free:int} bytes */
    private static function disk(): array
    {
        $free = @disk_free_space(self::DISK_PATH);
        $total = @disk_total_space(self::DISK_PATH);
        if ($free === false || $total === false) {
            $free = @disk_free_space('/');
            $total = @disk_total_space('/');
        }
        return ['total' => (int) $total, 'free' => (int) $free];
    }

    private static function load1(): ?float
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $load = @sys_getloadavg();
        return is_array($load) ? (float) $load[0] : null;
    }

    /**
     * Container CPU/memory limits, when this install runs in one. The PBX
     * itself normally runs on the whole VM; a cgroup quota is still the
     * honest number when it exists.
     *
     * @return array{cores:?int, mem_total:?int, mem_used:int}
     */
    private static function cgroupLimits(): array
    {
        $out = ['cores' => null, 'mem_total' => null, 'mem_used' => 0];

        $cpuMax = self::read('/sys/fs/cgroup/cpu.max');
        if ($cpuMax !== null && preg_match('/^(\d+)\s+(\d+)/', trim($cpuMax), $m) && (int) $m[2] > 0) {
            $out['cores'] = max(1, (int) ceil((int) $m[1] / (int) $m[2]));
        } else {
            $quota = self::read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
            $period = self::read('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
            if (is_numeric($quota) && (int) $quota > 0 && is_numeric($period) && (int) $period > 0) {
                $out['cores'] = max(1, (int) ceil((int) $quota / (int) $period));
            }
        }

        $memMax = self::read('/sys/fs/cgroup/memory.max');
        $memNow = self::read('/sys/fs/cgroup/memory.current');
        if (is_numeric($memMax) && (int) $memMax > 0) {
            $out['mem_total'] = (int) $memMax;
            $out['mem_used'] = is_numeric($memNow) ? (int) $memNow : 0;
        } else {
            $limit = self::read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
            $usage = self::read('/sys/fs/cgroup/memory/memory.usage_in_bytes');
            if (is_numeric($limit) && (int) $limit > 0 && (int) $limit < PHP_INT_MAX / 2) {
                $out['mem_total'] = (int) $limit;
                $out['mem_used'] = is_numeric($usage) ? (int) $usage : 0;
            }
        }
        return $out;
    }

    /**
     * Read a kernel file only when it exists.
     *
     * `@file_get_contents()` hides the warning from the page, but an installed
     * error handler (bin/smoke.php, and any host that logs warnings) is still
     * called for a suppressed error — a cgroup v2 path that a container does
     * not mount would fail every /ai-echo render. Asking is_readable() first
     * means no warning is raised at all, and a missing file is simply null.
     */
    private static function read(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $text = @file_get_contents($path);
        return is_string($text) ? $text : null;
    }
}
