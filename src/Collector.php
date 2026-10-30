<?php
declare(strict_types=1);

namespace SrvConsole;

use DateTimeImmutable;
use Throwable;

/**
 * Everything that needs root or an external command, run once a minute by
 * bin/srv-console-collect. The web app only reads what it writes.
 */
final class Collector {
	private const SKIP_FS = [
		'tmpfs', 'devtmpfs', 'devpts', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'overlay', 'squashfs',
		'efivarfs', 'securityfs', 'debugfs', 'tracefs', 'configfs', 'fusectl', 'mqueue', 'hugetlbfs',
		'pstore', 'bpf', 'autofs', 'binfmt_misc', 'nsfs', 'ramfs', 'rpc_pipefs', 'iso9660', 'udf',
		'fuse.portal', 'fuse.gvfsd-fuse', 'fuse.lxcfs',
	];
	private const SKIP_MOUNTS = ['/snap/', '/run/', '/sys/', '/proc/', '/dev/', '/boot/efi/', '/var/lib/docker/', '/var/lib/kubelet/', '/var/snap/'];
	private const LOGIN_NOISE = ['reboot', 'shutdown', 'runlevel', 'wtmp'];
	/** Parts that are slow or pointless to refresh every minute, and how long a result stays valid (s). */
	private const SLOW_PARTS = ['smart' => 600, 'updates' => 600];

	/**
	 * @param array<string, mixed>|null $previous last snapshot, to reuse the slow parts
	 */
	public function __construct(private readonly Config $config, private readonly ?array $previous = null) {
	}

	/**
	 * One full collection, each part isolated so a failure only blanks that part.
	 *
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		$status = ['collected_at' => time(), 'slow_at' => $this->previous['slow_at'] ?? []];
		$parts = [
			'docker'   => fn () => $this->docker(),
			'disks'    => fn () => $this->disks(),
			'smart'    => fn () => $this->smart(),
			'certs'    => fn () => $this->certs(),
			'updates'  => fn () => $this->updates(),
			'backup'   => fn () => $this->backup(),
			'logins'   => fn () => $this->logins(),
			'sessions' => fn () => $this->sessions(),
		];
		foreach ($parts as $key => $part) {
			$ttl = self::SLOW_PARTS[$key] ?? 0;
			$at = $status['slow_at'][$key] ?? 0;
			if ($ttl > 0 && time() - $at < $ttl && is_array($this->previous) && array_key_exists($key, $this->previous)) {
				$status[$key] = $this->previous[$key];
				continue;
			}
			try {
				$status[$key] = $part();
			} catch (Throwable) {
				$status[$key] = null;
			}
			if ($ttl > 0) {
				$status['slow_at'][$key] = time();
			}
		}
		return $status;
	}

	/**
	 * One point of the 24 h history.
	 *
	 * @return array<string, int|null>
	 */
	public function sample(): array {
		$cpu = Metrics::cpu();
		$iface = Metrics::defaultInterface();
		$read = static fn (string $f): ?int => $iface === null ? null : (int) trim(Util::read('/sys/class/net/' . $iface . '/statistics/' . $f) ?? '0');
		return [
			't'    => time(),
			'cpu'  => Metrics::load((int) $cpu['cores'])['percent'][0],
			'ram'  => Metrics::memory()['percent'],
			'temp' => $cpu['temp'],
			'rx'   => $read('rx_bytes'),
			'tx'   => $read('tx_bytes'),
		];
	}

	/**
	 * @return array{available: bool, containers: list<array<string, mixed>>}|null
	 */
	private function docker(): ?array {
		if (!$this->config->get('docker.enabled', true)) {
			return null;
		}
		[$out, , $code] = Util::run(['docker', 'ps', '-a', '--format', '{{.Names}}'], 15);
		if ($code !== 0) {
			return ['available' => false, 'containers' => []];
		}
		$names = array_values(array_filter(array_map('trim', explode("\n", $out))));
		if ($names === []) {
			return ['available' => true, 'containers' => []];
		}

		$template = implode("\t", [
			'{{.Name}}',
			'{{.State.Status}}',
			'{{if .State.Health}}{{.State.Health.Status}}{{end}}',
			'{{.State.StartedAt}}',
			'{{.RestartCount}}',
			'{{.Config.Image}}',
			'{{index .Config.Labels "org.opencontainers.image.version"}}',
			'{{index .Config.Labels "com.docker.compose.project"}}',
		]);
		[$out] = Util::run(array_merge(['docker', 'inspect', '--format', $template], $names), 30);
		$containers = [];
		foreach (explode("\n", trim($out)) as $line) {
			$f = explode("\t", $line);
			if (count($f) < 8) {
				continue;
			}
			$name = ltrim($f[0], '/');
			$containers[$name] = [
				'name'       => $name,
				'state'      => $f[1],
				'health'     => $f[2],
				'started_at' => self::timestamp($f[3]),
				'restarts'   => (int) $f[4],
				'image'      => $f[5],
				'version'    => self::label($f[6]),
				'project'    => self::label($f[7]),
				'cpu'        => null,
				'mem'        => null,
			];
		}

		[$out] = Util::run(['docker', 'stats', '--no-stream', '--format', "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}"], 30);
		foreach (explode("\n", trim($out)) as $line) {
			$f = explode("\t", $line);
			if (count($f) === 3 && isset($containers[$f[0]])) {
				$containers[$f[0]]['cpu'] = (float) rtrim($f[1], '%');
				$containers[$f[0]]['mem'] = self::bytes(explode('/', $f[2])[0]);
			}
		}
		ksort($containers);
		return ['available' => true, 'containers' => array_values($containers)];
	}

