<?php

$testsRoot = __DIR__;
$moduleRoot = dirname(__DIR__, 2);
$stubsRoot = $testsRoot.'/stubs';

if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', $stubsRoot);
}
if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}
if (!defined('LOG_ERR')) {
    define('LOG_ERR', 3);
}
if (!defined('LOG_DEBUG')) {
    define('LOG_DEBUG', 7);
}

if (!function_exists('dolibarr_set_const')) {
    function dolibarr_set_const() { return true; }
}
if (!function_exists('dol_syslog')) {
    function dol_syslog() { return true; }
}
if (!function_exists('getEntity')) {
    function getEntity() { return '1'; }
}
if (!function_exists('price2num')) {
    function price2num($v) { return (float) $v; }
}

$conf = new stdClass();
$conf->entity = 1;
$conf->global = new stdClass();
$conf->global->MAIN_SECURITY_SALT = 'testsalt';
$user = new stdClass();
$user->api_key = '';
$user->login = 'test-user';

$GLOBALS['conf'] = $conf;
$GLOBALS['user'] = $user;

require_once $moduleRoot.'/core/classes/SyncOdooLegacy.class.php';

class TestDbStub
{
    public function escape($v)
    {
        return addslashes((string) $v);
    }

    public function query($sql)
    {
        return true;
    }

    public function fetch_object($res)
    {
        return null;
    }

    public function num_rows($res)
    {
        return 0;
    }

    public function lasterror()
    {
        return '';
    }
}

class TestAsserts
{
    public static function same($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new Exception($message.' | expected='.var_export($expected, true).' actual='.var_export($actual, true));
        }
    }

    public static function true($value, $message)
    {
        if ($value !== true) {
            throw new Exception($message.' | expected=true actual='.var_export($value, true));
        }
    }

    public static function false($value, $message)
    {
        if ($value !== false) {
            throw new Exception($message.' | expected=false actual='.var_export($value, true));
        }
    }
}

function call_private($object, $method, array $args = [])
{
    $ref = new ReflectionMethod(get_class($object), $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}
