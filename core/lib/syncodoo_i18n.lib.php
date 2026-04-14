<?php

function syncodoo_handle_lang_request()
{
    $setLang = GETPOST('set_lang', 'alpha');
    if (in_array($setLang, ['fr', 'nl', 'en'], true)) {
        $_SESSION['syncodoo_lang'] = $setLang;
    }
}

function syncodoo_get_active_lang_code()
{
    if (!empty($_SESSION['syncodoo_lang'])) {
        return (string) $_SESSION['syncodoo_lang'];
    }

    global $langs;
    $default = strtolower((string) ($langs->defaultlang ?? ''));
    if (strpos($default, 'nl') === 0) {
        return 'nl';
    }
    if (strpos($default, 'en') === 0) {
        return 'en';
    }

    return 'fr';
}

function syncodoo_tr($key)
{
    global $langs;
    $args = func_get_args();
    $key = array_shift($args);

    return $langs->trans($key, ...$args);
}

/**
 * Legacy helper kept for backward compatibility while migrating to $langs->trans.
 */
function syncodooText($fr, $nl, $en = '')
{
    $langCode = syncodoo_get_active_lang_code();
    if ($langCode === 'nl') {
        return (string) $nl;
    }
    if ($langCode === 'en' && $en !== '') {
        return (string) $en;
    }

    return (string) $fr;
}
