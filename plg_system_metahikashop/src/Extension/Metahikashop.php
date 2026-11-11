<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.1
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Naftee\Plugin\System\Metahikashop\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Naftee\Plugin\System\Metahikashop\Helper\MetaCapiHelper;
use Naftee\Plugin\System\Metahikashop\Helper\PixelEventsHelper;

/*
 * Load HikaShop compatibility class when available.
 */
$pluginCompat = JPATH_ADMINISTRATOR
    . '/components/com_hikashop/pluginCompat.php';

if (file_exists($pluginCompat)) {
    include_once $pluginCompat;
}

if (class_exists('\\hikashopJoomlaPlugin')) {
    class plgSystemMetahikashopBridge extends \hikashopJoomlaPlugin {}
} else {
    class plgSystemMetahikashopBridge extends \Joomla\CMS\Plugin\CMSPlugin {}
}

/**
 * Main plugin class.
 */
class Metahikashop extends plgSystemMetahikashopBridge
{
    protected $autoloadLanguage = true;

    /** @var string Meta Dataset ID */
    private $dataset_id;

    /** @var string Meta Access Token */
    private $access_token;

    /** @var string Optional test event code */
    private $test_event_code;

    /** @var bool Debug mode */
    private $debug;

    /** @var string Graph API version */
    private $api_version = 'v26.0';

    /** @var bool Only send Purchase when order is confirmed */
    private $only_confirmed;

    /* Browser Pixel event toggles */
    private $js_pageview;
    private $js_viewcontent;
    private $js_initiatecheckout;
    private $js_purchase;

    /* Server-side CAPI event toggles */
    private $capi_pageview;
    private $capi_viewcontent;
    private $capi_initiatecheckout;
    private $capi_purchase;

    /** @var MetaCapiHelper */
    private $capiHelper;

    /**
     * Load plugin parameters and instantiate the CAPI helper.
     */
    private function init()
    {
        $this->dataset_id      = trim((string) $this->params->get('dataset_id', ''));
        $this->access_token    = trim((string) $this->params->get('access_token', ''));
        $this->test_event_code = trim((string) $this->params->get('test_event_code', ''));
        $this->debug           = (bool) $this->params->get('debug', false);
        $this->only_confirmed  = (bool) $this->params->get('only_confirmed', true);

        $this->js_pageview         = (bool) $this->params->get('js_pageview', 1);
        $this->js_viewcontent      = (bool) $this->params->get('js_viewcontent', 1);
        $this->js_initiatecheckout = (bool) $this->params->get('js_initiatecheckout', 1);
        $this->js_purchase         = (bool) $this->params->get('js_purchase', 1);

        $this->capi_pageview         = (bool) $this->params->get('capi_pageview', 0);
        $this->capi_viewcontent      = (bool) $this->params->get('capi_viewcontent', 0);
        $this->capi_initiatecheckout = (bool) $this->params->get('capi_initiatecheckout', 0);
        $this->capi_purchase         = (bool) $this->params->get('capi_purchase', 1);

        $this->capiHelper = new MetaCapiHelper(
            $this->dataset_id,
            $this->access_token,
            $this->test_event_code,
            $this->debug,
            $this->api_version
        );
    }

