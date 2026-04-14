<?php
/**
 * Page de gestion des divergences — SyncOdoo
 *
 * Affiche les factures et tiers présents d'un seul côté
 * et permet de choisir manuellement ce que l'on supprime/archive.
 */

$res = @include_once '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include_once '../../main.inc.php';
}
if (!$res) {
    die('main.inc.php introuvable');
}
require_once __DIR__.'/core/classes/SyncOdoo.class.php';
require_once __DIR__.'/core/lib/syncodoo.lib.php';

if (empty($conf->syncodoo->enabled)) {
    accessforbidden(syncodooText('Module SyncOdoo desactive', 'Module SyncOdoo uitgeschakeld'));
}
if (!$user->rights->syncodoo->lire) {
    accessforbidden();
}

$langs->load('syncodoo@syncodoo');
$action = GETPOST('action', 'alpha');
$scope = GETPOST('scope', 'alpha');

$_set_lang = GETPOST('set_lang', 'alpha');
if ($_set_lang === 'fr' || $_set_lang === 'nl') {
    $_SESSION['syncodoo_lang'] = $_set_lang;
}

$isDeleteScope = ($scope === 'effacer');
$showThirdparties = ($scope === 'tiers' || $scope === '' || $isDeleteScope);
$showInvoices = ($scope !== 'tiers' && !$isDeleteScope);

// ════════════════════════════════════════════════════════
// ACTION : appliquer les actions sélectionnées
// ════════════════════════════════════════════════════════
$messages = [];
$errors   = [];
$missingTierResolutions = [];
$vatChecks = [
    'missing_country' => [],
    'pending_rates' => [],
];

if ($action === 'reset_divergences' && $user->rights->syncodoo->lancer) {
    $syncReset = new SyncOdoo($db);
    $syncReset->clearDivergenceLogs();
    $_SESSION['syncodoo_divergences_reset'] = 1;
    $messages[] = syncodooText('Reinitialisation effectuee: les lignes de divergence du journal ont ete remises a zero.', 'Reset uitgevoerd: divergentieregels in het logboek zijn op nul gezet.');
}

if ($action === 'confirm_vat_rates' && $user->rights->syncodoo->lancer) {
    $syncVat = new SyncOdoo($db);
    $vatConfirm = GETPOST('vat_confirm', 'array');
    $vatCorrectRates = GETPOST('vat_correct_rate', 'array');

    foreach ($vatConfirm as $rowId => $decision) {
        $rowId = (int) $rowId;
        $decision = trim((string) $decision);
        if ($rowId <= 0 || $decision === '' || $decision === 'skip') {
            continue;
        }

        $isExact = ($decision === 'yes');
        $correctRate = ($isExact || !isset($vatCorrectRates[$rowId])) ? null : $vatCorrectRates[$rowId];
        $syncVat->confirmVatRateByRowId($rowId, $isExact, $correctRate);
    }

    $messages[] = syncodooText('Confirmation des nouveaux taux de TVA enregistr&eacute;e.', 'Bevestiging van nieuwe btw-tarieven opgeslagen.');
}

if ($action === 'confirm_missing_countries' && $user->rights->syncodoo->lancer) {
    $syncCountry = new SyncOdoo($db);
    $countrySelections = GETPOST('missing_country_choice', 'array');
    $rowSources = GETPOST('missing_country_source', 'array');
    $rowTypes = GETPOST('missing_country_type', 'array');
    $rowRefs = GETPOST('missing_country_ref', 'array');
    $rowDolSocids = GETPOST('missing_country_dol_socid', 'array');
    $rowOdooPartnerIds = GETPOST('missing_country_odoo_partner_id', 'array');
    $rowPartnerLabels = GETPOST('missing_country_partner_label', 'array');

    $updatedCount = 0;
    $deferredCount = 0;

    foreach ($countrySelections as $rowKey => $countryCode) {
        $countryCode = trim((string) $countryCode);
        if ($countryCode === '' || $countryCode === 'skip') {
            continue;
        }

        $rowRef = [
            'source' => (string) ($rowSources[$rowKey] ?? ''),
            'type' => (string) ($rowTypes[$rowKey] ?? ''),
            'ref' => (string) ($rowRefs[$rowKey] ?? ''),
            'dol_socid' => (int) ($rowDolSocids[$rowKey] ?? 0),
            'odoo_partner_id' => (int) ($rowOdooPartnerIds[$rowKey] ?? 0),
            'partner_label' => (string) ($rowPartnerLabels[$rowKey] ?? ''),
        ];

        if ($countryCode === 'later') {
            $deferredCount++;
            continue;
        }

        try {
            $updated = $syncCountry->applyMissingCountrySelection($rowRef, $countryCode);
            if (!empty($updated['dolibarr']) || !empty($updated['odoo'])) {
                $updatedCount++;
            }
        } catch (Exception $e) {
            $invoiceRef = $rowRef['ref'] !== '' ? $rowRef['ref'] : (string) $rowKey;
            $errors[] = syncodooText('Erreur sur pays manquant pour <strong>', 'Fout bij ontbrekend land op <strong>').htmlspecialchars($invoiceRef).'</strong> : '.htmlspecialchars($e->getMessage());
            $syncCountry->log('ERROR', 'divergence', 'invoice', $invoiceRef, syncodooText('✗ Erreur enregistrement pays manquant: ', '✗ Fout bij opslaan ontbrekend land: ').$e->getMessage());
        }
    }

    if ($updatedCount > 0) {
        $messages[] = $updatedCount.' '.syncodooText('tiers mis a jour avec un pays.', 'relatie(s) bijgewerkt met een land.');
    }
    if ($deferredCount > 0) {
        $messages[] = $deferredCount.' '.syncodooText('ligne(s) marquee(s) comme "a definir plus tard".', 'regel(s) gemarkeerd als "later bepalen".');
    }
}

if ($action === 'confirm_vat_inconsistencies' && $user->rights->syncodoo->lancer) {
    $syncVatFix = new SyncOdoo($db);
    $applyRows = GETPOST('vat_fix_apply', 'array');
    $rowSources = GETPOST('vat_fix_source', 'array');
    $rowRefs = GETPOST('vat_fix_ref', 'array');
    $rowTypes = GETPOST('vat_fix_type', 'array');
    $rowIds = GETPOST('vat_fix_id', 'array');
    $rowHt = GETPOST('vat_fix_ht', 'array');
    $rowTva = GETPOST('vat_fix_tva', 'array');
    $rowTtc = GETPOST('vat_fix_ttc', 'array');

    if (empty($applyRows)) {
        setEventMessages(syncodooText('Aucune ligne selectionnee.', 'Geen regel geselecteerd.'), null, 'warnings');
    } else {
        $needsOdooConnection = false;
        foreach ($applyRows as $key => $flag) {
            if (!empty($flag) && (($rowSources[$key] ?? '') === 'odoo')) {
                $needsOdooConnection = true;
                break;
            }
        }

        if ($needsOdooConnection && !$syncVatFix->connectOdoo()) {
            $errors[] = syncodooText('Impossible de se connecter a Odoo pour corriger les montants.', 'Kan geen verbinding maken met Odoo om bedragen te corrigeren.');
            if (!empty($syncVatFix->lastError)) {
                $errors[] = 'Details: '.$syncVatFix->lastError;
            }
        } else {
            $updatedRows = 0;
            foreach ($applyRows as $key => $flag) {
                if (empty($flag)) {
                    continue;
                }

                $source = (string) ($rowSources[$key] ?? '');
                $ref = trim((string) ($rowRefs[$key] ?? ''));
                $invoiceType = (string) ($rowTypes[$key] ?? 'customer');
                $invoiceId = (int) ($rowIds[$key] ?? 0);
                $ht = parseVatInput($rowHt[$key] ?? null);
                $tva = parseVatInput($rowTva[$key] ?? null);
                $ttc = parseVatInput($rowTtc[$key] ?? null);

                if ($ref === '' || $ht === null || $tva === null || $ttc === null) {
                    $errors[] = syncodooText('Ligne ignoree: donnees manquantes ou invalides pour ', 'Regel overgeslagen: ontbrekende of ongeldige gegevens voor ').htmlspecialchars($ref !== '' ? $ref : (string) $key);
                    continue;
                }

                if (!areVatTotalsConsistent($ht, $tva, $ttc)) {
                    $delta = ($ht + $tva) - $ttc;
                    $rate = computeVatRatePercent($ht, $tva);
                    $errors[] = syncodooText('Incoherence TVA sur ', 'Btw-inconsistentie op ').'<strong>'.htmlspecialchars($ref).'</strong> : HTVA + TVA != TVAC (ecart '.price($delta, 0, '', 1, 2, 2).', taux '.price($rate, 0, '', 1, 2, 2).'%).';
                    continue;
                }

                try {
                    if ($source === 'dolibarr') {
                        updateDolibarrInvoiceTotalsByRef($db, $ref, $invoiceType, $ht, $tva, $ttc);
                        $syncVatFix->log('INFO', 'divergence', 'invoice', $ref, 'Correction manuelle TVA appliquee cote Dolibarr');
                    } elseif ($source === 'odoo') {
                        $odooInvoice = $syncVatFix->findOdooInvoiceByRefPublic($ref, $invoiceId);
                        $odooInvId = (int) ($odooInvoice['_id'] ?? $odooInvoice['id'] ?? $invoiceId);
                        if ($odooInvId <= 0) {
                            throw new Exception(syncodooText('Facture Odoo introuvable', 'Odoo-factuur niet gevonden'));
                        }

                        $okWrite = $syncVatFix->odooCallPublic(
                            'account.move',
                            'write',
                            [[(int) $odooInvId], [
                                'amount_untaxed' => $ht,
                                'amount_tax' => $tva,
                                'amount_total' => $ttc,
                            ]]
                        );
                        if (!$okWrite) {
                            throw new Exception(syncodooText('Ecriture Odoo refusee (champs potentiellement en lecture seule).', 'Odoo-write geweigerd (velden mogelijk alleen-lezen).'));
                        }
                        $syncVatFix->log('INFO', 'divergence', 'invoice', $ref, 'Correction manuelle TVA appliquee cote Odoo');
                    } else {
                        throw new Exception(syncodooText('Source inconnue', 'Onbekende bron'));
                    }

                    $updatedRows++;
                    $messages[] = syncodooText('Correction enregistree pour ', 'Correctie opgeslagen voor ').'<strong>'.htmlspecialchars($ref).'</strong>.';
                } catch (Exception $e) {
                    $errors[] = syncodooText('Erreur de correction pour ', 'Correctiefout voor ').'<strong>'.htmlspecialchars($ref).'</strong> : '.htmlspecialchars($e->getMessage());
                    $syncVatFix->log('ERROR', 'divergence', 'invoice', $ref, '✗ Correction manuelle TVA: '.$e->getMessage());
                }
            }

            if ($updatedRows > 0) {
                $messages[] = $updatedRows.' '.syncodooText('ligne(s) TVA corrigee(s).', 'btw-regel(s) gecorrigeerd.');
            }
        }
    }
}

