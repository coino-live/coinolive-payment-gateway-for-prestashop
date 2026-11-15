<?php
/**
 * Orders <-> Coino Live payments.
 *
 * An order only changes from a payment as read back from the API with the shop's key:
 * a webhook is just a trigger, so a forged one cannot mark anything paid.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CoinoLivePayments
{
    const TABLE = 'coinolive_payment';

    /** @var Module */
    private $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public static function table()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    public function client($idShop = null)
    {
        return new CoinoLiveClient(
            (string) Configuration::get('COINOLIVE_API_KEY', null, null, $idShop),
            (string) Configuration::get('COINOLIVE_API_URL', null, null, $idShop)
        );
    }

    /**
     * Currency codes the API can price payments in (cached 12 hours).
     *
     * @return string[]
     */
    public function supportedCurrencies($idShop = null)
    {
        $cached = json_decode((string) Configuration::get('COINOLIVE_CURRENCIES', null, null, $idShop), true);
        if (is_array($cached) && !empty($cached['codes']) && $cached['at'] > time() - 43200) {
            return $cached['codes'];
        }
        $codes = array_map(function ($c) {
            return (string) $c['code'];
        }, $this->client($idShop)->currencies());
        Configuration::updateValue('COINOLIVE_CURRENCIES', json_encode(['at' => time(), 'codes' => $codes]), false, null, $idShop);

        return $codes;
    }

    /**
     * The payment for an order: the current one while it still fits (or money is on its way), otherwise a new one.
     *
     * @return array The payment (open, confirming, partially_paid or paid)
     */
    public function paymentFor(Order $order)
    {
        $idOrder = (int) $order->id;
        $currency = new Currency((int) $order->id_currency);
        $amount = number_format((float) $order->total_paid, 2, '.', '');
        $price = $amount . ' ' . strtoupper($currency->iso_code);
        $client = $this->client((int) $order->id_shop);

        $last = $this->latestRow($idOrder);
        if ($last) {
            try {
                $payment = $client->getPayment($last['payment_id']);
                $this->apply($payment);
                if (in_array($payment['status'], ['confirming', 'partially_paid', 'paid'], true)
                    || ($payment['status'] === 'open' && $last['price'] === $price)) {
                    return $payment;
                }
            } catch (CoinoLiveException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        // First payment, a changed total, or the last one expired: a new attempt with a new key.
        $attempt = $last ? (int) $last['attempt'] + 1 : 0;
        // A random per-order nonce keeps keys unique even if order IDs repeat (a reset database).
        $nonce = $last ? $last['nonce'] : bin2hex(random_bytes(8));
        $link = Context::getContext()->link;
        $shopUrl = rtrim((string) $link->getBaseLink((int) $order->id_shop, true), '/') . '/';
        $key = 'ps-' . $idOrder . '-' . $attempt . '-' . substr(md5($nonce . '|' . $price . '|' . $shopUrl), 0, 16);
        $customer = new Customer((int) $order->id_customer);
        $back = $link->getModuleLink($this->module->name, 'return', ['id_order' => $idOrder, 'key' => $customer->secure_key], true, (int) $order->id_lang, (int) $order->id_shop);
        $notify = $link->getModuleLink($this->module->name, 'webhook', [], true, null, (int) $order->id_shop);
        $shopName = (string) Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop);

        $data = [
            'amount' => $amount,
            'currency' => strtoupper($currency->iso_code),
            'order_id' => (string) $order->reference,
            'description' => Tools::substr(trim(sprintf('Order %s %s', $order->reference, $shopName !== '' ? 'at ' . $shopName : '')), 0, 500),
            'customer_email' => Validate::isEmail($customer->email) ? $customer->email : null,
            'success_url' => $back,
            'cancel_url' => $back,
            'expires_in' => max(15, min(10080, (int) Configuration::get('COINOLIVE_EXPIRES_IN', null, null, (int) $order->id_shop) ?: 60)),
            'metadata' => [
                'ps_order_id' => (string) $idOrder,
                'ps_reference' => (string) $order->reference,
            ],
        ];
        if (self::isPublicUrl($notify)) {
            $data['notify_url'] = $notify;
        }

        $payment = $client->createPayment($data, $key);
        if ((string) (isset($payment['metadata']['ps_order_id']) ? $payment['metadata']['ps_order_id'] : '') !== (string) $idOrder || $payment['status'] !== 'open') {
            throw new CoinoLiveException('Coino Live returned a payment that does not belong to this order.');
        }

        Db::getInstance()->insert(self::TABLE, [
            'id_order' => $idOrder,
            'id_shop' => (int) $order->id_shop,
            'payment_id' => pSQL($payment['id']),
            'checkout_url' => pSQL($payment['checkout_url']),
            'price' => pSQL($price),
            'attempt' => $attempt,
            'nonce' => pSQL($nonce),
            'state' => '',
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ], false, true, Db::INSERT_IGNORE);
        $this->note($order, sprintf('Coino Live payment %s created (%s USD). Waiting for the customer to pay.', $payment['id'], $payment['amount']));

        return $payment;
    }

    /**
     * Brings the order in line with the payment (as read from the API). Safe to call any number of times.
     *
     * @return string paid, updated, unchanged or unknown
     */
    public function apply(array $payment)
    {
        $row = $this->row((string) $payment['id']);
        $idOrder = (int) (isset($payment['metadata']['ps_order_id']) ? $payment['metadata']['ps_order_id'] : 0);
        // Only payments this shop created, for the order they were created for.
        if (!$row || (int) $row['id_order'] !== $idOrder) {
            return 'unknown';
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return 'unknown';
        }

        $state = $payment['status'] . '|' . $payment['received_usd'];
        if ($row['state'] === $state) {
            return 'unchanged';
        }

        $current = (int) $order->getCurrentState();
        $done = $this->isPaidState($current);
        $latestRow = $this->latestRow($idOrder);
        $latest = $latestRow && $latestRow['payment_id'] === $row['payment_id'];
        $partial = (int) Configuration::get('COINOLIVE_OS_PARTIAL');
        $result = 'updated';

        switch ($payment['status']) {
            case 'paid':
                $requested = $payment['requested_amount'] . ' ' . $payment['requested_currency'];
                if ($done) {
                    $this->note($order, sprintf('Another Coino Live payment (%s) was paid for this order: %s. Refund or settle it with the customer.', $payment['id'], $this->deposits($payment)));
                } elseif ($requested !== $row['price']) {
                    $this->setState($order, $partial);
                    $this->note($order, sprintf('Coino Live payment is paid but was for %s, the order expects %s. Check before shipping.', $requested, $row['price']));
                } else {
                    $this->setState($order, (int) Configuration::get('PS_OS_PAYMENT'));
                    $this->setTransactionId($order, (string) $payment['id']);
                    $this->note($order, sprintf('Coino Live payment %s confirmed: %s', $payment['id'], $this->deposits($payment)));
                    $result = 'paid';
                }
                break;
            case 'confirming':
                $this->note($order, 'Coino Live: the payment was sent and is waiting for network confirmations.');
                break;
            case 'partially_paid':
                if (!$done) {
                    $this->setState($order, $partial);
                }
                $this->note($order, sprintf('Coino Live: only %s of %s USD received so far. The customer was asked to send the rest.', $payment['received_usd'], $payment['amount']));
                break;
            case 'expired':
            case 'cancelled':
                if ((float) $payment['received_usd'] > 0) {
                    if (!$done) {
                        $this->setState($order, $partial);
                    }
                    $this->note($order, sprintf('Coino Live: the payment closed with %s of %s USD received (in your Coino Live balance). Settle the rest with the customer.', $payment['received_usd'], $payment['amount']));
                } elseif ($latest && $current === (int) Configuration::get('COINOLIVE_OS_WAITING')) {
                    $this->setState($order, (int) Configuration::get('PS_OS_CANCELED'));
                    $this->note($order, 'Coino Live: the payment window closed without payment.');
                }
                break;
            default:
                // open: nothing to record.
        }

        Db::getInstance()->update(self::TABLE, ['state' => pSQL($state), 'date_upd' => date('Y-m-d H:i:s')], 'payment_id = \'' . pSQL($row['payment_id']) . '\'');

        return $result;
    }

    /** Re-reads a payment this shop created and applies it. */
    public function refresh($paymentId)
    {
        $row = $this->row($paymentId);
        if (!$row) {
            return 'unknown';
        }

        return $this->apply($this->client((int) $row['id_shop'])->getPayment($paymentId));
    }

    /** Safety net for missed webhooks (cron): re-checks recent payments that can still change. */
    public function syncOpen($limit = 50)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `payment_id` FROM `' . self::table() . '`
             WHERE `date_add` >= DATE_SUB(NOW(), INTERVAL 3 DAY)
               AND (`state` = \'\' OR `state` LIKE \'open|%\' OR `state` LIKE \'confirming|%\' OR `state` LIKE \'partially_paid|%\')
             ORDER BY `id_coinolive_payment` DESC LIMIT ' . (int) $limit
        );

        $checked = 0;
        foreach ((array) $rows as $row) {
            try {
                $this->refresh($row['payment_id']);
                ++$checked;
            } catch (CoinoLiveException $e) {
                if ($e->status === 0 || $e->status >= 500) {
                    break; // API unreachable: try again on the next run.
                }
            }
        }

        return $checked;
    }

    public function row($paymentId)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . self::table() . '` WHERE `payment_id` = \'' . pSQL($paymentId) . '\'');

        return $row ?: null;
    }

    public function latestRow($idOrder)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . self::table() . '` WHERE `id_order` = ' . (int) $idOrder . ' ORDER BY `id_coinolive_payment` DESC');

        return $row ?: null;
    }

    /** @return array<string, int> payment ID => order ID, for the given payment IDs */
    public function ordersFor(array $paymentIds)
    {
        if (!$paymentIds) {
            return [];
        }
        $in = implode(',', array_map(function ($id) {
            return '\'' . pSQL($id) . '\'';
        }, $paymentIds));
        $map = [];
        foreach ((array) Db::getInstance()->executeS('SELECT `payment_id`, `id_order` FROM `' . self::table() . '` WHERE `payment_id` IN (' . $in . ')') as $row) {
            $map[$row['payment_id']] = (int) $row['id_order'];
        }

        return $map;
    }

    /** X-Coino-Signature: t=<unix time>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")> */
    public static function validSignature($header, $body, $secret, $tolerance = 300)
    {
        $parts = [];
        foreach (explode(',', (string) $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t']) || abs(time() - (int) $parts['t']) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $body, $secret), $parts['v1']);
    }

    /** False for local development shops, which Coino Live cannot (and must not) call. */
    public static function isPublicUrl($url)
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || preg_match('/\.(local|localhost|test|internal)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }

    /** Paid already, or further along (shipped, delivered, refunded). */
    private function isPaidState($idState)
    {
        $states = [];
        foreach (['PS_OS_PAYMENT', 'PS_OS_WS_PAYMENT', 'PS_OS_PREPARATION', 'PS_OS_SHIPPING', 'PS_OS_DELIVERED', 'PS_OS_REFUND'] as $key) {
            $states[] = (int) Configuration::get($key);
        }

        return in_array((int) $idState, $states, true);
    }

    private function setState(Order $order, $idState)
    {
        if ((int) $idState && (int) $order->getCurrentState() !== (int) $idState) {
            $order->setCurrentState((int) $idState);
        }
    }

    /** The Coino Live payment ID as the transaction ID of the payment PrestaShop recorded. */
    private function setTransactionId(Order $order, $paymentId)
    {
        Db::getInstance()->update(
            'order_payment',
            ['transaction_id' => pSQL($paymentId)],
            'order_reference = \'' . pSQL(Tools::substr($order->reference, 0, 9)) . '\' AND (transaction_id IS NULL OR transaction_id = \'\')'
        );
    }

    /** A private order message (Orders > order > Messages). */
    private function note(Order $order, $text)
    {
        $message = new Message();
        $message->id_order = (int) $order->id;
        $message->id_cart = (int) $order->id_cart;
        $message->id_customer = (int) $order->id_customer;
        $message->private = true;
        $message->message = Tools::substr(strip_tags($text), 0, 1600);
        $message->add();
    }

    private function deposits(array $payment)
    {
        $lines = [];
        foreach ((array) (isset($payment['deposits']) ? $payment['deposits'] : []) as $d) {
            $lines[] = sprintf('%s %s (~%s USD), tx %s', $d['amount'], isset($d['symbol']) ? $d['symbol'] : strtoupper((string) $d['asset']), $d['usd_value'], $d['tx_hash']);
        }

        return implode('; ', $lines);
    }
}
