<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

    /** @inheritDoc */
    public function onLoadExtensionSchemaUpdates( $updater ) {
        $dbType = $updater->getDB()->getType();
        $dir = __DIR__ . '/../../sql';

        $updater->addExtensionTable(
            'customdomain_requests',
            "$dir/$dbType/tables-generated.sql"
        );
    }
}
