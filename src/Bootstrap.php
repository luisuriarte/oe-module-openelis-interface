<?php

namespace OpenEMR\Modules\OpenElis;

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
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

        // Compute module URL relative to __DIR__ (src/) so it works regardless of directory name.
        // __DIR__ = .../openelis/src → two levels up = module root
        $moduleRelativePath = '/interface/modules/custom_modules/' . basename(dirname(__DIR__)) . '/public/admin_mapping.php';

        $menuItem = new \stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = 'mod';
        $menuItem->menu_id = 'mod0';
        $menuItem->acl_req = ["admin", "super"];
        $menuItem->label = xlt("OpenELIS Code Mapping");
        $menuItem->global_req = [];
        $menuItem->url = $moduleRelativePath;
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
