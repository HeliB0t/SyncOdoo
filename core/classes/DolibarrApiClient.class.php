<?php

class DolibarrApiClient
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function get($endpoint)
    {
        return $this->legacy->doliGetPublic($endpoint);
    }

    public function post($endpoint, $data)
    {
        return $this->legacy->doliPostPublic($endpoint, $data);
    }

    public function put($endpoint, $data)
    {
        return $this->legacy->doliPutPublic($endpoint, $data);
    }

    public function delete($endpoint)
    {
        return $this->legacy->doliDeletePublic($endpoint);
    }
}
