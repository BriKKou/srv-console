<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * The single JSON document the dashboard renders: live readings plus the
 * collector's last snapshot, with labels, permissions and alerts applied.
 */
final class State {
	/**
	 * @return array<string, mixed>
	 */
	public static function build(Config $config): array {
		$cpu = Metrics::cpu();
		$live = [
			'system'   => Metrics::system(),
			'cpu'      => $cpu,
			'load'     => Metrics::load((int) $cpu['cores']),
			'memory'   => Metrics::memory(),
			'network'  => Metrics::network(),
			'services' => Metrics::services($config),
		];
		$status = Util::readJson(rtrim((string) $config->get('data_dir'), '/') . '/status.json');
		return self::assemble($config, $live, $status);
	}

	/**
	 * Shared by the real state and the demo.
	 *
	 * @param array<string, mixed>      $live
	 * @param array<string, mixed>|null $status
	 * @return array<string, mixed>
	 */
	public static function assemble(Config $config, array $live, ?array $status): array {
		$status ??= [];
		$containers = $status['docker']['containers'] ?? [];
		$caps = Actions::capabilities($config, $containers);

		$services = [];
		foreach ($live['services'] as $svc) {
			$target = null;
			if ($svc['container'] !== null && in_array($svc['container'], $caps['containers'], true)) {
				$target = $svc['container'];
			} elseif ($svc['unit'] !== null && in_array($svc['unit'], $caps['units'], true)) {
				$target = $svc['unit'];
			}
			$services[] = ['name' => $svc['name'], 'port' => $svc['port'], 'url' => $svc['url'], 'up' => $svc['up'], 'target' => $target];
		}

		$labels = (array) $config->get('disks.labels', []);
		$disks = [];
		foreach ($status['disks'] ?? [] as $d) {
			$disks[] = $d + ['label' => isset($labels[$d['mount']]) ? (string) $labels[$d['mount']] : null];
		}

		$docker = null;
		if (isset($status['docker']) && is_array($status['docker'])) {
			$rows = [];
			foreach ($containers as $c) {
				$rows[] = $c + ['restartable' => in_array($c['name'], $caps['containers'], true)];
			}
			$docker = ['available' => (bool) $status['docker']['available'], 'containers' => $rows];
		}

		$state = [
			'version'      => SC_VERSION,
			'time'         => time(),
			'title'        => (string) $config->get('title'),
			'demo'         => (bool) $config->get('demo', false),
			'system'       => $live['system'] + ['sessions' => $status['sessions'] ?? null],
			'cpu'          => $live['cpu'],
			'load'         => $live['load'],
			'memory'       => $live['memory'],
			'network'      => $live['network'],
			'services'     => $services,
			'disks'        => $disks,
			'docker'       => $docker,
			'smart'        => $status['smart'] ?? null,
			'certs'        => $status['certs'] ?? null,
			'updates'      => $status['updates'] ?? null,
			'backup'       => $status['backup'] ?? null,
			'logins'       => $status['logins'] ?? null,
			'collector'    => ['at' => $status['collected_at'] ?? null],
			'capabilities' => ['actions' => $caps['actions'], 'reboot' => $caps['reboot']],
		];
		$state['alerts'] = Alerts::compute($config, $state);
		return $state;
	}
}
