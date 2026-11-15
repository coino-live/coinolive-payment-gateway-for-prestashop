<?php
/**
 * Back from the Coino Live payment page (paid or not): re-check the payment, then show the order confirmation.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CoinoLiveReturnModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $order = new Order((int) Tools::getValue('id_order'));
        $customer = Validate::isLoadedObject($order) ? new Customer((int) $order->id_customer) : null;
        if (!$customer || !hash_equals((string) $customer->secure_key, (string) Tools::getValue('key'))) {
            Tools::redirect('index.php');
        }

        $row = $this->module->payments()->latestRow((int) $order->id);
        if ($row) {
            try {
                $this->module->payments()->refresh($row['payment_id']);
            } catch (CoinoLiveException $e) {
                // The webhook and the cron job will update the order.
            }
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, (int) $order->id_lang, [
            'id_cart' => (int) $order->id_cart, 'id_module' => (int) $this->module->id, 'id_order' => (int) $order->id, 'key' => $customer->secure_key,
        ]));
    }
}
