<?php

require_once __DIR__.'/SyncHttpException.class.php';

class SyncHttpClient
{
    private $defaultConnectTimeout;
    private $defaultTimeout;
    private $defaultRetries;
    private $defaultBackoffMs;

    public function __construct($defaultConnectTimeout = 10, $defaultTimeout = 30, $defaultRetries = 2, $defaultBackoffMs = 250)
    {
        $this->defaultConnectTimeout = (int) $defaultConnectTimeout;
        $this->defaultTimeout = (int) $defaultTimeout;
        $this->defaultRetries = (int) $defaultRetries;
        $this->defaultBackoffMs = (int) $defaultBackoffMs;
    }

    public function request(array $options)
    {
        $url = $options['url'] ?? '';
        if ($url === '') {
            throw new SyncHttpException('http.request', 'URL manquante');
        }

        $method = strtoupper($options['method'] ?? 'GET');
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $context = (string) ($options['context'] ?? $method.' '.$url);
        $expectJson = !empty($options['expectJson']);
        $failOnHttpError = array_key_exists('failOnHttpError', $options) ? (bool) $options['failOnHttpError'] : true;

        $connectTimeout = isset($options['connectTimeout']) ? (int) $options['connectTimeout'] : $this->defaultConnectTimeout;
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : $this->defaultTimeout;

        $idempotentMethods = ['GET', 'HEAD', 'OPTIONS'];
        $isIdempotent = in_array($method, $idempotentMethods, true);
        $maxRetries = isset($options['maxRetries']) ? (int) $options['maxRetries'] : ($isIdempotent ? $this->defaultRetries : 0);
        $backoffMs = isset($options['backoffMs']) ? max(0, (int) $options['backoffMs']) : $this->defaultBackoffMs;

        $retryOnHttp = $options['retryOnHttp'] ?? [408, 425, 429, 500, 502, 503, 504];
        $retryOnCurlErrno = $options['retryOnCurlErrno'] ?? [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_GOT_NOTHING,
            CURLE_PARTIAL_FILE,
        ];

        $attempt = 0;
        while (true) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }

            if ($body !== null && $method !== 'HEAD') {
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $curlErrno = curl_errno($ch);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($attempt < $maxRetries && in_array($curlErrno, $retryOnCurlErrno, true)) {
                    $this->sleepBackoff($attempt, $backoffMs);
                    $attempt++;
                    continue;
                }

                throw new SyncHttpException($context, 'Erreur cURL: '.$curlError, null, $curlErrno);
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($failOnHttpError && ($httpCode < 200 || $httpCode >= 300)) {
                if ($attempt < $maxRetries && in_array($httpCode, $retryOnHttp, true)) {
                    $this->sleepBackoff($attempt, $backoffMs);
                    $attempt++;
                    continue;
                }

                throw new SyncHttpException($context, 'Reponse HTTP invalide', $httpCode, null, (string) $raw);
            }

            $json = null;
            if ($expectJson) {
                $json = json_decode((string) $raw, true);
                if (!is_array($json)) {
                    throw new SyncHttpException($context, 'Reponse JSON invalide', $httpCode, null, (string) $raw);
                }
            }

            return [
                'statusCode' => $httpCode,
                'body' => (string) $raw,
                'json' => $json,
            ];
        }
    }

    private function sleepBackoff($attempt, $baseMs)
    {
        if ($baseMs <= 0) {
            return;
        }

        $delayMs = (int) ($baseMs * pow(2, $attempt));
        $delayMs = min($delayMs, 5000);
        usleep($delayMs * 1000);
    }
}
