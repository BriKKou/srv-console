<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * Made-up server used for screenshots and for trying the UI without
 * installing anything (SRV_CONSOLE_DEMO=1). Nothing here comes from a real machine.
 */
final class Demo {
	private const GIB = 1073741824;

	private const SERVICES = [
		['Reverse proxy', 443, 'https://example.com', null, 'nginx'],
		['Cloud storage', 8443, 'https://cloud.example.com', 'files', null],
		['Media server', 8097, 'https://media.example.com', 'media', null],
		['Home automation', 8124, 'https://home.example.com', 'automation', null],
		['Password manager', 8222, 'https://vault.example.com', 'vault', null],
		['Git forge', 3001, 'https://git.example.com', 'git', null],
		['DNS filter', 5354, null, 'dns', null],
		['Wiki', 8091, 'https://wiki.example.com', 'wiki', null],
		['SSH', 22, null, null, 'ssh'],
	];

	/** name, image, version, days since start, health, cpu %, MiB */
	private const CONTAINERS = [
		['automation', 'example/automation:stable', '2026.10.2', 6, '', 1.42, 512],
		['dns', 'example/dns-filter:latest', '0.107.6', 21, '', 0.31, 96],
		['files', 'example/files:30', '30.0.4', 6, 'healthy', 2.15, 420],
		['files-cache', 'example/cache:7-alpine', '7.4.1', 6, '', 0.22, 12],
		['files-db', 'example/database:11.4', '11.4.3', 6, 'healthy', 0.84, 188],
		['git', 'example/git-forge:1.22', '1.22.3', 13, 'healthy', 0.12, 164],
		['media', 'example/media:10.10', '10.10.2', 2, 'healthy', 0.65, 610],
		['proxy', 'example/proxy:2.8', '2.8.1', 21, '', 0.08, 38],
		['vault', 'example/vault:1.32', '1.32.0', 13, 'healthy', 0.05, 54],
		['wiki', 'example/wiki:2.5', '2.5.304', 9, '', 0.18, 210],
	];