    /**
     * Inject Meta Pixel base code and process frontend CAPI events.
     */
    public function onBeforeCompileHead()
    {
        if ($this->getApplication()->isClient('administrator')) {
            return;
        }

        /*
         * Load HikaShop helper functions.
         */
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        if (!include_once(
            rtrim(JPATH_ADMINISTRATOR, DS)
            . DS . 'components' . DS . 'com_hikashop'
            . DS . 'helpers' . DS . 'helper.php'
        )) {
            return true;
        }

        $this->init();

        if ($this->dataset_id === '') {
            return true;
        }

        $app = $this->getApplication();

        /*
         * Generate shared PageView and ViewContent event IDs.
         */
        $pageViewEventId = MetaCapiHelper::getRequestEventId('PageView');
        $viewContentEventId = MetaCapiHelper::getRequestEventId('ViewContent');

        /*
         * PageView CAPI.
         */
        if ($this->capi_pageview) {
            $this->capiHelper->sendPageView($pageViewEventId);
        }

        /*
         * ViewContent CAPI.
         */
        if ($this->capi_viewcontent) {
            $product = PixelEventsHelper::getProductInfo();

            if ($product) {
                $this->capiHelper->sendViewContent(
                    $product,
                    $viewContentEventId
                );
            }
        }

        /*
         * InitiateCheckout CAPI.
         *
         * Preserve the existing checkout eligibility rules:
         * checkout step/show and an empty cid.
         */
        $checkoutEventId = null;

        $option = \hikaInput::get()->getString('option');
        $ctrl   = \hikaInput::get()->getString('ctrl');
        $task   = \hikaInput::get()->getString('task');
        $cid    = \hikaInput::get()->getInt('cid');

        if (
            $ctrl === 'checkout'
            && in_array($option, ['com_hikashop', ''])
            && in_array($task, ['step', 'show'])
            && empty($cid)
        ) {
            $cartClass = hikashop_get('class.cart');
            $cart = $cartClass->getFullCart();

            if (!empty($cart) && !empty($cart->products)) {
                $cartData = $this->capiHelper->getCartData($cart);

                if ($cartData !== false) {
                    $checkoutEventId = MetaCapiHelper::getCheckoutEventId(
                        $cartData['cart_signature']
                    );

                    if ($this->capi_initiatecheckout) {
                        $this->capiHelper->sendInitiateCheckout(
                            $cart,
                            $checkoutEventId
                        );
                    }
                }
            }
        }

        /*
         * Build browser Pixel event snippets.
         */
        $eventJs = '';

        if ($this->js_viewcontent) {
            $eventJs .= PixelEventsHelper::getViewContentJS(
                $viewContentEventId
            );
        }

        if ($this->js_initiatecheckout) {
            $eventJs .= PixelEventsHelper::getInitiateCheckoutJS(
                $checkoutEventId
            );
        }

        if ($this->js_purchase) {
            $eventJs .= PixelEventsHelper::getPurchaseJS();
        }

        /*
         * Initialize the Pixel when PageView or ViewContent is enabled,
         * or when another enabled event applies to the current page.
         */
        $globalPixel = $this->js_pageview || $this->js_viewcontent;

        if (!$globalPixel && $eventJs === '') {
            return true;
        }

        $wa = $app->getDocument()->getWebAssetManager();

        /*
         * Base Pixel code.
         */
        $baseJs = "
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', " . json_encode(
            (string) $this->dataset_id,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ");
";

        /*
         * PageView Pixel.
         *
         * Uses the same event ID as the PageView CAPI event.
         */
        if ($this->js_pageview) {
            $baseJs .= "fbq('track', 'PageView', {}, {eventID: "
                . json_encode(
                    $pageViewEventId,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                )
                . "});\n";
        }

        $wa->addInlineScript($baseJs);

        /*
         * Capture fbp / fbc for later server-side use.
         */
        $wa->addInlineScript("
(function() {
    function captureMetaCookies() {
        try {
            var fbp = document.cookie.match(/_fbp=([^;]+)/);
            var fbc = document.cookie.match(/_fbc=([^;]+)/);

            if (fbp || fbc) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send('hikashop_meta_fbp=' + encodeURIComponent(fbp ? fbp[1] : '') +
                         '&hikashop_meta_fbc=' + encodeURIComponent(fbc ? fbc[1] : ''));
            }
        } catch(e) {}
    }

    if (window.hikashop && typeof window.hikashop.ready === 'function') {
        window.hikashop.ready(captureMetaCookies);
    } else {
        captureMetaCookies();
    }
})();
");

        if ($eventJs !== '') {
            $wa->addInlineScript($eventJs);
        }

        return true;
    }

    /**
     * Capture fbp / fbc cookies early.
     */
    public function onAfterInitialise()
    {
        $app = Factory::getApplication();

        $fbp = $app->input->getString('hikashop_meta_fbp', '');
        $fbc = $app->input->getString('hikashop_meta_fbc', '');

        if ($fbp !== '') {
            $_SESSION['hikashop_meta_fbp'] = $fbp;
        }

        if ($fbc !== '') {
            $_SESSION['hikashop_meta_fbc'] = $fbc;
        }
    }

