# Coino Live crypto payments for PrestaShop

Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more. Customers pay on the Coino Live payment page; orders update automatically.

**Version 1.0.0** · requires PrestaShop 8.0+ or 9, PHP 7.2+

- Download the ready-to-install zip: [coinolive-prestashop-1.0.0.zip](https://coino.live/downloads/coinolive-prestashop-1.0.0.zip)
- API reference: [coino.live/api-guide](https://coino.live/api-guide) · Support: [support@coino.live](mailto:support@coino.live)

## Install

1. Modules > Module Manager > Upload a module: choose `coinolive-prestashop-1.0.0.zip`.
2. Configure: paste your API key. Create one in your Coino Live dashboard under Developers. Keys start with ck_live_.
3. Optional: Webhook signing secret, and the Payment window (minutes): how long the customer has to send the payment. Between 15 and 10080.

The configuration page shows the Webhook URL (sent with every payment, nothing to configure) and a safety-net URL for missed webhooks: call this URL every 10 to 60 minutes from a cron job (or install the "Cron tasks manager" module).

The module lives in the `coinolive/` folder, which is the root folder of the zip.

## License

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
