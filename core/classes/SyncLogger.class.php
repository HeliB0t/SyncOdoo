<?php

class SyncLogger
{
    private $legacy;

    public function __construct(SyncOdooLegacy $legacy)
    {
        $this->legacy = $legacy;
    }

    public function log($level, $direction, $entity_type, $entity_ref, $message)
    {
        return $this->legacy->log($level, $direction, $entity_type, $entity_ref, $message);
    }

    public function getLogs($limit = 100)
    {
        return $this->legacy->getLogs($limit);
    }

    public function purgeLogs($days = 30)
    {
        return $this->legacy->purgeLogs($days);
    }

    public function clearLogs()
    {
        return $this->legacy->clearLogs();
    }

    public function clearDivergenceLogs()
    {
        return $this->legacy->clearDivergenceLogs();
    }
}