if ($action === 'apply_actions' && $user->rights->syncodoo->lancer) {
    $sync = new SyncOdoo($db);

    // Odoo-verbinding contr&ocirc;leren
    if (!$sync->connectOdoo()) {
        $errors[] = syncodooText("✗ Impossible de se connecter a Odoo. Verifiez la configuration.", "✗ Kan geen verbinding maken met Odoo. Controleer de configuratie.");
        if (!empty($sync->lastError)) {
            $errors[] = "Details: ".$sync->lastError;
        }
    } else {
        $actions = GETPOST('actions', 'array');
        $missingTierActions = GETPOST('missing_tier_actions', 'array');
        $missingTierInvoiceRefs = GETPOST('missing_tier_invoice_ref', 'array');
        $missingTierDolIds = GETPOST('missing_tier_dol_id', 'array');
        $missingTierOdooIds = GETPOST('missing_tier_odoo_id', 'array');
        $missingTierDolLabels = GETPOST('missing_tier_dol_label', 'array');
        $missingTierOdooLabels = GETPOST('missing_tier_odoo_label', 'array');
        $thirdpartyTargets = GETPOST('thirdparty_targets', 'array');
        $thirdpartyCurrent = GETPOST('thirdparty_current', 'array');
        $thirdpartyLabels = GETPOST('thirdparty_labels', 'array');
        $thirdpartyTypes = GETPOST('thirdparty_types', 'array');
        $thirdpartyEverywhere = GETPOST('thirdparty_everywhere', 'array');
        $supplierDiffChoices = GETPOST('supplier_diff_choice', 'array');
        $supplierDiffRefs = GETPOST('supplier_diff_ref', 'array');
        $supplierDiffOdooIds = GETPOST('supplier_diff_odoo_id', 'array');
        $supplierDiffDolHt = GETPOST('supplier_diff_d_ht', 'array');
        $supplierDiffDolTva = GETPOST('supplier_diff_d_tva', 'array');
        $supplierDiffDolTtc = GETPOST('supplier_diff_d_ttc', 'array');
        $supplierDiffOdooHt = GETPOST('supplier_diff_o_ht', 'array');
        $supplierDiffOdooTva = GETPOST('supplier_diff_o_tva', 'array');
        $supplierDiffOdooTtc = GETPOST('supplier_diff_o_ttc', 'array');
        $hasSubmittedChanges = false;

        foreach ($supplierDiffChoices as $key => $choice) {
            $choice = trim((string) $choice);
            if ($choice !== 'keep_odoo' && $choice !== 'keep_dolibarr') {
                continue;
            }

            $ref = (string) ($supplierDiffRefs[$key] ?? '');
            $odooId = (int) ($supplierDiffOdooIds[$key] ?? 0);
            if ($ref === '') {
                continue;
            }

            try {
                if ($choice === 'keep_odoo') {
                    $srcHt = (float) ($supplierDiffOdooHt[$key] ?? 0);
                    $srcTva = (float) ($supplierDiffOdooTva[$key] ?? 0);
                    $srcTtc = (float) ($supplierDiffOdooTtc[$key] ?? 0);

                    if (!areVatTotalsConsistent($srcHt, $srcTva, $srcTtc)) {
                        throw new Exception(syncodooText('Incoh&eacute;rence TVA c&ocirc;t&eacute; Odoo : HT + TVA != TTC', 'Btw-inconsistentie aan Odoo-zijde: excl. btw + btw != incl. btw'));
                    }

                    $sync->syncFacturesOdooToDoli($ref, $odooId);
                    $messages[] = syncodooText(
                        'Facture fournisseur <strong>',
                        'Leveranciersfactuur <strong>'
                    ).htmlspecialchars($ref).syncodooText('</strong> align&eacute;e sur les donn&eacute;es Odoo puis synchronis&eacute;e vers Dolibarr.', '</strong> uitgelijnd op Odoo-gegevens en daarna gesynchronis&eacute;erd naar Dolibarr.');
                    $sync->log('INFO', 'divergence', 'invoice', $ref, 'Choix manuel: conserver Odoo puis synchroniser Dolibarr');
                } else {
                    $srcHt = (float) ($supplierDiffDolHt[$key] ?? 0);
                    $srcTva = (float) ($supplierDiffDolTva[$key] ?? 0);
                    $srcTtc = (float) ($supplierDiffDolTtc[$key] ?? 0);

                    if (!areVatTotalsConsistent($srcHt, $srcTva, $srcTtc)) {
                        throw new Exception(syncodooText('Incoh&eacute;rence TVA c&ocirc;t&eacute; Dolibarr : HT + TVA != TTC', 'Btw-inconsistentie aan Dolibarr-zijde: excl. btw + btw != incl. btw'));
                    }

                    $sync->syncFacturesDoliToOdoo($ref);
                    $messages[] = syncodooText(
                        'Facture fournisseur <strong>',
                        'Leveranciersfactuur <strong>'
                    ).htmlspecialchars($ref).syncodooText('</strong> align&eacute;e sur les donn&eacute;es Dolibarr puis synchronis&eacute;e vers Odoo.', '</strong> uitgelijnd op Dolibarr-gegevens en daarna gesynchronis&eacute;erd naar Odoo.');
                    $sync->log('INFO', 'divergence', 'invoice', $ref, 'Choix manuel: conserver Dolibarr puis synchroniser Odoo');
                }

                $hasSubmittedChanges = true;
            } catch (Exception $e) {
                $errors[] = syncodooText('Erreur sur la facture fournisseur <strong>', 'Fout op leveranciersfactuur <strong>').htmlspecialchars($ref).'</strong> : '.htmlspecialchars($e->getMessage());
                $sync->log('ERROR', 'divergence', 'invoice', $ref, syncodooText('✗ Erreur de choix fournisseur: ', '✗ Leverancierskeuzefout: ').$e->getMessage());
            }
        }

        foreach ($missingTierActions as $key => $missingAction) {
            $missingAction = trim((string) $missingAction);
            if ($missingAction === '' || $missingAction === 'skip') {
                continue;
            }

            $invoiceRef = (string) ($missingTierInvoiceRefs[$key] ?? '');
            $dolId = (int) ($missingTierDolIds[$key] ?? 0);
            $odooId = (int) ($missingTierOdooIds[$key] ?? 0);
            $dolLabel = (string) ($missingTierDolLabels[$key] ?? '');
            $odooLabel = (string) ($missingTierOdooLabels[$key] ?? '');

            try {
                if ($missingAction === 'add_odoo' || $missingAction === 'add_both') {
                    if ($dolId <= 0) {
                        throw new Exception(syncodooText('ID Dolibarr manquant pour ajout vers Odoo', 'Ontbrekend Dolibarr-relatie-ID voor toevoeging naar Odoo'));
                    }
                    $newOdooId = (int) $sync->syncTiersDoliToOdoo($dolId);
                    $messages[] = syncodooText(
                        'Tiers <strong>'.htmlspecialchars($dolLabel ?: ('Dolibarr #'.$dolId)).'</strong> ajout&eacute;/synchronis&eacute; vers Odoo (ID '.$newOdooId.') pour la facture <strong>'.htmlspecialchars($invoiceRef).'</strong>.',
                        'Relatie <strong>'.htmlspecialchars($dolLabel ?: ('Dolibarr #'.$dolId)).'</strong> toegevoegd/gesynchronis&eacute;erd naar Odoo (ID '.$newOdooId.') voor factuur <strong>'.htmlspecialchars($invoiceRef).'</strong>.'
                    );
                }

                if ($missingAction === 'add_doli' || $missingAction === 'add_both') {
                    if ($odooId <= 0) {
                        throw new Exception(syncodooText('ID partenaire Odoo manquant pour ajout vers Dolibarr', 'Ontbrekend Odoo-partner-ID voor toevoeging naar Dolibarr'));
                    }
                    $newDolId = (int) $sync->syncTiersOdooToDoli($odooId);
                    $messages[] = syncodooText(
                        'Tiers <strong>'.htmlspecialchars($odooLabel ?: ('Odoo #'.$odooId)).'</strong> ajout&eacute;/synchronis&eacute; vers Dolibarr (ID '.$newDolId.') pour la facture <strong>'.htmlspecialchars($invoiceRef).'</strong>.',
                        'Relatie <strong>'.htmlspecialchars($odooLabel ?: ('Odoo #'.$odooId)).'</strong> toegevoegd/gesynchronis&eacute;erd naar Dolibarr (ID '.$newDolId.') voor factuur <strong>'.htmlspecialchars($invoiceRef).'</strong>.'
                    );
                }

                $hasSubmittedChanges = true;
            } catch (Exception $e) {
                $errors[] = syncodooText('Erreur de tiers manquant pour <strong>', 'Fout bij ontbrekende relatie voor <strong>').htmlspecialchars($invoiceRef ?: (string) $key).'</strong> : '.htmlspecialchars($e->getMessage());
                $sync->log('ERROR', 'divergence', 'thirdparty', $invoiceRef ?: (string) $key,
                    syncodooText('✗ Fout ajout tiers manquant: ', '✗ Fout bijvoegen ontbrekende relatie: ').$e->getMessage());
            }
        }

        foreach ($thirdpartyTargets as $key => $targetMode) {
            $desiredTypes = normalizeSubmittedThirdpartyTypes($thirdpartyTypes[$key] ?? []);
            $currentTypes = parseThirdpartyTypeState($thirdpartyCurrent[$key] ?? '');
            if ($desiredTypes === $currentTypes) {
                continue;
            }

            $hasSubmittedChanges = true;
            $label = $thirdpartyLabels[$key] ?? (syncodooText('Tiers ', 'Relatie ').$key);
            $updateEverywhere = !empty($thirdpartyEverywhere[$key]);

            try {
                if ($targetMode === 'odoo') {
                    $odooId = (int) $key;
                    $sync->updateOdooThirdpartyTypes($odooId, $desiredTypes);
                    if ($updateEverywhere) {
                        $dolId = (int) $sync->syncTiersOdooToDoli($odooId);
                        if ($dolId > 0) {
                            $sync->updateDolibarrThirdpartyTypes($dolId, $desiredTypes);
                        }
                    }
                } elseif ($targetMode === 'dolibarr') {
                    $dolId = (int) $key;
                    $sync->updateDolibarrThirdpartyTypes($dolId, $desiredTypes);
                    if ($updateEverywhere) {
                        $odooId = (int) $sync->syncTiersDoliToOdoo($dolId);
                        if ($odooId > 0) {
                            $sync->updateOdooThirdpartyTypes($odooId, $desiredTypes);
                        }
                    }
                } elseif ($targetMode === 'pair') {
                    [$dolId, $odooId] = array_map('intval', explode(':', (string) $key, 2));
                    if ($dolId > 0) {
                        $sync->updateDolibarrThirdpartyTypes($dolId, $desiredTypes);
                    }
                    if ($odooId > 0) {
                        $sync->updateOdooThirdpartyTypes($odooId, $desiredTypes);
                    }
                }

                $messages[] = syncodooText('Types mis a jour pour', 'Types bijgewerkt voor').' <strong>'.htmlspecialchars($label).'</strong>.';
                $sync->log('INFO', 'divergence', 'thirdparty', $label, syncodooText('Types tiers mis a jour manuellement', 'Relatietypes handmatig bijgewerkt'));
            } catch (Exception $e) {
                $errors[] = syncodooText('Erreur de types pour', 'Fout in types voor').' <strong>'.htmlspecialchars($label).'</strong> : '.htmlspecialchars($e->getMessage());
                $sync->log('ERROR', 'divergence', 'thirdparty', $label, syncodooText('✗ Erreur de type manuelle : ', '✗ Handmatige typefout : ').$e->getMessage());
            }
        }

        // Filtrer les actions vides
        $actions = array_filter($actions, function($a) {
            return !empty($a) && $a !== 'skip';
        });

        if (count($actions) === 0 && !$hasSubmittedChanges) {
            setEventMessages(syncodooText('Aucune action s&eacute;lectionn&eacute;e.', 'Geen actie geselecteerd.'), null, 'warnings');
        } else {
            foreach ($actions as $actionStr) {
                // Format : "type|id|ref|action"
                $parts = explode('|', $actionStr, 4);
                if (count($parts) !== 4) {
                    continue;
                }

                [$type, $id, $ref, $actionType] = $parts;

                // Validate type and actionType against known whitelists.
                $allowedInvoiceActions = [
                    'delete_odoo', 'delete_doli', 'sync_to_odoo', 'sync_to_doli',
                    'delete_both_from_odoo', 'delete_both_from_doli', 'delete_both_pair',
                ];
                $allowedThirdpartyActions = [
                    'delete_odoo', 'delete_doli', 'sync_to_odoo', 'sync_to_doli',
                    'delete_both_from_odoo', 'delete_both_from_doli', 'delete_both_pair',
                ];
                if ($type === 'invoice' && !in_array($actionType, $allowedInvoiceActions, true)) {
                    continue;
                }
                if ($type === 'thirdparty' && !in_array($actionType, $allowedThirdpartyActions, true)) {
                    continue;
                }
                if (!in_array($type, ['invoice', 'thirdparty'], true)) {
                    continue;
                }

                try {
                    if ($type === 'invoice') {
                        if ($actionType === 'delete_odoo') {
                            $state = $sync->odooGetInvoiceState((int) $id);
                            if ($state === 'posted') {
                                $sync->odooCallPublic('account.move', 'button_draft', [[(int) $id]]);
                            }
                            $sync->odooCallPublic('account.move', 'button_cancel', [[(int) $id]]);
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('🗑 Facture annul&eacute;e dans Odoo (action manuelle)', '🗑 Factuur geannul&eacute;erd in Odoo (handmatige actie)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> annul&eacute;e dans Odoo.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> geannul&eacute;erd in Odoo.');
                        } elseif ($actionType === 'delete_doli') {
                            // Utiliser directement la classe Factuur de Dolibarr
                            require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
                            $facture = new Facture($db);
                            if ($facture->fetch((int) $id) > 0) {
                                if ($facture->statut == Facture::STATUS_VALIDATED) {
                                    $facture->setStatut(Facture::STATUS_DRAFT);
                                }
                                $facture->setStatut(Facture::STATUS_CANCELED);
                                $sync->log('INFO', 'divergence', 'invoice', $ref,
                                    syncodooText('🗑 Facture supprim&eacute;e dans Dolibarr (action manuelle)', '🗑 Factuur verwijderd in Dolibarr (handmatige actie)'));
                                $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> supprim&eacute;e dans Dolibarr.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> verwijderd in Dolibarr.');
                            } else {
                                throw new Exception(syncodooText("Facture Dolibarr $id introuvable", "Factuur Dolibarr $id introuvable"));
                            }
                        } elseif ($actionType === 'sync_to_odoo') {
                            $sync->syncFacturesDoliToOdoo($ref);
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('📤 Facture synchronis&eacute;e vers Odoo (action manuelle)', '📤 Factuur gesynchronis&eacute;erd naar Odoo (handmatige actie)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> synchronis&eacute;e vers Odoo.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> gesynchronis&eacute;erd naar Odoo.');
                        } elseif ($actionType === 'sync_to_doli') {
                            $sync->syncFacturesOdooToDoli($ref);
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('📥 Facture synchronis&eacute;e vers Dolibarr (action manuelle)', '📥 Factuur gesynchronis&eacute;erd naar Dolibarr (handmatige actie)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> synchronis&eacute;e vers Dolibarr.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> gesynchronis&eacute;erd naar Dolibarr.');
                        } elseif ($actionType === 'delete_both_from_odoo') {
                            $state = $sync->odooGetInvoiceState((int) $id);
                            if ($state === 'posted') {
                                $sync->odooCallPublic('account.move', 'button_draft', [[(int) $id]]);
                            }
                            $sync->odooCallPublic('account.move', 'button_cancel', [[(int) $id]]);
                            cancelDolibarrInvoiceByRef($db, $user, $ref);
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('🧹 Facture annul&eacute;e/supprim&eacute;e partout (depuis Odoo)', '🧹 Factuur geannul&eacute;erd/verwijderd overal (depuis Odoo)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> supprim&eacute;e partout.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> verwijderd overal.');
                        } elseif ($actionType === 'delete_both_from_doli') {
                            cancelDolibarrInvoiceByRef($db, $user, $ref);
                            $odooId = (int) $sync->findOdooInvoiceIdByRef($ref);
                            if ($odooId > 0) {
                                $state = $sync->odooGetInvoiceState($odooId);
                                if ($state === 'posted') {
                                    $sync->odooCallPublic('account.move', 'button_draft', [[$odooId]]);
                                }
                                $sync->odooCallPublic('account.move', 'button_cancel', [[$odooId]]);
                            }
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('🧹 Facture annul&eacute;e/supprim&eacute;e partout (depuis Dolibarr)', '🧹 Factuur geannul&eacute;erd/verwijderd overal (depuis Dolibarr)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> supprim&eacute;e partout.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> verwijderd overal.');
                        } elseif ($actionType === 'delete_both_pair') {
                            cancelDolibarrInvoiceByRef($db, $user, $ref);
                            $odooId = (int) $sync->findOdooInvoiceIdByRef($ref);
                            if ($odooId > 0) {
                                $state = $sync->odooGetInvoiceState($odooId);
                                if ($state === 'posted') {
                                    $sync->odooCallPublic('account.move', 'button_draft', [[$odooId]]);
                                }
                                $sync->odooCallPublic('account.move', 'button_cancel', [[$odooId]]);
                            }
                            $sync->log('INFO', 'divergence', 'invoice', $ref,
                                syncodooText('🧹 Facture annul&eacute;e/supprim&eacute;e partout (paire)', '🧹 Factuur geannul&eacute;erd/verwijderd overal (paire)'));
                            $messages[] = syncodooText('Facture <strong>'.htmlspecialchars($ref).'</strong> supprim&eacute;e partout.', 'Factuur <strong>'.htmlspecialchars($ref).'</strong> verwijderd overal.');
                        }
                    } elseif ($type === 'thirdparty') {
                        if ($actionType === 'delete_odoo') {
                            $sync->odooCallPublic('res.partner', 'write', [[(int) $id], ['active' => false]]);
                            $sync->log('INFO', 'divergence', 'partner', $ref,
                                syncodooText('🗑 Tiers archiv&eacute; dans Odoo (action manuelle)', '🗑 Relatie gearchiveerd in Odoo (handmatige actie)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> archive dans Odoo.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> gearchiveerd in Odoo.');
                        } elseif ($actionType === 'delete_doli') {
                            // Utiliser directement la classe Societe de Dolibarr
                            require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
                            $societe = new Societe($db);
                            if ($societe->fetch((int) $id) > 0) {
                                $societe->status = 0; // Inactif
                                $societe->update((int) $id, $user);
                                $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                    syncodooText('🗑 Tiers archiv&eacute; dans Dolibarr (action manuelle)', '🗑 Relatie gearchiveerd in Dolibarr (action manuelle)'));
                                $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> archive dans Dolibarr.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> gearchiveerd in Dolibarr.');
                            } else {
                                throw new Exception(syncodooText("Tiers Dolibarr $id introuvable", "Relatie Dolibarr $id introuvable"));
                            }
                        } elseif ($actionType === 'sync_to_odoo') {
                            $sync->syncTiersDoliToOdoo((int) $id);
                            $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                syncodooText('📤 Tiers synchronis&eacute; vers Odoo (action manuelle)', '📤 Relatie gesynchronis&eacute;erd naar Odoo (action manuelle)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> synchronis&eacute; vers Odoo.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> gesynchronis&eacute;erd naar Odoo.');
                        } elseif ($actionType === 'sync_to_doli') {
                            $sync->syncTiersOdooToDoli((int) $id);
                            $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                syncodooText('📥 Tiers synchronis&eacute; vers Dolibarr (action manuelle)', '📥 Relatie gesynchronis&eacute;erd naar Dolibarr (action manuelle)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> synchronis&eacute; vers Dolibarr.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> gesynchronis&eacute;erd naar Dolibarr.');
                        } elseif ($actionType === 'delete_both_from_odoo') {
                            $sync->odooCallPublic('res.partner', 'write', [[(int) $id], ['active' => false]]);
                            $dolId = findDolibarrThirdpartyIdByName($db, $ref);
                            if ($dolId > 0) {
                                archiveDolibarrThirdparty($db, $user, $dolId);
                            }
                            $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                syncodooText('🧹 Tiers effac&eacute;/archive partout (depuis Odoo)', '🧹 Relatie effacé/archivé partout (depuis Odoo)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> effac&eacute; partout.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> effacé partout.');
                        } elseif ($actionType === 'delete_both_from_doli') {
                            archiveDolibarrThirdparty($db, $user, (int) $id);
                            $odooId = findOdooThirdpartyIdByName($sync, $ref);
                            if ($odooId > 0) {
                                $sync->odooCallPublic('res.partner', 'write', [[(int) $odooId], ['active' => false]]);
                            }
                            $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                syncodooText('🧹 Tiers effac&eacute;/archive partout (depuis Dolibarr)', '🧹 Relatie effacé/archivé partout (depuis Dolibarr)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> effac&eacute; partout.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> effacé partout.');
                        } elseif ($actionType === 'delete_both_pair') {
                            [$dolId, $odooId] = array_map('intval', explode(':', (string) $id, 2));
                            if ($dolId > 0) {
                                archiveDolibarrThirdparty($db, $user, $dolId);
                            }
                            if ($odooId > 0) {
                                $sync->odooCallPublic('res.partner', 'write', [[(int) $odooId], ['active' => false]]);
                            }
                            $sync->log('INFO', 'divergence', 'thirdparty', $ref,
                                syncodooText('🧹 Tiers effac&eacute;/archive partout (paire)', '🧹 Relatie effacé/archivé partout (paire)'));
                            $messages[] = syncodooText('Tiers <strong>'.htmlspecialchars($ref).'</strong> effac&eacute; partout.', 'Relatie <strong>'.htmlspecialchars($ref).'</strong> effacé partout.');
                        }
                    }
                } catch (Exception $e) {
                    $isMissingTier = ($type === 'invoice' && in_array($actionType, ['sync_to_odoo', 'sync_to_doli'], true)
                        && isMissingThirdpartyInvoiceError($e->getMessage()));

                    if ($isMissingTier) {
                        $resolution = buildMissingTierResolutionRow($db, $sync, $actionType, (string) $id, (string) $ref, $e->getMessage());
                        if (!empty($resolution)) {
                            $missingTierResolutions[$resolution['key']] = $resolution;
                            $messages[] = syncodooText(
                                'Tiers manquant d&eacute;tect&eacute; pour la facture <strong>'.htmlspecialchars($ref).'</strong> : choisissez une action dans le tableau d&eacute;di&eacute;.',
                                'Ontbrekende relatie ged&eacute;tect&eacute;erd voor factuur <strong>'.htmlspecialchars($ref).'</strong> : kies een actie in de daarvoor bestemde tabel.'
                            );
                            $sync->log('WARNING', 'divergence', 'invoice', $ref,
                                syncodooText('Tiers manquant d&eacute;tect&eacute; lors de la synchronisation de la facture: ', 'Ontbrekende relatie ged&eacute;tect&eacute;erd tijdens factuursynchronisatie: ').$e->getMessage());
                            continue;
                        }
                    }

                    $errors[] = syncodooText('Erreur sur <strong>', 'Fout op <strong>').htmlspecialchars($ref).'</strong> : '.htmlspecialchars($e->getMessage());
                    $sync->log('ERROR', 'divergence', $type, $ref,
                        syncodooText('✗ Erreur d\'action manuelle : ', '✗ Handmatige actiefout : ').$e->getMessage());
                }
            }
        }
    }
}

