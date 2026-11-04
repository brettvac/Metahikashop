<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.0
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Naftee\Plugin\System\Metahikashop\Extension\Metahikashop;

return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Metahikashop(
					(array) PluginHelper::getPlugin('system', 'metahikashop')
				);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
