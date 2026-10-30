<?php
declare(strict_types=1);

namespace SrvConsole;

/**
 * Language choice and UI strings (public/assets/i18n/*.json).
 */
final class I18n {
	public const SUPPORTED = ['en', 'fr'];
	public const COOKIE = 'srv_console_lang';

	/**
	 * Fixed language from the config, else the visitor's cookie, else the browser.
	 */
	public static function resolve(Config $config, ?string $cookie, ?string $acceptLanguage): string {
		$fixed = $config->get('language', 'auto');
		if (in_array($fixed, self::SUPPORTED, true)) {
			return $fixed;
		}
		if ($cookie !== null && in_array($cookie, self::SUPPORTED, true)) {
			return $cookie;
		}
		foreach (explode(',', (string) $acceptLanguage) as $part) {
			$primary = strtolower(explode('-', trim(explode(';', $part)[0]))[0]);
			if (in_array($primary, self::SUPPORTED, true)) {
				return $primary;
			}
		}
		return 'en';
	}

	/**
	 * Strings for a language, English filling any missing key.
	 *
	 * @return array<string, string>
	 */
	public static function strings(string $lang): array {
		$dir = dirname(__DIR__) . '/public/assets/i18n/';
		$base = Util::readJson($dir . 'en.json') ?? [];
		$local = $lang === 'en' ? [] : (Util::readJson($dir . $lang . '.json') ?? []);
		return array_merge($base, $local);
	}
}
