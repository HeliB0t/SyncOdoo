<?php

class SyncHttpException extends Exception
{
    private $context;
    private $httpStatus;
    private $curlErrno;
    private $responseBody;

    public function __construct(
        $context,
        $message,
        $httpStatus = null,
        $curlErrno = null,
        $responseBody = '',
        Throwable $previous = null
    ) {
        $this->context = (string) $context;
        $this->httpStatus = $httpStatus;
        $this->curlErrno = $curlErrno;
        $this->responseBody = (string) $responseBody;

        parent::__construct($this->buildMessage((string) $message), 0, $previous);
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getCurlErrno()
    {
        return $this->curlErrno;
    }

    public function getResponseBody()
    {
        return $this->responseBody;
    }

    private function buildMessage($message)
    {
        $parts = ['[SYNC_HTTP]'];

        if ($this->context !== '') {
            $parts[] = $this->context;
        }

        if ($this->httpStatus !== null) {
            $parts[] = 'HTTP '.$this->httpStatus;
        }

        if ($this->curlErrno !== null) {
            $parts[] = 'CURL '.$this->curlErrno;
        }

        $prefix = implode(' | ', $parts);

        if ($message === '') {
            return $prefix;
        }

        return $prefix.' | '.$message;
    }
}
