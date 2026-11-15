<?php
/**
 * Coino Live API v2 client (cURL). PHP 7.2+ (PrestaShop 8.2 and 9).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/** An API call that failed. The message is safe to show to the shop owner. */
class CoinoLiveException extends Exception
{
    /** @var int HTTP status (0 when the request did not reach the API) */
    public $status;

    public function __construct($message, $status = 0)
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}

class CoinoLiveClient
{
    const DEFAULT_URL = 'https://coino.live/api/v2';

    const VERSION = '1.0.0';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    public function __construct($apiKey, $baseUrl = '')
    {
        $this->apiKey = trim((string) $apiKey);
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim(self::isAllowedUrl($baseUrl) ? $baseUrl : self::DEFAULT_URL, '/');
    }

    /** https only (plain http just for a local API while developing). */
    public static function isAllowedUrl($url)
    {
        return strpos((string) $url, 'https://') === 0 || (bool) preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', (string) $url);
    }

    /** The merchant dashboard next to the API ("…/api/v2" → "…/{locale}/dashboard"), or ''. */
    public static function dashboardUrl($apiUrl, $iso)
    {
        $api = rtrim(trim((string) $apiUrl), '/');
        $api = $api === '' ? self::DEFAULT_URL : $api;
        if (!preg_match('#^(https://[^/]+)/api/v2$#', $api, $m)) {
            return '';
        }
        $lang = strtolower(substr((string) $iso, 0, 2));
        if ($lang === 'br') {
            $lang = 'pt';
        }

        return $m[1] . '/' . (in_array($lang, ['fa', 'tr', 'pt', 'ru'], true) ? $lang : 'en') . '/dashboard';
    }

    /** The same idempotency key always returns the same payment. */
    public function createPayment(array $data, $idempotencyKey)
    {
        return $this->request('POST', '/payments', $data, $idempotencyKey);
    }

    public function getPayment($id)
    {
        return $this->request('GET', '/payments/' . rawurlencode($id));
    }

    public function currencies()
    {
        return $this->request('GET', '/currencies');
    }

    public function balances()
    {
        return $this->request('GET', '/balances');
    }

    /** One page of payments, newest first: ['data' => …, 'meta' => ['next_cursor', 'prev_cursor']]. */
    public function listPayments(array $query = [])
    {
        $query = array_filter($query, function ($v) {
            return $v !== null && $v !== '';
        });

        return $this->request('GET', '/payments' . ($query ? '?' . http_build_query($query) : ''), null, null, true);
    }

    private function request($method, $path, $body = null, $idempotencyKey = null, $full = false)
    {
        if ($this->apiKey === '') {
            throw new CoinoLiveException('The Coino Live API key is not set.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: CoinoLive-PrestaShop/' . self::VERSION . '; PrestaShop/' . _PS_VERSION_,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new CoinoLiveException('Could not reach Coino Live: ' . $error);
        }
        $json = json_decode((string) $raw, true);

        // A redirect or an HTML 404 means the API URL points at a website, not at the API.
        if (($status >= 300 && $status < 400) || ($status === 404 && !is_array($json))) {
            throw new CoinoLiveException(sprintf('There is no Coino Live API at %s (HTTP %d). Check the API URL setting.', $this->baseUrl, $status), $status);
        }
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP ' . $status;
            if (is_array($json) && !empty($json['errors']) && is_array($json['errors'])) {
                $first = reset($json['errors']);
                $message = is_array($first) ? (string) reset($first) : $message;
            }
            throw new CoinoLiveException($message, $status);
        }
        if (!is_array($json) || !array_key_exists('data', $json)) {
            throw new CoinoLiveException('Unexpected answer from Coino Live.', $status);
        }

        return $full ? $json : $json['data'];
    }
}
