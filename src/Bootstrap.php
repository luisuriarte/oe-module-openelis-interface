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
        $modulePath = '/interface/modules/custom_modules/' . basename(dirname(__DIR__)) . '/public/';

        $menuItems = [
            [
                'menu_id' => 'mod1',
                'label' => xlt("Pending Orders"),
                'url' => $modulePath . 'pending_orders.php',
            ],
            [
                'menu_id' => 'mod0',
                'label' => xlt("OpenELIS Code Mapping"),
                'url' => $modulePath . 'admin_mapping.php',
            ],
        ];

        foreach ($menu as $item) {
            if ($item->menu_id == 'proimg') {
                foreach ($menuItems as $mi) {
                    $menuItem = new \stdClass();
                    $menuItem->requirement = 0;
                    $menuItem->target = 'mod';
                    $menuItem->menu_id = $mi['menu_id'];
                    $menuItem->acl_req = ["admin", "super"];
                    $menuItem->label = $mi['label'];
                    $menuItem->global_req = [];
                    $menuItem->url = $mi['url'];
                    $menuItem->children = [];
                    $item->children[] = $menuItem;
                }
                break;
            }
        }

        $event->setMenu($menu);

        return $event;
    }
}