// ════════════════════════════════════════════════════════
// ANALYSE DES DIVERGENCES (lecture seule)
// ════════════════════════════════════════════════════════
$sync        = new SyncOdoo($db);
$divergences = [
    'tiers_only_doli' => [],
    'tiers_only_odoo' => [],
    'invoices_only_doli' => [],
    'invoices_only_odoo' => [],
    'tiers' => ['differences' => []],
    'factures' => ['differences' => []],
    'vat_checks' => ['missing_country' => [], 'pending_rates' => []],
];

try {
    if (empty($_SESSION['syncodoo_divergences_reset'])) {
        $divergences = $sync->analyserDivergences();
        $vatChecks = $divergences['vat_checks'] ?? $vatChecks;
    }
} catch (Exception $e) {
    $errors[] = syncodooText('Analyse impossible: ', 'Analyse onmogelijk: ').htmlspecialchars($e->getMessage());
}

// ════════════════════════════════════════════════════════
// AFFICHAGE
// ════════════════════════════════════════════════════════
llxHeader('', syncodooText('SyncOdoo — Divergences', 'SyncOdoo — Divergenties'));
print '<style>';
print '.butAction, .butActionDelete {';
print 'background:#1f8f43 !important;border-color:#1f8f43 !important;color:#fff !important;';
print '}';
print '.butAction:hover, .butActionDelete:hover {';
print 'background:#177336 !important;border-color:#177336 !important;color:#fff !important;';
print '}';
print '</style>';
print load_fiche_titre(syncodooText('SyncOdoo — Gestion des divergences', 'SyncOdoo — Beheer van divergenties'), '', 'refresh');

