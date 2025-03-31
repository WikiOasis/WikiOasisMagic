<?php

namespace WikiOasis\WikiOasisMagic\HookHandlers;

use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterShouldFilterActionHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\MimeMagicInitHook;
use MediaWiki\Hook\SiteNoticeAfterHook;
use MediaWiki\Hook\SkinAddFooterLinksHook;
use MediaWiki\Html\Html;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Hook\TitleReadWhitelistHook;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Memcached;
use MessageCache;
use Miraheze\CreateWiki\Hooks\CreateWikiStatePrivateHook;
use Miraheze\CreateWiki\Hooks\CreateWikiTablesHook;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;
use Miraheze\ImportDump\Hooks\ImportDumpJobGetFileHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobAfterImportHook;
use Redis;
use Skin;
use Throwable;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILBFactory;

class Main implements
    AbuseFilterShouldFilterActionHook,
    ContributionsToolLinksHook,
    CreateWikiStatePrivateHook,
    CreateWikiTablesHook,
    GetLocalURL__InternalHook,
    ImportDumpJobGetFileHook,
    ImportDumpJobAfterImportHook,
    MessageCacheFetchOverridesHook,
    MimeMagicInitHook,
    SiteNoticeAfterHook,
    SkinAddFooterLinksHook,
    TitleReadWhitelistHook
{

    /** @var ServiceOptions */
    private $options;

    /** @var CommentStore */
    private $commentStore;

    /** @var ILBFactory */
    private $dbLoadBalancerFactory;

    /** @var HttpRequestFactory */
    private $httpRequestFactory;

    /**
     * @param ServiceOptions $options
     * @param CommentStore $commentStore
     * @param ILBFactory $dbLoadBalancerFactory
     * @param HttpRequestFactory $httpRequestFactory
     */
    public function __construct(
        ServiceOptions $options,
        CommentStore $commentStore,
        ILBFactory $dbLoadBalancerFactory,
        HttpRequestFactory $httpRequestFactory
    ) {
        $this->options = $options;
        $this->commentStore = $commentStore;
        $this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
        $this->httpRequestFactory = $httpRequestFactory;
    }

    /**
     * @param Config $mainConfig
     * @param CommentStore $commentStore
     * @param ILBFactory $dbLoadBalancerFactory
     * @param HttpRequestFactory $httpRequestFactory
     *
     * @return self
     */
    public static function factory(
        Config $mainConfig,
        CommentStore $commentStore,
        ILBFactory $dbLoadBalancerFactory,
        HttpRequestFactory $httpRequestFactory
    ): self {
        return new self(
            new ServiceOptions(
                [
                    'ArticlePath',
                    'CreateWikiCacheDirectory',
                    'CreateWikiGlobalWiki',
                    'EchoSharedTrackingDB',
                    'JobTypeConf',
                    'LanguageCode',
                    'LocalDatabases',
                    'ManageWikiSettings',
                    'WikiOasisMagicMemcachedServers',
                    'Script',
                ],
                $mainConfig
            ),
            $commentStore,
            $dbLoadBalancerFactory,
            $httpRequestFactory
        );
    }

    /**
     * Avoid filtering automatic account creation
     *
     * @param VariableHolder $vars
     * @param Title $title
     * @param User $user
     * @param array &$skipReasons
     * @return bool|void
     */
    public function onAbuseFilterShouldFilterAction(
        VariableHolder $vars,
        Title $title,
        User $user,
        array &$skipReasons
    ) {
        if (defined('MW_PHPUNIT_TEST')) {
            return;
        }

        $varManager = AbuseFilterServices::getVariablesManager();

        $action = $varManager->getVar($vars, 'action', 1)->toString();
        if ($action === 'autocreateaccount') {
            $skipReasons[] = 'Blocking automatic account creation is not allowed';

            return false;
        }
    }

    public function onCreateWikiStatePrivate(string $dbname): void
    {
        $dir = "/var/www/mediawiki/sitemaps/{$dbname}";
        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
            wfDebugLog('WikiOasisMagic', "Directory {$dir} has been deleted.");
        } else {
            wfDebugLog('WikiOasisMagic', "Directory {$dir} does not exist.");
        }
    }

    private function deleteDirectory(string $dir): void {
        if (!file_exists($dir)) {
            return;
        }
    
        if (!is_dir($dir) || is_link($dir)) {
            unlink($dir);
            return;
        }
    
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item);
        }
    
        rmdir($dir);
    }

    public function onCreateWikiTables(array &$cTables): void
    {
        $cTables['localnames'] = 'ln_wiki';
        $cTables['localuser'] = 'lu_wiki';
    }

    public function onImportDumpJobGetFile(&$filePath, $importDumpRequestManager): void
    {
        wfDebugLog('WikiOasisMagic', "Importing dump from {$filePath}");
        $originalFilePath = $importDumpRequestManager->getSplitFilePath();

        if ($originalFilePath === null) {
            return;
        }

        wfDebugLog('WikiOasisMagic', "Importing dump from {$originalFilePath} to {$filePath}");

        // copy $originalFilePath to $filePath file
        if (!copy('/var/www/mediawiki/images/metawiki/' . $originalFilePath, $filePath)) {
            throw new RuntimeException("Failed to copy $originalFilePath to $filePath");
        }

        wfDebugLog('WikiOasisMagic', "Importing dump from {$originalFilePath} to {$filePath} done");
    }

    public function onImportDumpJobAfterImport($filePath, $importDumpRequestManager): void
    {
        $limits = ['memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0];
        Shell::command('/bin/rm', $filePath)
            ->limits($limits)
            ->disableSandbox()
            ->execute();
    }

    /**
     * From WikimediaMessages
     * When core requests certain messages, change the key to a Miraheze version.
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
     * @param string[] &$keys
     */
    public function onMessageCacheFetchOverrides(array &$keys): void
    {
        static $keysToOverride = [
        /*'centralauth-groupname',
                 'centralauth-login-error-locked',
                 'createwiki-close-email-body',
                 'createwiki-close-email-sender',
                 'createwiki-close-email-subject',
                 'createwiki-defaultmainpage',
                 'createwiki-defaultmainpage-summary',
                 'createwiki-email-body',
                 'createwiki-email-subject',
                 'createwiki-error-subdomaintaken',
                 'createwiki-help-bio',
                 'createwiki-help-category',
                 'createwiki-help-reason',
                 'createwiki-help-subdomain',
                 'createwiki-label-reason',
                 'dberr-again',
                 'dberr-problems',
                 'globalblocking-ipblocked-range',
                 'globalblocking-ipblocked-xff',
                 'globalblocking-ipblocked',*/
        'grouppage-autoconfirmed',
        'grouppage-automoderated',
        'grouppage-autoreview',
        'grouppage-blockedfromchat',
        'grouppage-bot',
        'grouppage-bureaucrat',
        'grouppage-chatmod',
        'grouppage-checkuser',
        'grouppage-commentadmin',
        'grouppage-csmoderator',
        'grouppage-editor',
        'grouppage-flow-bot',
        'grouppage-interface-admin',
        'grouppage-moderator',
        'grouppage-no-ipinfo',
        'grouppage-reviewer',
        'grouppage-suppress',
        'grouppage-sysop',
        'grouppage-upwizcampeditors',
        'grouppage-user',
        /*'importdump-help-reason',
                 'importdump-help-target',
                 'importdump-help-upload-file',
                 'importdump-import-failed-comment',
                 'importtext',
                 'interwiki_intro',
                 'newsignuppage-loginform-tos',
                 'newsignuppage-must-accept-tos',
                 'oathauth-step1',
                 'prefs-help-realname',
                 'privacypage',
                 'requestwiki-error-invalidcomment',
                 'requestwiki-info-guidance',
                 'requestwiki-info-guidance-post',
                 'requestwiki-label-agreement',
                 'requestwiki-success',
                 'restriction-delete',
                 'restriction-protect',
                 'skinname-snapwikiskin',
                 'snapwikiskin',
                 'uploadtext',
                 'webauthn-module-description',
                 'wikibase-sitelinks-miraheze',*/
        ];

        $languageCode = $this->options->get(MainConfigNames::LanguageCode);

        $transformationCallback = static function (string $key, MessageCache $cache) use ($languageCode): string {
            $transformedKey = "wikioasis-$key";

            // MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
            // of the above.  Revisit if non-ASCII keys are used.
            $ucKey = ucfirst($key);

            if (
                /*
                 * Override order:
                 * 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
                 * (in all languages) with normal fallback order.  Specific
                 * language pages (MediaWiki:$ucKey/xy) are not checked when
                 * deciding which key to use, but are still used if applicable
                 * after the key is decided.
                 *
                 * 2. Otherwise, use the prefixed key with normal fallback order
                 * (including MediaWiki pages if they exist).
                 */
                $cache->getMsgFromNamespace($ucKey, $languageCode) === false
            ) {
                return $transformedKey;
            }

            return $key;
        };

        foreach ($keysToOverride as $key) {
            $keys[$key] = $transformationCallback;
        }
    }

    public function onTitleReadWhitelist($title, $user, &$whitelisted)
    {
        if ($title->equals(Title::newMainPage())) {
            $whitelisted = true;
            return;
        }

        $specialsArray = [
            'CentralAutoLogin',
            'CentralLogin',
            'ConfirmEmail',
            'CreateAccount',
            'Notifications',
            'OAuth',
            'ResetPassword'
        ];

        if ($user->isAllowed('interwiki')) {
            $specialsArray[] = 'Interwiki';
        }

        if ($title->isSpecialPage()) {
            $rootName = strtok($title->getText(), '/');
            $rootTitle = Title::makeTitle($title->getNamespace(), $rootName);

            foreach ($specialsArray as $page) {
                if ($rootTitle->equals(SpecialPage::getTitleFor($page))) {
                    $whitelisted = true;
                    return;
                }
            }
        }
    }

    public function onGlobalUserPageWikis(array &$list): bool
    {
        $cwCacheDir = $this->options->get('CreateWikiCacheDirectory');

        if (file_exists("{$cwCacheDir}/databases.php")) {
            $databasesArray = include "{$cwCacheDir}/databases.php";

            $dbList = array_keys($databasesArray['databases'] ?? []);

            // Filter out those databases that don't have GlobalUserPage enabled
            $list = array_filter($dbList, static function ($dbname) {
                $extensions = new ManageWikiExtensions($dbname);
                return in_array('globaluserpage', $extensions->list());
            });

            return false;
        }

        return true;
    }

    public function onMimeMagicInit($mimeMagic)
    {
        $mimeMagic->addExtraTypes('text/plain txt off');
    }

    public function onSkinAddFooterLinks(Skin $skin, string $key, array &$footerItems)
    {
        /*if ( $key === 'places' ) {
                  $footerItems['termsofservice'] = $this->addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );
                  $footerItems['donate'] = $this->addFooterLink( $skin, 'miraheze-donate', 'miraheze-donatepage' );
              }*/
    }

    public function onSiteNoticeAfter(&$siteNotice, $skin)
    {
        /*$cwConfig = new GlobalVarConfig( 'cw' );

              if ( $cwConfig->get( 'Closed' ) ) {
                  if ( $cwConfig->get( 'Private' ) ) {
                      $siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
                  } elseif ( $cwConfig->get( 'Locked' ) ) {
                      $siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-locked' )->parse() . '</span></div>';
                  } else {
                      $siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed' )->parse() . '</span></div>';
                  }
              } elseif ( $cwConfig->get( 'Inactive' ) && $cwConfig->get( 'Inactive' ) !== 'exempt' ) {
                  if ( $cwConfig->get( 'Private' ) ) {
                      $siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive-private' )->parse() . '</span></div>';
                  } else {
                      $siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive' )->parse() . '</span></div>';
                  }
              }*/
    }

    public function onContributionsToolLinks($id, Title $title, array &$tools, SpecialPage $specialPage)
    {
        $username = $title->getText();

        if (!IPUtils::isIPAddress($username)) {
            $globalUserGroups = CentralAuthUser::getInstanceByName($username)->getGlobalGroups();

            if (
                !in_array('steward', $globalUserGroups) &&
                !in_array('global-sysop', $globalUserGroups) &&
                !$specialPage->getUser()->isAllowed('centralauth-lock')
            ) {
                return;
            }

            $tools['centralauth'] = Linker::makeExternalLink(
                'https://meta.wikioasis.org/wiki/Special:CentralAuth/' . $username,
                strtolower($specialPage->msg('centralauth')->text())
            );
        }
    }

    /**
     * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
     *
     * @param Title $title
     * @param string &$url
     * @param string $query
     */
    public function onGetLocalURL__Internal($title, &$url, $query)
    {
        // phpcs:enable

        if (defined('MW_PHPUNIT_TEST')) {
            return;
        }

        // If the URL contains wgScript, rewrite it to use wgArticlePath
        if (str_contains($url, $this->options->get(MainConfigNames::Script))) {
            $dbkey = wfUrlencode($title->getPrefixedDBkey());
            $url = str_replace('$1', $dbkey, $this->options->get(MainConfigNames::ArticlePath));
            if ($query !== '') {
                $url = wfAppendQuery($url, $query);
            }
        }
    }

    private function addFooterLink($skin, $desc, $page)
    {
        if ($skin->msg($desc)->inContentLanguage()->isDisabled()) {
            $title = null;
        } else {
            $title = Title::newFromText($skin->msg($page)->inContentLanguage()->text());
        }

        if (!$title) {
            return '';
        }

        return Html::element(
            'a',
            ['href' => $title->fixSpecialName()->getLinkURL()],
            $skin->msg($desc)->text()
        );
    }

    /** Removes redis keys for jobrunner */
    private function removeRedisKey(string $key)
    {
        $jobTypeConf = $this->options->get(MainConfigNames::JobTypeConf);
        if (!isset($jobTypeConf['default']['redisServer']) || !$jobTypeConf['default']['redisServer']) {
            return;
        }

        $hostAndPort = IPUtils::splitHostAndPort($jobTypeConf['default']['redisServer']);

        if ($hostAndPort) {
            try {
                $redis = new Redis();
                $redis->connect($hostAndPort[0], $hostAndPort[1]);
                $redis->auth($jobTypeConf['default']['redisConfig']['password']);
                $redis->del($redis->keys($key));
            } catch (Throwable $ex) {
                // empty
            }
        }
    }

    /** Remove memcached keys */
    private function removeMemcachedKey(string $key)
    {
        $memcachedServers = $this->options->get('WikiOasisMemcachedServers');

        try {
            foreach ($memcachedServers as $memcachedServer) {
                $memcached = new Memcached();

                $memcached->addServer($memcachedServer[0], (string) $memcachedServer[1]);

                // Fetch all keys
                $keys = $memcached->getAllKeys();
                if (!is_array($keys)) {
                    return;
                }

                foreach ($keys as $item) {
                    // Decide which keys to delete
                    if (preg_match("/{$key}/", $item)) {
                        $memcached->delete($item);
                    } else {
                        continue;
                    }
                }
            }
        } catch (Throwable $ex) {
            // empty
        }
    }
}