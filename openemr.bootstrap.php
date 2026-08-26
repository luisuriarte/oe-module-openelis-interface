<?php

/**
 * OpenELIS Interface Module - Bootstrap
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\OpenElis;

/**
 * @global OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists('OpenEMR\\Modules\\OpenElis\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

/**
 * @global EventDispatcherInterface $eventDispatcher
 */
$bootstrap = new Bootstrap($eventDispatcher);
$bootstrap->subscribeToEvents();
