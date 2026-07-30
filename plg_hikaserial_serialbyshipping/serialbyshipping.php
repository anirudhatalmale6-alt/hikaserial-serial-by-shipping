<?php
/**
 * @package     plg_hikaserial_serialbyshipping
 * @version     1.3.0
 * @author      Anirudha Talmale
 * @license     GNU General Public License version 3 or later
 *
 * Gates HikaSerial ticket generation AND delivery by the order's shipping method.
 *
 * HikaSerial assigns a serial to every paid order and shows it in the customer's
 * front-end / order e-mail, and cannot restrict this per shipping method. For an
 * event-ticket shop that is wrong: a "normal" order shipped by post must not get
 * a serial at all (otherwise the customer sees a ticket code they shouldn't).
 *
 * This plugin adds a shipping-method gate at two levels:
 *   1. Generation — onSerialOrderPreUpdate: for orders whose shipping method is
 *      NOT in the allowed "E-Mails" list, the serial is never assigned, so it
 *      never appears in the front-end or the order e-mail.
 *   2. Delivery (defence-in-depth) — the official onCustomPdfSerialRule /
 *      onCustomAttachSerialRule hooks skip attaching the ticket, and
 *      onBeforeSerialMailSend strips any inline serials, in case a serial exists
 *      for any other reason.
 *
 * HikaSerial's generation logic, the ticket layout, and the default e-mail
 * template all stay untouched — only the "does this order qualify?" decision is
 * added.
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

// This is a native HikaSerial plugin (like pdfserial / serialperorder), so it
// extends hikaserialPlugin — that way HikaSerial's own plugin manager recognises
// it instead of showing "the plugin is not an HikaSerial one". In normal use
// HikaSerial has already bootstrapped its autoloader before it dispatches to this
// plugin; the include below is only a safety net for the rare load order.
if (!class_exists('hikaserialPlugin')) {
	$hikaserialHelper = rtrim(JPATH_ADMINISTRATOR, '/\\') . '/components/com_hikaserial/helpers/helper.php';
	if (is_file($hikaserialHelper)) {
		include_once $hikaserialHelper;
	}
}

class plgHikaserialSerialbyshipping extends hikaserialPlugin
{
	/** @var string HikaSerial plugin type. 'plugin' = generic event plugin, so
	 *   it does NOT appear as a generator/consumer/subscriber option. */
	protected $type = 'plugin';

	/** @var bool Only one instance of this plugin is needed. */
	protected $multiple = false;

	/** @var string Documentation form key (HikaSerial convention). */
	protected $doc_form = 'serialbyshipping-';

	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		// Load this plugin's own language strings (admin labels).
		$this->loadLanguage();
	}

	/**
	 * GENERATION GATE. Fired by HikaSerial in class.order::preUpdate(), right
	 * before it decides whether to assign serials for the order (postUpdate).
	 *
	 * For orders whose shipping method is NOT in the allowed list, we neutralise
	 * the status transition HikaSerial checks (by making the "old" status equal
	 * the current one). HikaSerial then sees no transition into an assignable
	 * status and never assigns a serial — so nothing shows in the customer's
	 * front-end or order e-mail. Allowed orders are left completely untouched.
	 *
	 * @param   bool    $new                 Whether this is a new order.
	 * @param   object  $order               By-ref order; has serial_data set.
	 * @param   array   $order_serial_params  By-ref serial params (unused here).
	 * @return  void
	 */
	public function onSerialOrderPreUpdate($new, &$order, &$order_serial_params)
	{
		if (empty($order) || !isset($order->order_status) || $order->order_status === '') {
			return;
		}
		// serial_data is created by HikaSerial just before this hook; if it is
		// missing, HikaSerial will not assign anyway — nothing to do.
		if (!isset($order->serial_data)) {
			return;
		}

		if ($this->isOrderShippingAllowed($order)) {
			return; // qualifies — let HikaSerial generate as normal.
		}

		// Does not qualify: suppress assignment for this order.
		$order->serial_data->old_order_status = $order->order_status;
		$this->log('[generation] Order ' . (int) (isset($order->order_id) ? $order->order_id : 0)
			. ': shipping method not allowed — serial generation suppressed.');
	}

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
	 * plugin, nothing is blocked. Used by the delivery gates (order id known).
	 */
	protected function isShippingAllowed($orderId)
	{
		static $cache = array();
		$orderId = (int) $orderId;
		if (isset($cache[$orderId])) {
			return $cache[$orderId];
		}
		return $cache[$orderId] = $this->decideAllowed($this->getOrderShippingIds($orderId), $orderId);
	}

	/**
	 * Same decision for the generation gate, where the order object is available
	 * but the row may not be in the DB yet (order creation). Reads the shipping
	 * id(s) from the DB when the order id exists, otherwise from the object.
	 */
	protected function isOrderShippingAllowed($order)
	{
		$orderId = (isset($order->order_id)) ? (int) $order->order_id : 0;

		$orderShipping = array();
		if ($orderId > 0) {
			$orderShipping = $this->getOrderShippingIds($orderId);
		}
		if (empty($orderShipping)) {
			$orderShipping = $this->parseShippingIds(isset($order->order_shipping_id) ? $order->order_shipping_id : '');
		}

		return $this->decideAllowed($orderShipping, $orderId);
	}

	/**
	 * Core allow/deny decision given the order's shipping id(s).
	 */
	protected function decideAllowed($orderShipping, $orderId = 0)
	{
		$configured = $this->getAllowedShippingIds();
		if (empty($configured)) {
			// Misconfiguration guard: an empty allow-list means "don't gate".
			$this->log('No shipping method configured in plugin settings; not gating.', Log::WARNING);
			return true;
		}

		if (empty($orderShipping)) {
			// Order has no shipping method — behaviour is configurable.
			$allowed = ($this->params->get('no_shipping_behaviour', 'block') === 'send');
			$this->log('Order ' . (int) $orderId . ' has no shipping method; ' . ($allowed ? 'allowing' : 'blocking') . ' per settings.');
			return $allowed;
		}

		return count(array_intersect($configured, $orderShipping)) > 0;
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

		return $this->parseShippingIds($raw);
	}

	/**
	 * Parse a HikaShop shipping id value (single, comma-separated string, or
	 * array) into a list of positive ints.
	 */
	protected function parseShippingIds($value)
	{
		if (is_array($value)) {
			$parts = $value;
		} else {
			$parts = ($value === '' || $value === null) ? array() : explode(',', (string) $value);
		}
		$ids = array();
		foreach ($parts as $sid) {
			$sid = (int) trim((string) $sid);
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
