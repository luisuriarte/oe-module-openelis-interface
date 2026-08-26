<?php

/**
 * Class to be called from Laminas Module Manager for reporting management actions.
 *
 * @package OpenEMR Modules
 * @link    https://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\AbstractModuleActionListener;

class ModuleManagerListener extends AbstractModuleActionListener
{
    public function __construct()
    {
        parent::__construct();
    }

    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists(self::class, $methodName)) {
            return self::$methodName($modId, $currentActionStatus);
        } else {
            return $currentActionStatus;
        }
    }

    public static function getModuleNamespace(): string
    {
        return 'OpenEMR\\Modules\\OpenElis\\';
    }

    public static function initListenerSelf(): ModuleManagerListener
    {
        return new self();
    }

    private function install($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '0', '1');
        return $currentActionStatus;
    }

    private function enable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '1', '0');
        return $currentActionStatus;
    }

    private function disable($modId, $currentActionStatus): mixed
    {
        self::setModuleState($modId, '0', '1');
        return $currentActionStatus;
    }

    private function unregister($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    private function install_sql($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    private function upgrade_sql($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    private function preenable($modId, $currentActionStatus): mixed
    {
        return $currentActionStatus;
    }

    public function getModuleRegistry($modId, $col = '*'): array
    {
        $registry = [];
        $sql = "SELECT $col FROM modules WHERE mod_id = ?";
        $results = sqlQuery($sql, [$modId]);
        foreach ($results as $k => $v) {
            $registry[$k] = trim(((string) preg_replace('/\R/', '', (string) $v)));
        }

        return $registry;
    }

    private static function setModuleState(int|string $modId, int|string $flag, int|string $flag_ui): array|bool|null
    {
        $sql = "UPDATE `modules` SET `mod_active` = ?, `mod_ui_active` = ? WHERE `mod_id` = ? OR `mod_directory` = ?";
        return sqlQuery($sql, [$flag, $flag_ui, $modId, $modId]);
    }
}