	/**
	 * Config tweaks so every panel and button shows up in the demo.
	 */
	public static function config(Config $base): Config {
		return $base->with([
			'demo'    => true,
			'title'   => 'Home Server',
			'actions' => ['enabled' => true, 'containers' => 'all', 'units' => ['nginx', 'ssh'], 'reboot' => true],
			'disks'   => ['labels' => ['/' => 'System', '/srv/data' => 'Data', '/mnt/media' => 'Media', '/mnt/backup' => 'Backup disk']],
			'backup'  => ['status_file' => 'demo', 'max_age_hours' => 48],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function state(Config $base): array {
		$config = self::config($base);
		$now = time();
		mt_srand(intdiv($now, 15));
		$jitter = static fn (float $v, float $spread): float => $v + (mt_rand(-100, 100) / 100) * $spread;

		$cores = 12;
		$avg = [round($jitter(0.84, 0.15), 2), 0.71, 0.66];
		$traffic = 340_000_000_000 + ($now % 86400) * 230_000;

		$live = [
			'system' => [
				'hostname'  => 'atlas',
				'os'        => 'Ubuntu 24.04.1 LTS',
				'kernel'    => '6.8.0-48-generic',
				'uptime'    => 1_634_400 + $now % 86400,
				'boot_time' => $now - 1_634_400,
			],
			'cpu'    => ['model' => 'AMD Ryzen 5 5600U with Radeon Graphics', 'cores' => $cores, 'mhz' => (int) $jitter(2380, 300), 'temp' => (int) $jitter(52, 2)],
			'load'   => ['avg' => $avg, 'percent' => array_map(static fn (float $v): int => (int) round($v * 100 / $cores), $avg)],
			'memory' => [
				'total' => 32 * self::GIB, 'used' => (int) (11.4 * self::GIB), 'percent' => 36,
				'swap_total' => 8 * self::GIB, 'swap_used' => 0, 'swap_percent' => 0,
			],
			'network' => [
				['name' => 'eth0', 'ip' => '192.0.2.10', 'up' => true, 'speed' => 1000, 'rx' => $traffic, 'tx' => (int) ($traffic / 7)],
			],
			'services' => array_map(static fn (array $s): array => [
				'name' => $s[0], 'port' => $s[1], 'url' => $s[2], 'container' => $s[3], 'unit' => $s[4], 'up' => true,
			], self::SERVICES),
		];

		$containers = array_map(static fn (array $c): array => [
			'name' => $c[0], 'state' => 'running', 'health' => $c[4], 'started_at' => $now - $c[3] * 86400 - 3600 * 5,
			'restarts' => 0, 'image' => $c[1], 'version' => $c[2], 'project' => null,
			'cpu' => round($jitter($c[5], $c[5] / 3), 2), 'mem' => $c[6] * 1048576,
		], self::CONTAINERS);

		$disk = static fn (string $mount, string $device, string $fs, float $sizeGib, int $percent): array => [
			'mount' => $mount, 'device' => $device, 'fstype' => $fs, 'size' => (int) ($sizeGib * self::GIB),
			'used' => (int) ($sizeGib * self::GIB * $percent / 100), 'avail' => (int) ($sizeGib * self::GIB * (100 - $percent) / 100), 'percent' => $percent,
		];

		$status = [
			'collected_at' => $now - 20,
			'docker'       => ['available' => true, 'containers' => $containers],
			'disks'        => [
				$disk('/', '/dev/nvme0n1p2', 'ext4', 116, 38),
				$disk('/srv/data', '/dev/nvme0n1p3', 'ext4', 800, 71),
				$disk('/mnt/media', '/dev/sda1', 'ext4', 3726, 93),
				$disk('/mnt/backup', '/dev/sdb1', 'ext4', 465, 22),
			],
			'smart' => [
				['label' => 'Kingston NV2 1TB', 'device' => '/dev/nvme0', 'passed' => true, 'standby' => false, 'temp' => 41, 'hours' => 9120, 'wear' => 4],
				['label' => 'WDC WD40EFRX-68N32N0', 'device' => '/dev/sda', 'passed' => true, 'standby' => false, 'temp' => 37, 'hours' => 31450, 'wear' => null],
				['label' => 'Samsung SSD 870 EVO 500GB', 'device' => '/dev/sdb', 'passed' => true, 'standby' => true, 'temp' => null, 'hours' => null, 'wear' => null],
			],
			'certs' => [
				['name' => 'example.com', 'expires' => $now + 61 * 86400, 'days' => 61],
				['name' => 'cloud.example.com', 'expires' => $now + 58 * 86400, 'days' => 58],
				['name' => 'media.example.com', 'expires' => $now + 33 * 86400, 'days' => 33],
				['name' => 'git.example.com', 'expires' => $now + 27 * 86400, 'days' => 27],
			],
			'updates'  => ['total' => 3, 'security' => 0, 'reboot_required' => true],
			'backup'   => ['present' => true, 'ok' => true, 'last_run' => $now - 8 * 3600, 'last_success' => $now - 8 * 3600, 'detail' => 'nightly snapshot · repository 38.2 GB'],
			'logins'   => [
				['user' => 'admin', 'from' => '192.0.2.24', 'at' => $now - 3 * 3600],
				['user' => 'admin', 'from' => '192.0.2.24', 'at' => $now - 27 * 3600],
				['user' => 'deploy', 'from' => '198.51.100.7', 'at' => $now - 4 * 86400],
			],
			'sessions' => 1,
		];

		return State::assemble($config, $live, $status);
	}

	/**
	 * 24 h of plausible history: quiet nights, busier evenings, a nightly backup spike.
	 *
	 * @return list<array<string, int>>
	 */
	public static function history(): array {
		$now = intdiv(time(), 60) * 60;
		mt_srand(4242);
		$points = [];
		$rx = 480_000_000_000;
		for ($i = 1439; $i >= 0; $i--) {
			$t = $now - $i * 60;
			$hour = (int) gmdate('G', $t) + (int) gmdate('i', $t) / 60;
			$evening = exp(-(($hour - 20.5) ** 2) / 6);
			$backup = $hour >= 3.5 && $hour < 3.9 ? 55 : 0;
			$cpu = (int) min(100, round(6 + 22 * $evening + $backup + mt_rand(0, 60) / 10));
			$rx += (int) ((40_000 + 900_000 * $evening + ($backup > 0 ? 2_500_000 : 0)) * 60 * (0.6 + mt_rand(0, 80) / 100));
			$points[] = [
				't'    => $t,
				'cpu'  => $cpu,
				'ram'  => (int) round(34 + 4 * $evening + mt_rand(0, 10) / 10),
				'temp' => (int) round(44 + $cpu * 0.28 + mt_rand(0, 10) / 10),
				'rx'   => $rx,
				'tx'   => intdiv($rx, 7),
			];
		}
		return $points;
	}
}
