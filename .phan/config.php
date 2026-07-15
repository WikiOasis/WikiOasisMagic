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

// The CI's native `ast` extension build misparses includes/HookHandlers/Main.php,
// reporting a bogus "unexpected token \"<<\"" syntax error that php -l and Phan's
// own polyfill parser don't reproduce. Force the polyfill parser to avoid it.
$cfg['use_polyfill_parser'] = true;

return $cfg;
