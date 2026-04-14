<?php

class InvoiceSyncService
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function detecterDivergencesFactures()
    {
        return $this->legacy->detecterDivergencesFactures();
    }

    public function syncFacturesOdooToDoli($ref, $odooId = 0)
    {
        return $this->legacy->syncFacturesOdooToDoli($ref, $odooId);
    }

    public function syncFacturesDoliToOdoo($ref)
    {
        return $this->legacy->syncFacturesDoliToOdoo($ref);
    }

    public function findOdooInvoiceIdByRef($ref)
    {
        return $this->legacy->findOdooInvoiceIdByRef($ref);
    }

    public function findOdooInvoiceByRefPublic($ref, $odooId = 0)
    {
        return $this->legacy->findOdooInvoiceByRefPublic($ref, $odooId);
    }

    public function getPendingVatRateConfirmations()
    {
        return $this->legacy->getPendingVatRateConfirmations();
    }

    public function confirmVatRateByRowId($rowId, $isExact, $correctRate = null)
    {
        return $this->legacy->confirmVatRateByRowId($rowId, $isExact, $correctRate);
    }
}