$_syncoActiveLang = $_SESSION['syncodoo_lang'] ?? (strpos(strtolower((string) ($langs->defaultlang ?? '')), 'nl') === 0 ? 'nl' : 'fr');
$_syncoBase = dol_buildpath('/syncodoo/divergences.php', 1);
print '<div style="text-align:right;margin:4px 0 6px 0;font-size:0.88em">';
print '<span style="color:#6c757d;margin-right:4px">'.syncodooText('Langue :', 'Taal:').'</span>';
print '<a href="'.$_syncoBase.'?set_lang=fr" style="padding:2px 10px;text-decoration:none;border-radius:3px 0 0 3px;border:1px solid #ccc;'.($_syncoActiveLang === 'fr' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">FR</a>';
print '<a href="'.$_syncoBase.'?set_lang=nl" style="padding:2px 10px;text-decoration:none;border-radius:0 3px 3px 0;border:1px solid #ccc;border-left:none;'.($_syncoActiveLang === 'nl' ? 'background:#1f8f43;color:#fff;font-weight:700;border-color:#1f8f43' : 'background:#f8f9fa;color:#495057').'">NL</a>';
print '</div>';

$head = buildSyncodooHead($conf, $user);
print dol_get_fiche_head($head, 'divergences', 'SyncOdoo', 0, 'refresh');

// Retours d'action
foreach ($messages as $msg) {
    print '<div class="ok" style="margin-bottom:6px">'.$msg.'</div>';
}
foreach ($errors as $err) {
    print '<div class="error" style="margin-bottom:6px">'.$err.'</div>';
}

if (!empty($vatChecks['missing_country'])) {
    $countryOptions = [];
    if ($user->rights->syncodoo->lancer) {
        $syncCountryOptions = new SyncOdoo($db);
        $countryOptions = $syncCountryOptions->getCountryOptions();
    }

    print '<div style="border:1px solid #f5c6cb;border-radius:6px;overflow:hidden;margin:12px 0">';
    print '<div style="background:#fdecea;padding:10px 16px;border-bottom:1px solid #f5c6cb">';
    print '<strong>⚠ '.syncodooText('Pays manquant sur certaines factures', 'Land ontbreekt op sommige facturen').'</strong> ';
    print '<span style="color:#6c757d">('.count($vatChecks['missing_country']).' '.syncodooText('element(s)', 'item(s)').')</span>';
    print '</div>';
    print '<div style="padding:10px 16px;color:#842029">';
    print syncodooText('Enregistrez un pays d\'origine sur ces factures (ou sur le tiers li&eacute;) pour fiabiliser le contr&ocirc;le des taux de TVA.', 'Registreer een land van herkomst op deze facturen (of op de gekoppelde relatie) om de contr&ocirc;le van btw-tarieven betrouwbaarder te maken.');

    if ($user->rights->syncodoo->lancer) {
        print '<form method="POST" action="'.dol_buildpath('/syncodoo/divergences.php', 1).'" style="margin-top:10px">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="confirm_missing_countries">';
        if (!empty($scope)) {
            print '<input type="hidden" name="scope" value="'.htmlspecialchars($scope).'">';
        }

        print '<div style="overflow-x:auto">';
        print '<table class="noborder" style="width:100%;border-collapse:collapse">';
        print '<thead><tr class="liste_titre">';
        print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Source', 'Bron').'</th>';
        print '<th style="padding:8px 12px;text-align:left">Type</th>';
        print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Facture', 'Factuur').'</th>';
        print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Tiers', 'Relatie').'</th>';
        print '<th style="padding:8px 12px;text-align:left;min-width:260px">'.syncodooText('Pays a enregistrer', 'Land om op te slaan').'</th>';
        print '</tr></thead><tbody>';

        foreach ($vatChecks['missing_country'] as $idx => $missingCountryRow) {
            $src = ($missingCountryRow['source'] ?? '') === 'odoo' ? 'Odoo' : 'Dolibarr';
            $typ = ($missingCountryRow['type'] ?? '') === 'supplier' ? syncodooText('fournisseur', 'leverancier') : syncodooText('client', 'klant');
            $ref = (string) ($missingCountryRow['ref'] ?? '');
            $partnerLabel = (string) ($missingCountryRow['partner_label'] ?? '');
            $dolSocid = (int) ($missingCountryRow['dol_socid'] ?? 0);
            $odooPartnerId = (int) ($missingCountryRow['odoo_partner_id'] ?? 0);

            print '<tr>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars($src).'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars($typ).'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0"><code>'.htmlspecialchars($ref).'</code></td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars($partnerLabel !== '' ? $partnerLabel : '—').'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">';
            print '<select name="missing_country_choice['.$idx.']" style="padding:4px;min-width:240px">';
            print '<option value="skip">'.syncodooText('— Choisir —', '— Kies —').'</option>';
            foreach ($countryOptions as $countryOpt) {
                $countryCode = (string) ($countryOpt['code'] ?? '');
                $countryLabel = (string) ($countryOpt['label'] ?? $countryCode);
                print '<option value="'.htmlspecialchars($countryCode).'">'.htmlspecialchars($countryLabel.' ('.$countryCode.')').'</option>';
            }
            print '<option value="later">'.syncodooText('Definir plus tard', 'Later bepalen').'</option>';
            print '</select>';
            print '<input type="hidden" name="missing_country_source['.$idx.']" value="'.htmlspecialchars((string) ($missingCountryRow['source'] ?? '')).'">';
            print '<input type="hidden" name="missing_country_type['.$idx.']" value="'.htmlspecialchars((string) ($missingCountryRow['type'] ?? '')).'">';
            print '<input type="hidden" name="missing_country_ref['.$idx.']" value="'.htmlspecialchars($ref).'">';
            print '<input type="hidden" name="missing_country_dol_socid['.$idx.']" value="'.((int) $dolSocid).'">';
            print '<input type="hidden" name="missing_country_odoo_partner_id['.$idx.']" value="'.((int) $odooPartnerId).'">';
            print '<input type="hidden" name="missing_country_partner_label['.$idx.']" value="'.htmlspecialchars($partnerLabel).'">';
            print '</td>';
            print '</tr>';
        }

        print '</tbody></table>';
        print '</div>';
        print '<div style="margin-top:10px">';
        print '<button type="submit" class="butAction">'.syncodooText('Confirmer les pays selectionnes', 'Geselecteerde landen bevestigen').'</button>';
        print '</div>';
        print '</form>';
    } else {
        print '<ul style="margin:8px 0 0 18px">';
        foreach ($vatChecks['missing_country'] as $missingCountryRow) {
            $src = ($missingCountryRow['source'] ?? '') === 'odoo' ? 'Odoo' : 'Dolibarr';
            $typ = ($missingCountryRow['type'] ?? '') === 'supplier' ? syncodooText('fournisseur', 'leverancier') : syncodooText('client', 'klant');
            $ref = (string) ($missingCountryRow['ref'] ?? '');
            print '<li><strong>'.htmlspecialchars($src).'</strong> — '.syncodooText('Facture', 'Factuur').' '.htmlspecialchars($typ).' <code>'.htmlspecialchars($ref).'</code></li>';
        }
        print '</ul>';
    }

    print '</div>';
    print '</div>';
}

if (!empty($vatChecks['pending_rates']) && $user->rights->syncodoo->lancer) {
    $invoiceContextByRef = buildInvoiceContextByRef($divergences);
    print '<div style="border:1px solid #ffe58f;border-radius:6px;overflow:hidden;margin:12px 0">';
    print '<div style="background:#fffbe6;padding:10px 16px;border-bottom:1px solid #ffe58f">';
    print '<strong>🧾 '.syncodooText('Confirmation des nouveaux taux de TVA par pays', 'Bevestiging van nieuwe btw-tarieven per land').'</strong> ';
    print '<span style="color:#6c757d">('.count($vatChecks['pending_rates']).' '.syncodooText('taux &agrave; confirmer', 'tarief(ven) te bevestigen').')</span>';
    print '</div>';
    print '<form method="POST" action="'.dol_buildpath('/syncodoo/divergences.php', 1).'" style="padding:10px 16px">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="confirm_vat_rates">';
    if (!empty($scope)) {
        print '<input type="hidden" name="scope" value="'.htmlspecialchars($scope).'">';
    }
    print '<table class="noborder" style="width:100%;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Pays', 'Land').'</th>';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Taux constate', 'Waargenomen tarief').'</th>';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Source', 'Bron').'</th>';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Premiere facture', 'Eerste factuur').'</th>';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Fournisseur/Client', 'Leverancier/Klant').'</th>';
    print '<th style="padding:8px 12px;text-align:left">'.syncodooText('Objet de la vente', 'Onderwerp van verkoop').'</th>';
    print '<th style="padding:8px 12px;text-align:left;min-width:320px">'.syncodooText('Ce taux est-il correct ?', 'Is dit tarief correct?').'</th>';
    print '</tr></thead><tbody>';

    foreach ($vatChecks['pending_rates'] as $pendingRateRow) {
        $rowId = (int) ($pendingRateRow['rowid'] ?? 0);
        if ($rowId <= 0) {
            continue;
        }
        $country = (string) ($pendingRateRow['country_code'] ?? '');
        $rate = (float) ($pendingRateRow['vat_rate'] ?? 0);
        $isIntegerRate = (abs($rate - round($rate)) < 0.0001);
        $rateFloor = (float) floor($rate);
        $rateHalf = $rateFloor + 0.5;
        $rateCeil = (float) ceil($rate);
        
        // Auto-selection: select the closest approximation if very close
        $autoSelectedRate = null;
        $dropdownRates = [];
        
        if (!$isIntegerRate) {
            // Check floor (very close = within 0.05)
            $isFloorClose = (abs($rate - $rateFloor) < 0.05);
            
            // Check half (within range [floor+0.41, floor+0.59])
            $isHalfInRange = ($rate >= ($rateFloor + 0.41) && $rate <= ($rateFloor + 0.59));
            
            // Check ceil (very close = within 0.05)
            $isCeilClose = (abs($rate - $rateCeil) < 0.05);
            
            // Priority: floor > half > ceil (first closest wins)
            if ($isFloorClose) {
                $autoSelectedRate = $rateFloor;
            } elseif ($isHalfInRange) {
                $autoSelectedRate = $rateHalf;
            } elseif ($isCeilClose) {
                $autoSelectedRate = $rateCeil;
            }
            
            // Add remaining rates to dropdown if not auto-selected
            if ($autoSelectedRate === null || abs($rateFloor - $autoSelectedRate) >= 0.0001) {
                $dropdownRates[$rateFloor] = $rateFloor;
            }
            if ($autoSelectedRate === null || abs($rateHalf - $autoSelectedRate) >= 0.0001) {
                $dropdownRates[$rateHalf] = $rateHalf;
            }
            if ($autoSelectedRate === null || abs($rateCeil - $autoSelectedRate) >= 0.0001) {
                $dropdownRates[$rateCeil] = $rateCeil;
            }
            
            // Sort dropdown rates numerically
            ksort($dropdownRates);
        }
        
        $sourceLabel = ((string) ($pendingRateRow['source'] ?? '') === 'odoo') ? 'Odoo' : 'Dolibarr';
        $firstRef = (string) ($pendingRateRow['first_ref'] ?? '');
        $ctx = $invoiceContextByRef[$firstRef] ?? ['partner' => '—', 'subject' => '—'];

        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0"><strong>'.htmlspecialchars($country).'</strong></td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.number_format($rate, 2, ',', ' ').' %</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars($sourceLabel).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0"><code>'.htmlspecialchars($firstRef).'</code></td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars((string) ($ctx['partner'] ?? '—')).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">'.htmlspecialchars((string) ($ctx['subject'] ?? '—')).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #f0f0f0">';
        print '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        print '<select name="vat_confirm['.$rowId.']" onchange="syncVatToggleCorrectRate(this,'.$rowId.')" style="padding:4px">';
        print '<option value="skip">'.syncodooText('— Choisir —', '— Kies —').'</option>';
        print '<option value="yes">'.syncodooText('✅ Oui, taux exact', '✅ Ja, exact tarief').'</option>';
        print '<option value="no">'.syncodooText('❌ Non, taux incorrect', '❌ Nee, onjuist tarief').'</option>';
        print '</select>';
        print '<span id="vat_cr_wrap_'.$rowId.'" style="display:none;align-items:center;gap:4px">';
        print '<label style="font-size:0.9em;color:#555;white-space:nowrap">'.syncodooText('Taux correct', 'Juist tarief').'&nbsp;:</label>';
        if ($isIntegerRate) {
            print '<input type="number" name="vat_correct_rate['.$rowId.']" id="vat_cr_'.$rowId.'"';
            print ' step="0.01" min="0" max="100" placeholder="ex: 21"';
            print ' style="width:90px;padding:4px">';
        } else {
            print '<select name="vat_correct_rate['.$rowId.']" id="vat_cr_'.$rowId.'" style="padding:4px;min-width:180px">';
            if ($autoSelectedRate !== null) {
                // Auto-selected rate: only show it, already selected
                $autoSelectedValue = number_format((float) $autoSelectedRate, 2, '.', '');
                $autoSelectedLabel = number_format((float) $autoSelectedRate, 2, ',', ' ');
                print '<option value="">'.syncodooText('— Choisir un taux —', '— Kies een tarief —').'</option>';
                print '<option value="'.$autoSelectedValue.'" selected>'.$autoSelectedLabel.' % ✓ '.syncodooText('(d&eacute;tect&eacute; automatiquement)', '(automatisch ged&eacute;tect&eacute;erd)').'</option>';
                // Show other options from dropdown rates if any
                foreach ($dropdownRates as $candidateRate) {
                    if (abs($candidateRate - $autoSelectedRate) >= 0.0001) {
                        $candidateValue = number_format((float) $candidateRate, 2, '.', '');
                        $candidateLabel = number_format((float) $candidateRate, 2, ',', ' ');
                        print '<option value="'.$candidateValue.'">'.$candidateLabel.' %</option>';
                    }
                }
            } else {
                // No auto-selection: show dropdown rates
                print '<option value="">'.syncodooText('— Choisir un taux —', '— Kies een tarief —').'</option>';
                foreach ($dropdownRates as $candidateRate) {
                    $candidateValue = number_format((float) $candidateRate, 2, '.', '');
                    $candidateLabel = number_format((float) $candidateRate, 2, ',', ' ');
                    print '<option value="'.$candidateValue.'">'.$candidateLabel.' %</option>';
                }
            }
            print '</select>';
        }
        print ' <span style="font-size:0.9em">%</span>';
        print '</span>';
        print '</div>';
        print '</td>';
        print '</tr>';
    }

    print '</tbody></table>';
    print '<div style="margin-top:10px">';
    print '<button type="submit" class="butAction">'.syncodooText('Enregistrer mes confirmations TVA', 'Mijn btw-bevestigingen opslaan').'</button>';
    print '</div>';
    print '</form>';
    print '<script>';
    print 'function syncVatToggleCorrectRate(sel, rowId) {';
    print '  var wrap = document.getElementById("vat_cr_wrap_" + rowId);';
    print '  if (!wrap) return;';
    print '  var show = sel.value === "no";';
    print '  wrap.style.display = show ? "flex" : "none";';
    print '  if (!show) {';
    print '    var inp = document.getElementById("vat_cr_" + rowId);';
    print '    if (inp) inp.value = "";';
    print '  }';
    print '}';
    print '</script>';
    print '</div>';
}

$vatInconsistencyRows = $showInvoices ? buildVatInconsistencyRows($divergences) : [];
if (!empty($vatInconsistencyRows) && $user->rights->syncodoo->lancer) {
    print '<div style="border:1px solid #f5c6cb;border-radius:6px;overflow:hidden;margin:12px 0">';
    print '<div style="background:#fff1f3;padding:10px 16px;border-bottom:1px solid #f5c6cb">';
    print '<strong>🧮 '.syncodooText('Correction directe des incoherences TVA', 'Directe correctie van btw-inconsistenties').'</strong> ';
    print '<span style="color:#6c757d">('.count($vatInconsistencyRows).' '.syncodooText('ligne(s)', 'regel(s)').')</span>';
    print '</div>';
    print '<form method="POST" action="'.dol_buildpath('/syncodoo/divergences.php', 1).'" style="padding:10px 16px">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="confirm_vat_inconsistencies">';
    if (!empty($scope)) {
        print '<input type="hidden" name="scope" value="'.htmlspecialchars($scope).'">';
    }
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 10px;text-align:left">'.syncodooText('Appliquer', 'Toepassen').'</th>';
    print '<th style="padding:8px 10px;text-align:left">'.syncodooText('Source', 'Bron').'</th>';
    print '<th style="padding:8px 10px;text-align:left">'.syncodooText('Type', 'Type').'</th>';
    print '<th style="padding:8px 10px;text-align:left">'.syncodooText('Reference', 'Referentie').'</th>';
    print '<th style="padding:8px 10px;text-align:left">HTVA</th>';
    print '<th style="padding:8px 10px;text-align:left">TVA</th>';
    print '<th style="padding:8px 10px;text-align:left">TVAC</th>';
    print '<th style="padding:8px 10px;text-align:left">'.syncodooText('Controle direct', 'Directe controle').'</th>';
    print '</tr></thead><tbody>';

    $jsKeys = [];
    foreach ($vatInconsistencyRows as $rowKey => $row) {
        $safeKey = htmlspecialchars((string) $rowKey);
        $source = (string) ($row['source'] ?? '');
        $type = (string) ($row['type'] ?? 'customer');
        $ref = (string) ($row['ref'] ?? '');
        $id = (int) ($row['id'] ?? 0);
        $ht = (float) ($row['ht'] ?? 0);
        $tva = (float) ($row['tva'] ?? 0);
        $ttc = (float) ($row['ttc'] ?? 0);

        print '<tr>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">';
        print '<input type="checkbox" name="vat_fix_apply['.$safeKey.']" value="1">';
        print '<input type="hidden" name="vat_fix_source['.$safeKey.']" value="'.htmlspecialchars($source).'">';
        print '<input type="hidden" name="vat_fix_type['.$safeKey.']" value="'.htmlspecialchars($type).'">';
        print '<input type="hidden" name="vat_fix_ref['.$safeKey.']" value="'.htmlspecialchars($ref).'">';
        print '<input type="hidden" name="vat_fix_id['.$safeKey.']" value="'.$id.'">';
        print '</td>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">'.htmlspecialchars((string) ($row['source_label'] ?? $source)).'</td>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">'.htmlspecialchars((string) ($row['type_label'] ?? $type)).'</td>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec"><code>'.htmlspecialchars($ref).'</code></td>';

        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">';
        print '<div class="opacitymedium" style="font-size:0.82em">'.price($ht, 0, '', 1, 2, 2).'</div>';
        print '<input type="text" name="vat_fix_ht['.$safeKey.']" id="vat_fix_ht_'.$safeKey.'" value="'.htmlspecialchars(number_format($ht, 2, '.', '')).'" style="width:100px;padding:4px" oninput="syncVatFixPreview(\''.$safeKey.'\')">';
        print '</td>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">';
        print '<div class="opacitymedium" style="font-size:0.82em">'.price($tva, 0, '', 1, 2, 2).'</div>';
        print '<input type="text" name="vat_fix_tva['.$safeKey.']" id="vat_fix_tva_'.$safeKey.'" value="'.htmlspecialchars(number_format($tva, 2, '.', '')).'" style="width:100px;padding:4px" oninput="syncVatFixPreview(\''.$safeKey.'\')">';
        print '</td>';
        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">';
        print '<div class="opacitymedium" style="font-size:0.82em">'.price($ttc, 0, '', 1, 2, 2).'</div>';
        print '<input type="text" name="vat_fix_ttc['.$safeKey.']" id="vat_fix_ttc_'.$safeKey.'" value="'.htmlspecialchars(number_format($ttc, 2, '.', '')).'" style="width:100px;padding:4px" oninput="syncVatFixPreview(\''.$safeKey.'\')">';
        print '</td>';

        print '<td style="padding:8px 10px;border-bottom:1px solid #ececec">';
        print '<div id="vat_fix_check_'.$safeKey.'" class="opacitymedium" style="font-size:0.86em">-</div>';
        print '</td>';
        print '</tr>';

        $jsKeys[] = $safeKey;
    }

    print '</tbody></table>';
    print '</div>';
    print '<div style="margin-top:10px">';
    print '<button type="submit" class="butAction">'.syncodooText('Confirmer les corrections TVA', 'Btw-correcties bevestigen').'</button>';
    print '</div>';
    print '</form>';
    print '<script>';
    print 'function syncVatFixParse(n){if(n===null||n===undefined)return NaN;var s=(""+n).trim().replace(/\s+/g,"").replace(",", ".");return parseFloat(s);}';
    print 'function syncVatFixPreview(key){';
    print ' var ht=syncVatFixParse(document.getElementById("vat_fix_ht_"+key).value);';
    print ' var tva=syncVatFixParse(document.getElementById("vat_fix_tva_"+key).value);';
    print ' var ttc=syncVatFixParse(document.getElementById("vat_fix_ttc_"+key).value);';
    print ' var out=document.getElementById("vat_fix_check_"+key); if(!out) return;';
    print ' if(!isFinite(ht)||!isFinite(tva)||!isFinite(ttc)){out.style.color="#856404"; out.textContent="'.syncodooText('Valeurs invalides', 'Ongeldige waarden').'"; return;}';
    print ' var delta=(ht+tva)-ttc; var ok=Math.abs(delta)<=0.02; var rate=(Math.abs(ht)>1e-9)?((tva/ht)*100):0;';
    print ' out.style.color=ok?"#1f8f43":"#b00020";';
    print ' out.textContent=(ok?"✓ ":"✗ ")+"'.syncodooText('ecart', 'afwijking').'="+delta.toFixed(2)+" / '.syncodooText('taux TVA', 'btw-tarief').'="+rate.toFixed(2)+"%";';
    print '}';
    foreach ($jsKeys as $jsKey) {
        print 'syncVatFixPreview("'.$jsKey.'");';
    }
    print '</script>';
    print '</div>';
}

