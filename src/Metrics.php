<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * Live readings taken on every page refresh, from /proc and /sys only
 * (the web user never runs a command).
 */
final class Metrics {
	private const SKIP_INTERFACES = '/^(lo|veth|br-|docker|virbr|vnet|tap|tun|cali|flannel|cni|kube)/';
	private const CPU_SENSORS = ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal', 'soc_thermal'];

	/**
	 * @return array<string, mixed>
	 */
	public static function system(): array {
		$uptime = (int) explode(' ', Util::read('/proc/uptime') ?? '0')[0];
		$os = 'Linux';
		foreach (explode("\n", Util::read('/etc/os-release') ?? '') as $line) {
			if (str_starts_with($line, 'PRETTY_NAME=')) {
				$os = trim(substr($line, 12), "\"' ");
			}
		}
		return [
			'hostname'  => php_uname('n'),
			'os'        => $os,
			'kernel'    => php_uname('r'),
			'uptime'    => $uptime,
			'boot_time' => time() - $uptime,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function cpu(): array {
		$info = Util::read('/proc/cpuinfo') ?? '';
		$model = 'Unknown CPU';
		foreach (['model name', 'Model', 'Hardware'] as $key) {
			if (preg_match('/^' . $key . '\s*:\s*(.+)$/m', $info, $m) === 1) {
				$model = trim($m[1]);
				break;
			}
		}
		$mhz = null;
		if (preg_match_all('/^cpu MHz\s*:\s*([\d.]+)/m', $info, $all) > 0) {
			$mhz = (int) round(array_sum(array_map('floatval', $all[1])) / count($all[1]));
		}
		return [
			'model' => $model,
			'cores' => max(1, (int) preg_match_all('/^processor\s*:/m', $info)),
			'mhz'   => $mhz,
			'temp'  => self::cpuTemp(),
		];
	}

	/**
	 * CPU package temperature in °C, from the first known hwmon driver.
	 */
	public static function cpuTemp(): ?int {
		foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $dir) {
			if (!in_array(trim(Util::read($dir . '/name') ?? ''), self::CPU_SENSORS, true)) {
				continue;
			}
			$raw = Util::read($dir . '/temp1_input');
			if ($raw !== null) {
				return (int) round((int) $raw / 1000);
			}
		}
		$raw = Util::read('/sys/class/thermal/thermal_zone0/temp');
		return $raw === null ? null : (int) round((int) $raw / 1000);
	}

	/**
	 * @return array{avg: list<float>, percent: list<int>}
	 */
	public static function load(int $cores): array {
		$avg = sys_getloadavg() ?: [0.0, 0.0, 0.0];
		return [
			'avg'     => array_map(static fn ($v): float => round((float) $v, 2), $avg),
			'percent' => array_map(static fn ($v): int => (int) min(100, round((float) $v * 100 / max(1, $cores))), $avg),
		];
	}

	/**
	 * @return array<string, int>
	 */
	public static function memory(): array {
		$m = [];
		foreach (explode("\n", Util::read('/proc/meminfo') ?? '') as $line) {
			if (preg_match('/^(\w+):\s+(\d+)/', $line, $x) === 1) {
				$m[$x[1]] = (int) $x[2] * 1024;
			}
		}
		$total = $m['MemTotal'] ?? 0;
		$used = $total - ($m['MemAvailable'] ?? $m['MemFree'] ?? 0);
		$swapTotal = $m['SwapTotal'] ?? 0;
		$swapUsed = $swapTotal - ($m['SwapFree'] ?? 0);
		return [
			'total'        => $total,
			'used'         => $used,
			'percent'      => $total > 0 ? (int) round($used * 100 / $total) : 0,
			'swap_total'   => $swapTotal,
			'swap_used'    => $swapUsed,
			'swap_percent' => $swapTotal > 0 ? (int) round($swapUsed * 100 / $swapTotal) : 0,
		];
	}

	/**
	 * Physical interfaces that are up or have an address (Docker bridges skipped).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function network(): array {
		$addresses = function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : [];
		$list = [];
		foreach (glob('/sys/class/net/*') ?: [] as $path) {
			$name = basename($path);
			if (preg_match(self::SKIP_INTERFACES, $name) === 1) {
				continue;
			}
			$ip = null;
			foreach ($addresses[$name]['unicast'] ?? [] as $addr) {
				if (($addr['family'] ?? 0) === 2 && isset($addr['address'])) {
					$ip = $addr['address'];
					break;
				}
			}
			$up = trim(Util::read($path . '/operstate') ?? '') === 'up';
			if (!$up && $ip === null) {
				continue;
			}
			$list[] = [
				'name'  => $name,
				'ip'    => $ip,
				'up'    => $up,
				'speed' => max(0, (int) trim(Util::read($path . '/speed') ?? '0')),
				'rx'    => (int) trim(Util::read($path . '/statistics/rx_bytes') ?? '0'),
				'tx'    => (int) trim(Util::read($path . '/statistics/tx_bytes') ?? '0'),
			];
		}
		return $list;
	}

	/**
	 * Interface carrying the default route, for the traffic history.
	 */
	public static function defaultInterface(): ?string {
		foreach (array_slice(explode("\n", Util::read('/proc/net/route') ?? ''), 1) as $line) {
			$cols = preg_split('/\s+/', trim($line));
			if (is_array($cols) && count($cols) > 1 && $cols[1] === '00000000') {
				return $cols[0];
			}
		}
		return null;
	}

	/**
	 * TCP check of each configured service.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function services(Config $config): array {
		$list = [];
		foreach ((array) $config->get('services', []) as $svc) {
			if (!is_array($svc) || !isset($svc['name'], $svc['port'])) {
				continue;
			}
			$list[] = [
				'name'      => (string) $svc['name'],
				'port'      => (int) $svc['port'],
				'url'       => isset($svc['url']) ? (string) $svc['url'] : null,
				'container' => isset($svc['container']) ? (string) $svc['container'] : null,
				'unit'      => isset($svc['unit']) ? (string) $svc['unit'] : null,
				'up'        => self::portOpen((string) ($svc['host'] ?? '127.0.0.1'), (int) $svc['port']),
			];
		}
		return $list;
	}

	private static function portOpen(string $host, int $port): bool {
		set_error_handler(static fn (): bool => true);
		try {
			$socket = stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 1.0);
		} finally {
			restore_error_handler();
		}
		if ($socket === false) {
			return false;
		}
		fclose($socket);
		return true;
	}
}
