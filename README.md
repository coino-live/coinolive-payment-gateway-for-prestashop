# Free PrestaShop Crypto Payment Gateway by Coino Live

The free, open-source [Coino Live](https://coino.live) payment module lets your PrestaShop store accept USDT, USDC, Bitcoin, Ethereum, TRON, TON, Solana and more. Customers pay on the Coino Live payment page, and orders update automatically.

**Version 1.0.0** · requires PrestaShop 8.0+ or 9, PHP 7.2+ · License: GPL-2.0-or-later

- **Download the ready-to-install zip:** [coinolive-prestashop-1.0.0.zip](https://coino.live/downloads/coinolive-prestashop-1.0.0.zip)
- **API reference:** [coino.live/api-guide](https://coino.live/api-guide)
- **Support:** [support@coino.live](mailto:support@coino.live)

## How it works

1. The customer pays on the Coino Live payment page.
2. The PrestaShop order updates automatically.
3. The payment is credited to your Coino Live balance, and you withdraw it to your own wallet.

## Supported assets and networks

Coino Live supports **117 assets on 11 networks**: Bitcoin, Litecoin, Ethereum, BNB Smart Chain, opBNB, Polygon, Base, Arbitrum, TRON, TON and Solana. USDT and USDC are available on most of these networks.

**Not accepted:** Dogecoin and Bitcoin Cash.

## Fees and sign-up

- **Free module:** the PrestaShop module is open source under GPL-2.0-or-later.
- **Payment fees:** the platform fee is 0.25% per payment. Blockchain network fees are separate.
- **No KYC:** sign up with an email and password.

## Install and configure

1. Sign up at [coino.live](https://coino.live) with an email and password. In your Coino Live dashboard, create an API key under **Developers**. Keys start with `ck_live_`.
2. Download `coinolive-prestashop-1.0.0.zip` using the link above.
3. In PrestaShop, go to **Modules > Module Manager > Upload a module** and choose `coinolive-prestashop-1.0.0.zip`.
4. Open the module configuration and paste your API key.
5. Optional: add a **Webhook signing secret** and set the **Payment window (minutes)**. The payment window is how long the customer has to send the payment, between 15 and 10080 minutes.

The module lives in the `coinolive/` folder, which is the root folder of the zip.

## Webhooks and missed payment updates

The configuration page shows two URLs:

- **Webhook URL:** sent with every payment, so there is nothing to configure.
- **Safety-net URL for missed webhooks:** call this URL every 10 to 60 minutes from a cron job, or install the "Cron tasks manager" module.

## License

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html
