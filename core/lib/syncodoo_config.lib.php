<?php

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

function syncodoo_secret_encryption_key()
{
    global $conf;

    $salt = (string) ($conf->global->MAIN_SECURITY_SALT ?? '');
    if ($salt === '') {
        $salt = DOL_DOCUMENT_ROOT;
    }

    return hash('sha256', 'syncodoo|'.$salt, true);
}

function syncodoo_encrypt_secret($value)
{
    if (!function_exists('openssl_encrypt')) {
        return '';
    }

    $plaintext = (string) $value;
    if ($plaintext === '') {
        return '';
    }

    $iv = random_bytes(16);
    $cipherRaw = openssl_encrypt($plaintext, 'AES-256-CBC', syncodoo_secret_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipherRaw === false) {
        return '';
    }

    return 'v1:'.base64_encode($iv).':'.base64_encode($cipherRaw);
}

function syncodoo_decrypt_secret($encryptedValue)
{
    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $encryptedValue = (string) $encryptedValue;
    if ($encryptedValue === '' || strpos($encryptedValue, 'v1:') !== 0) {
        return '';
    }

    $parts = explode(':', $encryptedValue, 3);
    if (count($parts) !== 3) {
        return '';
    }

    $iv = base64_decode($parts[1], true);
    $cipherRaw = base64_decode($parts[2], true);
    if ($iv === false || $cipherRaw === false) {
        return '';
    }

    $plaintext = openssl_decrypt($cipherRaw, 'AES-256-CBC', syncodoo_secret_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return ($plaintext === false) ? '' : (string) $plaintext;
}

function syncodoo_get_secret_from_conf($conf, $baseConstName, array $legacyConstNames = [])
{
    $encConst = $baseConstName.'_ENC';
    $encrypted = (string) ($conf->global->$encConst ?? '');
    if ($encrypted !== '') {
        $decrypted = syncodoo_decrypt_secret($encrypted);
        if ($decrypted !== '') {
            return $decrypted;
        }
    }

    $legacy = (string) ($conf->global->$baseConstName ?? '');
    if ($legacy !== '') {
        return $legacy;
    }

    foreach ($legacyConstNames as $legacyName) {
        $legacyValue = (string) ($conf->global->$legacyName ?? '');
        if ($legacyValue !== '') {
            return $legacyValue;
        }
    }

    return '';
}

function syncodoo_store_secret($db, $entity, $baseConstName, $secretValue, array $legacyConstNames = [])
{
    $secretValue = (string) $secretValue;
    $encConst = $baseConstName.'_ENC';

    if ($secretValue === '') {
        dolibarr_set_const($db, $encConst, '', 'chaine', 0, '', $entity);
        dolibarr_set_const($db, $baseConstName, '', 'chaine', 0, '', $entity);
        foreach ($legacyConstNames as $legacyName) {
            dolibarr_set_const($db, $legacyName, '', 'chaine', 0, '', $entity);
        }
        return;
    }

    $encrypted = syncodoo_encrypt_secret($secretValue);
    if ($encrypted !== '') {
        dolibarr_set_const($db, $encConst, $encrypted, 'chaine', 0, '', $entity);
        dolibarr_set_const($db, $baseConstName, '', 'chaine', 0, '', $entity);
        foreach ($legacyConstNames as $legacyName) {
            dolibarr_set_const($db, $legacyName, '', 'chaine', 0, '', $entity);
        }
        return;
    }

    // Fallback if openssl is unavailable.
    dolibarr_set_const($db, $baseConstName, $secretValue, 'chaine', 0, '', $entity);
}

function syncodoo_rotate_stored_secrets($db, $conf, $entity)
{
    $odooPassword = syncodoo_get_secret_from_conf($conf, 'SYNCODOO_ODOO_PASSWORD', ['SYNCODOO_ODOO_PASS']);
    $fallbackApiKey = syncodoo_get_secret_from_conf($conf, 'SYNCODOO_DOLI_APIKEY');

    if ($odooPassword !== '') {
        syncodoo_store_secret($db, $entity, 'SYNCODOO_ODOO_PASSWORD', $odooPassword, ['SYNCODOO_ODOO_PASS']);
    }
    if ($fallbackApiKey !== '') {
        syncodoo_store_secret($db, $entity, 'SYNCODOO_DOLI_APIKEY', $fallbackApiKey);
    }
}

function syncodoo_validate_config_input(array $fields)
{
    $errors = [];

    $url = (string) ($fields['SYNCODOO_ODOO_URL'] ?? '');
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidUrl');
    } else {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidUrlScheme');
        }
    }

    if (trim((string) ($fields['SYNCODOO_ODOO_DB'] ?? '')) === '') {
        $errors[] = syncodoo_tr('SyncodooConfigErrorMissingDb');
    }
    if (trim((string) ($fields['SYNCODOO_ODOO_USER'] ?? '')) === '') {
        $errors[] = syncodoo_tr('SyncodooConfigErrorMissingUser');
    }

    $limit = (int) ($fields['SYNCODOO_LIMIT'] ?? 0);
    if ($limit < 10 || $limit > 5000) {
        $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidLimit');
    }

    $logLevel = (string) ($fields['SYNCODOO_LOG_LEVEL'] ?? '');
    if (!in_array($logLevel, ['DEBUG', 'INFO', 'WARNING', 'ERROR'], true)) {
        $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidLogLevel');
    }

    $direction = (string) ($fields['SYNCODOO_BANK_SYNC_DIRECTION'] ?? 'both');
    if (!in_array($direction, ['odoo_to_dolibarr', 'dolibarr_to_odoo', 'both'], true)) {
        $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidDirection');
    }

    $startDate = trim((string) ($fields['SYNCODOO_BANK_SYNC_START_DATE'] ?? ''));
    if ($startDate !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidDate');
        } else {
            [$y, $m, $d] = array_map('intval', explode('-', $startDate));
            if (!checkdate($m, $d, $y)) {
                $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidDate');
            }
        }
    }

    $bankAccountId = (int) ($fields['SYNCODOO_DOLI_BANK_ACCOUNT_ID'] ?? 0);
    if ($bankAccountId < 0) {
        $errors[] = syncodoo_tr('SyncodooConfigErrorInvalidBankAccount');
    }

    return $errors;
}
