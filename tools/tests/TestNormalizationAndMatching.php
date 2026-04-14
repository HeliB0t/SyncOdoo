<?php

require_once __DIR__.'/bootstrap.php';

class TestNormalizationAndMatching
{
    public function run()
    {
        $sync = new SyncOdooLegacy(new TestDbStub());

        // normalizeForComparison
        TestAsserts::same('', call_private($sync, 'normalizeForComparison', [null, 'email']), 'normalize null to empty');
        TestAsserts::same('john@example.com', call_private($sync, 'normalizeForComparison', [' John@Example.COM ', 'email']), 'normalize email');
        TestAsserts::same('0123456789', call_private($sync, 'normalizeForComparison', ['01 23-45.67(89)', 'phone']), 'normalize phone');
        TestAsserts::same('BE0123456789', call_private($sync, 'normalizeForComparison', ['be 0123.456.789', 'vat']), 'normalize vat');

        // mergeSyncValue
        TestAsserts::same('Dol', call_private($sync, 'mergeSyncValue', [' Dol ', 'Odoo']), 'merge prefers Dolibarr when set');
        TestAsserts::same('Odoo', call_private($sync, 'mergeSyncValue', ['', 'Odoo']), 'merge fallback to Odoo when Dolibarr empty');

        // findMatchingDolibarrThirdparty (priority vat > email > name)
        $doliData = [
            ['dol_id' => 1, 'vat' => 'BE111', 'email' => 'a@x.test', 'name' => 'AAA'],
            ['dol_id' => 2, 'vat' => '', 'email' => 'b@x.test', 'name' => 'BBB'],
            ['dol_id' => 3, 'vat' => '', 'email' => '', 'name' => 'CCC'],
        ];
        $matchVat = call_private($sync, 'findMatchingDolibarrThirdparty', [[
            'vat' => 'BE111',
            'email' => 'not-used@x.test',
            'name' => 'Not used',
        ], $doliData]);
        TestAsserts::same(1, (int) $matchVat['dol_id'], 'matching Dolibarr by VAT');

        $matchEmail = call_private($sync, 'findMatchingDolibarrThirdparty', [[
            'vat' => '',
            'email' => 'b@x.test',
            'name' => 'Not used',
        ], $doliData]);
        TestAsserts::same(2, (int) $matchEmail['dol_id'], 'matching Dolibarr by email');

        $matchName = call_private($sync, 'findMatchingDolibarrThirdparty', [[
            'vat' => '',
            'email' => '',
            'name' => 'CCC',
        ], $doliData]);
        TestAsserts::same(3, (int) $matchName['dol_id'], 'matching Dolibarr by name');

        // findMatchingOdooThirdparty (priority vat > email > name)
        $odooData = [
            ['odoo_id' => 11, 'vat' => 'BE111', 'email' => 'oa@x.test', 'name' => 'OAA'],
            ['odoo_id' => 12, 'vat' => '', 'email' => 'ob@x.test', 'name' => 'OBB'],
            ['odoo_id' => 13, 'vat' => '', 'email' => '', 'name' => 'OCC'],
        ];
        $matchOVat = call_private($sync, 'findMatchingOdooThirdparty', [[
            'vat' => 'BE111',
            'email' => '',
            'name' => '',
        ], $odooData]);
        TestAsserts::same(11, (int) $matchOVat['odoo_id'], 'matching Odoo by VAT');

        $matchOEmail = call_private($sync, 'findMatchingOdooThirdparty', [[
            'vat' => '',
            'email' => 'ob@x.test',
            'name' => '',
        ], $odooData]);
        TestAsserts::same(12, (int) $matchOEmail['odoo_id'], 'matching Odoo by email');

        $matchOName = call_private($sync, 'findMatchingOdooThirdparty', [[
            'vat' => '',
            'email' => '',
            'name' => 'OCC',
        ], $odooData]);
        TestAsserts::same(13, (int) $matchOName['odoo_id'], 'matching Odoo by name');

        return true;
    }
}
