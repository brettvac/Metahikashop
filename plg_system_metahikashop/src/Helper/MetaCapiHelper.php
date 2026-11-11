<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.1
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Naftee\Plugin\System\Metahikashop\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Builds and sends server-side Meta Conversions API events for HikaShop.
 *
 * PixelEventsHelper remains responsible for browser events. The event ID
 * methods in this class are also used by PixelEventsHelper so browser/server
 * events match.
 */
class MetaCapiHelper
{
      /**
     * Meta Graph API version.
     *
     * @since   1.1
     */
    private const GRAPH_API_VERSION = 'v27.0';
    
    private string $datasetId;
    private string $accessToken;
    private string $testEventCode;
    private bool $debug;

    /** Events already submitted during this PHP request. */
    private static array $sentThisRequest = [];

    /** Event IDs shared by callers during this PHP request. */
    private static array $requestEventIds = [];

    public function __construct(
        string $datasetId,
        string $accessToken,
        string $testEventCode = '',
        bool $debug = false
    ) {
        $this->datasetId = trim($datasetId);
        $this->accessToken = trim($accessToken);
        $this->testEventCode = trim($testEventCode);
        $this->debug = $debug;
    }

    /**
     * Return a stable ID for the named event during this request.
     */
    public static function getRequestEventId(
        string $eventName,
        string $context = ''
    ): string {
        $key = $eventName . ':' . $context;

        if (!isset(self::$requestEventIds[$key])) {
            try {
                $nonce = bin2hex(random_bytes(12));
            } catch (\Throwable $e) {
                $nonce = str_replace('.', '', uniqid('', true));
            }

            self::$requestEventIds[$key] =
                'hk_' . strtolower($eventName) . '_' . $nonce;
        }

        return self::$requestEventIds[$key];
    }

    /**
     * Build browser-level identifiers without requiring an order.
     */
    public function buildBrowserUserData(): array
    {
        $app = \Joomla\CMS\Factory::getApplication();
        $server = $app->input->server;
        $userData = [];

        $ip = trim((string) $server->getString(
            'REMOTE_ADDR',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ));

        $userAgent = trim((string) $server->getString(
            'HTTP_USER_AGENT',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ));

        if ($ip !== '') {
            $userData['client_ip_address'] = $ip;
        }

        if ($userAgent !== '') {
            $userData['client_user_agent'] = $userAgent;
        }

        $fbp = $this->getStoredBrowserIdentifier('fbp');
        $fbc = $this->getStoredBrowserIdentifier('fbc');

        if ($fbp !== '') {
            $userData['fbp'] = $fbp;
        }

        if ($fbc !== '') {
            $userData['fbc'] = $fbc;
        }

        return $userData;
    }

    /**
     * Build order/customer identifiers and merge browser-level identifiers.
     */
    public function buildOrderUserData($orderData): array
    {
        $userData = $this->buildBrowserUserData();
        $email = '';

        if (!empty($orderData->customer->user_email)) {
            $email = $orderData->customer->user_email;
        } elseif (!empty($orderData->billing_address->address_email)) {
            $email = $orderData->billing_address->address_email;
        }

        if ($email !== '') {
            $userData['em'] = [$this->hash($email)];
        }

        $phone = '';

        if (!empty($orderData->billing_address->address_telephone)) {
            $phone = $orderData->billing_address->address_telephone;
        } elseif (!empty($orderData->shipping_address->address_telephone)) {
            $phone = $orderData->shipping_address->address_telephone;
        }

        $phone = preg_replace('/\D+/', '', (string) $phone);

        if ($phone !== '') {
            $userData['ph'] = [$this->hash($phone)];
        }

        $billing = $orderData->billing_address ?? null;

        $fields = [
            'fn' => 'address_firstname',
            'ln' => 'address_lastname',
            'ct' => 'address_city',
            'st' => 'address_state',
            'zp' => 'address_post_code',
            'country' => 'address_country',
        ];

        foreach ($fields as $metaField => $addressField) {
            if (!empty($billing->$addressField)) {
                $value = (string) $billing->$addressField;

                if ($metaField === 'country') {
                    $value = strtolower($value);
                }

                $userData[$metaField] = [$this->hash($value)];
            }
        }

        if (!empty($orderData->order_user_id)) {
            $userData['external_id'] = [
                $this->hash((string) $orderData->order_user_id)
            ];
        }

        /*
         * Preserve identifiers captured when the order was first created.
         */
        $orderParams = $this->decodeOrderParams($orderData);

        foreach (['fbp', 'fbc'] as $key) {
            $property = 'meta_' . $key;

            if (!empty($orderParams->$property)) {
                $userData[$key] = trim((string) $orderParams->$property);
            }
        }

        /*
         * The IP and user agent saved with the order are more reliable
         * for delayed Purchase events.
         */
        if (!empty($orderParams->meta_client_ip)) {
            $userData['client_ip_address'] =
                trim((string) $orderParams->meta_client_ip);
        }

        if (!empty($orderParams->meta_user_agent)) {
            $userData['client_user_agent'] =
                trim((string) $orderParams->meta_user_agent);
        }

        return $userData;
    }

