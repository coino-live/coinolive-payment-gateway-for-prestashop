<?php
/**
 * Coino Live crypto payments for PrestaShop 8.2 and 9.
 *
 * Customers pay with USDT, Bitcoin, Ethereum, TRON, TON, Solana and more on the Coino Live payment page;
 * orders are marked paid once the payment is confirmed on chain.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/CoinoLiveClient.php';
require_once __DIR__ . '/classes/CoinoLivePayments.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class CoinoLive extends PaymentModule
{
    const CONFIG = ['COINOLIVE_API_KEY', 'COINOLIVE_WEBHOOK_SECRET', 'COINOLIVE_EXPIRES_IN', 'COINOLIVE_API_URL'];

    public function __construct()
    {
        $this->name = 'coinolive';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Coino Live';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->controllers = ['validation', 'return', 'webhook', 'cron'];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Coino Live crypto payments');
        $this->description = $this->l('Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more. Customers pay on the Coino Live payment page; orders update automatically.');
        $this->confirmUninstall = $this->l('Remove Coino Live crypto payments? Existing orders keep their payment information.');
    }

    public function payments()
    {
        return new CoinoLivePayments($this);
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionCronJob')
            && $this->installTable()
            && $this->installOrderStates()
            && $this->installTab()
            && Configuration::updateValue('COINOLIVE_API_URL', CoinoLiveClient::DEFAULT_URL)
            && Configuration::updateValue('COINOLIVE_EXPIRES_IN', 60)
            && Configuration::updateValue('COINOLIVE_CRON_TOKEN', bin2hex(random_bytes(16)));
    }

    public function uninstall()
    {
        foreach (array_merge(self::CONFIG, ['COINOLIVE_CRON_TOKEN', 'COINOLIVE_CURRENCIES']) as $key) {
            Configuration::deleteByName($key);
        }
        $idTab = (int) Tab::getIdFromClassName('AdminCoinoLive');
        if ($idTab) {
            (new Tab($idTab))->delete();
        }
        // The order states stay (orders reference them); the table of payments goes.
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . CoinoLivePayments::table() . '`');

        return parent::uninstall();
    }

    private function installTable()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . CoinoLivePayments::table() . '` (
                `id_coinolive_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `payment_id` VARCHAR(32) NOT NULL,
                `checkout_url` VARCHAR(2048) NOT NULL,
                `price` VARCHAR(40) NOT NULL,
                `attempt` INT UNSIGNED NOT NULL DEFAULT 0,
                `nonce` VARCHAR(32) NOT NULL,
                `state` VARCHAR(64) NOT NULL DEFAULT \'\',
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_coinolive_payment`),
                UNIQUE KEY `payment_id` (`payment_id`),
                KEY `id_order` (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** "Awaiting crypto payment" and "Crypto payment partly received", named in each shop language. */
    private function installOrderStates()
    {
        $states = [
            'COINOLIVE_OS_WAITING' => ['color' => '#4169E1', 'names' => [
                'en' => 'Awaiting crypto payment', 'fr' => 'En attente de paiement crypto', 'es' => 'Esperando pago en cripto', 'de' => 'Warten auf Krypto-Zahlung',
                'it' => 'In attesa di pagamento in cripto', 'pt' => 'A aguardar pagamento em cripto', 'br' => 'Aguardando pagamento em cripto', 'tr' => 'Kripto ödeme bekleniyor',
                'ru' => 'Ожидается криптоплатёж', 'fa' => 'در انتظار پرداخت رمزارزی',
            ]],
            'COINOLIVE_OS_PARTIAL' => ['color' => '#FF8C00', 'names' => [
                'en' => 'Crypto payment partly received', 'fr' => 'Paiement crypto partiellement reçu', 'es' => 'Pago en cripto recibido en parte', 'de' => 'Krypto-Zahlung teilweise erhalten',
                'it' => 'Pagamento in cripto ricevuto in parte', 'pt' => 'Pagamento em cripto recebido em parte', 'br' => 'Pagamento em cripto recebido em parte', 'tr' => 'Kripto ödemenin bir kısmı alındı',
                'ru' => 'Криптоплатёж получен частично', 'fa' => 'بخشی از پرداخت رمزارزی دریافت شد',
            ]],
        ];

        foreach ($states as $key => $info) {
            $existing = (int) Configuration::get($key);
            if ($existing && Validate::isLoadedObject(new OrderState($existing))) {
                continue;
            }
            $state = new OrderState();
            $state->module_name = $this->name;
            $state->color = $info['color'];
            $state->send_email = false;
            $state->logable = false;
            $state->paid = false;
            $state->invoice = false;
            $state->hidden = false;
            $state->unremovable = true;
            $state->name = [];
            foreach (Language::getLanguages(false) as $language) {
                $iso = strtolower($language['iso_code']);
                $state->name[(int) $language['id_lang']] = isset($info['names'][$iso]) ? $info['names'][$iso] : $info['names']['en'];
            }
            if (!$state->add()) {
                return false;
            }
            Configuration::updateValue($key, (int) $state->id);
        }

        return true;
    }

    /** Orders > Coino Live (balance and payments). */
    private function installTab()
    {
        if (Tab::getIdFromClassName('AdminCoinoLive')) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = 'AdminCoinoLive';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $tab->active = true;
        $tab->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Coino Live';
        }

        return (bool) $tab->add();
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || trim((string) Configuration::get('COINOLIVE_API_KEY')) === '') {
            return [];
        }
        $currency = new Currency((int) $params['cart']->id_currency);
        try {
            if (!in_array(strtoupper($currency->iso_code), $this->payments()->supportedCurrencies(), true)) {
                return [];
            }
        } catch (CoinoLiveException $e) {
            // API unreachable right now: confirming the order will say so.
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pay with crypto'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation('<p>' . htmlspecialchars($this->l('Pay with USDT, Bitcoin, Ethereum, TRON, TON, Solana and more. You will be taken to a secure payment page.'), ENT_QUOTES, 'UTF-8') . '</p>');

        return [$option];
    }

    /** Order confirmation page. */
    public function hookDisplayPaymentReturn($params)
    {
        return isset($params['order']) ? $this->statusBox($params['order']) : '';
    }

    /** Customer account > order details. */
    public function hookDisplayOrderDetail($params)
    {
        return isset($params['order']) ? $this->statusBox($params['order']) : '';
    }

    /** Called by the "Cron tasks manager" module when installed; the cron URL in the settings works without it. */
    public function hookActionCronJob()
    {
        $this->payments()->syncOpen();
    }

    public function getCronFrequency()
    {
        return ['hour' => -1, 'day' => -1, 'month' => -1, 'day_of_week' => -1];
    }

    private function statusBox($order)
    {
        if (!$order instanceof Order || $order->module !== $this->name) {
            return '';
        }
        $row = $this->payments()->latestRow((int) $order->id);
        if (!$row) {
            return '';
        }
        $parts = explode('|', $row['state'] ?: 'open|0');
        $status = $parts[0];
        $texts = [
            'paid' => $this->l('Your crypto payment was received. Thank you!'),
            'confirming' => $this->l('Your crypto payment was received and is being confirmed on the network. We will email you when the order is paid.'),
            'partially_paid' => $this->l('Part of your crypto payment has arrived. Please send the rest:'),
            'open' => $this->l('We have not seen your payment yet. If you have not paid, you can still do so:'),
            'expired' => $this->l('The payment time ran out. Please contact us or place the order again.'),
            'cancelled' => $this->l('The payment time ran out. Please contact us or place the order again.'),
        ];
        $this->context->smarty->assign([
            'coinolive_status' => $status,
            'coinolive_text' => isset($texts[$status]) ? $texts[$status] : '',
            'coinolive_link' => in_array($status, ['open', 'partially_paid'], true) ? $row['checkout_url'] : '',
            'coinolive_button' => $this->l('Open the payment page'),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/payment_status.tpl');
    }

    /** Module settings (Modules > Coino Live > Configure). */
    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitCoinoLive')) {
            $output .= $this->saveSettings();
        }
        $link = $this->context->link;
        $this->context->smarty->assign([
            'coinolive_webhook' => $link->getModuleLink($this->name, 'webhook', [], true),
            'coinolive_cron' => $link->getModuleLink($this->name, 'cron', ['token' => Configuration::get('COINOLIVE_CRON_TOKEN')], true),
            'coinolive_dashboard' => $link->getAdminLink('AdminCoinoLive'),
            'coinolive_text_webhook' => $this->l('Webhook URL (sent with every payment, nothing to configure):'),
            'coinolive_text_cron' => $this->l('Safety net for missed webhooks: call this URL every 10 to 60 minutes from a cron job (or install the "Cron tasks manager" module):'),
            'coinolive_text_dashboard' => $this->l('Balance and payments'),
        ]);

        return $output . $this->renderForm() . $this->display(__FILE__, 'views/templates/admin/info.tpl');
    }

    private function saveSettings()
    {
        $apiKey = trim((string) Tools::getValue('COINOLIVE_API_KEY'));
        $secret = trim((string) Tools::getValue('COINOLIVE_WEBHOOK_SECRET'));
        $apiUrl = trim((string) Tools::getValue('COINOLIVE_API_URL'));
        $expires = (int) Tools::getValue('COINOLIVE_EXPIRES_IN');

        // Password fields come back empty when not changed: keep the stored value.
        $apiKey = $apiKey !== '' ? $apiKey : (string) Configuration::get('COINOLIVE_API_KEY');
        $secret = $secret !== '' ? $secret : (string) Configuration::get('COINOLIVE_WEBHOOK_SECRET');

        if ($apiUrl !== '' && !CoinoLiveClient::isAllowedUrl($apiUrl)) {
            return $this->displayError($this->l('The API URL must start with https://.'));
        }
        if ($expires < 15 || $expires > 10080) {
            return $this->displayError($this->l('The payment window must be between 15 and 10080 minutes.'));
        }
        if ($apiKey === '') {
            return $this->displayError($this->l('Enter your Coino Live API key.'));
        }
        try {
            $codes = array_map(function ($c) {
                return (string) $c['code'];
            }, (new CoinoLiveClient($apiKey, $apiUrl))->currencies());
        } catch (CoinoLiveException $e) {
            return $this->displayError(sprintf($this->l('Coino Live refused the API key: %s'), $e->getMessage()));
        }

        Configuration::updateValue('COINOLIVE_API_KEY', $apiKey);
        Configuration::updateValue('COINOLIVE_WEBHOOK_SECRET', $secret);
        Configuration::updateValue('COINOLIVE_API_URL', $apiUrl !== '' ? $apiUrl : CoinoLiveClient::DEFAULT_URL);
        Configuration::updateValue('COINOLIVE_EXPIRES_IN', $expires);
        Configuration::deleteByName('COINOLIVE_CURRENCIES');

        $missing = [];
        foreach (Currency::getCurrencies(false, true) as $currency) {
            if (!in_array(strtoupper($currency['iso_code']), $codes, true)) {
                $missing[] = strtoupper($currency['iso_code']);
            }
        }

        return $missing
            ? $this->displayWarning(sprintf($this->l('Saved and connected, but Coino Live cannot price payments in %s yet. Customers paying in those currencies do not see the crypto option.'), implode(', ', $missing)))
            : $this->displayConfirmation($this->l('Saved. Connected to Coino Live.'));
    }

    private function renderForm()
    {
        $form = ['form' => [
            'legend' => ['title' => $this->l('Settings'), 'icon' => 'icon-cogs'],
            'input' => [
                ['type' => 'password', 'name' => 'COINOLIVE_API_KEY', 'label' => $this->l('API key'), 'required' => true,
                    'desc' => $this->l('Create one in your Coino Live dashboard under Developers. Keys start with ck_live_. Leave empty to keep the saved key.')],
                ['type' => 'password', 'name' => 'COINOLIVE_WEBHOOK_SECRET', 'label' => $this->l('Webhook signing secret (optional)'),
                    'desc' => $this->l('From your gateway settings (whsec_…). When set, webhooks with a wrong signature are refused. Orders are always checked against the API before they change, so this is an extra layer.')],
                ['type' => 'text', 'name' => 'COINOLIVE_EXPIRES_IN', 'label' => $this->l('Payment window (minutes)'), 'class' => 'fixed-width-sm',
                    'desc' => $this->l('How long the customer has to send the payment. Between 15 and 10080.')],
                ['type' => 'text', 'name' => 'COINOLIVE_API_URL', 'label' => $this->l('API URL'),
                    'desc' => $this->l('Leave as is unless Coino Live support tells you otherwise.')],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitCoinoLive';
        $helper->fields_value = [
            'COINOLIVE_API_KEY' => '',
            'COINOLIVE_WEBHOOK_SECRET' => '',
            'COINOLIVE_EXPIRES_IN' => (int) Configuration::get('COINOLIVE_EXPIRES_IN') ?: 60,
            'COINOLIVE_API_URL' => (string) Configuration::get('COINOLIVE_API_URL') ?: CoinoLiveClient::DEFAULT_URL,
        ];

        return $helper->generateForm([$form]);
    }
}
