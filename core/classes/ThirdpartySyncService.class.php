<?php

class ThirdpartySyncService
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function detecterDivergencesTiers()
    {
        return $this->legacy->detecterDivergencesTiers();
    }

    public function syncTiersOdooToDoli($id)
    {
        return $this->legacy->syncTiersOdooToDoli($id);
    }

    public function syncTiersDoliToOdoo($id)
    {
        return $this->legacy->syncTiersDoliToOdoo($id);
    }

    public function updateDolibarrThirdpartyTypes($dolId, array $types)
    {
        return $this->legacy->updateDolibarrThirdpartyTypes($dolId, $types);
    }

    public function updateOdooThirdpartyTypes($odooId, array $types)
    {
        return $this->legacy->updateOdooThirdpartyTypes($odooId, $types);
    }

    public function findOdooThirdpartyIdByName($name)
    {
        return $this->legacy->findOdooThirdpartyIdByName($name);
    }

    public function getCountryOptions()
    {
        return $this->legacy->getCountryOptions();
    }

    public function applyMissingCountrySelection(array $row, $countryCode)
    {
        return $this->legacy->applyMissingCountrySelection($row, $countryCode);
    }
}
