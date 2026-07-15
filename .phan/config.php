<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$extensions = [
	'AbuseFilter',
	'CentralAuth',
	'CreateWiki',
	'Echo',
	'GlobalUserPage',
	'ImportDump',
	'ManageWiki',
];

foreach ( $extensions as $extension ) {
	$cfg['directory_list'][] = "../../extensions/$extension";
	$cfg['exclude_analysis_directory_list'][] = "../../extensions/$extension";
}

$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'],
	[
		'PhanAccessMethodInternal',
		'SecurityCheck-LikelyFalsePositive',
	]
);

$cfg['minimum_target_php_version'] = '8.1';
$cfg['allow_class_alias'] = false;

// CI's Phan/php-ast toolchain (Miraheze's quibble-bullseye-php84 image) misparses
// includes/HookHandlers/Main.php, reporting a bogus "unexpected token \"<<\""
// syntax error. Neither `php -l`, a freshly built `ast` extension, nor Phan's own
// polyfill parser reproduce this outside that specific container, so it isn't a
// real defect in the file - forcing the polyfill parser (tried first) still hits
// the same bogus error there. Exclude the file from parsing entirely until the
// underlying toolchain issue is understood/fixed upstream.
$cfg['exclude_file_list'][] = 'includes/HookHandlers/Main.php';

return $cfg;
