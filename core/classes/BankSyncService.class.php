<?php

class BankSyncService
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function syncBankTransactions()
    {
        return $this->legacy->syncBankTransactions();
    }

    public function getDolibarrBankAccounts()
    {
        return $this->legacy->getDolibarrBankAccounts();
    }
}
