<?php
/**
 * @package     plg_hikaserial_serialbyshipping
 * @version     1.0.0
 * @author      Anirudha Talmale
 * @license     GNU General Public License version 3 or later
 *
 * Gates HikaSerial ticket delivery by the order's shipping method.
 *
 * HikaSerial attaches the serial (e.g. a PDF event ticket) onto the HikaShop
 * order e-mail for every paid order and cannot restrict this per shipping
 * method. This plugin implements the official HikaSerial 6.1.0+ rule hooks
 * (onCustomPdfSerialRule / onCustomAttachSerialRule) to skip attaching the
 * ticket when the order's shipping method is NOT one of the allowed "E-Mails"
 * methods. A secondary onBeforeSerialMailSend gate strips any inline serials
 * from the same e-mail as a safety net.
 *
 * HikaSerial's own generation, the ticket layout, and the default e-mail
 * template all stay untouched — only the "should this order's ticket be
 * e-mailed?" decision is added.
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

class plgHikaserialSerialbyshipping extends CMSPlugin
{
	/** @var bool Load plugin language automatically. */
	protected $autoloadLanguage = true;

	/**
	 * HikaSerial PDF-generator rule. Fired per serial while the PDF ticket is
	 * built for an order e-mail. Set $do = false to skip attaching that ticket.
	 *
	 * @param   object  $serial  The serial being processed (has serial_order_id).
	 * @param   object  $params  Generator params, incl. an `order` object.
	 * @param   bool    $do      By-ref: whether to generate/attach the PDF.
	 * @return  void
	 */
	public function onCustomPdfSerialRule($serial, $params, &$do)
	{
		$this->applyRule($serial, $params, $do, 'pdf');
	}

	/**
	 * HikaSerial image-generator rule (barcode/QR image tickets). Same gate as
	 * the PDF rule, so the plugin also covers shops that deliver image tickets.
	 */
	public function onCustomAttachSerialRule($serial, $params, &$do)
	{
		$this->applyRule($serial, $params, $do, 'image');
	}

	/**
	 * Shared decision: block the ticket when the order's shipping method is not
	 * in the configured allow-list.
	 */
	protected function applyRule($serial, $params, &$do, $context)
	{
		// If something upstream already decided to skip, leave it skipped.
		if ($do === false) {
			return;
		}

		$orderId = $this->resolveOrderId($serial, $params);
		if ($orderId <= 0) {
			// Cannot determine the order — do not interfere.
			$this->log('[' . $context . '] Could not resolve order id from serial/params; leaving ticket as-is.', Log::WARNING);
			return;
		}

		if (!$this->isShippingAllowed($orderId)) {
			$do = false;
			$this->log('[' . $context . '] Order ' . $orderId . ': shipping method not allowed — ticket skipped.');
		} else {
			$this->log('[' . $context . '] Order ' . $orderId . ': shipping method allowed — ticket delivered.');
		}
	}

	/**
	 * Safety net: right before HikaSerial hands the serials to the order e-mail,
	 * strip them entirely for non-allowed shipping methods. Covers inline/text
	 * serials and any generator that reads the serial list late.
	 *
	 * @param   object  $mail     The HikaShop mail object.
	 * @param   object  $mailer   The mailer instance.
	 * @param   array   $serials  By-ref list of serials to be shown/attached.
	 * @param   object  $data     The order data (has order_id).
	 * @return  void
	 */
	public function onBeforeSerialMailSend(&$mail, &$mailer, &$serials, $data)
	{
		$orderId = 0;
		if (!empty($data) && !empty($data->order_id)) {
			$orderId = (int) $data->order_id;
		} elseif (!empty($mail->data->order_id)) {
			$orderId = (int) $mail->data->order_id;
		}
		if ($orderId <= 0) {
			return;
		}

		if (!$this->isShippingAllowed($orderId)) {
			$serials = array();
			if (isset($mail->hikaserial) && isset($mail->hikaserial->serials)) {
				$mail->hikaserial->serials = array();
			}
			$this->log('[mail] Order ' . $orderId . ': shipping method not allowed — serials stripped from e-mail.');
		}
	}

	/**
	 * Resolve the HikaShop order id for a serial being processed.
	 */
	protected function resolveOrderId($serial, $params)
	{
		if (is_object($serial)) {
			if (!empty($serial->serial_order_id)) {
				return (int) $serial->serial_order_id;
			}
			if (!empty($serial->order_id)) {
				return (int) $serial->order_id;
			}
		}
		if (is_object($params) && !empty($params->order)) {
			$order = $params->order;
			if (!empty($order->order_id)) {
				return (int) $order->order_id;
			}
			if (!empty($order->order_parent_id)) {
				return (int) $order->order_parent_id;
			}
		}
		return 0;
	}

	/**
	 * Is the given order's shipping method one of the allowed "E-Mails" methods?
	 * Result is cached per request. When no shipping method is configured on the
	 * plugin, nothing is blocked.
	 */
	protected function isShippingAllowed($orderId)
	{
		static $cache = array();
		$orderId = (int) $orderId;
		if (isset($cache[$orderId])) {
			return $cache[$orderId];
		}

		$configured = $this->getAllowedShippingIds();
		if (empty($configured)) {
			// Misconfiguration guard: an empty allow-list means "don't gate".
			$this->log('No shipping method configured in plugin settings; not gating.', Log::WARNING);
			return $cache[$orderId] = true;
		}

		$orderShipping = $this->getOrderShippingIds($orderId);

		if (empty($orderShipping)) {
			// Order has no shipping method — behaviour is configurable.
			$allowed = ($this->params->get('no_shipping_behaviour', 'block') === 'send');
			$this->log('Order ' . $orderId . ' has no shipping method; ' . ($allowed ? 'sending' : 'blocking') . ' per settings.');
			return $cache[$orderId] = $allowed;
		}

		$allowed = count(array_intersect($configured, $orderShipping)) > 0;
		return $cache[$orderId] = $allowed;
	}

	/**
	 * The plugin's configured allow-list of shipping ids.
	 */
	protected function getAllowedShippingIds()
	{
		$raw = $this->params->get('shipping_ids', array());
		if (!is_array($raw)) {
			$raw = ($raw === '' || $raw === null) ? array() : explode(',', (string) $raw);
		}
		$out = array();
		foreach ($raw as $v) {
			$v = (int) $v;
			if ($v > 0) {
				$out[] = $v;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Read the shipping method id(s) stored on a HikaShop order.
	 */
	protected function getOrderShippingIds($orderId)
	{
		$db = Factory::getContainer()->get('DatabaseDriver');
		try {
			$query = $db->getQuery(true)
				->select($db->quoteName('order_shipping_id'))
				->from($db->quoteName('#__hikashop_order'))
				->where($db->quoteName('order_id') . ' = ' . (int) $orderId);
			$db->setQuery($query);
			$raw = (string) $db->loadResult();
		} catch (\Throwable $e) {
			$this->log('DB error reading shipping for order ' . (int) $orderId . ': ' . $e->getMessage(), Log::ERROR);
			return array();
		}

		$ids = array();
		foreach (explode(',', $raw) as $sid) {
			$sid = (int) trim($sid);
			if ($sid > 0) {
				$ids[] = $sid;
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Lightweight logger, gated by the debug_log param (warnings/errors always
	 * logged).
	 */
	protected function log($message, $priority = Log::INFO)
	{
		if ($priority === Log::INFO && (int) $this->params->get('debug_log', 0) !== 1) {
			return;
		}
		try {
			Log::addLogger(
				array('text_file' => 'plg_hikaserial_serialbyshipping.php'),
				Log::ALL,
				array('plg_hikaserial_serialbyshipping')
			);
			Log::add($message, $priority, 'plg_hikaserial_serialbyshipping');
		} catch (\Throwable $e) {
			// Never let logging interfere with order/e-mail processing.
		}
	}
}
