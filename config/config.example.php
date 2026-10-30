<?php
/**
 * srv-console settings.
 *
 * Copy this file to /etc/srv-console/config.php (owner root, mode 0644) and edit it.
 * Every key is optional: whatever you leave out keeps the default shown here.
 */
return [
	// Name shown in the sidebar and in the browser tab.
	'title' => 'Home Server',

	// 'auto' follows the browser (English or French, with a switch in the header).
	// 'en' or 'fr' forces one language and hides the switch.
	'language' => 'auto',

	// Seconds between two refreshes of the page (5 minimum).
	'refresh' => 15,

	// Where the collector writes its snapshots.
	'data_dir' => '/var/lib/srv-console',

	// Services to check: a TCP connection to this port, made from the server itself.
	// Leave the list empty and the Services card disappears.
	// 'url'       adds an "open" button.
	// 'container' or 'unit' ties the line to a Docker container or a systemd unit,
	//             which is what makes its restart button work (see 'actions').
	'services' => [
		// ['name' => 'Web server', 'port' => 443, 'url' => 'https://example.com', 'unit' => 'nginx'],
		// ['name' => 'Git', 'port' => 3000, 'url' => 'https://git.example.com', 'container' => 'gitea'],
	],

	// Restart and reboot buttons. Everything is off until you turn it on here.
	// Read "Actions" in the README first: this is the part that can hurt.
	'actions' => [
		'enabled'    => false,
		'containers' => 'all',   // 'all', or a list such as ['gitea', 'wiki']
		'units'      => [],      // systemd units, e.g. ['nginx', 'ssh']
		'reboot'     => false,
	],

	'docker' => [
		'enabled' => true,
		'ignore'  => [],         // container names that should never raise an alert (one-shot jobs...)
	],

	'disks' => [
		// Mount points to hide. Shell-style patterns work: '/mnt/tmp*'.
		'ignore' => [],
		// Friendlier names: ['/' => 'System', '/srv/data' => 'Data'].
		'labels' => [],
	],

	// SMART health. By default every drive found by `smartctl --scan` is read.
	'smart' => [
		'enabled' => true,
		// Fixed list instead of the scan: [['label' => 'Boot SSD', 'device' => '/dev/nvme0', 'type' => 'nvme']]
		'devices' => [],
		// Drives to leave alone. Matched, case-insensitively, against the device path,
		// its /dev/disk/by-id names and the model read from sysfs.
		'exclude' => [],
	],

	// Status of your own backup job. See "Backup status" in the README for the file format.
	// Leave null and the backup tile and its alerts disappear.
	'backup' => [
		'status_file'   => null,   // e.g. '/var/lib/backup/status.json'
		'max_age_hours' => 48,
	],

	// Let's Encrypt certificates, read straight from the certbot folder.
	'certificates' => [
		'path'      => '/etc/letsencrypt/live',
		'warn_days' => 14,
		'crit_days' => 7,
	],

	// Pending apt updates and the "reboot required" flag (Debian and Ubuntu).
	'updates' => [
		'enabled' => true,
	],

	// Last logins, from `last`.
	'logins' => [
		'enabled' => true,
		'max'     => 5,
	],

	// When an alert turns orange (warn) or red (crit).
	'thresholds' => [
		'disk_warn' => 90,
		'disk_crit' => 97,
		'temp_warn' => 80,
		'temp_crit' => 95,
	],
];