    /**
     * Store client identifiers on the order at creation time.
     */
    public function onBeforeOrderCreate(&$order)
    {
        if (isset($order->order_type) && $order->order_type != 'sale') {
            return;
        }

        $app = Factory::getApplication();

        if (function_exists('hikashop_isClient') && hikashop_isClient('administrator')) {
            return;
        }

        if (!empty($order->order_payment_params) && is_string($order->order_payment_params)) {
            $order->order_payment_params = hikashop_unserialize(
                $order->order_payment_params
            );
        }

        if (empty($order->order_payment_params)) {
            $order->order_payment_params = new \stdClass();
        }

        if (!empty($_SESSION['hikashop_meta_fbp'])) {
            $order->order_payment_params->meta_fbp = trim(
                $_SESSION['hikashop_meta_fbp']
            );
        }

        if (!empty($_SESSION['hikashop_meta_fbc'])) {
            $order->order_payment_params->meta_fbc = trim(
                $_SESSION['hikashop_meta_fbc']
            );
        }

        $order->order_payment_params->meta_client_ip =
            $app->input->server->getString('REMOTE_ADDR', '');

        $order->order_payment_params->meta_user_agent =
            $app->input->server->getString('HTTP_USER_AGENT', '');
    }

    public function onAfterOrderCreate(&$order)
    {
        if (isset($order->order_type) && $order->order_type != 'sale') {
            return;
        }

        return $this->checkOrder($order, true);
    }

    public function onAfterOrderUpdate(&$order)
    {
        if (isset($order->order_type) && $order->order_type != 'sale') {
            return;
        }

        return $this->checkOrder($order, false);
    }

    /**
     * Decide whether to send a server-side Purchase event.
     *
     * Only sale orders in an invoice/exportable status are eligible.
     *
     * @param   object  $order  HikaShop order.
     * @param   bool    $new    Whether this is a newly created order.
     *
     * @return  bool|null
     */
    protected function checkOrder(&$order, $new = false)
    {
        $this->init();

        if (!$this->capi_purchase) {
            return;
        }

        if (!empty($order->order_type) && $order->order_type == 'subsale') {
            return true;
        }

        if (!empty($order->old->order_type) && $order->old->order_type == 'subsale') {
            return true;
        }

        if (empty($order->order_status)) {
            return true;
        }

        $config = hikashop_config();

        $invoice_statuses = array_filter(
            array_map(
                'trim',
                explode(',', $config->get('invoice_order_statuses', 'confirmed,shipped'))
            )
        );

        $isExportable = in_array(
            $order->order_status,
            $invoice_statuses,
            true
        );

        $oldStatus = isset($order->old->order_status)
            ? $order->old->order_status
            : null;

        $wasNotExportable = $new
            || ($oldStatus === null)
            || !in_array($oldStatus, $invoice_statuses, true);

        if (!$isExportable) {
            return;
        }

        if ($this->only_confirmed && !$wasNotExportable) {
            return;
        }

        if ($this->dataset_id === '' || $this->access_token === '') {
            $this->log('Meta CAPI: missing dataset_id or access_token');
            return;
        }

        $orderClass = hikashop_get('class.order');
        $orderData = $orderClass->loadFullOrder(
            $order->order_id,
            false,
            false
        );

        if (empty($orderData) || empty($orderData->products)) {
            return;
        }

        /*
         * Atomically claim this order before sending.
         */
        $orderId = (int) $orderData->order_id;

        if (!$this->claimPurchaseEvent($orderId)) {
            $this->log(
                'Meta CAPI: Purchase already sent or being processed for order '
                . $orderId . ', skipping'
            );

            return true;
        }

        $sent = $this->sendPurchaseEvent($orderData);

        if ($sent) {
            $this->markPurchaseSent($orderId);
        } else {
            $this->releasePurchaseClaim($orderId);
        }

        return true;
    }

