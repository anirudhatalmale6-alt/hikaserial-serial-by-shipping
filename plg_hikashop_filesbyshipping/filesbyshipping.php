<?php
/**
 * @package     plg_hikashop_filesbyshipping
 * @version     1.0.0
 * @author      Anirudha Talmale
 * @license     GNU General Public License version 3 or later
 *
 * Companion to the "Serials by Shipping" HikaSerial plugin.
 *
 * HikaShop shows a product download button (the "Deine Reservierung" link) in the
 * order e-mail, the front-end order page and the back-end order view whenever the
 * ordered product has a downloadable file attached — regardless of the shipping
 * method. For an event-ticket shop that is wrong: only orders that used one of the
 * "E-Mails" shipping methods should offer the ticket download; a normal order sent
 * by post must not show it.
 *
 * HikaShop attaches those files onto every order product in
 * class.order::getOrderAdditionalInfo() and then fires onAfterLoadFullOrder — the
 * single full-order loader used by the e-mail, the front-end and the back-end.
 * This plugin listens to that one event and, for orders whose shipping method is
 * NOT in the allowed list, removes the attached download files, so the download
 * buttons disappear from all three places at once. Qualifying orders are left
 * untouched, so their ticket download still works exactly as before.
 *
 * The allowed shipping list is shared with the HikaSerial "Serials by Shipping"
 * plugin so the merchant only maintains one list (an optional override is
 * available on this plugin if needed).
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

class plgHikashopFilesbyshipping extends CMSPlugin
{
	/** @var bool Load the plugin's language automatically. */
	protected $autoloadLanguage = true;

	/**
	 * Fired by HikaShop after a full order has been loaded (class.order line
	 * ~1959), after getOrderAdditionalInfo() has attached the product download
	 * files. For non-qualifying orders we strip those files so the download
	 * buttons are not rendered anywhere (e-mail / front-end / back-end).
	 *
	 * @param   object  $order  By-ref fully-loaded order.
	 * @return  void
	 */
	public function onAfterLoadFullOrder(&$order)
	{
		if (empty($order) || empty($order->products) || !is_array($order->products)) {
			return;
		}

		if ($this->isShippingAllowed($order)) {
			return; // qualifying order — keep the ticket download button
		}

		$removed = 0;
		foreach ($order->products as $k => $product) {
			if (!empty($product->files) && is_array($product->files)) {
				$removed += count($product->files);
				$order->products[$k]->files = array();
			}
			// Bundled products can carry their own attached files too.
			if (!empty($product->bundle) && is_array($product->bundle)) {
				foreach ($product->bundle as $b => $child) {
					if (!empty($child->files) && is_array($child->files)) {
						$removed += count($child->files);
						$order->products[$k]->bundle[$b]->files = array();
					}
				}
			}
		}
		// Some HikaShop layouts also look at an order-level file list.
		if (!empty($order->files) && is_array($order->files)) {
			$order->files = array();
		}

		$this->log('Order ' . (int) (isset($order->order_id) ? $order->order_id : 0)
			. ': shipping method not allowed — ' . $removed . ' download button(s) hidden.');
	}

	/**
	 * Is this order's shipping method one of the allowed "E-Mails" methods?
	 */
	protected function isShippingAllowed($order)
	{
		$configured = $this->getAllowedShippingIds();
		if (empty($configured)) {
			// Nothing configured anywhere: do not hide anything.
			return true;
		}

		$orderShipping = $this->getOrderShippingIds($order);
		if (empty($orderShipping)) {
			// Order without a shipping method — behaviour is configurable.
			return ($this->params->get('no_shipping_behaviour', 'block') === 'send');
		}

		return count(array_intersect($configured, $orderShipping)) > 0;
	}

	/**
	 * The allowed shipping ids. An explicit override on this plugin wins;
	 * otherwise the list is inherited from the HikaSerial "Serials by Shipping"
	 * plugin so both plugins always agree.
	 */
	protected function getAllowedShippingIds()
	{
		$own = $this->parseShippingIds($this->params->get('shipping_ids', ''));
		if (!empty($own)) {
			return $own;
		}
		return $this->readCompanionShippingIds();
	}

	/**
	 * Read the shipping list configured on the HikaSerial serialbyshipping plugin.
	 */
	protected function readCompanionShippingIds()
	{
		try {
			$plugin = PluginHelper::getPlugin('hikaserial', 'serialbyshipping');
			if (empty($plugin) || empty($plugin->params)) {
				return array();
			}
			$reg = new Registry($plugin->params);
			return $this->parseShippingIds($reg->get('shipping_ids', ''));
		} catch (\Throwable $e) {
			return array();
		}
	}

	/**
	 * Read the shipping method id(s) from an already-loaded order object.
	 */
	protected function getOrderShippingIds($order)
	{
		$raw = isset($order->order_shipping_id) ? $order->order_shipping_id : '';
		return $this->parseShippingIds($raw);
	}

	/**
	 * Parse a HikaShop shipping id value into a list of positive ints. Handles a
	 * single id, a comma/semicolon list, arrays and the "id@warehouse" /
	 * "id-suboption" forms HikaShop uses for multi-shipping orders.
	 */
	protected function parseShippingIds($value)
	{
		if (is_array($value)) {
			$parts = $value;
		} else {
			$value = str_replace(';', ',', (string) $value);
			$parts = ($value === '') ? array() : explode(',', $value);
		}

		$ids = array();
		foreach ($parts as $part) {
			$part = trim((string) $part);
			if ($part === '') {
				continue;
			}
			if (strpos($part, '@') !== false) {
				$tmp  = explode('@', $part);
				$part = $tmp[0];
			}
			if (strpos($part, '-') !== false) {
				$tmp  = explode('-', $part, 2);
				$part = $tmp[0];
			}
			$id = (int) $part;
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Lightweight logger, gated by the debug_log param.
	 */
	protected function log($message, $priority = Log::INFO)
	{
		if ($priority === Log::INFO && (int) $this->params->get('debug_log', 0) !== 1) {
			return;
		}
		try {
			Log::addLogger(
				array('text_file' => 'plg_hikashop_filesbyshipping.php'),
				Log::ALL,
				array('plg_hikashop_filesbyshipping')
			);
			Log::add($message, $priority, 'plg_hikashop_filesbyshipping');
		} catch (\Throwable $e) {
			// Never let logging interfere with order/e-mail processing.
		}
	}
}