    /**
     * Send a PageView event.
     */
    public function sendPageView(?string $eventId = null): bool
    {
        $eventId = $eventId ?: self::getRequestEventId('PageView');

        return $this->sendOnce('PageView:' . $eventId, [
            'event_name' => 'PageView',
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $this->getCurrentUrl(),
            'user_data' => $this->buildBrowserUserData(),
        ]);
    }

    /**
     * Send a ViewContent event for a HikaShop product.
     */
    public function sendViewContent(
        $product,
        ?string $eventId = null
    ): bool {
        if (empty($product) || empty($product->product_code)) {
            return false;
        }

        $config = hikashop_config();

        $priceKey = $config->get('price_with_tax')
            ? 'price_value_with_tax'
            : 'price_value';

        $value = 0.0;

        if (!empty($product->prices[0]->$priceKey)) {
            $value = round(
                (float) $product->prices[0]->$priceKey,
                2
            );
        }

        $currency = strtoupper(
            (string) ($product->currency_code ?? '')
        );

        if ($currency === '') {
            $this->log(
                'Meta CAPI: ViewContent skipped because product currency is unavailable'
            );

            return false;
        }

        $eventId = $eventId ?: self::getRequestEventId('ViewContent');

        $event = [
            'event_name' => 'ViewContent',
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $this->getCurrentUrl(),
            'user_data' => $this->buildBrowserUserData(),
            'custom_data' => [
                'content_ids' => [(string) $product->product_code],
                'content_name' => strip_tags(
                    (string) ($product->product_name ?? '')
                ),
                'content_type' => 'product',
                'value' => $value,
                'currency' => $currency,
            ],
        ];

        return $this->sendOnce('ViewContent:' . $eventId, $event);
    }

    /**
     * Send InitiateCheckout for a full HikaShop cart.
     */
    public function sendInitiateCheckout(
        $cart,
        ?string $eventId = null
    ): bool {
        $data = $this->getCartData($cart);

        if ($data === false) {
            return false;
        }

        $eventId = $eventId ?: self::getCheckoutEventId(
            $data['cart_signature']
        );

        /*
         * Checkout may invoke step/show repeatedly across separate requests.
         */
        if (self::hasCheckoutEventBeenSent($data['cart_signature'])) {
            return true;
        }

        $event = [
            'event_name' => 'InitiateCheckout',
            'event_time' => time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $this->getCurrentUrl(),
            'user_data' => $this->buildBrowserUserData(),
            'custom_data' => [
                'content_ids' => $data['content_ids'],
                'contents' => $data['contents'],
                'content_type' => 'product',
                'value' => $data['value'],
                'currency' => $data['currency'],
                'num_items' => $data['num_items'],
            ],
        ];

        $sent = $this->sendOnce(
            'InitiateCheckout:' . $eventId,
            $event
        );

        if ($sent) {
            self::markCheckoutEventSent($data['cart_signature']);
        }

        return $sent;
    }

