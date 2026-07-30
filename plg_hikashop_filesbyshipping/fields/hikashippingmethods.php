<?php
/**
 * @package     plg_hikaserial_serialbyshipping
 * @subpackage  Form fields
 * @version     1.3.0
 * @author      Anirudha Talmale
 * @license     GNU General Public License version 3 or later
 *
 * Custom Joomla form field that lists the shop's shipping methods with their
 * real (translated) names instead of only numeric IDs.
 *
 * HikaShop does not keep the display name in the plain #__hikashop_shipping
 * shipping_name column in a directly usable form — the name is resolved through
 * HikaShop's own shipping class and hikashop_translate() (see
 * administrator/components/com_hikashop/classes/shipping.php::get()). A plain
 * SQL form field therefore shows blank names and falls back to the ID. This
 * field asks HikaShop to resolve each name exactly the way the order views do,
 * so the merchant sees e.g. "Ticket(s) per E-Mail (#1)".
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

class JFormFieldHikashippingmethods extends ListField
{
	/** @var string The form field type. */
	protected $type = 'Hikashippingmethods';

	/**
	 * Build the option list: one entry per published shipping method, labelled
	 * with the method's resolved name and its ID.
	 *
	 * @return  array
	 */
	protected function getOptions()
	{
		$options = array();

		// Bring in HikaShop's helper so hikashop_get()/hikashop_translate() exist.
		if (!function_exists('hikashop_get')) {
			$helper = rtrim(JPATH_ADMINISTRATOR, '/\\') . '/components/com_hikashop/helpers/helper.php';
			if (is_file($helper)) {
				include_once $helper;
			}
		}

		$rows = array();
		try {
			$db = Factory::getContainer()->get('DatabaseDriver');
			$query = $db->getQuery(true)
				->select($db->quoteName(array('shipping_id', 'shipping_name')))
				->from($db->quoteName('#__hikashop_shipping'))
				->where($db->quoteName('shipping_published') . ' = 1')
				->order($db->quoteName('shipping_ordering') . ' ASC');
			$db->setQuery($query);
			$rows = $db->loadObjectList();
		} catch (\Throwable $e) {
			$rows = array();
		}

		$shippingClass = function_exists('hikashop_get') ? hikashop_get('class.shipping') : null;

		foreach ((array) $rows as $row) {
			$id   = (int) $row->shipping_id;
			$name = '';

			// Preferred: resolve the name the same way HikaShop's order views do.
			if (is_object($shippingClass) && method_exists($shippingClass, 'get')) {
				try {
					$shipping = $shippingClass->get($id);
					if (is_object($shipping) && !empty($shipping->shipping_name)) {
						$name = $shipping->shipping_name;
					}
				} catch (\Throwable $e) {
					// fall through to the raw value below
				}
			}

			// Fallbacks: the raw column, then translation, then a safe label.
			if ($name === '' && !empty($row->shipping_name)) {
				$name = $row->shipping_name;
			}
			if ($name !== '' && function_exists('hikashop_translate')) {
				$name = hikashop_translate($name);
			}
			if ($name === '') {
				$name = 'Shipping method';
			}

			$options[] = HTMLHelper::_('select.option', (string) $id, $name . ' (#' . $id . ')');
		}

		return array_merge(parent::getOptions(), $options);
	}
}
