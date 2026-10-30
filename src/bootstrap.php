<?php
declare(strict_types=1);

const SC_VERSION = '1.0.0';

spl_autoload_register(static function (string $class): void {
	$prefix = 'SrvConsole\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
