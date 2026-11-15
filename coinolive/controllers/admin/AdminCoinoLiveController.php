<?php
/**
 * Orders > Coino Live: the account balance and this shop's payments, read live from the API.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminCoinoLiveController extends ModuleAdminController
{
    const STATUSES = ['paid', 'partially_paid', 'confirming', 'open', 'expired', 'cancelled'];

    const COLORS = ['paid' => '#78d07d', 'partially_paid' => '#ff9a52', 'confirming' => '#ff9a52', 'open' => '#25b9d7', 'expired' => '#e08f95', 'cancelled' => '#e08f95'];

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = 'Coino Live';
    }

    public function initContent()
    {
        $m = $this->module;
        $link = $this->context->link;
        $self = $link->getAdminLink('AdminCoinoLive');

        $status = (string) Tools::getValue('status');
        $status = in_array($status, self::STATUSES, true) ? $status : '';
        $reference = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) Tools::getValue('reference'));
        $cursor = preg_replace('/[^A-Za-z0-9_\-=]/', '', (string) Tools::getValue('cursor'));

        $statusNames = [
            'paid' => $m->l('Paid'), 'partially_paid' => $m->l('Partially paid'), 'confirming' => $m->l('Confirming'),
            'open' => $m->l('Waiting for payment'), 'expired' => $m->l('Expired'), 'cancelled' => $m->l('Cancelled'),
        ];
        $cl = [
            'configured' => trim((string) Configuration::get('COINOLIVE_API_KEY')) !== '',
            'dashboard_url' => CoinoLiveClient::dashboardUrl((string) Configuration::get('COINOLIVE_API_URL'), $this->context->language->iso_code),
            'settings' => $link->getAdminLink('AdminModules', true, [], ['configure' => $m->name]),
            'token' => Tools::getAdminTokenLite('AdminCoinoLive'),
            'status' => $status,
            'reference' => $reference,
            'tabs' => [],
            'balances' => [],
            'total' => '',
            'balance_error' => '',
            'payments' => [],
            'payments_error' => '',
            'newer' => '',
            'older' => '',
            't' => [
                'open_dashboard' => $m->l('Open Coino Live dashboard'), 'settings' => $m->l('Settings'),
                'not_configured' => $m->l('Add your Coino Live API key in the module settings to see your balance and payments here.'),
                'balance' => $m->l('Balance'), 'total' => $m->l('Total'), 'withdraw' => $m->l('Withdraw'),
                'balance_note' => $m->l('Your whole Coino Live account (all gateways), after fees. Withdraw from the Coino Live dashboard.'),
                'no_balance' => $m->l('No balance yet. Paid orders are added here after fees.'),
                'payments' => $m->l('Payments'), 'reference' => $m->l('Order reference'), 'search' => $m->l('Search'),
                'date' => $m->l('Date'), 'order' => $m->l('Order'), 'status' => $m->l('Status'), 'amount' => $m->l('Amount'),
                'received' => $m->l('Received'), 'transactions' => $m->l('Transactions'), 'no_payments' => $m->l('No payments found.'),
                'newer' => $m->l('Newer'), 'older' => $m->l('Older'), 'payment_page' => $m->l('Payment page'),
            ],
        ];
        foreach (array_merge(['' => $m->l('All')], $statusNames) as $key => $name) {
            $cl['tabs'][] = ['name' => $name, 'href' => $self . ($key ? '&status=' . $key : ''), 'active' => $key === $status];
        }

        if ($cl['configured']) {
            $payments = $m->payments();
            $client = $payments->client();
            try {
                $total = 0.0;
                $known = true;
                foreach ($client->balances() as $b) {
                    if ((float) $b['balance'] <= 0) {
                        continue;
                    }
                    if ($b['usd_value'] === null) {
                        $known = false;
                    } else {
                        $total += (float) $b['usd_value'];
                    }
                    $cl['balances'][] = [
                        'label' => $b['symbol'] . ' · ' . $b['network'],
                        'balance' => $b['balance'] . ' ' . $b['symbol'],
                        'usd' => $b['usd_value'] === null ? '—' : '≈ ' . number_format((float) $b['usd_value'], 2) . ' USD',
                    ];
                }
                $cl['total'] = ($known ? '' : '≥ ') . number_format($total, 2) . ' USD';
            } catch (CoinoLiveException $e) {
                $cl['balance_error'] = sprintf($m->l('Could not load from Coino Live: %s'), $e->getMessage());
            }

            try {
                $page = $client->listPayments(['status' => $status, 'order_id' => $reference, 'cursor' => $cursor]);
                $ours = $payments->ordersFor(array_map(function ($p) {
                    return (string) $p['id'];
                }, $page['data']));
                foreach ($page['data'] as $p) {
                    $cl['payments'][] = $this->row($p, isset($ours[$p['id']]) ? $ours[$p['id']] : 0, $statusNames);
                }
                $base = $self . ($status ? '&status=' . $status : '') . ($reference ? '&reference=' . $reference : '');
                $cl['newer'] = empty($page['meta']['prev_cursor']) ? '' : $base . '&cursor=' . rawurlencode($page['meta']['prev_cursor']);
                $cl['older'] = empty($page['meta']['next_cursor']) ? '' : $base . '&cursor=' . rawurlencode($page['meta']['next_cursor']);
            } catch (CoinoLiveException $e) {
                $cl['payments_error'] = sprintf($m->l('Could not load from Coino Live: %s'), $e->getMessage());
            }
        }

        $this->context->smarty->assign('cl', $cl);
        $this->content = $this->context->smarty->fetch($m->getLocalPath() . 'views/templates/admin/dashboard.tpl');

        parent::initContent();
    }

    private function row(array $p, $idOrder, array $statusNames)
    {
        $deposits = [];
        foreach ((array) (isset($p['deposits']) ? $p['deposits'] : []) as $d) {
            $hash = (string) $d['tx_hash'];
            $deposits[] = [
                'amount' => $d['amount'] . ' ' . (isset($d['symbol']) ? $d['symbol'] : strtoupper((string) $d['asset'])),
                'network' => isset($d['network']) ? (string) $d['network'] : '',
                'hash' => $hash,
                'short' => strlen($hash) > 16 ? substr($hash, 0, 8) . '…' . substr($hash, -6) : $hash,
                'explorer' => isset($d['explorer_url']) ? (string) $d['explorer_url'] : '',
            ];
        }
        $received = (float) $p['received_usd'];

        return [
            'date' => Tools::displayDate(date('Y-m-d H:i:s', strtotime((string) $p['created_at'])), true),
            'order' => $p['order_id'] ? (string) $p['order_id'] : '—',
            'order_url' => $idOrder ? $this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => (int) $idOrder, 'vieworder' => 1]) : '',
            'status_name' => isset($statusNames[$p['status']]) ? $statusNames[$p['status']] : $p['status'],
            'color' => isset(self::COLORS[$p['status']]) ? self::COLORS[$p['status']] : '#6c868e',
            'amount' => $p['requested_amount'] . ' ' . $p['requested_currency'],
            'usd' => $p['requested_currency'] !== $p['currency'] ? $p['amount'] . ' ' . $p['currency'] : '',
            'received' => number_format($received, 2) . ' USD',
            'missing' => ($p['status'] === 'partially_paid' || ($p['status'] === 'expired' && $received > 0)) ? sprintf($this->module->l('%s USD missing'), number_format((float) $p['remaining_usd'], 2)) : '',
            'deposits' => $deposits,
            'checkout' => in_array($p['status'], ['open', 'partially_paid'], true) ? $p['checkout_url'] : '',
        ];
    }
}
