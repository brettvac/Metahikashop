# System - HikaShop Meta Conversions API

Send HikaShop events to Meta using the Conversions API (CAPI), with optional browser-side event tracking through the Meta Pixel.

## Features

- Server-side tracking of **PageView**, **ViewContent**, **InitiateCheckout**, and **Purchase** events via Meta Conversions API.
- Optional Meta Pixel integration for browser-side tracking of PageView, ViewContent, InitiateCheckout, and Purchase events.
- Shared event IDs for browser and server-side event deduplication.
- Shared eligibility rules and customer data collection for browser and server-side events.
- Configurable Dataset ID, access token, test event code, and Graph API version.
- Individual configuration options to enable or disable server-side events.
- Debug logging via HikaShop's logging system and/or Joomla's `JDEBUG` constant.

## Requirements

- Joomla 5.x or later
- HikaShop
- PHP 8.1.0 or later
- Meta Dataset ID and System User access token

## Installation

1. Download the `plg_system_metahikashop.zip` file.
2. In Joomla, navigate to **System → Extensions → Install**.
3. Enable **System - HikaShop Meta Conversions API**.
4. Open the plugin settings and enter your Dataset ID and Access Token.

## Configuration notes

- Server-side tracking supports **PageView**, **ViewContent**, **InitiateCheckout**, and **Purchase** events. Each event can be enabled or disabled independently.
- Meta Pixel initialization is optimized to avoid loading its base code unnecessarily when PageView and ViewContent tracking are disabled. In this configuration, Pixel initialization is restricted to relevant HikaShop checkout and order confirmation pages.
- The `only_confirmed` option (enabled by default) restricts server-side Purchase events to orders that have reached an invoice status.
- Successfully transmitted Purchase events are recorded on the order using `meta_capi_purchase_sent` to prevent duplicate submissions.
- Browser and server-side Purchase events both require the order status to be included in HikaShop's invoice statuses.
- Matching event IDs are used where applicable to allow Meta to deduplicate events received from both the Pixel and Conversions API.
- The InitiateCheckout server-side event is deduplicated to prevent repeated submissions for the same cart during checkout.
