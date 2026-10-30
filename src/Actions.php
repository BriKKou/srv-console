<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * What the restart and reboot buttons are allowed to do. Checked twice: by the
 * web endpoint, then again by the root helper against live data.
 */
final class Actions {
	private const NAME = '/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,127}$/';

	/**
	 * Targets the UI may offer, given the containers the collector saw.
	 *
	 * @param list<array<string, mixed>> $containers
	 * @return array{actions: bool, reboot: bool, containers: list<string>, units: list<string>}
	 */
	public static function capabilities(Config $config, array $containers): array {
		if (!$config->get('actions.enabled', false)) {
			return ['actions' => false, 'reboot' => false, 'containers' => [], 'units' => []];
		}
		$names = array_column($containers, 'name');
		$policy = $config->get('actions.containers', 'all');
		$allowed = $policy === 'all' ? $names : array_values(array_intersect((array) $policy, $names));
		return [
			'actions'    => true,
			'reboot'     => (bool) $config->get('actions.reboot', false),
			'containers' => array_values($allowed),
			'units'      => array_values(array_map('strval', (array) $config->get('actions.units', []))),
		];
	}

	/**
	 * Decide whether a request is allowed.
	 *
	 * @param list<string> $knownContainers container names that exist right now
	 * @return array{kind: string}|array{error: string}
	 */
	public static function resolve(Config $config, string $verb, string $target, array $knownContainers): array {
		if (!$config->get('actions.enabled', false)) {
			return ['error' => 'actions are disabled'];
		}
		if ($verb === 'reboot') {
			return $target === 'now' && $config->get('actions.reboot', false) ? ['kind' => 'reboot'] : ['error' => 'reboot is not allowed'];
		}
		if ($verb !== 'restart' || preg_match(self::NAME, $target) !== 1) {
			return ['error' => 'invalid request'];
		}
		if (in_array($target, array_map('strval', (array) $config->get('actions.units', [])), true)) {
			return ['kind' => 'unit'];
		}
		$policy = $config->get('actions.containers', 'all');
		$listed = $policy === 'all' || in_array($target, (array) $policy, true);
		if ($listed && in_array($target, $knownContainers, true)) {
			return ['kind' => 'container'];
		}
		return ['error' => 'target is not allowed'];
	}
}
