<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SrvConsole\Config;
use SrvConsole\I18n;

$config = Config::load();
$lang = I18n::resolve($config, $_COOKIE[I18n::COOKIE] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
$strings = I18n::strings($lang);
$title = $config->get('demo') ? 'Home Server' : (string) $config->get('title');
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn (string $key): string => $e($strings[$key] ?? $key);
$boot = [
	'lang'    => $lang,
	'refresh' => max(5, (int) $config->get('refresh', 15)),
	'switch'  => !in_array($config->get('language'), I18n::SUPPORTED, true),
	'strings' => $strings,
];
$v = rawurlencode(SC_VERSION);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?= $e($title) ?> · Console</title>
	<link rel="icon" type="image/svg+xml" href="assets/icon.svg?v=<?= $v ?>">
	<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
	<script src="assets/theme.js?v=<?= $v ?>"></script>
	<script src="assets/app.js?v=<?= $v ?>" defer></script>
	<script type="application/json" id="boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>

<svg class="sc-sprite" aria-hidden="true">
	<defs>
		<symbol id="i-terminal" viewBox="0 0 24 24"><path d="m4 17 6-6-6-6M12 19h8"/></symbol>
		<symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
		<symbol id="i-activity" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></symbol>
		<symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/></symbol>
		<symbol id="i-disk" viewBox="0 0 24 24"><path d="M22 12H2M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/><path d="M6 16h.01M10 16h.01"/></symbol>
		<symbol id="i-net" viewBox="0 0 24 24"><rect x="9" y="2" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M5 16v-3h14v3M12 13V8"/></symbol>
		<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
		<symbol id="i-server" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 6h.01M6 18h.01"/></symbol>
		<symbol id="i-cpu" viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/></symbol>
		<symbol id="i-mem" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="10" rx="2"/><path d="M6 11v2M10 11v2M14 11v2M18 11v2M6 17v3M18 17v3"/></symbol>
		<symbol id="i-temp" viewBox="0 0 24 24"><path d="M14 14.76V4a2 2 0 0 0-4 0v10.76a4 4 0 1 0 4 0z"/></symbol>
		<symbol id="i-check" viewBox="0 0 24 24"><path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m22 4-10 10-3-3"/></symbol>
		<symbol id="i-archive" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/></symbol>
		<symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
		<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8M21 3v5h-5M3 21v-5h5"/></symbol>
		<symbol id="i-power" viewBox="0 0 24 24"><path d="M12 2v10M18.4 6.6a9 9 0 1 1-12.8 0"/></symbol>
		<symbol id="i-external" viewBox="0 0 24 24"><path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
		<symbol id="i-rotate" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5"/></symbol>
	</defs>
</svg>

<div class="sc-app">
	<aside class="sc-side">
		<a class="sc-brand" href="#top">
			<span class="sc-brand__mark"><svg class="ic"><use href="#i-terminal"/></svg></span>
			<span><?= $e($title) ?></span>
		</a>
		<nav class="sc-nav" aria-label="<?= $t('nav.label') ?>">
			<a href="#top" aria-current="page"><svg class="ic"><use href="#i-grid"/></svg><?= $t('nav.overview') ?></a>
			<a href="#services" data-section="services"><svg class="ic"><use href="#i-activity"/></svg><?= $t('nav.services') ?></a>
			<a href="#containers" data-section="containers"><svg class="ic"><use href="#i-box"/></svg><?= $t('nav.containers') ?></a>
			<a href="#storage" data-section="storage"><svg class="ic"><use href="#i-disk"/></svg><?= $t('nav.storage') ?></a>
			<a href="#network" data-section="network"><svg class="ic"><use href="#i-net"/></svg><?= $t('nav.network') ?></a>
			<a href="#journal" data-section="journal"><svg class="ic"><use href="#i-list"/></svg><?= $t('nav.journal') ?></a>
			<a href="#system" data-section="system"><svg class="ic"><use href="#i-server"/></svg><?= $t('nav.system') ?></a>
		</nav>
		<div class="sc-usage">
			<div class="sc-usage__head"><span><?= $t('side.disk') ?></span><span class="mono" id="side-disk">—</span></div>
			<div class="sc-meter"><i id="side-disk-bar"></i></div>
			<p class="sc-usage__sub" id="side-uptime">—</p>
		</div>
	</aside>

	<main class="sc-main" id="top">
		<header class="sc-top">
			<h1><span id="host">—</span> / Console</h1>
			<span class="sc-badge" id="global-badge"><i class="dot"></i><span>…</span></span>
			<div class="sc-top__right">
				<span class="sc-caption mono" id="updated"></span>
				<fieldset class="sc-seg" id="lang-switch" aria-label="<?= $t('header.language') ?>" hidden>
					<label><input type="radio" name="lang" value="en">EN</label>
					<label><input type="radio" name="lang" value="fr">FR</label>
				</fieldset>
				<fieldset class="sc-seg" aria-label="<?= $t('header.theme') ?>">
					<label><input type="radio" name="theme" value="light"><?= $t('header.light') ?></label>
					<label><input type="radio" name="theme" value="dark"><?= $t('header.dark') ?></label>
					<label><input type="radio" name="theme" value="system"><?= $t('header.system') ?></label>
				</fieldset>
				<button class="sc-btn sc-btn--icon" id="refresh-btn" type="button" title="<?= $t('header.refresh') ?>" aria-label="<?= $t('header.refresh') ?>"><svg class="ic"><use href="#i-refresh"/></svg></button>
				<button class="sc-btn sc-btn--danger" id="reboot-btn" type="button" hidden><svg class="ic"><use href="#i-power"/></svg><span class="sc-hide-sm"><?= $t('header.reboot') ?></span></button>
			</div>
		</header>

		<section id="alerts" class="sc-alerts" aria-live="polite"></section>

		<section class="sc-kpis">
			<article class="sc-card sc-kpi" id="kpi-cpu">
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-cpu"/></svg><?= $t('kpi.cpu') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<svg class="sc-spark" viewBox="0 0 240 32" preserveAspectRatio="none"></svg>
			</article>
			<article class="sc-card sc-kpi" id="kpi-ram">
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-mem"/></svg><?= $t('kpi.ram') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<svg class="sc-spark" viewBox="0 0 240 32" preserveAspectRatio="none"></svg>
			</article>
			<article class="sc-card sc-kpi" id="kpi-temp" hidden>
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-temp"/></svg><?= $t('kpi.temp') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<svg class="sc-spark" viewBox="0 0 240 32" preserveAspectRatio="none"></svg>
			</article>
			<article class="sc-card sc-kpi" id="kpi-services" hidden>
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-check"/></svg><?= $t('kpi.services') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<p class="sc-kpi__sub"></p>
			</article>
			<article class="sc-card sc-kpi" id="kpi-backup" hidden>
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-archive"/></svg><?= $t('kpi.backup') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<p class="sc-kpi__sub"></p>
			</article>
			<article class="sc-card sc-kpi" id="kpi-updates" hidden>
				<div class="sc-kpi__label"><svg class="ic"><use href="#i-download"/></svg><?= $t('kpi.updates') ?></div>
				<div class="sc-kpi__row"><span class="sc-kpi__value">—</span><span class="sc-badge"></span></div>
				<p class="sc-kpi__sub"></p>
			</article>
		</section>

		<section class="sc-split" id="split">
			<article class="sc-card sc-chart-card">
				<div class="sc-card__head">
					<h2><?= $t('chart.title') ?></h2>
					<div class="sc-legend"><span><i class="lg-cpu"></i><?= $t('chart.cpu') ?></span><span><i class="lg-ram"></i><?= $t('chart.ram') ?></span><span><i class="lg-temp"></i><?= $t('chart.temp') ?></span></div>
				</div>
				<div class="sc-chart">
					<div class="sc-chart__y mono"><span>100</span><span>75</span><span>50</span><span>25</span><span>0</span></div>
					<svg id="chart" viewBox="0 0 560 180" preserveAspectRatio="none" role="img" aria-label="<?= $t('chart.title') ?>"></svg>
				</div>
				<div class="sc-chart__x mono" id="chart-x"></div>
			</article>
			<article class="sc-card" id="services" hidden>
				<div class="sc-card__head"><h2><?= $t('services.title') ?></h2><span class="sc-caption" id="services-count"></span></div>
				<ul class="sc-svc" id="services-list"></ul>
			</article>
		</section>

		<section class="sc-card" id="containers" hidden>
			<div class="sc-card__head"><h2><?= $t('containers.title') ?></h2><span class="sc-caption" id="containers-count"></span></div>
			<div class="sc-table-wrap">
				<table class="sc-table">
					<thead><tr>
						<th><?= $t('containers.name') ?></th><th><?= $t('containers.version') ?></th><th><?= $t('containers.state') ?></th>
						<th><?= $t('containers.uptime') ?></th><th class="num">CPU</th><th class="num">RAM</th><th class="num"><span class="sc-sr"><?= $t('containers.actions') ?></span></th>
					</tr></thead>
					<tbody id="containers-body"></tbody>
				</table>
			</div>
		</section>

		<section class="sc-grid2">
			<article class="sc-card" id="storage" hidden>
				<div class="sc-card__head"><h2><?= $t('storage.title') ?></h2></div>
				<div id="disks"></div>
				<div class="sc-smart" id="smart"></div>
			</article>
			<article class="sc-card" id="network">
				<div class="sc-card__head"><h2><?= $t('network.title') ?></h2><span class="sc-caption mono" id="net-caption"></span></div>
				<div id="net-list"></div>
				<svg class="sc-spark sc-spark--lg" id="net-spark" viewBox="0 0 240 48" preserveAspectRatio="none"></svg>
				<p class="sc-caption"><?= $t('network.history') ?></p>
			</article>
		</section>

		<section class="sc-card" id="journal">
			<div class="sc-card__head"><h2><?= $t('journal.title') ?></h2><span class="sc-caption"><?= $t('journal.sub') ?></span></div>
			<div class="sc-log mono" id="log"></div>
		</section>

		<section class="sc-grid3" id="system">
			<article class="sc-card">
				<div class="sc-card__head"><h2><?= $t('system.title') ?></h2></div>
				<dl class="sc-kv" id="sys-kv"></dl>
			</article>
			<article class="sc-card" id="certs-card" hidden>
				<div class="sc-card__head"><h2><?= $t('certs.title') ?></h2></div>
				<ul class="sc-list" id="certs"></ul>
			</article>
			<article class="sc-card" id="logins-card" hidden>
				<div class="sc-card__head"><h2><?= $t('logins.title') ?></h2></div>
				<ul class="sc-list" id="logins"></ul>
			</article>
		</section>

		<footer class="sc-caption" id="footer"></footer>
	</main>
</div>

<div class="sc-toast" id="toast" role="status"></div>

</body>
</html>
