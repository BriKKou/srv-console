<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * JSON responses for the API, with the security headers every endpoint needs.
 */
final class Http {
	/**
	 * Send a JSON response and stop.
	 */
	public static function json(int $status, mixed $data): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	/**
	 * Answer 405 unless the request uses the expected method.
	 */
	public static function requireMethod(string $method): void {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
			header('Allow: ' . $method);
			self::json(405, ['ok' => false, 'error' => 'method not allowed']);
		}
	}
}
