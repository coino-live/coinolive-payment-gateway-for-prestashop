<?php
/**
 * POST index.php?fc=module&module=coinolive&controller=webhook
 *
 * A webhook only says "this payment changed"; the order is updated from the payment as read back
 * from the API with the shop's key. With a signing secret set, the signature is checked too.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CoinoLiveWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->module->active) {
            $this->reply(405, ['message' => 'Method not allowed.']);
        }

        $body = (string) file_get_contents('php://input', false, null, 0, 65536);
        $secret = trim((string) Configuration::get('COINOLIVE_WEBHOOK_SECRET'));
        if ($secret !== '' && !CoinoLivePayments::validSignature((string) ($_SERVER['HTTP_X_COINO_SIGNATURE'] ?? ''), $body, $secret)) {
            $this->reply(401, ['message' => 'Invalid signature.']);
        }

        $event = json_decode($body, true);
        $id = is_array($event) && isset($event['data']['id']) ? (string) $event['data']['id'] : '';
        $name = is_array($event) && isset($event['event']) ? (string) $event['event'] : '';
        $payments = $this->module->payments();
        if (strpos($name, 'payment.') !== 0 || !preg_match('/^[0-9a-z]{26}$/', $id) || !$payments->row($id)) {
            $this->reply(200, ['ignored' => true]);
        }

        try {
            $result = $payments->refresh($id);
        } catch (CoinoLiveException $e) {
            $this->reply(503, ['message' => 'Try again later.']); // Coino Live retries.
        }

        $this->reply(200, ['ok' => true, 'result' => $result]);
    }

    private function reply($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }
}