// Comptage total
$total = 0;
if ($showInvoices) {
    $total += count($divergences['invoices_only_odoo'])
        + count($divergences['invoices_only_doli']);
}
if ($showThirdparties) {
    $total += count($divergences['tiers_only_odoo'])
        + count($divergences['tiers_only_doli'])
        + count($divergences['tiers']['differences']);
}

if ($total === 0) {
    if (!empty($_SESSION['syncodoo_divergences_reset'])) {
        print '<div class="warning" style="font-size:1.02em;padding:16px;margin-top:10px">';
        print '🔄 Reset active: la liste des divergences est vide tant qu\'une synchronisation n\'a pas été relancée.';
        print '</div>';
        print dol_get_fiche_end();
        llxFooter();
        $db->close();
        exit;
    }
    print '<div class="ok" style="font-size:1.05em;padding:16px;margin-top:10px">
        '.syncodooText('✓ Aucune divergence d&eacute;tect&eacute;e — les deux syst&egrave;mes sont synchronis&eacute;s.', '✓ Geen divergenties gedetecteerd — beide systemen zijn gesynchroniseerd.').'
    </div>';
    print dol_get_fiche_end();
    llxFooter();
    $db->close();
    exit;
}

print '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;
       padding:12px 16px;margin:10px 0 20px">
    <strong>⚠ '.$total.' '.syncodooText('divergence(s) d&eacute;tect&eacute;e(s).', 'divergentie(s) ged&eacute;tect&eacute;erd.').'</strong>
    '.syncodooText(
        'S&eacute;lectionnez une action par ligne (supprimer d\'un c&ocirc;t&eacute; ou synchroniser), puis cliquez sur <em>Appliquer les actions</em>. Les lignes sans action restent inchang&eacute;es.',
        'Selecteer per regel een actie (aan een kant verwijderen of synchroniseren), en klik daarna op <em>Acties toepassen</em>. Regels zonder actie blijven ongewijzigd.'
    ).'
</div>';

print '<form id="divergence-actions-form" method="POST" action="'.dol_buildpath('/syncodoo/divergences.php', 1).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="apply_actions">';
if (!empty($scope)) {
    print '<input type="hidden" name="scope" value="'.htmlspecialchars($scope).'">';
}

print '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 16px 0">';
print '<a class="butAction" href="'.dol_buildpath('/syncodoo/divergences.php', 1).'?scope=tiers">'.syncodooText('Synchroniser les tiers', 'Relaties synchroniseren').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/syncodoo/divergences.php', 1).'">'.syncodooText('Vue complete', 'Volledige weergave').'</a>';
if ($user->rights->syncodoo->lancer) {
    print '<span style="margin-left:auto"></span>';
    print '<button type="submit" form="divergence-actions-form" class="butActionDelete">⚡ '.syncodooText('Appliquer les actions', 'Acties toepassen').'</button>';
    $resetUrl = dol_buildpath('/syncodoo/divergences.php', 1).'?action=reset_divergences&token='.urlencode(newToken());
    if (!empty($scope)) {
        $resetUrl .= '&scope='.urlencode($scope);
    }
    print '<a class="butAction" href="'.$resetUrl.'">🔄 '.syncodooText('Reinitialiser', 'Reset').'</a>';
}
if ($scope === 'tiers') {
    print '<span class="opacitymedium">'.syncodooText('Ce mode affiche en priorit&eacute; les tiers a synchroniser, y compris ceux lies aux factures.', 'Deze modus toont eerst de relaties die prioriteit hebben voor synchronisatie, inclusief die van facturen.').'</span>';
} elseif ($scope === 'effacer') {
    print '<span class="opacitymedium">'.syncodooText('Ce mode priorise les actions de suppression sur les tiers.', 'Deze modus geeft voorrang aan verwijderacties op relaties.').'</span>';
}
print '</div>';

if (!empty($missingTierResolutions)) {
    print '<div style="border:1px solid #f5c6cb;border-radius:6px;overflow:hidden;margin:0 0 16px 0">';
    print '<div style="background:#fdecea;padding:10px 16px;border-bottom:1px solid #f5c6cb">';
    print '<strong>⚠ '.syncodooText('Tiers manquants d&eacute;tect&eacute;s pendant la synchronisation des factures', 'Ontbrekende relaties ged&eacute;tect&eacute;erd tijdens factuursynchronisatie').'</strong>';
    print ' <span style="color:#6c757d">('.count($missingTierResolutions).' '.syncodooText('element(s)', 'item(s)').')</span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Facture', 'Factuur').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Tiers Dolibarr', 'Relatie Dolibarr').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Partenaire Odoo', 'Odoo-partner').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Cause d&eacute;tect&eacute;e', 'Ged&eacute;tect&eacute;erde oorzaak').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:260px">'.syncodooText('Action sur le tiers', 'Relatieactie').'</th>';
    print '</tr></thead><tbody>';

    foreach ($missingTierResolutions as $key => $row) {
        $safeKey = htmlspecialchars((string) $key);
        $invoiceRef = (string) ($row['invoice_ref'] ?? '');
        $dolId = (int) ($row['dol_id'] ?? 0);
        $odooId = (int) ($row['odoo_id'] ?? 0);
        $dolLabel = (string) ($row['dol_label'] ?? '—');
        $odooLabel = (string) ($row['odoo_label'] ?? '—');
        $reason = (string) ($row['reason'] ?? '');

        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><code>'.htmlspecialchars($invoiceRef).'</code></td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($dolLabel).($dolId > 0 ? ' <span class="opacitymedium">(#'.$dolId.')</span>' : '').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($odooLabel).($odooId > 0 ? ' <span class="opacitymedium">(#'.$odooId.')</span>' : '').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;color:#b00020">'.htmlspecialchars($reason).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        print '<input type="hidden" name="missing_tier_invoice_ref['.$safeKey.']" value="'.htmlspecialchars($invoiceRef).'">';
        print '<input type="hidden" name="missing_tier_dol_id['.$safeKey.']" value="'.$dolId.'">';
        print '<input type="hidden" name="missing_tier_odoo_id['.$safeKey.']" value="'.$odooId.'">';
        print '<input type="hidden" name="missing_tier_dol_label['.$safeKey.']" value="'.htmlspecialchars($dolLabel).'">';
        print '<input type="hidden" name="missing_tier_odoo_label['.$safeKey.']" value="'.htmlspecialchars($odooLabel).'">';
        print '<select name="missing_tier_actions['.$safeKey.']" style="width:100%;padding:4px">';
        print '<option value="skip">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
        if ($dolId > 0) {
            print '<option value="add_odoo">'.syncodooText('➕ Ajouter ce tiers dans Odoo', '➕ Deze relatie toevoegen aan Odoo').'</option>';
        }
        if ($odooId > 0) {
            print '<option value="add_doli">'.syncodooText('➕ Ajouter ce tiers dans Dolibarr', '➕ Deze relatie toevoegen aan Dolibarr').'</option>';
        }
        if ($dolId > 0 && $odooId > 0) {
            print '<option value="add_both">'.syncodooText('🔁 Ajouter/synchroniser dans les deux', '🔁 Toevoegen/synchroniseren op beide').'</option>';
        }
        print '</select>';
        print '</td>';
        print '</tr>';
    }

    print '</tbody></table></div></div>';
}

// ── Facturen : alleen in Odoo ───────────────────────────
if ($showInvoices && !empty($divergences['invoices_only_odoo'])) {
    $customer_invoices = array_filter($divergences['invoices_only_odoo'], fn($r) => ($r['type'] ?? 'customer') === 'customer');
    $supplier_invoices = array_filter($divergences['invoices_only_odoo'], fn($r) => ($r['type'] ?? 'customer') === 'supplier');

    if (!empty($customer_invoices)) {
        renderInvoiceSection(syncodooText('📄 Factures clients | uniquement dans Odoo', '📄 Facturen klanten | alleen in Odoo'), 'customer', 'odoo', $customer_invoices);
    }
    if (!empty($supplier_invoices)) {
        renderInvoiceSection(syncodooText('📦 Factures fournisseurs | uniquement dans Odoo', '📦 Facturen leveranciers | alleen in Odoo'), 'supplier', 'odoo', $supplier_invoices);
    }
}

// ── Facturen : alleen in Dolibarr ──────────────────────
if ($showInvoices && !empty($divergences['invoices_only_doli'])) {
    $customer_invoices = array_filter($divergences['invoices_only_doli'], fn($r) => ($r['type'] ?? 'customer') === 'customer');
    $supplier_invoices = array_filter($divergences['invoices_only_doli'], fn($r) => ($r['type'] ?? 'customer') === 'supplier');

    if (!empty($customer_invoices)) {
        renderInvoiceSection(syncodooText('📄 Factures clients | uniquement dans Dolibarr', '📄 Facturen klanten | alleen in Dolibarr'), 'customer', 'dolibarr', $customer_invoices);
    }
    if (!empty($supplier_invoices)) {
        renderInvoiceSection(syncodooText('📦 Factures fournisseurs | uniquement dans Dolibarr', '📦 Facturen leveranciers | alleen in Dolibarr'), 'supplier', 'dolibarr', $supplier_invoices);
    }
}