    /**
     * Send Purchase.
     *
     * Call only after the plugin's existing order-status and
     * claimPurchaseEvent() checks have succeeded.
     */
    public function sendPurchaseEvent($orderData): bool
    {
        if (empty($orderData) || empty($orderData->products)) {
            return false;
        }

        $config = hikashop_config();

        $priceWithTax = (bool) $config->get('price_with_tax');
        $groupOptions = (bool) $config->get('group_options', 0);

        $currencyClass = hikashop_get('class.currency');

        $currencyData = $currencyClass->get(
            (int) $orderData->order_currency_id
        );

        $currency = $currencyData->currency_code ?? null;

        if ($currency !== null) {
            $currency = strtoupper((string) $currency);
        }

        $value = (float) $orderData->order_full_price;

        if (!$priceWithTax && !empty($orderData->order_tax_info)) {
            $taxInfo = is_string($orderData->order_tax_info)
                ? hikashop_unserialize($orderData->order_tax_info)
                : $orderData->order_tax_info;

            $taxes = 0.0;

            if (is_array($taxInfo) || is_object($taxInfo)) {
                foreach ($taxInfo as $tax) {
                    if (is_object($tax) && isset($tax->tax_amount)) {
                        $taxes += (float) $tax->tax_amount;
                    }
                }
            }

            $value -= $taxes;
        }

        $contents = [];
        $contentIds = [];
        $numItems = 0;

        foreach ($orderData->products as $product) {
            $quantity = (int) ($product->order_product_quantity ?? 0);

            if (
                $quantity <= 0
                || (
                    $groupOptions
                    && !empty($product->order_product_option_parent_id)
                )
            ) {
                continue;
            }

            $itemId = (string) ($product->order_product_code ?? '');

            if ($itemId === '') {
                continue;
            }

            $itemPrice = (float) ($product->order_product_price ?? 0);

            if ($priceWithTax) {
                $itemPrice += (float) ($product->order_product_tax ?? 0);
            }

            $contents[] = [
                'id' => $itemId,
                'quantity' => $quantity,
                'item_price' => round($itemPrice, 2),
            ];

            $contentIds[] = $itemId;
            $numItems += $quantity;
        }

        if (!$contents) {
            return false;
        }

        // Use the time the order became exportable when available.
        $eventTime = time();

        if (!empty($orderData->order_invoice_created)) {
            $eventTime = (int) $orderData->order_invoice_created;
        } elseif (!empty($orderData->order_modified)) {
            $eventTime = (int) $orderData->order_modified;
        }

        if ($eventTime < time() - (7 * 24 * 3600)) {
            $eventTime = time();
        }

        // Keep the Purchase event ID identical to the Pixel event ID.
        $eventId = 'hk_order_'
            . (int) $orderData->order_id
            . '_'
            . (string) $orderData->order_number;

        $customData = [
            'currency' => $currency,
            'value' => round($value, 2),
            'content_ids' => $contentIds,
            'contents' => $contents,
            'content_type' => 'product',
            'num_items' => $numItems,
            'order_id' => (string) $orderData->order_number,
        ];

        if (!empty($orderData->order_discount_code)) {
            $customData['coupon'] =
                (string) $orderData->order_discount_code;
        }

        return $this->sendOnce(
            'Purchase:' . $eventId,
            [
                'event_name' => 'Purchase',
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => $this->getCurrentUrl(),
                'user_data' => $this->buildOrderUserData($orderData),
                'custom_data' => $customData,
            ],
            $orderData->order_id
        );
    }

    /**
     * Generate/reuse a checkout event ID for a given cart during this session.
     */
    public static function getCheckoutEventId(
        string $cartSignature
    ): string {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            && !headers_sent()
        ) {
            @session_start();
        }

