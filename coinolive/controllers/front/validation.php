<?php
/**
 * Places the order ("Awaiting crypto payment"), creates its Coino Live payment and sends the customer to it.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CoinoLiveValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$cart || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // The option may have gone away (address or currency changed just before paying).
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            exit($this->module->l('This payment method is not available.'));
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Clicked twice, or came back: the cart is an order already.
        $idOrder = (int) Order::getIdByCartId((int) $cart->id);
        $created = !$idOrder;
        if ($created) {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('COINOLIVE_OS_WAITING'),
                (float) $cart->getOrderTotal(true, Cart::BOTH),
                $this->module->displayName,
                null,
                [],
                (int) $this->context->currency->id,
                false,
                $customer->secure_key
            );
            $idOrder = (int) $this->module->currentOrder;
        }
        $order = new Order($idOrder);

        try {
            $payment = $this->module->payments()->paymentFor($order);
        } catch (CoinoLiveException $e) {
            PrestaShopLogger::addLog('Coino Live: ' . $e->getMessage(), 3, null, 'Order', $idOrder, true);
            // A new order Coino Live refused (below the minimum, unsupported currency…) must not wait forever and hold stock.
            if ($created && (int) $order->getCurrentState() === (int) Configuration::get('COINOLIVE_OS_WAITING')) {
                $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));
                $message = new Message();
                $message->id_order = (int) $order->id;
                $message->id_cart = (int) $order->id_cart;
                $message->id_customer = (int) $order->id_customer;
                $message->private = true;
                $message->message = Tools::substr('Coino Live refused the payment: ' . strip_tags($e->getMessage()), 0, 1600);
                $message->add();
            }
            $this->errors[] = sprintf($this->module->l('Crypto payment is not available right now: %s'), $e->getMessage());
            $this->redirectWithNotifications($this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart' => (int) $cart->id, 'id_module' => (int) $this->module->id, 'id_order' => $idOrder, 'key' => $customer->secure_key,
            ]));

            return;
        }

        if (in_array($payment['status'], ['paid', 'confirming'], true)) {
            Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart' => (int) $cart->id, 'id_module' => (int) $this->module->id, 'id_order' => $idOrder, 'key' => $customer->secure_key,
            ]));
        }
        Tools::redirect($payment['checkout_url']);
    }
}