// ── Relatie : alleen in Odoo ──────────────────────────────
if ($showThirdparties && !empty($divergences['tiers_only_odoo'])) {
    print '<div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:16px 0">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #dee2e6">';
    print '<strong>🏢 '.syncodooText('Tiers pr&eacute;sents uniquement dans Odoo', 'Relaties alleen aanwezig in Odoo').'</strong> ';
    print '<span style="background:#e8f0fe;color:#1a56a0;padding:2px 8px;border-radius:10px;font-size:0.8em;font-weight:600">Odoo</span>';
    print ' <span style="color:#6c757d;font-weight:400">('.count($divergences['tiers_only_odoo']).' '.syncodooText('element(s)', 'element(en)').')</span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Nom', 'Naam').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Email</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Telephone', 'Telefoon').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Ville', 'Stad').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:220px">Types</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:200px">'.syncodooText('Action a effectuer', 'Uit te voeren actie').'</th>';
    print '</tr></thead>';
    print '<tbody>';
    foreach ($divergences['tiers_only_odoo'] as $row) {
        $id = $row['_id'] ?? $row['id'] ?? '';
        $ref = $row['_ref'] ?? $row['name'] ?? '';
        $types = [];
        if (($row['customer_rank'] ?? 0) > 0) $types[] = syncodooText('Client', 'Klant');
        if (($row['supplier_rank'] ?? 0) > 0) $types[] = syncodooText('Fournisseur', 'Leverancier');
        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><strong>'.htmlspecialchars($ref).'</strong> '.
              (implode(' / ', $types) ? '<span style="font-size:0.85em;color:#6c757d">('.implode(' / ', $types).')</span>' : '').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['email'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['phone'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['city'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        renderThirdpartyTypeEditor((string) $id, 'odoo', $ref, thirdpartyTypeFlagsFromOdooRow($row), true);
        print '</td>';
        $val = htmlspecialchars('thirdparty|'.$id.'|'.$ref.'|');
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        print '<select name="actions[]" style="width:100%;padding:4px">';
        print '<option value="">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
        print '<option value="'.$val.'delete_odoo">'.syncodooText('🗑 Archiver dans Odoo', '🗑 Archiveren in Odoo').'</option>';
        print '<option value="'.$val.'delete_both_from_odoo">'.syncodooText('🧹 Supprimer partout', '🧹 Overal verwijderen').'</option>';
        print '<option value="'.$val.'sync_to_doli">'.syncodooText('📥 Synchroniser vers Dolibarr', '📥 Synchroniseren naar Dolibarr').'</option>';
        print '</select></td>';
        print '</tr>';
    }
    print '</tbody></table></div></div>';
}

// ── Relatie : alleen in Dolibarr ──────────────────────────
if ($showThirdparties && !empty($divergences['tiers_only_doli'])) {
    print '<div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:16px 0">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #dee2e6">';
    print '<strong>🏢 '.syncodooText('Tiers pr&eacute;sents uniquement dans Dolibarr', 'Relaties alleen aanwezig in Dolibarr').'</strong> ';
    print '<span style="background:#fce8e6;color:#a03000;padding:2px 8px;border-radius:10px;font-size:0.8em;font-weight:600">Dolibarr</span>';
    print ' <span style="color:#6c757d;font-weight:400">('.count($divergences['tiers_only_doli']).' '.syncodooText('element(s)', 'element(en)').')</span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Nom', 'Naam').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Email</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Telephone', 'Telefoon').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Ville', 'Stad').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:220px">Types</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:200px">'.syncodooText('Action a effectuer', 'Uit te voeren actie').'</th>';
    print '</tr></thead>';
    print '<tbody>';
    foreach ($divergences['tiers_only_doli'] as $row) {
        $id = $row['_id'] ?? $row['rowid'] ?? '';
        $ref = $row['_ref'] ?? $row['name'] ?? '';
        $types = [];
        if ((int)($row['client']      ?? 0)) $types[] = syncodooText('Client', 'Klant');
        if ((int)($row['fournisseur'] ?? 0)) $types[] = syncodooText('Fournisseur', 'Leverancier');
        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><strong>'.htmlspecialchars($ref).'</strong> '.
              (implode(' / ', $types) ? '<span style="font-size:0.85em;color:#6c757d">('.implode(' / ', $types).')</span>' : '').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['email'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['phone'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['town'] ?: '—').'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        renderThirdpartyTypeEditor((string) $id, 'dolibarr', $ref, thirdpartyTypeFlagsFromDolibarrRow($row), true);
        print '</td>';
        $val = htmlspecialchars('thirdparty|'.$id.'|'.$ref.'|');
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        print '<select name="actions[]" style="width:100%;padding:4px">';
        print '<option value="">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
        print '<option value="'.$val.'delete_doli">'.syncodooText('🗑 Archiver dans Dolibarr', '🗑 Archiveren in Dolibarr').'</option>';
        print '<option value="'.$val.'delete_both_from_doli">'.syncodooText('🧹 Supprimer partout', '🧹 Overal verwijderen').'</option>';
        print '<option value="'.$val.'sync_to_odoo">'.syncodooText('📤 Synchroniser vers Odoo', '📤 Synchroniseren naar Odoo').'</option>';
        print '</select></td>';
        print '</tr>';
    }
    print '</tbody></table></div></div>';
}

// ── Relatie : différences ──────────────────────────────
if ($showThirdparties && !empty($divergences['tiers']['differences'])) {
    print '<div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:16px 0">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #dee2e6">';
    print '<strong>🏢 '.syncodooText('Diff&eacute;rences sur les tiers', 'Verschillen bij relaties').'</strong> ('.syncodooText('donn&eacute;es divergentes dans les deux syst&egrave;mes', 'afwijkende gegevens in beide systemen').')';
    print ' <span style="color:#6c757d;font-weight:400">('.count($divergences['tiers']['differences']).' '.syncodooText('difference(s)', 'verschil(len)').')</span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Nom', 'Naam').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Dolibarr</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Odoo</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:220px">Types</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:230px">'.syncodooText('Action de synchronisation', 'Synchronisatieactie').'</th>';
    print '</tr></thead>';
    print '<tbody>';
    foreach ($divergences['tiers']['differences'] as $diff) {
        $d = $diff['dolibarr'];
        $o = $diff['odoo'];
        $d_id = $d['dol_id'] ?? $d['rowid'] ?? '';
        $o_id = $o['odoo_id'] ?? $o['id'] ?? '';
        print '<tr>';
        $nameDiff = ((string) ($d['name'] ?? '') !== (string) ($o['name'] ?? ''));
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><strong'.($nameDiff ? ' style="color:#b00020"' : '').'>'.htmlspecialchars($d['name']).'</strong></td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-size:0.85em">';
        print renderThirdpartyDiffField(syncodooText('Nom', 'Naam'),         $d['name']  ?? '', $o['name']  ?? '', 'dolibarr').'<br>';
        print renderThirdpartyDiffField('Email',                             $d['email'] ?? '', $o['email'] ?? '', 'dolibarr', 'email').'<br>';
        print renderThirdpartyDiffField(syncodooText('Tél', 'Tel.'),         $d['phone'] ?? '', $o['phone'] ?? '', 'dolibarr', 'phone').'<br>';
        print renderThirdpartyDiffField(syncodooText('Code postal', 'Postcode'), $d['zip'] ?? '', $o['zip'] ?? '', 'dolibarr').'<br>';
        print renderThirdpartyDiffField(syncodooText('Ville', 'Stad'),       $d['town']  ?? '', $o['town']  ?? '', 'dolibarr').'<br>';
        print renderThirdpartyDiffField(syncodooText('TVA', 'BTW'),          $d['vat']   ?? '', $o['vat']   ?? '', 'dolibarr', 'vat').'<br>';
        // Types : comparer uniquement klant et leverancier (prospect ignoré)
        $dTypeLabel = formatThirdpartyTypeLabel(['client' => !empty($d['type_flags']['client']), 'fournisseur' => !empty($d['type_flags']['fournisseur'])]);
        $oTypeLabel = formatThirdpartyTypeLabel(['client' => !empty($o['type_flags']['client']), 'fournisseur' => !empty($o['type_flags']['fournisseur'])]);
        print renderThirdpartyDiffField('Type', $dTypeLabel, $oTypeLabel, 'dolibarr');
        print '</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-size:0.85em">';
        print renderThirdpartyDiffField(syncodooText('Nom', 'Naam'),         $d['name']  ?? '', $o['name']  ?? '', 'odoo').'<br>';
        print renderThirdpartyDiffField('Email',                             $d['email'] ?? '', $o['email'] ?? '', 'odoo', 'email').'<br>';
        print renderThirdpartyDiffField(syncodooText('Tél', 'Tel.'),         $d['phone'] ?? '', $o['phone'] ?? '', 'odoo', 'phone').'<br>';
        print renderThirdpartyDiffField(syncodooText('Code postal', 'Postcode'), $d['zip'] ?? '', $o['zip'] ?? '', 'odoo').'<br>';
        print renderThirdpartyDiffField(syncodooText('Ville', 'Stad'),       $d['town']  ?? '', $o['town']  ?? '', 'odoo').'<br>';
        print renderThirdpartyDiffField(syncodooText('TVA', 'BTW'),          $d['vat']   ?? '', $o['vat']   ?? '', 'odoo', 'vat').'<br>';
        $dTypeLabel = formatThirdpartyTypeLabel(['client' => !empty($d['type_flags']['client']), 'fournisseur' => !empty($d['type_flags']['fournisseur'])]);
        $oTypeLabel = formatThirdpartyTypeLabel(['client' => !empty($o['type_flags']['client']), 'fournisseur' => !empty($o['type_flags']['fournisseur'])]);
        print renderThirdpartyDiffField('Type', $dTypeLabel, $oTypeLabel, 'odoo');
        print '</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        renderThirdpartyTypeEditor((string) $d_id.':'.(string) $o_id, 'pair', $d['name'], mergeThirdpartyTypeFlags(thirdpartyTypeFlagsFromDolibarrRow($d), thirdpartyTypeFlagsFromOdooRow($o)), false);
        print '</td>';
        $val = htmlspecialchars('thirdparty|'.$d_id.':'.$o_id.'|'.$d['name'].'|');
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        print '<select name="actions[]" style="width:100%;padding:4px">';
        print '<option value="">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
        print '<option value="'.$val.'delete_both_pair">'.syncodooText('🧹 Supprimer partout', '🧹 Overal verwijderen').'</option>';
        print '<option value="'.$val.'sync_to_doli">'.syncodooText('📥 Importer depuis Odoo', '📥 Importeren uit Odoo').'</option>';
        print '<option value="'.$val.'sync_to_odoo">'.syncodooText('📤 Exporter vers Odoo', '📤 Exporteren naar Odoo').'</option>';
        print '</select></td>';
        print '</tr>';
    }
    print '</tbody></table></div></div>';
}

// ── Facturen : différences ────────────────────────────
if ($showInvoices && !empty($divergences['factures']['differences'])) {
    $customer_diffs = array_filter($divergences['factures']['differences'], fn($r) => ($r['dolibarr']['type'] ?? 'customer') === 'customer');
    $supplier_diffs = array_filter($divergences['factures']['differences'], fn($r) => ($r['dolibarr']['type'] ?? 'customer') === 'supplier');

    if (!empty($customer_diffs)) {
        renderInvoiceDiftSection(syncodooText('📄 Factures clients | Donn&eacute;es divergentes', '📄 Facturen klanten | Afwijkende gegevens'), 'customer', $customer_diffs);
    }
    if (!empty($supplier_diffs)) {
        renderInvoiceDiftSection(syncodooText('📦 Factures fournisseurs | Donn&eacute;es divergentes', '📦 Facturen leveranciers | Afwijkende gegevens'), 'supplier', $supplier_diffs);
    }
}

// ── Statut droits ─────────────────────────────────────────
if (!$user->rights->syncodoo->lancer) {
    print '<br><div><span class="opacitymedium">'.syncodooText('Vous n\'avez pas le droit d\'appliquer des actions.', 'U hebt geen rechten om acties toe te passen.').'</span></div>';
}
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();

// ════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════

function buildSyncodooHead(object $conf, object $user): array
{
    $head = [];
    $head[0] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=dashboard',  'Dashboard', 'dashboard'];
    $head[1] = [dol_buildpath('/syncodoo/divergences.php', 1),              'Divergences', 'divergences'];
    $head[2] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=log',         'Journal', 'log'];
    if ($user->rights->syncodoo->config) {
        $head[3] = [dol_buildpath('/syncodoo/admin/config.php', 1), 'Configuration', 'config'];
        $head[4] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=about',  'À propos', 'about'];
    } else {
        $head[3] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=about',  'À propos', 'about'];
    }
    return $head;
}

function renderInvoiceSection(string $title, string $invoice_type, string $side, array $rows): void
{
    if (empty($rows)) return;

    $sideBadge = ($side === 'odoo')
        ? '<span style="background:#e8f0fe;color:#1a56a0;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">Odoo</span>'
        : '<span style="background:#fce8e6;color:#a03000;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">Dolibarr</span>';

    $typeBadge = ($invoice_type === 'supplier')
        ? '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">'.syncodooText('Fournisseur', 'Leverancier').'</span>'
        : '<span style="background:#d1ecf1;color:#0c5460;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">'.syncodooText('Client', 'Klant').'</span>';

    print '<div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:16px 0">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #dee2e6;display:flex;justify-content:space-between;align-items:center">';
    print '<span><strong>'.(strpos($title, '|') ? str_replace('|', '  ', $title) : $title).'</strong>  '.$typeBadge.'  '.$sideBadge.
          ' <span style="color:#6c757d;font-weight:400;font-size:0.9em">('.count($rows).' '.syncodooText('element(s)', 'item(s)').')</span></span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('R&eacute;f&eacute;rence', 'Referentie').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Partenaire', 'Relatie').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Date', 'Datum').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Montants HTVA / TVA / TVAC', 'Bedragen excl. / btw / incl.').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Statut', 'Status').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:200px">'.syncodooText('Action', 'Actie').'</th>';
    print '</tr></thead>';
    print '<tbody>';

    foreach ($rows as $row) {
        $id = $row['_id'] ?? $row['rowid'] ?? $row['id'] ?? '';
        $ref = $row['_ref'] ?? $row['ref'] ?? '';
        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><code>'.htmlspecialchars($ref).'</code></td>';

        if ($side === 'odoo') {
            $ht = (float) ($row['total_ht'] ?? $row['amount_untaxed'] ?? 0);
            $tva = (float) ($row['total_tva'] ?? $row['amount_tax'] ?? 0);
            $ttc = (float) ($row['total_ttc'] ?? $row['amount_total'] ?? 0);
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.
                  htmlspecialchars(is_array($row['partner_id']) ? ($row['partner_id'][1] ?? '—') : '—').'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.
                  htmlspecialchars($row['invoice_date'] ?? '—').'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
            print '<div><strong>HTVA:</strong> '.number_format($ht, 2, ',', ' ').' €</div>';
            print '<div><strong>TVA:</strong> '.number_format($tva, 2, ',', ' ').' €</div>';
            print '<div><strong>TVAC:</strong> '.number_format($ttc, 2, ',', ' ').' €</div>';
            if (abs($tva) < 0.00001) {
                print '<div class="opacitymedium" style="font-size:0.82em">'.syncodooText('TVA nulle: HTVA = TVAC', 'Geen btw: excl. = incl.').'</div>';
            }
            print '</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.statusBadge($row['state'] ?? '', [
                'draft'  => ['Concept', '#495057', '#e9ecef'],
                'posted' => ['Gevalideerd',   '#155724', '#d4edda'],
                'cancel' => ['Geannul&eacute;erd',   '#721c24', '#f8d7da'],
            ]).'</td>';
        } else {
            $ht = (float) ($row['total_ht'] ?? 0);
            $tva = (float) ($row['total_tva'] ?? 0);
            $ttc = (float) ($row['total_ttc'] ?? 0);
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($row['socid_name'] ?? '—').'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.
                  htmlspecialchars($row['date_creation'] ?? '—').'</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
            print '<div><strong>HTVA:</strong> '.number_format($ht, 2, ',', ' ').' €</div>';
            print '<div><strong>TVA:</strong> '.number_format($tva, 2, ',', ' ').' €</div>';
            print '<div><strong>TVAC:</strong> '.number_format($ttc, 2, ',', ' ').' €</div>';
            if (abs($tva) < 0.00001) {
                print '<div class="opacitymedium" style="font-size:0.82em">'.syncodooText('TVA nulle: HTVA = TVAC', 'Geen btw: excl. = incl.').'</div>';
            }
            print '</td>';
            print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.statusBadge((string)($row['statut'] ?? '0'), [
                '0' => ['Concept',  '#495057', '#e9ecef'],
                '1' => ['Gevalideerd',    '#155724', '#d4edda'],
                '2' => ['Betaald',      '#0c5460', '#d1ecf1'],
                '3' => ['Stopgezet', '#721c24', '#f8d7da'],
            ]).'</td>';
        }

        $delete_action = ($side === 'odoo') ? 'delete_odoo' : 'delete_doli';
        $delete_both_action = ($side === 'odoo') ? 'delete_both_from_odoo' : 'delete_both_from_doli';
        $sync_action = ($side === 'odoo') ? 'sync_to_doli' : 'sync_to_odoo';
        $sync_label = ($side === 'odoo') ? syncodooText('📥 Synchroniser vers Dolibarr', '📥 Synchroniseren naar Dolibarr') : syncodooText('📤 Synchroniser vers Odoo', '📤 Synchroniseren naar Odoo');
        $delete_label = ($side === 'odoo') ? syncodooText('🗑 Annuler dans Odoo', '🗑 Annuleren in Odoo') : syncodooText('🗑 Supprimer de Dolibarr', '🗑 Verwijderen uit Dolibarr');

        $val = htmlspecialchars('invoice|'.$id.'|'.$ref.'|');
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        print '<select name="actions[]" style="width:100%;padding:4px">';
        print '<option value="">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
        print '<option value="'.$val.$delete_action.'">'.$delete_label.'</option>';
        print '<option value="'.$val.$delete_both_action.'">'.syncodooText('🧹 Supprimer partout', '🧹 Overal verwijderen').'</option>';
        print '<option value="'.$val.$sync_action.'">'.$sync_label.'</option>';
        print '</select></td>';
        print '</tr>';
    }
    print '</tbody></table></div></div>';
}

