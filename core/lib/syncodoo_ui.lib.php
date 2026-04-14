<?php

require_once __DIR__.'/syncodoo_i18n.lib.php';

function syncodoo_print_common_styles()
{
    print '<style>';
    print '.butAction, .butActionDelete {';
    print 'background:#1f8f43 !important;border-color:#1f8f43 !important;color:#fff !important;';
    print '}';
    print '.butAction:hover, .butActionDelete:hover {';
    print 'background:#177336 !important;border-color:#177336 !important;color:#fff !important;';
    print '}';
    print '.syncodoo-about-box {';
    print 'border:1px solid #e1e7ef;border-radius:8px;padding:16px;background:#fff;max-width:980px;';
    print '}';
    print '</style>';
}

function syncodoo_render_lang_switcher($baseUrl)
{
    $activeLang = syncodoo_get_active_lang_code();
    $baseUrl = (string) $baseUrl;

    include __DIR__.'/../partials/syncodoo_lang_switcher.php';
}

function syncodoo_get_tabs($canConfig)
{
    $tabs = [];
    $tabs[] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=dashboard', syncodoo_tr('SyncodooTabDashboard'), 'dashboard'];
    $tabs[] = [dol_buildpath('/syncodoo/divergences.php', 1), syncodoo_tr('SyncodooTabDivergences'), 'divergences'];
    $tabs[] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=log', syncodoo_tr('SyncodooTabLog'), 'log'];

    if ($canConfig) {
        $tabs[] = [dol_buildpath('/syncodoo/admin/config.php', 1), syncodoo_tr('SyncodooTabConfig'), 'config'];
        $tabs[] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=about', syncodoo_tr('SyncodooTabAbout'), 'about'];
    } else {
        $tabs[] = [dol_buildpath('/syncodoo/index.php', 1).'?tab=about', syncodoo_tr('SyncodooTabAbout'), 'about'];
    }

    return $tabs;
}

function syncodoo_render_tabs($activeTab, $canConfig)
{
    $head = syncodoo_get_tabs((bool) $canConfig);
    print dol_get_fiche_head($head, $activeTab, 'SyncOdoo', 0, 'refresh');
}
