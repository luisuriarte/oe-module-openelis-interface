<?php

namespace OpenEMR\Modules\OpenElis;

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_DIRECTORY_NAME = "openelis";

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function subscribeToEvents(): void
    {
        $this->registerMenuItems();
    }

    private function registerMenuItems(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addCustomModuleMenuItem(...));
    }

    public function addCustomModuleMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();

        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'mod';
        $menuItem->menu_id = 'mod0';
        $menuItem->acl_req = ["admin", "super"];
        $menuItem->label = xlt("Mapeo códigos OpenELIS");
        $menuItem->global_req = [];
        $menuItem->url = self::MODULE_INSTALLATION_PATH . self::MODULE_DIRECTORY_NAME . "/public/admin_mapping.php";
        $menuItem->children = [];

        foreach ($menu as $item) {
            if ($item->menu_id == 'proimg') {
                $item->children[] = $menuItem;
                break;
            }
        }

        $event->setMenu($menu);

        return $event;
    }
}
