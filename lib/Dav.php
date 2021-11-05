<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
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

use OCP\IDBConnection;
use OCP\IUser;

// Backends
use OC\KnownUser\KnownUserService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\CalendarRoot;
use OCA\DAV\Connector\LegacyDAVACL;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\MaintenancePlugin;
use OCA\DAV\Connector\Sabre\Principal;
use OCA\DAV\DAV\CustomPropertiesBackend;

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
            'principals/'
        );
        $db = \OC::$server->getDatabaseConnection();
        $userManager = \OC::$server->getUserManager();
        $random = \OC::$server->getSecureRandom();
        $logger = \OC::$server->getLogger();
        $dispatcher = \OC::$server->get(\OCP\EventDispatcher\IEventDispatcher::class);
        $legacyDispatcher = \OC::$server->getEventDispatcher();

        $calDavBackend = new CalDavBackend($db, $principalBackend, $userManager, \OC::$server->getGroupManager(), $random, $logger, $dispatcher, $legacyDispatcher, false);

        $debugging = \OC::$server->getConfig()->getSystemValue('debug', false);
        $sendInvitations = \OC::$server->getConfig()->getAppValue('dav', 'sendInvitations', 'yes') === 'yes';

        // Root nodes
        $principalCollection = new \Sabre\CalDAV\Principal\Collection($principalBackend);
        $principalCollection->disableListing = !$debugging; // Disable listing

        $addressBookRoot = new CalendarRoot($principalBackend, $calDavBackend);
        $addressBookRoot->disableListing = !$debugging; // Disable listing

        $nodes = [
            $principalCollection,
            $addressBookRoot,
        ];

        // Fire up server
        $server = new \Sabre\DAV\Server($nodes);
        $server::$exposeVersion = false;
        $server->httpRequest->setUrl(\OC::$server->getRequest()->getRequestUri());

        $baseuri = \OC::$WEBROOT . '/remote.php/dav/';

        $server->setBaseUri($baseuri);

        // Add plugins
        $server->addPlugin(new MaintenancePlugin(\OC::$server->getConfig(), \OC::$server->getL10N('dav')));
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend, 'ownCloud'));
        $server->addPlugin(new \Sabre\CalDAV\Plugin());

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
