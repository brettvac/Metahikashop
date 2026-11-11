# System - HikaShop Meta Conversions API

Send HikaShop purchase events to Meta via the Conversions API and optionally tracks shop browser events via the Meta Pixel.

## Features

- Server-side **Purchase** via Meta Conversions API (idempotent per order)
- Optional Meta Pixel JS: PageView, ViewContent, InitiateCheckout, Purchase
- Shared eligibility rules between browser and server Purchase events
- Dataset ID, access token, test event code, Graph API version
- Debug logging via HikaShop log and/or Joomla `JDEBUG`

## Requirements

- Joomla 5.x and up
- HikaShop
- PHP 8.1.0 and up
- Meta Dataset ID + System User access token

## Installation

1. Download the  `plg_system_metahikashop.zip` file.
2. In Joomla: **System → Extensions → Install**.
3. Enable **System - HikaShop Meta Conversions API**.
4. Open the plugin settings and enter your Dataset ID and Access Token.

## Configuration notes

- **Purchase (CAPI)** is the only server-side event fully implemented.
- **only_confirmed** (default on): send CAPI Purchase only when the order transitions into an invoice status.
- A successful CAPI send is stored on the order (`meta_capi_purchase_sent`) so the same order is never sent twice.
- Browser and server Purchase both require the order status to be in HikaShop’s invoice statuses.
