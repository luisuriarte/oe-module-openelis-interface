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

        // In production the web scripts are copied to <openemr_root>/public/,
        // so URLs resolve to {webroot}/public/{script}.
        $webroot = $GLOBALS['webroot'] ?? '';
        $scriptsUrl = $webroot . '/public/modules/openelis/';

        $menuItems = [
            [
                'menu_id' => 'mod3',
                'label' => xlt("Import Catalog"),
                'url' => $scriptsUrl . 'catalog_import.php',
            ],
            [
                'menu_id' => 'mod1',
                'label' => xlt("Pending Orders"),
                'url' => $scriptsUrl . 'pending_orders.php',
            ],
            [
                'menu_id' => 'mod0',
                'label' => xlt("OpenELIS Code Mapping"),
                'url' => $scriptsUrl . 'admin_mapping.php',
            ],
            [
                'menu_id' => 'mod2',
                'label' => xlt("OpenELIS Settings"),
                'url' => $scriptsUrl . 'openelis_config.php',
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
