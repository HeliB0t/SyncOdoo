<?php

require_once __DIR__.'/bootstrap.php';

class DryRunSync extends SyncOdooLegacy
{
    public $connected = true;
    public $divergences = [];
    public $logs = [];
    public $throwOn = [];

    public function connectOdoo()
    {
        if (!$this->connected) {
            $this->lastError = 'dry-run connect failure';
        }
        return $this->connected;
    }

    public function analyserDivergences()
    {
        return $this->divergences;
    }

    public function syncTiersOdooToDoli($id)
    {
        if (!empty($this->throwOn['syncTiersOdooToDoli'])) {
            throw new Exception('forced syncTiersOdooToDoli failure');
        }
        return (int) $id;
    }

    public function syncTiersDoliToOdoo($id)
    {
        if (!empty($this->throwOn['syncTiersDoliToOdoo'])) {
            throw new Exception('forced syncTiersDoliToOdoo failure');
        }
        return (int) $id;
    }

    public function syncFacturesOdooToDoli($ref, $odooId = 0)
    {
        if (!empty($this->throwOn['syncFacturesOdooToDoli'])) {
            throw new Exception('forced syncFacturesOdooToDoli failure');
        }
        return true;
    }

    public function syncFacturesDoliToOdoo($ref)
    {
        if (!empty($this->throwOn['syncFacturesDoliToOdoo'])) {
            throw new Exception('forced syncFacturesDoliToOdoo failure');
        }
        return true;
    }

    public function syncBankTransactions()
    {
        if (!empty($this->throwOn['syncBankTransactions'])) {
            throw new Exception('forced syncBankTransactions failure');
        }
        return true;
    }

    public function log($level, $direction, $entity_type, $entity_ref, $message)
    {
        $this->logs[] = [$level, $direction, $entity_type, $entity_ref, $message];
        return true;
    }
}

class TestRunAllDry
{
    private function makeDefaultDivergences()
    {
        return [
            'tiers_only_odoo' => [['_id' => 101, '_ref' => 'O-TP-101']],
            'tiers_only_doli' => [['dol_id' => 201, '_id' => 201, '_ref' => 'D-TP-201']],
            'tiers' => ['differences' => []],
            'invoices_only_odoo' => [['ref' => 'FO-1', '_id' => 301]],
            'invoices_only_doli' => [['ref' => 'FD-1', '_id' => 401]],
            'factures' => ['differences' => []],
            'vat_checks' => ['missing_country' => [], 'pending_rates' => []],
        ];
    }

    public function run()
    {
        // Scenario 1: connection failure should stop runAll and increment errors.
        $syncFailConnect = new DryRunSync(new TestDbStub());
        $syncFailConnect->connected = false;
        $ok = $syncFailConnect->runAll();
        TestAsserts::false($ok, 'runAll should fail when connection fails');
        TestAsserts::same(1, (int) $syncFailConnect->stats['erreurs'], 'error counter on connection failure');

        // Scenario 2: dry successful run with critical divergence flows.
        $syncOk = new DryRunSync(new TestDbStub());
        $syncOk->connected = true;
        $syncOk->divergences = $this->makeDefaultDivergences();
        $ok2 = $syncOk->runAll();
        TestAsserts::true($ok2, 'runAll dry path should succeed');
        TestAsserts::same(0, (int) $syncOk->stats['erreurs'], 'no errors on happy path');
        TestAsserts::same(1, (int) $syncOk->stats['tiers_crees_doli'], 'tiers created doli stat');
        TestAsserts::same(1, (int) $syncOk->stats['tiers_crees_odoo'], 'tiers created odoo stat');

        // Scenario 3: critical divergence action failure should be logged and produce global KO.
        $syncDivergenceFail = new DryRunSync(new TestDbStub());
        $syncDivergenceFail->connected = true;
        $syncDivergenceFail->divergences = $this->makeDefaultDivergences();
        $syncDivergenceFail->throwOn['syncFacturesDoliToOdoo'] = true;
        $ok3 = $syncDivergenceFail->runAll();
        TestAsserts::false($ok3, 'runAll should fail when one critical divergence action fails');
        TestAsserts::same(1, (int) $syncDivergenceFail->stats['erreurs'], 'error counter after forced divergence failure');

        return true;
    }
}
