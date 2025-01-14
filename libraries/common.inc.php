<?php
/**
 * Misc stuff and REQUIRED by ALL the scripts.
 * MUST be included by every script
 *
 * Among other things, it contains the advanced authentication work.
 *
 * Order of sections for common.inc.php:
 *
 * the authentication libraries must be before the connection to db
 *
 * ... so the required order is:
 *
 * LABEL_variables_init
 *  - initialize some variables always needed
 * LABEL_parsing_config_file
 *  - parsing of the configuration file
 * LABEL_loading_language_file
 *  - loading language file
 * LABEL_setup_servers
 *  - check and setup configured servers
 * LABEL_theme_setup
 *  - setting up themes
 *
 * - load of MySQL extension (if necessary)
 * - loading of an authentication library
 * - db connection
 * - authentication work
 */

declare(strict_types=1);

use PhpMyAdmin\Config;
use PhpMyAdmin\Core;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\ErrorHandler;
use PhpMyAdmin\LanguageManager;
use PhpMyAdmin\Logging;
use PhpMyAdmin\Message;
use PhpMyAdmin\MoTranslator\Loader;
use PhpMyAdmin\Plugins;
use PhpMyAdmin\Profiling;
use PhpMyAdmin\Response;
use PhpMyAdmin\Routing;
use PhpMyAdmin\Session;
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\ThemeManager;
use PhpMyAdmin\Tracker;

global $containerBuilder, $errorHandler, $config, $server, $dbi;
global $lang, $cfg, $isConfigLoading, $auth_plugin, $route, $theme;
global $url_params, $goto, $back, $db, $table, $sql_query, $token_mismatch;

/**
 * block attempts to directly run this script
 */
if (getcwd() == __DIR__) {
    die('Attack stopped');
}

/**
 * Minimum PHP version; can't call Core::fatalError() which uses a
 * PHP 5 function, so cannot easily localize this message.
 */
if (PHP_VERSION_ID < 70205) {
    die(
        '<p>PHP 7.2.5+ is required.</p>'
        . '<p>Currently installed version is: ' . PHP_VERSION . '</p>'
    );
}

// phpcs:disable PSR1.Files.SideEffects
/**
 * for verification in all procedural scripts under libraries
 */
define('PHPMYADMIN', true);
// phpcs:enable

/**
 * Load vendor configuration.
 */
require_once ROOT_PATH . 'libraries/vendor_config.php';

/**
 * Activate autoloader
 */
if (! @is_readable(AUTOLOAD_FILE)) {
    die(
        '<p>File <samp>' . AUTOLOAD_FILE . '</samp> missing or not readable.</p>'
        . '<p>Most likely you did not run Composer to '
        . '<a href="https://docs.phpmyadmin.net/en/latest/setup.html#installing-from-git">'
        . 'install library files</a>.</p>'
    );
}

require_once AUTOLOAD_FILE;

/**
 * (TCPDF workaround)
 * Avoid referring to nonexistent files (causes warnings when open_basedir is used)
 * This is defined to avoid the tcpdf code to search for a directory outside of open_basedir
 * See: https://github.com/phpmyadmin/phpmyadmin/issues/16709
 * This value if not used but is usefull, no header logic is used for PDF exports
 */
if (! defined('K_PATH_IMAGES')) {
    // phpcs:disable PSR1.Files.SideEffects
    define('K_PATH_IMAGES', ROOT_PATH);
    // phpcs:enable
}

$route = Routing::getCurrentRoute();

if ($route === '/import-status') {
    // phpcs:disable PSR1.Files.SideEffects
    define('PMA_MINIMUM_COMMON', true);
    // phpcs:enable
}

$containerBuilder = Core::getContainerBuilder();

/**
 * Load gettext functions.
 */
Loader::loadFunctions();

/** @var ErrorHandler $errorHandler */
$errorHandler = $containerBuilder->get('error_handler');

/**
 * Warning about missing PHP extensions.
 */
Core::checkExtensions();

/**
 * Configure required PHP settings.
 */
Core::configure();

/* start procedural code                       label_start_procedural         */

Core::cleanupPathInfo();

/* parsing configuration file                  LABEL_parsing_config_file      */

/** @var bool $isConfigLoading Indication for the error handler */
$isConfigLoading = false;

/**
 * Force reading of config file, because we removed sensitive values
 * in the previous iteration.
 *
 * @var Config $config
 */
$config = $containerBuilder->get('config');

register_shutdown_function([Config::class, 'fatalErrorHandler']);

/**
 * include session handling after the globals, to prevent overwriting
 */
