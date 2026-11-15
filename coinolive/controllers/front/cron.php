<?php
/**
 * Safety net for missed webhooks: index.php?fc=module&module=coinolive&controller=cron&token=… (token in the settings).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CoinoLiveCronModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $token = (string) Configuration::get('COINOLIVE_CRON_TOKEN');
        header('Content-Type: application/json');
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            echo json_encode(['message' => 'Forbidden.']);
            exit;
        }

        echo json_encode(['checked' => $this->module->payments()->syncOpen()]);
        exit;
    }
}
