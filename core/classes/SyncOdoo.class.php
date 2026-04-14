<?php

require_once __DIR__.'/SyncOdooLegacy.class.php';
require_once __DIR__.'/OdooClient.class.php';
require_once __DIR__.'/DolibarrApiClient.class.php';
require_once __DIR__.'/SyncLogger.class.php';
require_once __DIR__.'/ThirdpartySyncService.class.php';
require_once __DIR__.'/InvoiceSyncService.class.php';
require_once __DIR__.'/BankSyncService.class.php';

class SyncOdoo
{
    private $legacy;
    private $odooClient;
    private $doliApiClient;
    private $logger;
    private $thirdpartyService;
    private $invoiceService;
    private $bankService;

    public function __construct($db)
    {
        $this->legacy = new SyncOdooLegacy($db);
        $this->odooClient = new OdooClient($this->legacy);
        $this->doliApiClient = new DolibarrApiClient($this->legacy);
        $this->logger = new SyncLogger($this->legacy);
        $this->thirdpartyService = new ThirdpartySyncService($this->legacy);
        $this->invoiceService = new InvoiceSyncService($this->legacy);
        $this->bankService = new BankSyncService($this->legacy);
    }

    public function __get($name)
    {
        if (property_exists($this->legacy, $name)) {
            return $this->legacy->$name;
        }

        return null;
    }

    public function __set($name, $value)
    {
        $this->legacy->$name = $value;
    }

    public function __isset($name)
    {
        return isset($this->legacy->$name);
    }

    public function testOdooConnectionDetailed()
    {
        return $this->odooClient->testConnectionDetailed();
    }

    public function connectOdoo()
    {
        return $this->odooClient->connect();
    }

    public function odooCallPublic($model, $method, $args)
    {
        return $this->odooClient->call($model, $method, $args);
    }

    public function odooGetInvoiceState($id)
    {
        return $this->odooClient->getInvoiceState($id);
    }

    public function doliGetPublic($endpoint)
    {
        return $this->doliApiClient->get($endpoint);
    }

    public function doliPostPublic($endpoint, $data)
    {
        return $this->doliApiClient->post($endpoint, $data);
    }

    public function doliPutPublic($endpoint, $data)
    {
        return $this->doliApiClient->put($endpoint, $data);
    }

    public function doliDeletePublic($endpoint)
    {
        return $this->doliApiClient->delete($endpoint);
    }

    public function detecterDivergencesTiers()
    {
        return $this->thirdpartyService->detecterDivergencesTiers();
    }

    public function detecterDivergencesFactures()
    {
        return $this->invoiceService->detecterDivergencesFactures();
    }

    public function analyserDivergences()
    {
        return $this->legacy->analyserDivergences();
    }

    public function runAll()
    {
        return $this->legacy->runAll();
    }

    public function log($level, $direction, $entity_type, $entity_ref, $message)
    {
        return $this->logger->log($level, $direction, $entity_type, $entity_ref, $message);
    }

    public function getLogs($limit = 100)
    {
        return $this->logger->getLogs($limit);
    }

    public function purgeLogs($days = 30)
    {
        return $this->logger->purgeLogs($days);
    }

    public function clearLogs()
    {
        return $this->logger->clearLogs();
    }

    public function clearDivergenceLogs()
    {
        return $this->logger->clearDivergenceLogs();
    }

    public function syncTiersOdooToDoli($id)
    {
        return $this->thirdpartyService->syncTiersOdooToDoli($id);
    }

    public function syncTiersDoliToOdoo($id)
    {
        return $this->thirdpartyService->syncTiersDoliToOdoo($id);
    }

    public function syncFacturesOdooToDoli($ref, $odooId = 0)
    {
        return $this->invoiceService->syncFacturesOdooToDoli($ref, $odooId);
    }

    public function syncFacturesDoliToOdoo($ref)
    {
        return $this->invoiceService->syncFacturesDoliToOdoo($ref);
    }

    public function syncBankTransactions()
    {
        return $this->bankService->syncBankTransactions();
    }

    public function getDolibarrBankAccounts()
    {
        return $this->bankService->getDolibarrBankAccounts();
    }

    public function getCountryOptions()
    {
        return $this->thirdpartyService->getCountryOptions();
    }

    public function applyMissingCountrySelection(array $row, $countryCode)
    {
        return $this->thirdpartyService->applyMissingCountrySelection($row, $countryCode);
    }

    public function getPendingVatRateConfirmations()
    {
        return $this->invoiceService->getPendingVatRateConfirmations();
    }

    public function confirmVatRateByRowId($rowId, $isExact, $correctRate = null)
    {
        return $this->invoiceService->confirmVatRateByRowId($rowId, $isExact, $correctRate);
    }

    public function updateDolibarrThirdpartyTypes($dolId, array $types)
    {
        return $this->thirdpartyService->updateDolibarrThirdpartyTypes($dolId, $types);
    }

    public function updateOdooThirdpartyTypes($odooId, array $types)
    {
        return $this->thirdpartyService->updateOdooThirdpartyTypes($odooId, $types);
    }

    public function findOdooInvoiceIdByRef($ref)
    {
        return $this->invoiceService->findOdooInvoiceIdByRef($ref);
    }

    public function findOdooInvoiceByRefPublic($ref, $odooId = 0)
    {
        return $this->invoiceService->findOdooInvoiceByRefPublic($ref, $odooId);
    }

    public function findOdooThirdpartyIdByName($name)
    {
        return $this->thirdpartyService->findOdooThirdpartyIdByName($name);
    }
}
