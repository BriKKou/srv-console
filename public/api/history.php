<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SrvConsole\Config;
use SrvConsole\Demo;
use SrvConsole\Http;
use SrvConsole\Util;

Http::requireMethod('GET');
$config = Config::load();
if ($config->get('demo')) {
	Http::json(200, Demo::history());
}
$history = Util::readJson(rtrim((string) $config->get('data_dir'), '/') . '/history.json');
Http::json(200, is_array($history) && array_is_list($history) ? $history : []);
