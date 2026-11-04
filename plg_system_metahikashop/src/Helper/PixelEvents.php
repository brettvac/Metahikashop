<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.0
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Naftee\Plugin\System\Metahikashop\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Builds Meta Pixel JavaScript event snippets for HikaShop pages.
 *
 * @since  1.0
 */
class PixelEvents
{
	/**
	 * Build the Meta Pixel ViewContent JavaScript snippet for a HikaShop product page.
	 *
	 * Returns an empty string when the current request is not a product detail view
	 * or when the product cannot be resolved.
	 *
	 * @return  string  Inline JavaScript for fbq('track', 'ViewContent', …), or empty.
	 *
	 * @since   1.0
	 */
	public static function getViewContentJS(): string
	{
		$option = \hikaInput::get()->getString('option');
		$ctrl   = \hikaInput::get()->getString('ctrl');
		$task   = \hikaInput::get()->getString('task');

		if (!in_array($option, ['com_hikashop', '']) || $ctrl !== 'product' || $task === 'listing') {
			return '';
		}

		$product = self::getProductInfo();
		if (!$product) {
			return '';
		}

		$config         = hikashop_config();
		$price_with_tax = $config->get('price_with_tax');
		$price_key      = $price_with_tax ? 'price_value_with_tax' : 'price_value';

		$value = 0;
		if (!empty($product->prices[0]->$price_key)) {
			$value = round($product->prices[0]->$price_key, 2);
		}

		$content_id   = json_encode($product->product_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$content_name = json_encode(strip_tags($product->product_name), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$currency     = json_encode($product->currency_code ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return "
fbq('track', 'ViewContent', {
	content_ids: [{$content_id}],
	content_name: {$content_name},
	content_type: 'product',
	value: {$value},
	currency: {$currency}
});
";
	}

	/**
	 * Build the Meta Pixel InitiateCheckout JavaScript snippet for the HikaShop checkout.
	 *
	 * Returns an empty string when the current request is not the start of checkout
	 * or when the cart has no products.
	 *
	 * @return  string  Inline JavaScript for fbq('track', 'InitiateCheckout', …), or empty.
	 *
	 * @since   1.0
	 */
	public static function getInitiateCheckoutJS(): string
	{
		$option = \hikaInput::get()->getString('option');
		$ctrl   = \hikaInput::get()->getString('ctrl');
		$task   = \hikaInput::get()->getString('task');
		$cid    = \hikaInput::get()->getInt('cid');

		if (!in_array($option, ['com_hikashop', '']) || $ctrl !== 'checkout' || !in_array($task, ['step', 'show']) || !empty($cid)) {
			return '';
		}

		$cartClass = hikashop_get('class.cart');
		$cart      = $cartClass->getFullCart();

		if (empty($cart) || empty($cart->products)) {
			return '';
		}

		$data = self::getCartDataForJS($cart);
		if (!$data) {
			return '';
		}

		return "
fbq('track', 'InitiateCheckout', {
	content_ids: {$data['content_ids']},
	contents: {$data['contents']},
	content_type: 'product',
	value: {$data['value']},
	currency: {$data['currency']},
	num_items: {$data['num_items']}
});
";
	}

	/**
	 * Build the Meta Pixel Purchase JavaScript snippet for the order confirmation page.
	 *
	 * Fires only when the order is in an invoice/exportable status (same rule as CAPI).
	 * Uses a stable eventID so Meta can deduplicate against the server-side Purchase event.
	 *
	 * @return  string  Inline JavaScript for fbq('track', 'Purchase', …), or empty.
	 *
	 * @since   1.0
	 */
	public static function getPurchaseJS(): string
	{
		$option = \hikaInput::get()->getString('option');
		$ctrl   = \hikaInput::get()->getString('ctrl');
		$task   = \hikaInput::get()->getString('task');

		if (!in_array($option, ['com_hikashop', '']) || $ctrl !== 'checkout' || !in_array($task, ['confirm', 'after_end'])) {
			return '';
		}

		$order_id = \hikaInput::get()->getInt('order_id');
		if (empty($order_id)) {
			$app = Factory::getApplication();
			$order_id = $app->getUserState('com_hikashop.order_id');
		}
		if (empty($order_id)) {
			return '';
		}

		$orderClass = hikashop_get('class.order');
		$order      = $orderClass->loadFullOrder($order_id, false, true);
		if (!$order || empty($order->products)) {
			return '';
		}

		$config = hikashop_config();

		/* Same eligibility rules as server-side CAPI: only fire Purchase when the
		 * order is in an invoice/exportable status. Applies to both confirm and after_end.
		 */
		$invoice_statuses = array_filter(array_map('trim', explode(',', $config->get('invoice_order_statuses', 'confirmed,shipped'))));
		if (!in_array($order->order_status, $invoice_statuses, true)) {
			return '';
		}

		$price_with_tax = $config->get('price_with_tax');
		$group          = $config->get('group_options', 0);

		$currencyClass = hikashop_get('class.currency');
		$currencies    = null;
		$currencies    = $currencyClass->getCurrencies($order->order_currency_id, $currencies);
		if (empty($currencies[$order->order_currency_id]->currency_code)) {
			return '';
		}
		$currency_code = $currencies[$order->order_currency_id]->currency_code;

		$content_ids = [];
		$contents    = [];
		$num_items   = 0;

		foreach ($order->products as $product) {
			if (empty($product->order_product_quantity)) {
				continue;
			}
			if ($group && !empty($product->order_product_option_parent_id)) {
				continue;
			}

			$id  = json_encode((string) $product->order_product_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			$qty = (int) $product->order_product_quantity;
			$price = (float) $product->order_product_price;
			if ($price_with_tax) {
				$price += (float) $product->order_product_tax;
			}
			$price = round($price, 2);

			$content_ids[] = $id;
			$contents[]    = "{id: {$id}, quantity: {$qty}, item_price: {$price}}";
			$num_items    += $qty;
		}

		if (empty($content_ids)) {
			return '';
		}

		/* Keep JS value calculation aligned with server-side CAPI Purchase. */
		$value = (float) $order->order_full_price;
		if (!$price_with_tax && !empty($order->order_tax_info)) {
			if (is_string($order->order_tax_info)) {
				$order->order_tax_info = hikashop_unserialize($order->order_tax_info);
			}
			$taxes = 0.0;
			if (is_array($order->order_tax_info) || is_object($order->order_tax_info)) {
				foreach ($order->order_tax_info as $tax) {
					if (is_object($tax) && isset($tax->tax_amount)) {
						$taxes += (float) $tax->tax_amount;
					}
				}
			}
			$value -= $taxes;
		}
		$value = round($value, 2);

		$event_id = json_encode('hk_order_' . $order->order_id . '_' . $order->order_number, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		return "
fbq('track', 'Purchase', {
	content_ids: [" . implode(',', $content_ids) . "],
	contents: [" . implode(',', $contents) . "],
	content_type: 'product',
	value: {$value},
	currency: " . json_encode($currency_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ",
	num_items: {$num_items},
	order_id: " . json_encode((string) $order->order_number, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "
}, {eventID: {$event_id}});
";
	}

	/**
	 * Load product data (including prices and currency) for the current product page.
	 *
	 * Resolves the product ID from the request, expands variants to their parent,
	 * and attaches currency-aware price data used by ViewContent.
	 *
	 * @return  object|false  Product object with prices and currency_code on success;
	 *                        false when not on a product page or the product cannot be loaded.
	 *
	 * @since   1.0
	 */
	public static function getProductInfo()
	{
		static $product = null;
		if ($product !== null) {
			return $product;
		}

		$product_id = (int) hikashop_getCID('product_id');
		$product = false;

		if (!empty($product_id)) {
			$productClass = hikashop_get('class.product');
			$product = $productClass->get($product_id);
			if (!empty($product)) {
				if ($product->product_type === 'variant') {
					$product = $productClass->get($product->product_parent_id);
				}
				$ids = [$product->product_id];
				$currencyClass = hikashop_get('class.currency');
				$currencyClass->getProductPrices($product, $ids, []);
				$currencies = null;
				$currency_id = hikashop_getCurrency();
				$currencies = $currencyClass->getCurrencies($currency_id, $currencies);
				$product->currency_code = $currencies[$currency_id]->currency_code;
			}
		}

		return $product;
	}

	/**
	 * Build cart payload fragments used by InitiateCheckout (and similar) Pixel events.
	 *
	 * Produces JSON-safe content_ids / contents strings plus numeric value and item count
	 * from a full HikaShop cart, honouring price_with_tax and group_options settings.
	 *
	 * @param   object  $cart  Full cart object from hikashop class.cart->getFullCart().
	 *
	 * @return  array|false  Associative array with keys content_ids, contents, value,
	 *                       currency, num_items; or false when the cart has no sellable lines.
	 *
	 * @since   1.0
	 */
	public static function getCartDataForJS($cart)
	{
		$config         = hikashop_config();
		$price_with_tax = $config->get('price_with_tax');
		$price_key      = $price_with_tax ? 'price_value_with_tax' : 'price_value';
		$group          = $config->get('group_options', 0);

		$currencyClass = hikashop_get('class.currency');
		$currencies    = null;
		$currency_id   = hikashop_getCurrency();
		$currencies    = $currencyClass->getCurrencies($currency_id, $currencies);
		$currency_code = $currencies[$currency_id]->currency_code;

		$content_ids = [];
		$contents    = [];
		$num_items   = 0;

		foreach ($cart->products as $k => $product) {
			if (empty($product->cart_product_quantity) || substr($k, 0, 1) === 'p') {
				continue;
			}
			if ($group && !empty($product->cart_product_option_parent_id)) {
				continue;
			}

			$id  = json_encode($product->product_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			$qty = (int) $product->cart_product_quantity;
			$price = 0;
			if (!empty($product->prices[0]->$price_key)) {
				$price = round($product->prices[0]->$price_key, 2);
			}

			$content_ids[] = $id;
			$contents[]    = "{id: {$id}, quantity: {$qty}, item_price: {$price}}";
			$num_items    += $qty;
		}

		if (empty($content_ids)) {
			return false;
		}

		$total = 0;
		if (!empty($cart->total->prices[0]->$price_key)) {
			$total = round($cart->total->prices[0]->$price_key, 2);
		}

		return [
			'content_ids' => '[' . implode(',', $content_ids) . ']',
			'contents'    => '[' . implode(',', $contents) . ']',
			'value'       => $total,
			'currency'    => json_encode($currency_code, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
			'num_items'   => $num_items,
		];
	}
}