    /**
     * Atomically claim an order for Purchase event processing.
     */
    private function claimPurchaseEvent(int $orderId): bool
    {
        $db = Factory::getContainer()->get(
            \Joomla\Database\DatabaseInterface::class
        );

        $table = $db->quoteName(
            $db->getPrefix() . 'metahikashop_capi_purchase'
        );

        try {
            $db->setQuery(
                'INSERT IGNORE INTO ' . $table . ' '
                . '(' . $db->quoteName('order_id') . ', '
                . $db->quoteName('status') . ') VALUES ('
                . (int) $orderId . ', ' . $db->quote('pending') . ')'
            );

            $db->execute();

            $query = $db->getQuery(true)
                ->update($table)
                ->set($db->quoteName('status') . ' = ' . $db->quote('processing'))
                ->set($db->quoteName('claimed_at') . ' = UTC_TIMESTAMP()')
                ->where($db->quoteName('order_id') . ' = ' . (int) $orderId)
                ->where($db->quoteName('status') . ' <> ' . $db->quote('sent'))
                ->where(
                    '('
                    . $db->quoteName('status') . ' <> ' . $db->quote('processing')
                    . ' OR ' . $db->quoteName('claimed_at') . ' IS NULL'
                    . ' OR ' . $db->quoteName('claimed_at')
                    . ' < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)'
                    . ')'
                );

            $db->setQuery($query);
            $db->execute();

            return $db->getAffectedRows() === 1;
        } catch (\Throwable $e) {
            $this->log(
                'Meta CAPI: could not claim Purchase event for order '
                . $orderId . ': ' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Mark a Purchase event as successfully sent.
     */
    private function markPurchaseSent(int $orderId): void
    {
        $db = Factory::getContainer()->get(
            \Joomla\Database\DatabaseInterface::class
        );

        $table = $db->quoteName(
            $db->getPrefix() . 'metahikashop_capi_purchase'
        );

        try {
            $query = $db->getQuery(true)
                ->update($table)
                ->set($db->quoteName('status') . ' = ' . $db->quote('sent'))
                ->set($db->quoteName('sent_at') . ' = UTC_TIMESTAMP()')
                ->set($db->quoteName('claimed_at') . ' = NULL')
                ->where($db->quoteName('order_id') . ' = ' . (int) $orderId);

            $db->setQuery($query);
            $db->execute();

            $this->log(
                'Meta CAPI: Purchase recorded as sent for order ' . $orderId
            );
        } catch (\Throwable $e) {
            $this->log(
                'Meta CAPI: failed to record Purchase success for order '
                . $orderId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Release a claim after a failed Meta API request.
     */
    private function releasePurchaseClaim(int $orderId): void
    {
        $db = Factory::getContainer()->get(
            \Joomla\Database\DatabaseInterface::class
        );

        $table = $db->quoteName(
            $db->getPrefix() . 'metahikashop_capi_purchase'
        );

        try {
            $query = $db->getQuery(true)
                ->update($table)
                ->set($db->quoteName('status') . ' = ' . $db->quote('pending'))
                ->set($db->quoteName('claimed_at') . ' = NULL')
                ->where($db->quoteName('order_id') . ' = ' . (int) $orderId)
                ->where($db->quoteName('status') . ' = ' . $db->quote('processing'));

            $db->setQuery($query);
            $db->execute();

            $this->log(
                'Meta CAPI: Purchase claim released for retry, order ' . $orderId
            );
        } catch (\Throwable $e) {
            $this->log(
                'Meta CAPI: failed to release Purchase claim for order '
                . $orderId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Delegate Purchase processing to MetaCapiHelper.
     */
    private function sendPurchaseEvent($orderData): bool
    {
        return $this->capiHelper->sendPurchaseEvent($orderData);
    }

    /**
     * Log plugin messages.
     */
    private function log($message)
    {
        if ($this->debug && function_exists('hikashop_writeToLog')) {
            hikashop_writeToLog($message);
        }

        if (defined('JDEBUG') && JDEBUG) {
            Log::add($message, Log::DEBUG, 'plg_system_metahikashop');
        }
    }
}
