<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SrvConsole\Actions;
use SrvConsole\Config;
use SrvConsole\Http;
use SrvConsole\Util;

Http::requireMethod('POST');

// A custom header can't be sent cross-site without a CORS preflight we never answer
if (($_SERVER['HTTP_X_CONSOLE_ACTION'] ?? '') !== '1') {
	Http::json(403, ['ok' => false, 'error' => 'missing X-Console-Action header']);
}
if (!str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
	Http::json(415, ['ok' => false, 'error' => 'expected application/json']);
}
$input = json_decode((string) file_get_contents('php://input'), true);
$verb = is_array($input) ? ($input['verb'] ?? null) : null;
$target = is_array($input) ? ($input['target'] ?? null) : null;
if (!is_string($verb) || !is_string($target)) {
	Http::json(400, ['ok' => false, 'error' => 'expected {"verb": string, "target": string}']);
}

$config = Config::load();
if ($config->get('demo')) {
	Http::json(200, ['ok' => true, 'message' => 'demo mode, nothing was done']);
}

$status = Util::readJson(rtrim((string) $config->get('data_dir'), '/') . '/status.json');
$known = array_column($status['docker']['containers'] ?? [], 'name');
$decision = Actions::resolve($config, $verb, $target, $known);
if (isset($decision['error'])) {
	Http::json(403, ['ok' => false, 'error' => $decision['error']]);
}

// The root helper checks everything again against live data
[$out, $err, $code] = Util::run(['sudo', '-n', dirname(__DIR__, 2) . '/bin/srv-console-action', $verb, $target], 90);
if ($code !== 0) {
	Http::json(500, ['ok' => false, 'error' => trim($err !== '' ? $err : $out)]);
}
Http::json(200, ['ok' => true, 'message' => trim($out)]);
