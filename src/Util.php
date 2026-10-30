<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * File and process helpers shared by the web app and the CLI tools.
 */
final class Util {
	/**
	 * Read a file, null on any failure (no warning leaks into the output).
	 */
	public static function read(string $path): ?string {
		set_error_handler(static fn (): bool => true);
		try {
			$data = file_get_contents($path);
		} finally {
			restore_error_handler();
		}
		return $data === false ? null : $data;
	}

	/**
	 * Decode a JSON file into an array, null if missing or invalid.
	 *
	 * @return array<mixed>|null
	 */
	public static function readJson(string $path): ?array {
		$raw = self::read($path);
		if ($raw === null) {
			return null;
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}

	/**
	 * Write JSON atomically (temp file then rename), world-readable.
	 */
	public static function writeJson(string $path, mixed $data): bool {
		$dir = dirname($path);
		if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
			return false;
		}
		$tmp = $path . '.tmp';
		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false || file_put_contents($tmp, $json) === false) {
			return false;
		}
		chmod($tmp, 0644);
		return rename($tmp, $path);
	}

	/**
	 * Run a command without a shell, killed after $timeout seconds.
	 *
	 * @param list<string> $cmd
	 * @return array{0: string, 1: string, 2: int} stdout, stderr, exit code
	 */
	public static function run(array $cmd, int $timeout = 20): array {
		$spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open(array_merge(['timeout', (string) $timeout], $cmd), $spec, $pipes);
		if (!is_resource($proc)) {
			return ['', '', -1];
		}
		$out = (string) stream_get_contents($pipes[1]);
		$err = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [$out, $err, proc_close($proc)];
	}
}
