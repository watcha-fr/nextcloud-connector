<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Citharel <nextcloud@tcit.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Watcha;

use OCA\DAV\DAV\CustomPropertiesBackend;
use OCP\IDBConnection;
use OCP\IUser;

// <apps/dav/appinfo/v1/caldav.php>
// Backends
use OC\KnownUser\KnownUserService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\Connector\LegacyDAVACL;
use OCA\DAV\CalDAV\CalendarRoot;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Principal;
use Psr\Log\LoggerInterface;
// </apps/dav/appinfo/v1/caldav.php>

/**
 * Sabre DAV Server
 *
 * @package OCA\Watcha
 */
class Dav {

    /**
     * @return \Sabre\DAV\Server
     */
    public static function getServerInstance(IDBConnection $connection = null, IUser $user = null) {
        $baseuri = \OC::$WEBROOT . '/remote.php/dav/';

        // <apps/dav/appinfo/v1/caldav.php>
        $authBackend = new Auth(
            \OC::$server->getSession(),
            \OC::$server->getUserSession(),
            \OC::$server->getRequest(),
            \OC::$server->getTwoFactorAuthManager(),
            \OC::$server->getBruteForceThrottler(),
            'principals/'
        );
        $principalBackend = new Principal(
            \OC::$server->getUserManager(),
            \OC::$server->getGroupManager(),
            \OC::$server->getShareManager(),
            \OC::$server->getUserSession(),
            \OC::$server->getAppManager(),
            \OC::$server->query(\OCA\DAV\CalDAV\Proxy\ProxyMapper::class),
            \OC::$server->get(KnownUserService::class),
            \OC::$server->getConfig(),
            \OC::$server->getL10NFactory(),
            'principals/'
        );
        $db = \OC::$server->getDatabaseConnection();
        $userManager = \OC::$server->getUserManager();
        $random = \OC::$server->getSecureRandom();
        $logger = \OC::$server->getLogger();
        $dispatcher = \OC::$server->get(\OCP\EventDispatcher\IEventDispatcher::class);
        $legacyDispatcher = \OC::$server->getEventDispatcher();
        $config = \OC::$server->get(\OCP\IConfig::class);

        $calDavBackend = new CalDavBackend(
            $db,
            $principalBackend,
            $userManager,
            \OC::$server->getGroupManager(),
            $random,
            $logger,
            $dispatcher,
            $legacyDispatcher,
            $config,
            false
        );

        $debugging = \OC::$server->getConfig()->getSystemValue('debug', false);
        $sendInvitations = \OC::$server->getConfig()->getAppValue('dav', 'sendInvitations', 'yes') === 'yes';

        // Root nodes
        $principalCollection = new \Sabre\CalDAV\Principal\Collection($principalBackend);
        $principalCollection->disableListing = !$debugging; // Disable listing

        $addressBookRoot = new CalendarRoot($principalBackend, $calDavBackend, 'principals', \OC::$server->get(LoggerInterface::class));
        $addressBookRoot->disableListing = !$debugging; // Disable listing

        $nodes = [
            $principalCollection,
            $addressBookRoot,
        ];

        // Fire up server
        $server = new \Sabre\DAV\Server($nodes);
        $server::$exposeVersion = false;
        $server->httpRequest->setUrl(\OC::$server->getRequest()->getRequestUri());
        $server->setBaseUri($baseuri);

        // Add plugins
        $server->addPlugin(new MaintenancePlugin(\OC::$server->getConfig(), \OC::$server->getL10N('dav')));
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend, 'ownCloud'));
        $server->addPlugin(new \Sabre\CalDAV\Plugin());

        /* watcha! causes "Node with name 'xxx' could not be found"
        $server->addPlugin(new LegacyDAVACL());
        !watcha */
        if ($debugging) {
            $server->addPlugin(new \Sabre\DAV\Browser\Plugin());
        }

        $server->addPlugin(new \Sabre\DAV\Sync\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
        $server->addPlugin(new \OCA\DAV\CalDAV\Schedule\Plugin(\OC::$server->getConfig()));

        if ($sendInvitations) {
            $server->addPlugin(\OC::$server->query(\OCA\DAV\CalDAV\Schedule\IMipPlugin::class));
        }
        $server->addPlugin(new ExceptionLoggerPlugin('caldav', \OC::$server->getLogger()));
        // </apps/dav/appinfo/v1/caldav.php>

        if ($connection && $user) {
            $server->addPlugin(
                new \Sabre\DAV\PropertyStorage\Plugin(
                    new CustomPropertiesBackend(
                        $server->tree,
                        $connection,
                        $user
                    )
                )
            );
        }
        return $server;
    }
}
