<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * Settings from a PHP file returning an array, merged over the defaults below.
 */
final class Config {
	public const SYSTEM_PATH = '/etc/srv-console/config.php';

	private const DEFAULTS = [
		'title'    => 'Home Server',
		'language' => 'auto',
		'refresh'  => 15,
		'data_dir' => '/var/lib/srv-console',
		'demo'     => false,
		'services' => [],
		'actions'  => [
			'enabled'    => false,
			'containers' => 'all',
			'units'      => [],
			'reboot'     => false,
		],
		'docker' => [
			'enabled' => true,
			'ignore'  => [],
		],
		'disks' => [
			'ignore' => [],
			'labels' => [],
		],
		'smart' => [
			'enabled' => true,
			'devices' => [],
			'exclude' => [],
		],
		'backup' => [
			'status_file'   => null,
			'max_age_hours' => 48,
		],
		'certificates' => [
			'path'      => '/etc/letsencrypt/live',
			'warn_days' => 14,
			'crit_days' => 7,
		],
		'updates' => [
			'enabled' => true,
		],
		'logins' => [
			'enabled' => true,
			'max'     => 5,
		],
		'thresholds' => [
			'disk_warn' => 90,
			'disk_crit' => 97,
			'temp_warn' => 80,
			'temp_crit' => 95,
		],
	];

	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $values
	 */
	private function __construct(array $values) {
		$this->values = $values;
	}

	/**
	 * Load a config file. Without an explicit path, the file is looked up and
	 * SRV_CONSOLE_* environment variables apply; with one, the environment is ignored.
	 */
	public static function load(?string $path = null): self {
		$explicit = $path !== null;
		$path ??= self::locate();
		$user = [];
		if ($path !== null && is_file($path)) {
			$loaded = (static fn (string $file): mixed => require $file)($path);
			if (is_array($loaded)) {
				$user = $loaded;
			}
		}
		$values = self::merge(self::DEFAULTS, $user);
		if (!$explicit) {
			if (getenv('SRV_CONSOLE_DEMO') === '1') {
				$values['demo'] = true;
			}
			$dir = getenv('SRV_CONSOLE_DATA_DIR');
			if (is_string($dir) && $dir !== '') {
				$values['data_dir'] = $dir;
			}
		}
		return new self($values);
	}

	/**
	 * Copy of this config with some values overridden.
	 *
	 * @param array<string, mixed> $override
	 */
	public function with(array $override): self {
		return new self(self::merge($this->values, $override));
	}

	/**
	 * Read a value by dotted path, e.g. "actions.enabled".
	 */
	public function get(string $path, mixed $default = null): mixed {
		$node = $this->values;
		foreach (explode('.', $path) as $key) {
			if (!is_array($node) || !array_key_exists($key, $node)) {
				return $default;
			}
			$node = $node[$key];
		}
		return $node;
	}

	private static function locate(): ?string {
		$env = getenv('SRV_CONSOLE_CONFIG');
		if (is_string($env) && $env !== '') {
			return $env;
		}
		foreach ([self::SYSTEM_PATH, dirname(__DIR__) . '/config/config.php'] as $candidate) {
			if (is_file($candidate)) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Recursive merge where lists are replaced as a whole (so a user can empty one).
	 *
	 * @param array<mixed> $base
	 * @param array<mixed> $over
	 * @return array<mixed>
	 */
	private static function merge(array $base, array $over): array {
		foreach ($over as $key => $value) {
			$both = is_array($value) && isset($base[$key]) && is_array($base[$key]);
			if ($both && !array_is_list($value) && !array_is_list($base[$key])) {
				$base[$key] = self::merge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}
}
