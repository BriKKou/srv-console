<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * Turns the state into a list of alerts. Texts live in the i18n files, keyed
 * by "alert.<key>"; the UI fills in the params.
 */
final class Alerts {
	private const ORDER = ['err' => 0, 'warn' => 1, 'info' => 2];

	/**
	 * @param array<string, mixed> $state
	 * @return list<array{level: string, key: string, subject: ?string, params: array<string, mixed>}>
	 */
	public static function compute(Config $config, array $state): array {
		$alerts = [];
		$add = static function (string $level, string $key, ?string $subject = null, array $params = []) use (&$alerts): void {
			$alerts[] = ['level' => $level, 'key' => $key, 'subject' => $subject, 'params' => $params];
		};
		$th = (array) $config->get('thresholds', []);

		foreach ($state['services'] ?? [] as $svc) {
			if (!$svc['up']) {
				$add('err', 'service_down', $svc['name'], ['port' => $svc['port']]);
			}
		}

		$ignored = (array) $config->get('docker.ignore', []);
		foreach ($state['docker']['containers'] ?? [] as $c) {
			if (in_array($c['name'], $ignored, true)) {
				continue;
			}
			if ($c['state'] !== 'running') {
				$add(in_array($c['state'], ['restarting', 'dead'], true) ? 'err' : 'warn', 'container_down', $c['name'], ['state' => $c['state']]);
			} elseif ($c['health'] === 'unhealthy') {
				$add('err', 'container_unhealthy', $c['name']);
			}
		}

		foreach ($state['disks'] ?? [] as $d) {
			if ($d['percent'] >= ($th['disk_warn'] ?? 90)) {
				$level = $d['percent'] >= ($th['disk_crit'] ?? 97) ? 'err' : 'warn';
				$add($level, 'disk_full', $d['label'] ?? $d['mount'], ['percent' => $d['percent'], 'free' => $d['avail']]);
			}
		}

		foreach ($state['smart'] ?? [] as $s) {
			if ($s['passed'] === false) {
				$add('err', 'smart_failed', $s['label']);
			}
		}

		$backup = $state['backup'] ?? null;
		if (is_array($backup)) {
			if (!$backup['present']) {
				$add('warn', 'backup_missing');
			} elseif (!$backup['ok']) {
				$add('err', 'backup_failed', null, ['detail' => $backup['detail'] ?? '']);
			} elseif ($backup['last_success'] === null || time() - $backup['last_success'] > 3600 * (int) $config->get('backup.max_age_hours', 48)) {
				$add('warn', 'backup_old', null, ['since' => $backup['last_success']]);
			}
		}

		foreach ($state['certs'] ?? [] as $cert) {
			if ($cert['days'] < (int) $config->get('certificates.warn_days', 14)) {
				$level = $cert['days'] < (int) $config->get('certificates.crit_days', 7) ? 'err' : 'warn';
				$add($level, 'cert_expiring', $cert['name'], ['days' => $cert['days']]);
			}
		}

		$updates = $state['updates'] ?? null;
		if (is_array($updates)) {
			if (($updates['security'] ?? 0) > 0) {
				$add('warn', 'updates_security', null, ['count' => $updates['security']]);
			}
			if ($updates['reboot_required'] ?? false) {
				$add('info', 'reboot_required');
			}
		}

		$temp = $state['cpu']['temp'] ?? null;
		if ($temp !== null && $temp >= ($th['temp_warn'] ?? 80)) {
			$add($temp >= ($th['temp_crit'] ?? 95) ? 'err' : 'warn', 'temp_high', null, ['temp' => $temp]);
		}

		$at = $state['collector']['at'] ?? null;
		if ($at === null) {
			$add('warn', 'collector_missing');
		} elseif (time() - $at > 300) {
			$add('warn', 'collector_stale', null, ['seconds' => time() - $at]);
		}

		usort($alerts, static fn (array $a, array $b): int => self::ORDER[$a['level']] <=> self::ORDER[$b['level']]);
		return $alerts;
	}
}