if (! defined('PMA_NO_SESSION')) {
    Session::setUp($config, $errorHandler);
}

/**
 * init some variables LABEL_variables_init
 */

/**
 * holds parameters to be passed to next page
 *
 * @global array $url_params
 */
$url_params = [];
$containerBuilder->setParameter('url_params', $url_params);

Core::setGotoAndBackGlobals($containerBuilder, $config);

Core::checkTokenRequestParam();

Core::setDatabaseAndTableFromRequest($containerBuilder);

/**
 * SQL query to be executed
 *
 * @global string $sql_query
 */
$sql_query = '';
if (Core::isValid($_POST['sql_query'])) {
    $sql_query = $_POST['sql_query'];
}

$containerBuilder->setParameter('sql_query', $sql_query);

//$_REQUEST['set_theme'] // checked later in this file LABEL_theme_setup
//$_REQUEST['server']; // checked later in this file
//$_REQUEST['lang'];   // checked by LABEL_loading_language_file

/* loading language file                       LABEL_loading_language_file    */

/**
 * lang detection is done here
 */
$language = LanguageManager::getInstance()->selectLanguage();
$language->activate();

/**
 * check for errors occurred while loading configuration
 * this check is done here after loading language files to present errors in locale
 */
$config->checkPermissions();
$config->checkErrors();

/* Check server configuration */
Core::checkConfiguration();

/* Check request for possible attacks */
Core::checkRequest();

/* setup servers                                       LABEL_setup_servers    */

$config->checkServers();

/**
 * current server
 *
 * @global integer $server
 */
$server = $config->selectServer();
$url_params['server'] = $server;
$containerBuilder->setParameter('server', $server);
$containerBuilder->setParameter('url_params', $url_params);

/**
 * BC - enable backward compatibility
 * exports all configuration settings into globals ($cfg global)
 */
$config->enableBc();

/* setup themes                                          LABEL_theme_setup    */

$theme = ThemeManager::initializeTheme();

/** @var DatabaseInterface $dbi */
$dbi = null;

if (defined('PMA_MINIMUM_COMMON')) {
    $config->loadUserPreferences();
    $containerBuilder->set('theme_manager', ThemeManager::getInstance());
    Tracker::enable();

    return;
}

/**
 * save some settings in cookies
 *
 * @todo should be done in PhpMyAdmin\Config
 */
$config->setCookie('pma_lang', (string) $lang);

ThemeManager::getInstance()->setThemeCookie();

$dbi = DatabaseInterface::load();
$containerBuilder->set(DatabaseInterface::class, $dbi);
$containerBuilder->setAlias('dbi', DatabaseInterface::class);

if (! empty($cfg['Server'])) {
    $config->getLoginCookieValidityFromCache($server);

    $auth_plugin = Plugins::getAuthPlugin();
    $auth_plugin->authenticate();

    Core::connectToDatabaseServer($dbi, $auth_plugin);

    $auth_plugin->rememberCredentials();

    $auth_plugin->checkTwoFactor();

    /* Log success */
    Logging::logUser($cfg['Server']['user']);

    if ($dbi->getVersion() < $cfg['MysqlMinVersion']['internal']) {
        Core::fatalError(
            __('You should upgrade to %s %s or later.'),
            [
                'MySQL',
                $cfg['MysqlMinVersion']['human'],
            ]
        );
    }

    // Sets the default delimiter (if specified).
    if (! empty($_REQUEST['sql_delimiter'])) {
        Lexer::$DEFAULT_DELIMITER = $_REQUEST['sql_delimiter'];
    }

    // TODO: Set SQL modes too.
} else { // end server connecting
    $response = Response::getInstance();
    $response->getHeader()->disableMenuAndConsole();
    $response->getFooter()->setMinimal();
}

$response = Response::getInstance();

Profiling::check($dbi, $response);

/*
 * There is no point in even attempting to process
 * an ajax request if there is a token mismatch
 */
if ($response->isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST' && $token_mismatch) {
    $response->setRequestStatus(false);
    $response->addJSON(
        'message',
        Message::error(__('Error: Token mismatch'))
    );
    exit;
}

$containerBuilder->set('response', Response::getInstance());

// load user preferences
$config->loadUserPreferences();

$containerBuilder->set('theme_manager', ThemeManager::getInstance());

/* Tell tracker that it can actually work */
Tracker::enable();

if (! empty($server) && isset($cfg['ZeroConf']) && $cfg['ZeroConf'] === true) {
    $dbi->postConnectControl();
}