	/**
	 * Mounted filesystems, df-style figures (reserved blocks excluded from "free").
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function disks(): ?array {
		[$out] = Util::run(['df', '-B1', '--output=source,fstype,size,used,avail,target'], 15);
		if (trim($out) === '') {
			return null;
		}
		$ignore = (array) $this->config->get('disks.ignore', []);
		$seen = [];
		$disks = [];
		foreach (array_slice(explode("\n", trim($out)), 1) as $line) {
			$f = preg_split('/\s+/', trim($line), 6);
			if (!is_array($f) || count($f) < 6) {
				continue;
			}
			[$source, $type, $size, $used, $avail, $mount] = $f;
			if (in_array($type, self::SKIP_FS, true) || isset($seen[$source]) || (int) $size === 0 || self::skipMount($mount, $ignore)) {
				continue;
			}
			$seen[$source] = true;
			$total = (int) $used + (int) $avail;
			$disks[] = [
				'mount'   => $mount,
				'device'  => $source,
				'fstype'  => $type,
				'size'    => (int) $size,
				'used'    => (int) $used,
				'avail'   => (int) $avail,
				'percent' => $total > 0 ? (int) ceil((int) $used * 100 / $total) : 0,
			];
		}
		return $disks;
	}

	/**
	 * SMART health, from the config list or `smartctl --scan`. Sleeping drives
	 * are left asleep (-n standby).
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private function smart(): ?array {
		if (!$this->config->get('smart.enabled', true)) {
			return null;
		}
		[, , $code] = Util::run(['smartctl', '--version'], 5);
		if ($code !== 0) {
			return null;
		}
		$devices = (array) $this->config->get('smart.devices', []);
		if ($devices === []) {
			[$out] = Util::run(['smartctl', '--scan', '--json'], 15);
			foreach (json_decode($out, true)['devices'] ?? [] as $d) {
				$devices[] = ['device' => $d['name'], 'type' => $d['type'] ?? 'auto'];
			}
		}
		$exclude = array_map('strtolower', (array) $this->config->get('smart.exclude', []));
		$list = [];
		foreach ($devices as $d) {
			$device = (string) ($d['device'] ?? '');
			if ($device === '' || self::excluded($device, $exclude)) {
				continue;
			}
			// The scan sometimes reports "scsi" for a SATA drive, which hides its health: try the others
			$j = null;
			$standby = false;
			foreach (array_unique([(string) ($d['type'] ?? 'auto'), 'auto', 'sat']) as $type) {
				[$out] = Util::run(['smartctl', '-j', '-i', '-H', '-A', '-n', 'standby', '-d', $type, $device], 30);
				$try = json_decode($out, true);
				if (!is_array($try)) {
					continue;
				}
				$standby = false;
				foreach ($try['smartctl']['messages'] ?? [] as $msg) {
					$standby = $standby || stripos((string) ($msg['string'] ?? ''), 'standby') !== false;
				}
				$j = $try;
				if ($standby || isset($try['smart_status']['passed'])) {
					break;
				}
			}
			$passed = $j['smart_status']['passed'] ?? null;
			if ($j === null || (!$standby && $passed === null)) {
				continue;
			}
			$list[] = [
				'label'   => $d['label'] ?? ($j['model_name'] ?? basename($device)),
				'device'  => $device,
				'passed'  => $passed,
				'standby' => $standby,
				'temp'    => $j['temperature']['current'] ?? null,
				'hours'   => $j['power_on_time']['hours'] ?? null,
				'wear'    => $j['nvme_smart_health_information_log']['percentage_used'] ?? null,
			];
		}
		return $list;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function certs(): ?array {
		$dir = rtrim((string) $this->config->get('certificates.path', ''), '/');
		if ($dir === '' || !is_dir($dir) || !function_exists('openssl_x509_parse')) {
			return null;
		}
		$list = [];
		foreach (glob($dir . '/*/cert.pem') ?: [] as $pem) {
			$x509 = openssl_x509_parse((string) Util::read($pem));
			if (!is_array($x509) || !isset($x509['validTo_time_t'])) {
				continue;
			}
			$expires = (int) $x509['validTo_time_t'];
			$list[] = ['name' => basename(dirname($pem)), 'expires' => $expires, 'days' => (int) floor(($expires - time()) / 86400)];
		}
		usort($list, static fn (array $a, array $b): int => $a['days'] <=> $b['days']);
		return $list === [] ? null : $list;
	}

	/**
	 * Pending apt updates (Debian/Ubuntu) and the reboot-required flag.
	 *
	 * @return array<string, int|bool|null>|null
	 */
	private function updates(): ?array {
		if (!$this->config->get('updates.enabled', true)) {
			return null;
		}
		$reboot = is_file('/var/run/reboot-required');
		$check = '/usr/lib/update-notifier/apt-check';
		if (!is_file($check)) {
			return $reboot ? ['total' => null, 'security' => null, 'reboot_required' => true] : null;
		}
		// apt-check prints "updates;security" on stderr
		[, $err] = Util::run([$check], 120);
		$counts = preg_match('/(\d+);(\d+)/', $err, $m) === 1 ? [(int) $m[1], (int) $m[2]] : [null, null];
		return ['total' => $counts[0], 'security' => $counts[1], 'reboot_required' => $reboot];
	}

	/**
	 * Backup status file written by your own backup job (format in the README).
	 *
	 * @return array<string, mixed>|null
	 */
	private function backup(): ?array {
		$file = $this->config->get('backup.status_file');
		if (!is_string($file) || $file === '') {
			return null;
		}
		$j = Util::readJson($file);
		if ($j === null) {
			return ['present' => false];
		}
		$lastRun = self::timestamp((string) ($j['last_run'] ?? ''));
		$ok = (bool) ($j['ok'] ?? false);
		return [
			'present'      => true,
			'ok'           => $ok,
			'last_run'     => $lastRun,
			'last_success' => self::timestamp((string) ($j['last_success'] ?? '')) ?? ($ok ? $lastRun : null),
			'detail'       => isset($j['detail']) ? self::truncate((string) $j['detail'], 200) : null,
		];
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function logins(): ?array {
		if (!$this->config->get('logins.enabled', true)) {
			return null;
		}
		$max = max(1, (int) $this->config->get('logins.max', 5));
		[$out, , $code] = Util::run(['last', '--time-format', 'iso', '-w', '-n', (string) ($max * 3)], 10);
		if ($code !== 0) {
			return null;
		}
		$list = [];
		foreach (explode("\n", $out) as $line) {
			$pattern = '/^(\S+)\s+(\S+)\s+(?:(\S+)\s+)?(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d)/';
			if (preg_match($pattern, $line, $m) !== 1 || in_array($m[1], self::LOGIN_NOISE, true)) {
				continue;
			}
			$list[] = ['user' => $m[1], 'from' => $m[3] !== '' ? $m[3] : null, 'at' => self::timestamp($m[4])];
			if (count($list) >= $max) {
				break;
			}
		}
		return $list;
	}

	private function sessions(): ?int {
		[$out, , $code] = Util::run(['who'], 5);
		return $code === 0 ? count(array_filter(explode("\n", trim($out)))) : null;
	}

	/**
	 * @param list<string> $ignore
	 */
	private static function skipMount(string $mount, array $ignore): bool {
		foreach (self::SKIP_MOUNTS as $prefix) {
			if (str_starts_with($mount . '/', $prefix)) {
				return true;
			}
		}
		foreach ($ignore as $pattern) {
			if (fnmatch((string) $pattern, $mount)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match the exclude list against the device path, its /dev/disk/by-id names
	 * and the model reported by sysfs (no I/O to the drive).
	 *
	 * @param list<string> $exclude
	 */
	private static function excluded(string $device, array $exclude): bool {
		if ($exclude === []) {
			return false;
		}
		$names = [$device];
		$real = realpath($device) ?: $device;
		foreach (glob('/dev/disk/by-id/*') ?: [] as $link) {
			if (realpath($link) === $real) {
				$names[] = basename($link);
			}
		}
		$block = basename($real);
		$names[] = Util::read('/sys/block/' . $block . '/device/model') ?? '';
		$names[] = Util::read('/sys/block/' . $block . '/device/vendor') ?? '';
		$haystack = strtolower(implode(' ', $names));
		foreach ($exclude as $needle) {
			if ($needle !== '' && str_contains($haystack, $needle)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cut to $max characters without splitting a UTF-8 sequence (no mbstring needed).
	 */
	private static function truncate(string $value, int $max): string {
		return preg_match('/^.{0,' . $max . '}/su', $value, $m) === 1 ? $m[0] : substr($value, 0, $max);
	}

	private static function label(string $value): ?string {
		$value = trim($value);
		return $value === '' || $value === '<no value>' ? null : $value;
	}

	private static function timestamp(string $value): ?int {
		if (trim($value) === '' || str_starts_with($value, '0001-')) {
			return null;
		}
		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * "95.01MiB" -> bytes.
	 */
	private static function bytes(string $value): ?int {
		if (preg_match('/([\d.]+)\s*([KMGTP]?i?B)/i', trim($value), $m) !== 1) {
			return null;
		}
		$unit = strtoupper($m[2]);
		$powers = ['B' => 0, 'KB' => 1, 'KIB' => 1, 'MB' => 2, 'MIB' => 2, 'GB' => 3, 'GIB' => 3, 'TB' => 4, 'TIB' => 4, 'PB' => 5, 'PIB' => 5];
		$base = str_contains($unit, 'I') ? 1024 : 1000;
		return (int) round((float) $m[1] * $base ** ($powers[$unit] ?? 0));
	}
}