function renderInvoiceDiftSection(string $title, string $invoice_type, array $diffs): void
{
    if (empty($diffs)) return;

    $typeBadge = ($invoice_type === 'supplier')
        ? '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">'.syncodooText('Fournisseur', 'Leverancier').'</span>'
        : '<span style="background:#d1ecf1;color:#0c5460;padding:2px 8px;border-radius:10px;font-size:0.75em;font-weight:600">'.syncodooText('Client', 'Klant').'</span>';

    print '<div style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin:16px 0">';
    print '<div style="background:#f8f9fa;padding:10px 16px;border-bottom:1px solid #dee2e6">';
    print '<strong>'.(strpos($title, '|') ? str_replace('|', '  ', $title) : $title).'</strong>  '.$typeBadge;
    print ' <span style="color:#6c757d;font-weight:400">('.syncodooText('montants ou donn&eacute;es divergents', 'bedragen of gegevens verschillen').')</span>';
    print ' <span style="color:#6c757d;font-weight:400;font-size:0.85em">('.count($diffs).' '.syncodooText('difference(s)', 'verschil(len)').')</span>';
    print '</div>';
    print '<div style="overflow-x:auto">';
    print '<table class="noborder" style="width:100%;margin:0;border-collapse:collapse">';
    print '<thead><tr class="liste_titre">';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Ref.</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Dolibarr</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">Odoo</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Fournisseur/Client', 'Leverancier/Klant').'</th>';
    print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6">'.syncodooText('Objet de la vente', 'Onderwerp van verkoop').'</th>';
    if ($invoice_type === 'supplier') {
        print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:320px">'.syncodooText('Choix de reference', 'Referentiekeuze').'</th>';
    } else {
        print '<th style="padding:8px 12px;text-align:left;border-bottom:1px solid #dee2e6;min-width:230px">Actie</th>';
    }
    print '</tr></thead>';
    print '<tbody>';

    foreach ($diffs as $diff) {
        $d = $diff['dolibarr'];
        $o = $diff['odoo'];
        $d_id = $d['_id'] ?? $d['rowid'] ?? '';
        print '<tr>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef"><code>'.htmlspecialchars($d['ref']).'</code></td>';
        $dHt = (float) ($d['total_ht'] ?? 0);
        $dTva = (float) ($d['total_tva'] ?? 0);
        $dTtc = (float) ($d['total_ttc'] ?? ($d['total'] ?? 0));
        $oHt = (float) ($o['total_ht'] ?? 0);
        $oTva = (float) ($o['total_tva'] ?? 0);
        $oTtc = (float) ($o['total_ttc'] ?? ($o['total'] ?? 0));
        $diffHt = (abs($dHt - $oHt) > 0.01);
        $diffTva = (abs($dTva - $oTva) > 0.01);
        $diffTtc = (abs($dTtc - $oTtc) > 0.01);

        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-size:0.85em;background:#fff8f8">';
        print '<span style="'.($diffHt ? 'color:#b00020;font-weight:700' : 'color:#495057').'">HTVA: '.number_format($dHt, 2, ',', ' ').' €</span><br>';
        print '<span style="'.($diffTva ? 'color:#b00020;font-weight:700' : 'color:#495057').'">TVA: '.number_format($dTva, 2, ',', ' ').' €</span><br>';
        print '<span style="'.($diffTtc ? 'color:#b00020;font-weight:700' : 'color:#495057').'">TVAC: '.number_format($dTtc, 2, ',', ' ').' €</span><br>';
        print '<span style="color:#6c757d;font-size:0.8em">'.htmlspecialchars($d['date'] ?? '—').'</span>';
        print '</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-size:0.85em;background:#f0f8ff">';
        print '<span style="'.($diffHt ? 'color:#b00020;font-weight:700' : 'color:#495057').'">HTVA: '.number_format($oHt, 2, ',', ' ').' €</span><br>';
        print '<span style="'.($diffTva ? 'color:#b00020;font-weight:700' : 'color:#495057').'">TVA: '.number_format($oTva, 2, ',', ' ').' €</span><br>';
        print '<span style="'.($diffTtc ? 'color:#b00020;font-weight:700' : 'color:#495057').'">TVAC: '.number_format($oTtc, 2, ',', ' ').' €</span><br>';
        print '<span style="color:#6c757d;font-size:0.8em">'.htmlspecialchars($o['date'] ?? '—').'</span>';
        print '</td>';
        $partnerLabel = (string) ($d['socid_name'] ?? ($o['partner_id'][1] ?? '—'));
        $subjectLabel = (string) (($d['subject'] ?? '') !== '' ? $d['subject'] : (($o['subject'] ?? '') !== '' ? $o['subject'] : ($o['name'] ?? '—')));
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($partnerLabel).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">'.htmlspecialchars($subjectLabel).'</td>';
        print '<td style="padding:8px 12px;border-bottom:1px solid #e9ecef">';
        if ($invoice_type === 'supplier') {
            $rowKey = (string) $d_id;
            $odooId = (int) ($o['_id'] ?? $o['id'] ?? 0);
            print '<input type="hidden" name="supplier_diff_ref['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) ($d['ref'] ?? '')).'">';
            print '<input type="hidden" name="supplier_diff_odoo_id['.htmlspecialchars($rowKey).']" value="'.$odooId.'">';
            print '<input type="hidden" name="supplier_diff_d_ht['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $dHt).'">';
            print '<input type="hidden" name="supplier_diff_d_tva['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $dTva).'">';
            print '<input type="hidden" name="supplier_diff_d_ttc['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $dTtc).'">';
            print '<input type="hidden" name="supplier_diff_o_ht['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $oHt).'">';
            print '<input type="hidden" name="supplier_diff_o_tva['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $oTva).'">';
            print '<input type="hidden" name="supplier_diff_o_ttc['.htmlspecialchars($rowKey).']" value="'.htmlspecialchars((string) $oTtc).'">';

            print '<label style="display:block;margin:0 0 6px 0">';
            print '<input type="radio" name="supplier_diff_choice['.htmlspecialchars($rowKey).']" value="keep_odoo"> ';
            print syncodooText('Conserver Odoo', 'Odoo behouden').'</label>';
            print '<label style="display:block">';
            print '<input type="radio" name="supplier_diff_choice['.htmlspecialchars($rowKey).']" value="keep_dolibarr"> ';
            print syncodooText('Conserver Dolibarr', 'Dolibarr behouden').'</label>';
            print '<span class="opacitymedium" style="font-size:0.85em">'.syncodooText('Aucun choix = facture inchang&eacute;e.', 'Geen keuze = factuur ongewijzigd.').'</span>';
        } else {
            $val = htmlspecialchars('invoice|'.$d_id.'|'.$d['ref'].'|');
            print '<select name="actions[]" style="width:100%;padding:4px">';
            print '<option value="">'.syncodooText('— Ne rien faire —', '— Niets doen —').'</option>';
            print '<option value="'.$val.'delete_both_pair">'.syncodooText('🧹 Supprimer partout', '🧹 Overal verwijderen').'</option>';
            print '<option value="'.$val.'sync_to_doli">'.syncodooText('📥 Importer depuis Odoo', '📥 Importeren uit Odoo').'</option>';
            print '<option value="'.$val.'sync_to_odoo">'.syncodooText('📤 Exporter vers Odoo', '📤 Exporteren naar Odoo').'</option>';
            print '</select>';
        }
        print '</td>';
        print '</tr>';
    }
    print '</tbody></table></div>';
    if ($invoice_type === 'supplier') {
        print '<div style="padding:10px 16px;border-top:1px solid #dee2e6;background:#fafafa">';
        print '<button type="submit" form="divergence-actions-form" class="butAction">'.syncodooText('Confirmer les choix fournisseurs', 'Keuzes voor leveranciers bevestigen').'</button>';
        print '</div>';
    }
    print '</div>';
}

function statusBadge(string $key, array $map): string
{
    [$label, $fg, $bg] = array_pad($map[$key] ?? [$key, '#495057', '#e9ecef'], 3, '#e9ecef');
    return '<span style="background:'.htmlspecialchars($bg).';color:'.htmlspecialchars($fg).
           ';padding:2px 8px;border-radius:10px;font-size:0.82em;font-weight:600">'.
           htmlspecialchars($label).'</span>';
}

function isMissingThirdpartyInvoiceError(string $message): bool
{
    $patterns = [
        'Odoo-partner introuvable après synchronisation voor factuur',
        'Relatie Dolibarr introuvable après synchronisation voor factuur',
        'Odoo-partner absent voor factuur',
        'Relatie Dolibarr absent voor factuur',
    ];

    foreach ($patterns as $pattern) {
        $found = function_exists('mb_stripos') ? mb_stripos($message, $pattern) : stripos($message, $pattern);
        if ($found !== false) {
            return true;
        }
    }

    return false;
}

function getDolibarrThirdpartyFromInvoiceContext($db, string $invoiceRef, int $invoiceId = 0): array
{
    $whereCustomer = ($invoiceId > 0)
        ? 'f.rowid = '.((int) $invoiceId)
        : "f.ref = '".$db->escape($invoiceRef)."'";
    $whereSupplier = ($invoiceId > 0)
        ? 'f.rowid = '.((int) $invoiceId)
        : "f.ref = '".$db->escape($invoiceRef)."'";

    $sql = "SELECT s.rowid as tier_id, s.nom as tier_name FROM ".MAIN_DB_PREFIX."facture f ";
    $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc ";
    $sql .= "WHERE ".$whereCustomer." ";
    $sql .= "UNION ALL ";
    $sql .= "SELECT s.rowid as tier_id, s.nom as tier_name FROM ".MAIN_DB_PREFIX."facture_fourn f ";
    $sql .= "LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = f.fk_soc ";
    $sql .= "WHERE ".$whereSupplier." ";
    $sql .= "LIMIT 1";

    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            return [
                'dol_id' => (int) ($obj->tier_id ?? 0),
                'dol_label' => (string) ($obj->tier_name ?? ''),
            ];
        }
    }

    return [
        'dol_id' => 0,
        'dol_label' => '',
    ];
}

function areVatTotalsConsistent(float $ht, float $tva, float $ttc): bool
{
    // Delegates to SyncOdoo::isVatConsistent() to keep a single threshold (0.02).
    static $inst = null;
    if ($inst === null) {
        global $db;
        $inst = new SyncOdoo($db);
    }
    return $inst->isVatConsistent($ht, $tva, $ttc);
}

