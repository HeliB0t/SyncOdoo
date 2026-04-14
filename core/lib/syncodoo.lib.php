<?php
/**
 * Bibliothèque partagée du module SyncOdoo.
 *
 * Contient les fonctions utilitaires communes utilisées par
 * index.php, divergences.php et admin/config.php.
 */

/**
 * Retourne le texte FR ou NL selon la langue active de l'utilisateur.
 *
 * La langue peut être forcée via $_SESSION['syncodoo_lang'] (fr|nl).
 * En l'absence de forçage, on utilise $langs->defaultlang de Dolibarr.
 */
function syncodooText(string $fr, string $nl): string
{
    global $langs;
    $forced = $_SESSION['syncodoo_lang'] ?? '';
    if ($forced === 'nl') {
        return $nl;
    }
    if ($forced === 'fr') {
        return $fr;
    }
    $code = strtolower((string) ($langs->defaultlang ?? ''));
    return (strpos($code, 'nl') === 0) ? $nl : $fr;
}
