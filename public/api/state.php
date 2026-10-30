<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SrvConsole\Config;
use SrvConsole\Demo;
use SrvConsole\Http;
use SrvConsole\State;

Http::requireMethod('GET');
$config = Config::load();
Http::json(200, $config->get('demo') ? Demo::state($config) : State::build($config));
