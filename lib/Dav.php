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
use OCP\Accounts\IAccountManager;
use Psr\Log\LoggerInterface;
use OCA\DAV\CalDAV\Sharing\Backend as CalendarSharingBackend;
use OCP\L10N\IFactory as IL10NFactory;
use OCA\DAV\CalDAV\DefaultCalendarValidator;
use OCA\DAV\Db\PropertyMapper;
use OCA\DAV\CalDAV\Federation\FederatedCalendarFactory;
use OCA\DAV\CalDAV\Federation\FederatedCalendarMapper;
use OCA\DAV\CalDAV\Proxy\ProxyMapper;
use OCA\DAV\CalDAV\Schedule\IMipPlugin;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\ISecureRandom;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
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
    public static function getServerInstance(?IDBConnection $connection = null, ?IUser $user = null) {
        $baseuri = \OC::$WEBROOT . '/remote.php/dav/';

        // <apps/dav/appinfo/v1/caldav.php>
        // watcha+
        // Nextcloud 34 removed every `\OC::$server->getXxx()` getter but `getL10N()`,
        // `getUserFolder()` and `getWebRoot()`. Upstream's own `caldav.php` — the file
        // this block mirrors — moved to `OCP\Server::get()`, so this mirrors it too;
        // keeping the copy literally aligned with upstream is what makes the next
        // server upgrade a diff rather than an investigation.
        // +watcha
        $authBackend = new Auth(
            Server::get(ISession::class),
            Server::get(IUserSession::class),
            Server::get(IRequest::class),
            Server::get(\OC\Authentication\TwoFactorAuth\Manager::class),
            Server::get(IThrottler::class),
            'principals/'
        );
        $principalBackend = new Principal(
            Server::get(IUserManager::class),
            Server::get(IGroupManager::class),
            Server::get(IAccountManager::class),
            Server::get(IShareManager::class),
            Server::get(IUserSession::class),
            Server::get(IAppManager::class),
            Server::get(ProxyMapper::class),
            Server::get(KnownUserService::class),
            Server::get(IConfig::class),
            Server::get(IL10NFactory::class),
            'principals/'
        );
        $db = Server::get(IDBConnection::class);
        $userManager = Server::get(IUserManager::class);
        $random = Server::get(ISecureRandom::class);
        $logger = Server::get(LoggerInterface::class);
        $dispatcher = Server::get(IEventDispatcher::class);
        $config = Server::get(IConfig::class);
        $calendarSharingBackend = Server::get(CalendarSharingBackend::class); //dla+
        $l10nFactory = Server::get(IL10NFactory::class);
        $davL10n = $l10nFactory->get('dav');
        $federatedCalendarFactory = Server::get(FederatedCalendarFactory::class);

        $calDavBackend = new CalDavBackend(
            $db,
            $principalBackend,
            $userManager,
            $random,
            $logger,
            $dispatcher,
            $config,
            $calendarSharingBackend,
            Server::get(FederatedCalendarMapper::class),
            Server::get(ICacheFactory::class),

            /* watcha! default: false
            true
            !watcha */
        );

        $debugging = $config->getSystemValue('debug', false);
        // watcha+
        // `getAppValue()` went away with the other legacy getters; upstream reads this
        // one through IAppConfig now, and its default flipped from the 'yes' string to
        // a real boolean.
        // +watcha
        $sendInvitations = Server::get(IAppConfig::class)->getValueBool('dav', 'sendInvitations', true);

        // Root nodes
        $principalCollection = new \Sabre\CalDAV\Principal\Collection($principalBackend);
        $principalCollection->disableListing = !$debugging; // Disable listing

        $addressBookRoot = new CalendarRoot($principalBackend, $calDavBackend, 'principals', $logger, $davL10n, $config, $federatedCalendarFactory);
        $addressBookRoot->disableListing = !$debugging; // Disable listing

        $nodes = [
            $principalCollection,
            $addressBookRoot,
        ];

        // Fire up server
        $server = new \Sabre\DAV\Server($nodes);
        $server::$exposeVersion = false;
        $server->httpRequest->setUrl(Server::get(IRequest::class)->getRequestUri());
        $server->setBaseUri($baseuri);

        // Add plugins
        $server->addPlugin(new MaintenancePlugin($config, $davL10n));
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
        $server->addPlugin(new \Sabre\CalDAV\Plugin());

        /* watcha! causes "Node with name 'xxx' could not be found"
        $server->addPlugin(new LegacyDAVACL());
        !watcha */
        if ($debugging) {
            /* watcha! causes "Class \"OCA\\Watcha\\Sabre\\DAV\\Browser\\Plugin\" not found""
            $server->addPlugin(new Sabre\DAV\Browser\Plugin());
            !watcha */
            $server->addPlugin(new \Sabre\DAV\Browser\Plugin());
        }

        $defaultCalendarValidator = Server::get(DefaultCalendarValidator::class);
        $server->addPlugin(new \Sabre\DAV\Sync\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\ICSExportPlugin());
        $server->addPlugin(new \OCA\DAV\CalDAV\Schedule\Plugin($config, $logger, $defaultCalendarValidator));

        if ($sendInvitations) {
            $server->addPlugin(Server::get(IMipPlugin::class));
        }
        $server->addPlugin(new ExceptionLoggerPlugin('caldav', $logger));
        // </apps/dav/appinfo/v1/caldav.php>

        if ($connection && $user) {
            $server->addPlugin(
                new \Sabre\DAV\PropertyStorage\Plugin(
                    new CustomPropertiesBackend(
                        $server,
                        $server->tree,
                        $connection,
                        $user,
                        Server::get(PropertyMapper::class),
                        $defaultCalendarValidator,
                    )
                )
            );
        }
        return $server;
    }
}