        if (!isset($_SESSION['metahikashop_checkout_event_ids'])) {
            $_SESSION['metahikashop_checkout_event_ids'] = [];
        }

        if (empty($_SESSION['metahikashop_checkout_event_ids'][$cartSignature])) {
            try {
                $nonce = bin2hex(random_bytes(12));
            } catch (\Throwable $e) {
                $nonce = str_replace('.', '', uniqid('', true));
            }

            $_SESSION['metahikashop_checkout_event_ids'][$cartSignature] =
                'hk_initiatecheckout_' . $nonce;
        }

        return (string) $_SESSION['metahikashop_checkout_event_ids'][$cartSignature];
    }

    /**
     * Check whether InitiateCheckout was already accepted for this cart.
     */
    public static function hasCheckoutEventBeenSent(
        string $cartSignature
    ): bool {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            && !headers_sent()
        ) {
            @session_start();
        }

        return !empty(
            $_SESSION['metahikashop_checkout_events_sent'][$cartSignature]
        );
    }

    /**
     * Mark a successfully submitted checkout event in the session.
     */
    private static function markCheckoutEventSent(
        string $cartSignature
    ): void {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            && !headers_sent()
        ) {
            @session_start();
        }

        if (!isset($_SESSION['metahikashop_checkout_events_sent'])) {
            $_SESSION['metahikashop_checkout_events_sent'] = [];
        }

        $_SESSION['metahikashop_checkout_events_sent'][$cartSignature] = time();
    }

    /**
     * Convert the full cart into CAPI-ready values.
     */
    public function getCartData($cart)
    {
        if (empty($cart) || empty($cart->products)) {
            return false;
        }

        $config = hikashop_config();

        $priceKey = $config->get('price_with_tax')
            ? 'price_value_with_tax'
            : 'price_value';

        $groupOptions = (bool) $config->get('group_options', 0);

        $currencyClass = hikashop_get('class.currency');
        $currencyId = hikashop_getCurrency();

        $currencies = [];
        $currencyClass->getCurrencies($currencyId, $currencies);

        $currency = strtoupper(
            (string) ($currencies[$currencyId]->currency_code ?? '')
        );

        if ($currency === '') {
            return false;
        }

        $contentIds = [];
        $contents = [];
        $numItems = 0;
        $signatureLines = [];

        foreach ($cart->products as $key => $product) {
            $quantity = (int) ($product->cart_product_quantity ?? 0);

            if (
                $quantity <= 0
                || substr((string) $key, 0, 1) === 'p'
            ) {
                continue;
            }

            if (
                $groupOptions
                && !empty($product->cart_product_option_parent_id)
            ) {
                continue;
            }

            $itemId = (string) ($product->product_code ?? '');

            if ($itemId === '') {
                continue;
            }

            $price = 0.0;

            if (isset($product->prices[0]->$priceKey)) {
                $price = round(
                    (float) $product->prices[0]->$priceKey,
                    2
                );
            }

            $contentIds[] = $itemId;

            $contents[] = [
                'id' => $itemId,
                'quantity' => $quantity,
                'item_price' => $price,
            ];

            $numItems += $quantity;

            $signatureLines[] =
                $itemId . ':' . $quantity . ':' . $price;
        }

        if (!$contents) {
            return false;
        }

        $value = 0.0;

        if (isset($cart->total->prices[0]->$priceKey)) {
            $value = round(
                (float) $cart->total->prices[0]->$priceKey,
                2
            );
        }

        sort($signatureLines);

        return [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'value' => $value,
            'currency' => $currency,
            'num_items' => $numItems,
            'cart_signature' => hash(
                'sha256',
                $currency . '|' . $value . '|' . implode('|', $signatureLines)
            ),
        ];
    }

    /**
     * Submit an event only once per request.
     *
     * Failed submissions may be retried.
     */
    private function sendOnce(
        string $dedupeKey,
        array $event,
        $reference = null
    ): bool {
        if (isset(self::$sentThisRequest[$dedupeKey])) {
            return self::$sentThisRequest[$dedupeKey];
        }

        $sent = $this->sendEvent($event, $reference);

        if ($sent) {
            self::$sentThisRequest[$dedupeKey] = true;
        }

        return $sent;
    }

    /**
     * Validate and submit an event.
     */
    private function sendEvent(
        array $event,
        $reference = null
    ): bool {
        if ($this->datasetId === '' || $this->accessToken === '') {
            $this->log('Meta CAPI: missing dataset ID or access token');
            return false;
        }

        $payload = ['data' => [$event]];

        if ($this->testEventCode !== '') {
            $payload['test_event_code'] = $this->testEventCode;
        }

        return $this->sendToMeta($payload, $reference);
    }

    //Send the event payload to Meta.
    private function sendToMeta(
        array $payload,
        $reference = null
    ): bool {
        $url = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION . '/' . rawurlencode($this->datasetId) . '/events';

        $body = ['data' => $payload['data']];

        if (!empty($payload['test_event_code'])) {
            $body['test_event_code'] = $payload['test_event_code'];
        }

        /*
         * Do not write customer identifiers or full user_data to logs.
         */
        $eventNames = array_map(
            static function ($event) {
                return $event['event_name'] ?? 'unknown';
            },
            $body['data']
        );

        $this->log(
            'Meta CAPI: sending ' . implode(', ', $eventNames)
            . (
                $reference !== null
                    ? ' (reference ' . (string) $reference . ')'
                    : ''
            )
        );

        try {
            $http = HttpFactory::getHttp();

            $response = $http->post(
                $url,
                json_encode($body),
                [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ],
                15
            );

            if ($response->code < 200 || $response->code >= 300) {
                $this->log(
                    'Meta CAPI error: HTTP '
                    . $response->code . ' - ' . $response->body
                );

                return false;
            }

            $this->log('Meta CAPI success: ' . $response->body);

            return true;
        } catch (\Throwable $e) {
            $this->log('Meta CAPI exception: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Retrieve a stored Meta browser identifier.
     */
    private function getStoredBrowserIdentifier(string $name): string
    {
        $key = 'hikashop_meta_' . $name;

        if (!empty($_SESSION[$key])) {
            return trim((string) $_SESSION[$key]);
        }

        $cookieName = '_' . $name;

        if (!empty($_COOKIE[$cookieName])) {
            return trim((string) $_COOKIE[$cookieName]);
        }

        return '';
    }

    /**
     * Decode order payment parameters.
     */
    private function decodeOrderParams($orderData)
    {
        if (empty($orderData->order_payment_params)) {
            return new \stdClass();
        }

        $params = $orderData->order_payment_params;

        if (is_string($params)) {
            $params = function_exists('hikashop_unserialize')
                ? hikashop_unserialize($params)
                : null;
        }

        return is_object($params) ? $params : new \stdClass();
    }

    /**
     * Get the current page URL.
     */
    private function getCurrentUrl(): string
    {
        $scheme = (
            !empty($_SERVER['HTTPS'])
            && strtolower((string) $_SERVER['HTTPS']) !== 'off'
        ) ? 'https' : 'http';

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        if ($host === '') {
            return '';
        }

        return $scheme . '://' . $host . $uri;
    }

    /**
     * Normalize and hash a customer value.
     */
    private function hash($value): string
    {
        return hash(
            'sha256',
            strtolower(trim((string) $value))
        );
    }

    /**
     * Write a plugin log message.
     */
    private function log(string $message): void
    {
        if ($this->debug && function_exists('hikashop_writeToLog')) {
            hikashop_writeToLog($message);
        }

        if (defined('JDEBUG') && JDEBUG) {
            \Joomla\CMS\Log\Log::add(
                $message,
                \Joomla\CMS\Log\Log::DEBUG,
                'plg_system_metahikashop'
            );
        }
    }
}
