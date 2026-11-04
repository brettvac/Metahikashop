<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.0
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Naftee\Plugin\System\Metahikashop\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Naftee\Plugin\System\Metahikashop\Helper\PixelEvents;

/**
 * System plugin: HikaShop Meta Conversions API
 *
 * Load HikaShop's compatibility class when available.
 * Fall back to Joomla's standard CMSPlugin otherwise.
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
 *
 * @since 1.0
 */
class Metahikashop extends plgSystemMetahikashopBridge
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  1.0
	 */
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

	/** @var bool Only send CAPI Purchase when order is confirmed */
	private $only_confirmed;

	/* JS Pixel toggles */
	private $js_pageview;
	private $js_viewcontent;
	private $js_initiatecheckout;
	private $js_purchase;

	/*
	 * Server-side CAPI toggles
	 * TODO: implement server-side PageView / ViewContent / InitiateCheckout
	 * private $capi_pageview;
	 * private $capi_viewcontent;
	 * private $capi_initiatecheckout;
	 */
	private $capi_purchase;


	/**
	 * Load all plugin parameters
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

		/*
		 * TODO: implement server-side PageView / ViewContent / InitiateCheckout
		 * $this->capi_pageview         = (bool) $this->params->get('capi_pageview', 0);
		 * $this->capi_viewcontent      = (bool) $this->params->get('capi_viewcontent', 0);
		 * $this->capi_initiatecheckout = (bool) $this->params->get('capi_initiatecheckout', 0);
		 */
		$this->capi_purchase = (bool) $this->params->get('capi_purchase', 1);
	}

	/**
	 * Inject Meta Pixel base code + event tracking JS when enabled
	 */
	public function onBeforeCompileHead()
	{
		if ($this->getApplication()->isClient('administrator')) {
			return;
		}

		$this->init();

		if ($this->dataset_id === '') {
			return true;
		}

		$anyJsEnabled = $this->js_pageview
			|| $this->js_viewcontent
			|| $this->js_initiatecheckout
			|| $this->js_purchase;

		if (!$anyJsEnabled) {
			return true;
		}

		if (!defined('DS')) {
			define('DS', DIRECTORY_SEPARATOR);
		}

		if (!include_once(rtrim(JPATH_ADMINISTRATOR, DS) . DS . 'components' . DS . 'com_hikashop' . DS . 'helpers' . DS . 'helper.php')) {
			return true;
		}

		$wa = $this->getApplication()->getDocument()->getWebAssetManager();

		/* Base Pixel code */
		$baseJs = "
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', " . json_encode((string) $this->dataset_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ");
";

		if ($this->js_pageview) {
			$baseJs .= "fbq('track', 'PageView');\n";
		}

		$wa->addInlineScript($baseJs);

		/* Capture fbp / fbc for later server-side use */
		$wa->addInlineScript("
window.hikashop = window.hikashop || {};
window.hikashop.ready(function(){
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
});
");

		$eventJs = '';

		if ($this->js_viewcontent) {
			$eventJs .= PixelEvents::getViewContentJS();
		}

		if ($this->js_initiatecheckout) {
			$eventJs .= PixelEvents::getInitiateCheckoutJS();
		}

		if ($this->js_purchase) {
			$eventJs .= PixelEvents::getPurchaseJS();
		}

		if ($eventJs !== '') {
			$wa->addInlineScript($eventJs);
		}

		return true;
	}

	/**
	 * Capture fbp / fbc cookies early
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
	 * Store client identifiers on the order at creation time
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
			$order->order_payment_params = hikashop_unserialize($order->order_payment_params);
		}

		if (empty($order->order_payment_params)) {
			$order->order_payment_params = new \stdClass();
		}

		if (!empty($_SESSION['hikashop_meta_fbp'])) {
			$order->order_payment_params->meta_fbp = trim($_SESSION['hikashop_meta_fbp']);
		}

		if (!empty($_SESSION['hikashop_meta_fbc'])) {
			$order->order_payment_params->meta_fbc = trim($_SESSION['hikashop_meta_fbc']);
		}

		$order->order_payment_params->meta_client_ip  = $app->input->server->getString('REMOTE_ADDR', '');
		$order->order_payment_params->meta_user_agent = $app->input->server->getString('HTTP_USER_AGENT', '');
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
	 * - only_confirmed = true: send only when the order transitions into an exportable status.
	 * - only_confirmed = false: allow sending on any eligible hook, provided the Purchase event has not already been sent or claimed.
	 *
	 * A dedicated plugin-owned database table tracks the Purchase event status and prevents concurrent requests from normally sending the
	 * same event more than once. Failed sends can be retried.
	 *
	 * @param   object  $order  HikaShop order being created or updated.
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

		$isExportable     = in_array($order->order_status, $invoice_statuses, true);
		$oldStatus        = isset($order->old->order_status) ? $order->old->order_status : null;
		$wasNotExportable = $new || ($oldStatus === null) || !in_array($oldStatus, $invoice_statuses, true);

		/* Purchase is only meaningful once the order is in an exportable status. */
		if (!$isExportable) {
			return;
		}

		/* When only_confirmed is enabled, require the transition into that status. */
		if ($this->only_confirmed && !$wasNotExportable) {
			return;
		}

		if ($this->dataset_id === '' || $this->access_token === '') {
			$this->log('Meta CAPI: missing dataset_id or access_token');
			return;
		}

		$orderClass = hikashop_get('class.order');
		$orderData  = $orderClass->loadFullOrder($order->order_id, false, false);

		if (empty($orderData) || empty($orderData->products)) {
			return;
		}

		/*
		 * Atomically claim this order before sending. This prevents concurrent
		 * requests from normally sending the same Purchase event simultaneously.
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
			/* Allow a later eligible hook to retry after a failed send. */
			$this->releasePurchaseClaim($orderId);
		}

		return true;
	}

	/**
	 * Atomically claim an order for Purchase event processing.
	 *
	 * Returns true only when this request successfully acquires the claim.
	 * A sent order cannot be claimed again. A stale processing claim can
	 * be reclaimed after ten minutes.
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
			/*
			 * Ensure a tracking row exists. INSERT IGNORE handles concurrent
			 * attempts to create the same order's row.
			 */
			$db->setQuery(
				'INSERT IGNORE INTO ' . $table . ' '
				. '(' . $db->quoteName('order_id') . ', '
				. $db->quoteName('status') . ') VALUES ('
				. (int) $orderId . ', ' . $db->quote('pending') . ')'
			);

			$db->execute();

			/*
			 * Claim only pending orders, or processing orders whose claim
			 * is stale. The UPDATE is atomic, so only one concurrent request
			 * can acquire a given claim.
			 */
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

			/* Fail closed: do not send if idempotency cannot be checked. */
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
	 * Release a claim after a failed Meta API request so it can be retried.
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
	 * Build and send the CAPI Purchase event.
	 *
	 * @return  bool  True when Meta accepted the event (HTTP 2xx)
	 */
	private function sendPurchaseEvent($orderData)
	{
		$config         = hikashop_config();
		$price_with_tax = $config->get('price_with_tax');
		$group          = $config->get('group_options', 0);

		$currencyClass = hikashop_get('class.currency');
		$currencies    = null;
		$currencies    = $currencyClass->getCurrencies($orderData->order_currency_id, $currencies);

		if (empty($currencies[$orderData->order_currency_id]->currency_code)) {
			$this->log('Meta CAPI: missing currency for order ' . $orderData->order_id);
			return false;
		}

		$currency_code = strtoupper($currencies[$orderData->order_currency_id]->currency_code);

		$value = (float) $orderData->order_full_price;

		if (!$price_with_tax && !empty($orderData->order_tax_info)) {
			if (is_string($orderData->order_tax_info)) {
				$orderData->order_tax_info = hikashop_unserialize($orderData->order_tax_info);
			}

			$taxes = 0.0;

			if (is_array($orderData->order_tax_info) || is_object($orderData->order_tax_info)) {
				foreach ($orderData->order_tax_info as $tax) {
					if (is_object($tax) && isset($tax->tax_amount)) {
						$taxes += (float) $tax->tax_amount;
					}
				}
			}

			$value -= $taxes;
		}

		$value = round($value, 2);

		$contents    = [];
		$content_ids = [];
		$num_items   = 0;

		foreach ($orderData->products as $product) {
			if (empty($product->order_product_quantity)) {
				continue;
			}

			if ($group && !empty($product->order_product_option_parent_id)) {
				continue;
			}

			$item_id = (string) $product->order_product_code;
			$qty     = (int) $product->order_product_quantity;
			$price   = (float) $product->order_product_price;

			if ($price_with_tax) {
				$price += (float) $product->order_product_tax;
			}

			$price = round($price, 2);

			$contents[] = [
				'id'         => $item_id,
				'quantity'   => $qty,
				'item_price' => $price,
			];

			$content_ids[] = $item_id;
			$num_items    += $qty;
		}

		if (empty($contents)) {
			return false;
		}

		/*
		 * Prefer the moment the order became eligible (now), not the original creation
		 * time, so delayed payment confirmations are timestamped correctly.
		 * Fall back to order_created only when it is recent and we have no better signal.
		 */
		$event_time = time();

		if (!empty($orderData->order_invoice_created) && (int) $orderData->order_invoice_created > 0) {
			$event_time = (int) $orderData->order_invoice_created;
		} elseif (!empty($orderData->order_modified) && (int) $orderData->order_modified > 0) {
			$event_time = (int) $orderData->order_modified;
		}

		/* Meta rejects event_time older than 7 days. */
		$sevenDaysAgo = time() - (7 * 24 * 3600);

		if ($event_time < $sevenDaysAgo) {
			$event_time = time();
		}

		$event_id = 'hk_order_' . $orderData->order_id . '_' . $orderData->order_number;

		$user_data = $this->buildUserData($orderData);

		$custom_data = [
			'currency'     => $currency_code,
			'value'        => $value,
			'content_ids'  => $content_ids,
			'contents'     => $contents,
			'content_type' => 'product',
			'num_items'    => $num_items,
			'order_id'     => (string) $orderData->order_number,
		];

		if (!empty($orderData->order_discount_code)) {
			$custom_data['coupon'] = $orderData->order_discount_code;
		}

		$event = [
			'event_name'    => 'Purchase',
			'event_time'    => $event_time,
			'event_id'      => $event_id,
			'action_source' => 'website',
			'user_data'     => $user_data,
			'custom_data'   => $custom_data,
		];

		if (!empty($_SERVER['HTTP_REFERER'])) {
			$event['event_source_url'] = $_SERVER['HTTP_REFERER'];
		} elseif (!empty($_SERVER['REQUEST_URI'])) {
			$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
			$host   = $_SERVER['HTTP_HOST'] ?? '';
			$event['event_source_url'] = $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
		}

		$payload = ['data' => [$event]];

		if ($this->test_event_code !== '') {
			$payload['test_event_code'] = $this->test_event_code;
		}

		return $this->sendToMeta($payload, $orderData->order_id);
	}

	/**
	 * Build hashed user_data
	 */
	private function buildUserData($orderData)
	{
		$user_data = [];

		$email = '';

		if (!empty($orderData->customer->user_email)) {
			$email = $orderData->customer->user_email;
		} elseif (!empty($orderData->billing_address->address_email)) {
			$email = $orderData->billing_address->address_email;
		}

		if ($email !== '') {
			$user_data['em'] = [$this->hash($email)];
		}

		$phone = '';

		if (!empty($orderData->billing_address->address_telephone)) {
			$phone = $orderData->billing_address->address_telephone;
		} elseif (!empty($orderData->shipping_address->address_telephone)) {
			$phone = $orderData->shipping_address->address_telephone;
		}

		if ($phone !== '') {
			$phone = preg_replace('/\D+/', '', $phone);

			if ($phone !== '') {
				$user_data['ph'] = [$this->hash($phone)];
			}
		}

		$fn = !empty($orderData->billing_address->address_firstname) ? $orderData->billing_address->address_firstname : '';
		$ln = !empty($orderData->billing_address->address_lastname)  ? $orderData->billing_address->address_lastname  : '';

		if ($fn !== '') {
			$user_data['fn'] = [$this->hash($fn)];
		}

		if ($ln !== '') {
			$user_data['ln'] = [$this->hash($ln)];
		}

		if (!empty($orderData->billing_address->address_city)) {
			$user_data['ct'] = [$this->hash($orderData->billing_address->address_city)];
		}

		if (!empty($orderData->billing_address->address_state)) {
			$user_data['st'] = [$this->hash($orderData->billing_address->address_state)];
		}

		if (!empty($orderData->billing_address->address_post_code)) {
			$user_data['zp'] = [$this->hash($orderData->billing_address->address_post_code)];
		}

		if (!empty($orderData->billing_address->address_country)) {
			$user_data['country'] = [$this->hash(strtolower($orderData->billing_address->address_country))];
		}

		if (!empty($orderData->order_user_id)) {
			$user_data['external_id'] = [$this->hash((string) $orderData->order_user_id)];
		}

		$order_params = null;

		if (!empty($orderData->order_payment_params)) {
			$order_params = is_string($orderData->order_payment_params)
				? hikashop_unserialize($orderData->order_payment_params)
				: $orderData->order_payment_params;
		}

		$ip = !empty($order_params->meta_client_ip) ? $order_params->meta_client_ip : ($_SERVER['REMOTE_ADDR'] ?? '');
		$ua = !empty($order_params->meta_user_agent) ? $order_params->meta_user_agent : ($_SERVER['HTTP_USER_AGENT'] ?? '');

		if ($ip !== '') {
			$user_data['client_ip_address'] = $ip;
		}

		if ($ua !== '') {
			$user_data['client_user_agent'] = $ua;
		}

		if (!empty($order_params->meta_fbp)) {
			$user_data['fbp'] = $order_params->meta_fbp;
		} elseif (!empty($_SESSION['hikashop_meta_fbp'])) {
			$user_data['fbp'] = $_SESSION['hikashop_meta_fbp'];
		}

		if (!empty($order_params->meta_fbc)) {
			$user_data['fbc'] = $order_params->meta_fbc;
		} elseif (!empty($_SESSION['hikashop_meta_fbc'])) {
			$user_data['fbc'] = $_SESSION['hikashop_meta_fbc'];
		}

		return $user_data;
	}

	private function hash($value)
	{
		return hash('sha256', strtolower(trim((string) $value)));
	}

	/**
	 * Send payload via Joomla HttpFactory
	 *
	 * @return  bool  True on HTTP 2xx
	 */
	private function sendToMeta(array $payload, $order_id = null)
	{
		$url = 'https://graph.facebook.com/' . $this->api_version . '/' . rawurlencode($this->dataset_id) . '/events';

		$body = ['data' => $payload['data']];

		if (!empty($payload['test_event_code'])) {
			$body['test_event_code'] = $payload['test_event_code'];
		}

		$this->log('Meta CAPI payload for order ' . $order_id . ': ' . json_encode($body));

		try {
			$http = HttpFactory::getHttp();

			$response = $http->post(
				$url,
				json_encode($body),
				[
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $this->access_token,
				],
				15
			);

			if ($response->code < 200 || $response->code >= 300) {
				$this->log('Meta CAPI error: HTTP ' . $response->code . ' – ' . $response->body);
				return false;
			}

			$this->log('Meta CAPI success for order ' . $order_id . ': ' . $response->body);

			return true;
		} catch (\Throwable $e) {
			$this->log('Meta CAPI exception: ' . $e->getMessage());

			return false;
		}
	}

	private function log($message)
	{
		if ($this->debug) {
			hikashop_writeToLog($message);
		}

		if (defined('JDEBUG') && JDEBUG) {
			Log::add($message, Log::DEBUG, 'plg_system_metahikashop');
		}
	}
}
