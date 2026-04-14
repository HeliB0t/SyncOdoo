<?php

class OdooClient
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function __get($name)
    {
        return $this->legacy->$name ?? null;
    }

    public function __set($name, $value)
    {
        $this->legacy->$name = $value;
    }

    public function connect()
    {
        return $this->legacy->connectOdoo();
    }

    public function testConnectionDetailed()
    {
        return $this->legacy->testOdooConnectionDetailed();
    }

    public function call($model, $method, $args)
    {
        return $this->legacy->odooCallPublic($model, $method, $args);
    }

    public function executeKw($model, $method, array $args, array $kwargs = [])
    {
        // execute_kw kwargs are not exposed in the public legacy API.
        if (!empty($kwargs)) {
            throw new Exception('executeKw avec kwargs n\'est pas disponible via la façade legacy');
        }

        return $this->legacy->odooCallPublic($model, $method, $args);
    }

    public function searchReadAll($model, array $domain, array $fields, array $kwargs = [])
    {
        $args = [$domain, $fields];
        return $this->legacy->odooCallPublic($model, 'search_read', $args);
    }

    public function getInvoiceState($id)
    {
        return $this->legacy->odooGetInvoiceState($id);
    }
}
