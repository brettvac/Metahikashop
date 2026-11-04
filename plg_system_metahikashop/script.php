<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_system_metahikashop
 * @version     1.0
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface
{
    private string $minimumJoomla = '4.4.0';
    private string $minimumPhp    = '7.2.5';

    private const TABLE_NAME = '#__metahikashop_capi_purchase';

    /**
     * Check minimum Joomla and PHP versions.
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(
                    Text::_('JLIB_INSTALLER_MINIMUM_PHP'),
                    $this->minimumPhp
                ),
                'error'
            );

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(
                    Text::_('JLIB_INSTALLER_MINIMUM_JOOMLA'),
                    $this->minimumJoomla
                ),
                'error'
            );

            return false;
        }

        return true;
    }

    /**
     * Create the Purchase tracking table on installation.
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return $this->createPurchaseTable();
    }

    /**
     * Ensure the Purchase tracking table exists on update.
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return $this->createPurchaseTable();
    }

    /**
     * Installation and update messages.
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        /* Ensure the table exists after installation or an extension update. */
        if (!$this->createPurchaseTable()) {
            return false;
        }

        $app = Factory::getApplication();

        if ($type === 'install' || $type === 'discover_install') {
            $app->enqueueMessage(
                Text::_('PLG_SYSTEM_METAHIKASHOP_POSTFLIGHT_INSTALL'),
                'message'
            );
        } elseif ($type === 'update') {
            $app->enqueueMessage(
                Text::_('PLG_SYSTEM_METAHIKASHOP_POSTFLIGHT_UPDATE'),
                'message'
            );
        }

        return true;
    }

    /**
     * Create the plugin-owned Purchase tracking table.
     */
    private function createPurchaseTable(): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $sql = '
            CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
                `order_id` BIGINT UNSIGNED NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `claimed_at` DATETIME NULL DEFAULT NULL,
                `sent_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`order_id`),
                KEY `idx_status_claimed` (`status`, `claimed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ';

        try {
            $db->setQuery($db->replacePrefix($sql));
            $db->execute();

            return true;
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'PLG_SYSTEM_METAHIKASHOP_ERROR_CREATE_TABLE',
                    $e->getMessage()
                ),
                'error'
            );

            return false;
        }
    }

    /**
     * Remove the plugin-owned tracking table on uninstallation.
     * HikaShop order records are not deleted.
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        try {
            $sql = 'DROP TABLE IF EXISTS ' . self::TABLE_NAME;

            $db->setQuery($db->replacePrefix($sql));
            $db->execute();

            Factory::getApplication()->enqueueMessage(
                Text::_('PLG_SYSTEM_METAHIKASHOP_UNINSTALL'),
                'message'
            );

            return true;
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'PLG_SYSTEM_METAHIKASHOP_ERROR_DROP_TABLE',
                    $e->getMessage()
                ),
                'error'
            );

            return false;
        }
    }
};