function parseVatInput($raw): ?float
{
    if ($raw === null) {
        return null;
    }
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

function computeVatRatePercent(float $ht, float $tva): float
{
    if (abs($ht) < 0.00001) {
        return 0.0;
    }
    return round(($tva / $ht) * 100.0, 2);
}

function updateDolibarrInvoiceTotalsByRef($db, string $ref, string $invoiceType, float $ht, float $tva, float $ttc): void
{
    $safeRef = $db->escape($ref);
    $targets = [];
    if ($invoiceType === 'supplier') {
        $targets[] = MAIN_DB_PREFIX.'facture_fourn';
    } elseif ($invoiceType === 'customer') {
        $targets[] = MAIN_DB_PREFIX.'facture';
    } else {
        $targets[] = MAIN_DB_PREFIX.'facture';
        $targets[] = MAIN_DB_PREFIX.'facture_fourn';
    }

    foreach ($targets as $table) {
        $sqlFind = "SELECT rowid FROM ".$table." WHERE ref = '".$safeRef."' ORDER BY rowid DESC LIMIT 1";
        $resFind = $db->query($sqlFind);
        if (!$resFind) {
            continue;
        }
        $obj = $db->fetch_object($resFind);
        if (!$obj) {
            continue;
        }

        $sqlUpdate = "UPDATE ".$table." SET";
        $sqlUpdate .= " total_ht = ".price2num((string) $ht, 'MU');
        $sqlUpdate .= ", total_tva = ".price2num((string) $tva, 'MU');
        $sqlUpdate .= ", total_ttc = ".price2num((string) $ttc, 'MU');
        $sqlUpdate .= " WHERE rowid = ".((int) $obj->rowid);
        if (!$db->query($sqlUpdate)) {
            throw new Exception('Mise a jour Dolibarr impossible: '.$db->lasterror());
        }
        return;
    }

    throw new Exception('Facture Dolibarr introuvable pour la reference '.$ref);
}

function buildVatInconsistencyRows(array $divergences): array
{
    $rows = [];

    $addRow = function (string $source, array $invoice) use (&$rows): void {
        $ht = (float) ($invoice['total_ht'] ?? 0);
        $tva = (float) ($invoice['total_tva'] ?? 0);
        $ttc = (float) ($invoice['total_ttc'] ?? 0);
        if (areVatTotalsConsistent($ht, $tva, $ttc)) {
            return;
        }

        $id = (int) ($invoice['_id'] ?? $invoice['id'] ?? $invoice['rowid'] ?? 0);
        $ref = (string) ($invoice['ref'] ?? $invoice['_ref'] ?? '');
        if ($ref === '' && $id <= 0) {
            return;
        }

        $type = (string) ($invoice['type'] ?? 'customer');
        $rowKey = md5($source.'|'.$id.'|'.$ref.'|'.$type);
        $rows[$rowKey] = [
            'source' => $source,
            'source_label' => ($source === 'odoo') ? 'Odoo' : 'Dolibarr',
            'type' => $type,
            'type_label' => ($type === 'supplier') ? syncodooText('Fournisseur', 'Leverancier') : syncodooText('Client', 'Klant'),
            'id' => $id,
            'ref' => ($ref !== '' ? $ref : (($source === 'odoo') ? ('Odoo #'.$id) : ('Dolibarr #'.$id))),
            'ht' => $ht,
            'tva' => $tva,
            'ttc' => $ttc,
        ];
    };

    foreach (($divergences['invoices_only_doli'] ?? []) as $invoice) {
        $addRow('dolibarr', (array) $invoice);
    }
    foreach (($divergences['invoices_only_odoo'] ?? []) as $invoice) {
        $addRow('odoo', (array) $invoice);
    }
    foreach (($divergences['factures']['differences'] ?? []) as $diff) {
        if (!empty($diff['dolibarr']) && is_array($diff['dolibarr'])) {
            $addRow('dolibarr', $diff['dolibarr']);
        }
        if (!empty($diff['odoo']) && is_array($diff['odoo'])) {
            $addRow('odoo', $diff['odoo']);
        }
    }

    return $rows;
}

function buildInvoiceContextByRef(array $divergences): array
{
    $map = [];

    $collect = function (array $invoice) use (&$map) {
        $ref = (string) ($invoice['ref'] ?? $invoice['_ref'] ?? '');
        if ($ref === '') {
            return;
        }

        $partner = (string) ($invoice['socid_name'] ?? ($invoice['partner_id'][1] ?? ''));
        $subject = (string) ($invoice['subject'] ?? '');
        if ($subject === '') {
            foreach (['invoice_origin', 'payment_reference', 'name', 'odoo_ref'] as $field) {
                $candidate = trim((string) ($invoice[$field] ?? ''));
                if ($candidate !== '' && $candidate !== $ref && $candidate !== '/') {
                    $subject = $candidate;
                    break;
                }
            }
        }
        if ($subject === '') {
            $subject = '—';
        }
        if ($partner === '') {
            $partner = '—';
        }

        $map[$ref] = [
            'partner' => $partner,
            'subject' => $subject,
        ];
    };

    foreach (($divergences['invoices_only_doli'] ?? []) as $invoice) {
        $collect((array) $invoice);
    }
    foreach (($divergences['invoices_only_odoo'] ?? []) as $invoice) {
        $collect((array) $invoice);
    }
    foreach (($divergences['factures']['differences'] ?? []) as $diff) {
        if (!empty($diff['dolibarr']) && is_array($diff['dolibarr'])) {
            $collect($diff['dolibarr']);
        }
        if (!empty($diff['odoo']) && is_array($diff['odoo'])) {
            $collect($diff['odoo']);
        }
    }

    return $map;
}

function getOdooThirdpartyFromInvoiceContext(SyncOdoo $sync, string $invoiceRef, int $invoiceId = 0): array
{
    $invoice = $sync->findOdooInvoiceByRefPublic($invoiceRef, $invoiceId);
    if (!is_array($invoice)) {
        return [
            'odoo_id' => 0,
            'odoo_label' => '',
        ];
    }

    return [
        'odoo_id' => (int) ($invoice['partner_id'][0] ?? 0),
        'odoo_label' => (string) ($invoice['partner_id'][1] ?? ''),
    ];
}

function buildMissingTierResolutionRow($db, SyncOdoo $sync, string $actionType, string $id, string $invoiceRef, string $reason): array
{
    $invoiceId = (int) $id;
    $dolInfo = ['dol_id' => 0, 'dol_label' => ''];
    $odooInfo = ['odoo_id' => 0, 'odoo_label' => ''];

    if ($actionType === 'sync_to_odoo') {
        $dolInfo = getDolibarrThirdpartyFromInvoiceContext($db, $invoiceRef, $invoiceId);
        $odooInfo = getOdooThirdpartyFromInvoiceContext($sync, $invoiceRef, 0);
    } elseif ($actionType === 'sync_to_doli') {
        $odooInfo = getOdooThirdpartyFromInvoiceContext($sync, $invoiceRef, $invoiceId);
        $dolInfo = getDolibarrThirdpartyFromInvoiceContext($db, $invoiceRef, 0);
    }

    if ((int) $dolInfo['dol_id'] <= 0 && (int) $odooInfo['odoo_id'] <= 0) {
        return [];
    }

    return [
        'key' => md5($invoiceRef.'|'.$actionType.'|'.$id),
        'invoice_ref' => $invoiceRef,
        'reason' => $reason,
        'dol_id' => (int) $dolInfo['dol_id'],
        'dol_label' => (string) $dolInfo['dol_label'],
        'odoo_id' => (int) $odooInfo['odoo_id'],
        'odoo_label' => (string) $odooInfo['odoo_label'],
    ];
}

function formatThirdpartyTypeLabel(array $types): string
{
    $labels = [];
    if (!empty($types['client'])) {
        $labels[] = syncodooText('Client', 'Klant');
    }
    if (!empty($types['prospect'])) {
        $labels[] = 'Prospect';
    }
    if (!empty($types['fournisseur'])) {
        $labels[] = syncodooText('Fournisseur', 'Leverancier');
    }

    return empty($labels) ? '—' : implode(' / ', $labels);
}

/**
 * Normalise une valeur comme le fait SyncOdoo::normalizeForComparison()
 * (dupliqué ici pour l'affichage côté PHP de la page)
 */
function normalizeFieldForDisplay($value, string $field = ''): string
{
    if ($value === false || $value === null) {
        return '';
    }
    $str = trim((string) $value);
    if ($field === 'email') {
        return strtolower($str);
    }
    if ($field === 'phone') {
        return preg_replace('/[\s\-\.\(\)\/]/', '', $str);
    }
    if ($field === 'vat') {
        return strtoupper(preg_replace('/[\s\-\.]/', '', $str));
    }
    return $str;
}

function renderThirdpartyDiffField(string $label, $dolValue, $odooValue, string $side, string $normType = ''): string
{
    $dol  = normalizeFieldForDisplay($dolValue,  $normType);
    $odoo = normalizeFieldForDisplay($odooValue, $normType);
    $isDifferent = ($dol !== $odoo);

    // Valeur brute (non normalisée) pour l'affichage
    $rawDisplay = ($side === 'dolibarr') ? trim((string) $dolValue) : trim((string) $odooValue);
    if ($rawDisplay === '') {
        $rawDisplay = '—';
    }

    $style = $isDifferent ? 'color:#b00020;font-weight:600' : 'color:#495057';

    return '<span style="'.$style.'">'.htmlspecialchars($label).': '.htmlspecialchars($rawDisplay).'</span>';
}

function thirdpartyTypeFlagsFromDolibarrRow(array $row): array
{
    $clientValue = (int) ($row['client'] ?? 0);

    return [
        'client' => in_array($clientValue, [1, 3], true),
        'prospect' => in_array($clientValue, [2, 3], true),
        'fournisseur' => ((int) ($row['fournisseur'] ?? 0) > 0),
    ];
}

function thirdpartyTypeFlagsFromOdooRow(array $row): array
{
    $customerRank = (int) ($row['customer_rank'] ?? 0);
    $supplierRank = (int) ($row['supplier_rank'] ?? 0);

    return [
        'client' => $customerRank > 0,
        'prospect' => ($customerRank <= 0 && $supplierRank <= 0),
        'fournisseur' => $supplierRank > 0,
    ];
}

function mergeThirdpartyTypeFlags(array $left, array $right): array
{
    return [
        'client' => !empty($left['client']) || !empty($right['client']),
        'prospect' => !empty($left['prospect']) || !empty($right['prospect']),
        'fournisseur' => !empty($left['fournisseur']) || !empty($right['fournisseur']),
    ];
}

function serializeThirdpartyTypeState(array $types): string
{
    return (!empty($types['client']) ? '1' : '0').
        '|'.(!empty($types['prospect']) ? '1' : '0').
        '|'.(!empty($types['fournisseur']) ? '1' : '0');
}

function parseThirdpartyTypeState(string $value): array
{
    $parts = array_pad(explode('|', $value), 3, '0');

    return [
        'client' => ($parts[0] === '1'),
        'prospect' => ($parts[1] === '1'),
        'fournisseur' => ($parts[2] === '1'),
    ];
}

function normalizeSubmittedThirdpartyTypes(array $types): array
{
    return [
        'client' => !empty($types['client']),
        'prospect' => !empty($types['prospect']),
        'fournisseur' => !empty($types['fournisseur']),
    ];
}

function renderThirdpartyTypeEditor(string $key, string $targetMode, string $label, array $types, bool $allowEverywhere = true): void
{
    $safeKey = htmlspecialchars($key);
    $serialized = serializeThirdpartyTypeState($types);

    print '<input type="hidden" name="thirdparty_targets['.$safeKey.']" value="'.htmlspecialchars($targetMode).'">';
    print '<input type="hidden" name="thirdparty_labels['.$safeKey.']" value="'.htmlspecialchars($label).'">';
    print '<input type="hidden" name="thirdparty_current['.$safeKey.']" value="'.htmlspecialchars($serialized).'">';
    print '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    foreach (['client' => syncodooText('Client', 'Klant'), 'prospect' => 'Prospect', 'fournisseur' => syncodooText('Fournisseur', 'Leverancier')] as $field => $caption) {
        $checked = !empty($types[$field]) ? ' checked' : '';
        print '<label style="display:inline-flex;align-items:center;gap:4px;font-size:0.9em">';
        print '<input type="checkbox" name="thirdparty_types['.$safeKey.']['.$field.']" value="1"'.$checked.'>';
        print htmlspecialchars($caption);
        print '</label>';
    }
    if ($allowEverywhere) {
        print '<label style="display:inline-flex;align-items:center;gap:4px;font-size:0.9em">';
        print '<input type="checkbox" name="thirdparty_everywhere['.$safeKey.']" value="1">';
        print syncodooText('Mettre a jour partout', 'Overal bijwerken');
        print '</label>';
    }
    print '</div>';
}

function cancelDolibarrInvoiceByRef($db, $user, string $ref): void
{
    $safeRef = $db->escape($ref);

    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
    $sqlCustomer = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE ref = '".$safeRef."' ORDER BY rowid DESC LIMIT 1";
    $resCustomer = $db->query($sqlCustomer);
    if ($resCustomer) {
        $obj = $db->fetch_object($resCustomer);
        if ($obj) {
            $facture = new Facture($db);
            if ($facture->fetch((int) $obj->rowid) > 0) {
                if ((int) $facture->statut === Facture::STATUS_VALIDATED) {
                    $facture->setStatut(Facture::STATUS_DRAFT);
                }
                $facture->setStatut(Facture::STATUS_CANCELED);
                return;
            }
        }
    }

    $sqlSupplier = "UPDATE ".MAIN_DB_PREFIX."facture_fourn SET fk_statut = 3 WHERE ref = '".$safeRef."'";
    $db->query($sqlSupplier);
}

function findDolibarrThirdpartyIdByName($db, string $name): int
{
    $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom = '".$db->escape($name)."' ORDER BY rowid DESC LIMIT 1";
    $resql = $db->query($sql);
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            return (int) $obj->rowid;
        }
    }

    return 0;
}

function archiveDolibarrThirdparty($db, $user, int $dolId): void
{
    require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
    $societe = new Societe($db);
    if ($societe->fetch($dolId) > 0) {
        $societe->status = 0;
        $res = $societe->update($dolId, $user);
        if ($res <= 0) {
            throw new Exception('Fout archive Dolibarr: '.($societe->error ?: $db->lasterror()));
        }
    }
}

function findOdooThirdpartyIdByName(SyncOdoo $sync, string $name): int
{
    return (int) $sync->findOdooThirdpartyIdByName($name);
}
