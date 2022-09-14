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

namespace OCA\Watcha\Controller;

use Psr\Log\LoggerInterface;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Util;
use Sabre\DAV\Exception\NotFound;
use Sabre\Uri;

use OCA\Watcha\Dav;
use OCA\Watcha\Exception\GenericException;

const CALENDAR_ORDER_KEY = "{http://apple.com/ns/ical/}calendar-order";
const DISPLAYNAME_KEY = "{DAV:}displayname";
const OWNER_PRINCIPAL_KEY = "{" . \OCA\DAV\DAV\Sharing\Plugin::NS_OWNCLOUD . "}owner-principal";
const SUPPORTED_CALENDAR_COMPONENT_SET_KEY = "{" . \OCA\DAV\CalDAV\Plugin::NS_CALDAV . "}supported-calendar-component-set";

class CalendarController extends Controller {
    /** @var string */
    private $userId;

    /** @var LoggerInterface */
    private $logger;

    /** @var CalDavBackend */
    private $caldav;

    /** @var IDBConnection */
    private $connection;

    /** @var IUserManager */
    private $userManager;

    /** @var IGroupManager */
    private $groupManager;

    /** @var IConfig */
    private $config;

    public function __construct(
        string $AppName,
        ?string $UserId,
        IRequest $request,
        LoggerInterface $logger,
        CalDavBackend $caldav,
        IDBConnection $connection,
        IUserManager $userManager,
        IGroupManager $groupManager,
        IConfig $config
    ) {
        parent::__construct($AppName, $request);
        $this->userId = $UserId;
        $this->logger = $logger;
        $this->caldav = $caldav;
        $this->connection = $connection;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @return JSONResponse
     */
    public function list(string $userId) {
        $principalUri = "principals/users/$userId";
        $calendars = $this->caldav->getUsersOwnCalendars($principalUri);
        $fmtCalendars = [];
        foreach ($calendars as $calendar) {
            $fmtCalendars[] = [
                "id" => (int) $calendar["id"],
                "displayname" => $calendar[DISPLAYNAME_KEY],
                "components" => $calendar[SUPPORTED_CALENDAR_COMPONENT_SET_KEY]->getValue()
            ];
        }
        usort($fmtCalendars, function (array $a, array $b) {
            return strcmp(strtolower($a['displayname']), strtolower($b['displayname']));
        });
        return new JSONResponse($fmtCalendars);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @param int $calendarId
     * @return JSONResponse
     */
    public function get(string $userId, int $calendarId) {
        $calendar = $this->getFormatedCalendar($calendarId);
        try {
            $displayName = $this->getDisplayName($userId, $calendarId);
        } catch (GenericException $e) {
            return new JSONResponse(["message" => $e->getMessage()], $e->getHTTPCode());
        }
        $calendar["displayname"] = $displayName;
        return new JSONResponse($calendar);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @param int $calendarId
     * @return JSONResponse
     */
    public function reorder(string $userId, int $calendarId) {
        $calendar = $this->caldav->getCalendarById($calendarId);
        if (is_null($calendar)) {
            $this->logger->warning("calendar $calendarId no found, can't reorder it for user $userId");
            return;
        }
        $components = $calendar[SUPPORTED_CALENDAR_COMPONENT_SET_KEY]->getValue();
        if (in_array("VTODO", $components)) {
            list(, $ownerId) = Uri\split($calendar["principaluri"]);
            $calendarUri = $ownerId === $userId ? $calendar["uri"] : $this->computeUriForSharedCalendar($calendar["uri"], $ownerId);
            $value = "/calendars/$calendarUri";
            $this->config->setUserValue($userId, "tasks", "various_initialRoute", $value);
            $this->logger->info("todo list with URI $calendarUri defined as initial route for user $userId");
        }

        $query = $this->connection->getQueryBuilder();
        $fields = ["propertypath", "propertyvalue"];
        $query->select($fields)
            ->from("properties")
            ->where($query->expr()->eq("userid", $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->eq("propertyname",  $query->createNamedParameter(CALENDAR_ORDER_KEY)))
            ->orderBy("propertyvalue", "ASC");

        $cursor = $query->execute();

        $ocProperties = array();

        while ($row = $cursor->fetch()) {
            $order = (int) $row["propertyvalue"];
            $path = (string) $row["propertypath"];
            $ocProperties[$path] = [
                "order" => $order
            ];
        }

        $cursor->closeCursor();

        $principalUri = "principals/users/$userId";
        $calendars = $this->caldav->getCalendarsForUser($principalUri);
        $orderedCalendars = array();

        foreach ($calendars as $calendar) {
            $owner = $calendar[OWNER_PRINCIPAL_KEY];
            $uri = $calendar["uri"];
            $path = "calendars/$userId/$uri";
            if ($owner !== $principalUri and array_key_exists($path, $ocProperties)) {
                $ocProperties[$path]["calendar"] = $calendar;
            } else {
                $orderedCalendars[] = $calendar;
            }
        }

        foreach ($ocProperties as $propertie) {
            $order = $propertie["order"];
            $calendar = $propertie["calendar"];
            array_splice($orderedCalendars, $order, 0, array($calendar));
        }

        $user = $this->userManager->get($userId);
        $server = Dav::getServerInstance($this->connection, $user);

        $i = 1;
        foreach ($orderedCalendars as $calendar) {
            $uri = $calendar["uri"];
            $path = "calendars/$userId/$uri";
            $order = (int) $calendar["id"] === $calendarId ? 0 : $i++;
            $properties = [CALENDAR_ORDER_KEY => $order];
            $server->updateProperties($path, $properties);
        }
        $this->logger->info("calendar $calendarId moved to top of list for user $userId");
        return new JSONResponse((object)[]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $mxRoomId
     * @param string $displayName
     * @param string[] $userIds (optional)
     * @return JSONResponse
     */
    public function createAndShare(string $mxRoomId, string $displayName, array $userIds = []) {
        $userId = $this->userId;
        $calendarUri = $this->computeIdFromMxRoomId($mxRoomId);
        $calendarId = $this->create($userId, $calendarUri, $displayName);
        $userIds[] = $this->userId;
        return $this->share($userId, $calendarId, $mxRoomId, $displayName, $userIds);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @param int $calendarId
     * @param string $mxRoomId
     * @param string $displayName
     * @param string[] $userIds (optional)
     * @return JSONResponse
     */
    public function share(string $userId, int $calendarId, string $mxRoomId, string $displayName, array $userIds = []) {
        $ownerId = $this->getUserIdFromCalendarId($calendarId);
        if (is_null($ownerId)) {
            $message = "calendar $calendarId not found, can't share it";
            $this->logger->warning($message);
            return new JSONResponse(["message" => $message], Http::STATUS_NOT_FOUND);
        }
        if ($ownerId !== $userId) {
            $message = "calendar $calendarId is not owned by $userId, can't share it";
            $this->logger->warning($message);
            return new JSONResponse(["message" => $message], Http::STATUS_FORBIDDEN);
        }
        $groupId = $this->computeIdFromMxRoomId($mxRoomId);
        try {
            $this->createGroup($groupId, $displayName);
        } catch (GenericException $e) {
            return new JSONResponse(["message" => $e->getMessage()], $e->getHTTPCode());
        }
        # workaround for an upstream bug: users must be added to the group before
        # sharing the calendar to avoid it appearing empty when its members are listed later
        $this->addUsersToGroup($groupId, $userIds, $ownerId);
        $add = [
            array(
                "href" => "principal:principals/groups/$groupId",
                "commonName" => null,
                "summary" => null,
                "readOnly" => false,
            )
        ];
        try {
            $this->updateShares($calendarId, $add);
        } catch (GenericException | NotFound $e) {
            return new JSONResponse(["message" => $e->getMessage()], $e->getHTTPCode());
        }
        $this->renameForGroupMembers($groupId, $calendarId, $displayName);
        $calendar = $this->getFormatedCalendar($calendarId);
        return new JSONResponse($calendar);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param int[] $calendarIds
     * @param string $mxRoomId
     * @param bool $deleteGroup (optional)
     * @return JSONResponse
     */
    public function unShare(array $calendarIds, string $mxRoomId, bool $deleteGroup = False) {
        $groupId = $this->computeIdFromMxRoomId($mxRoomId);
        foreach ($calendarIds as $calendarId) {
            if ($this->getUserIdFromCalendarId($calendarId) === $this->userId) {
                $this->delete($calendarId);
            } else {
                $remove = ["principal:principals/groups/$groupId"];
                $this->updateShares($calendarId, [], $remove);
            }
        }
        if ($deleteGroup) {
            try {
                $this->deleteGroup($groupId);
            } catch (GenericException $e) {
                return new JSONResponse(["message" => $e->getMessage()], $e->getHTTPCode());
            }
        }
        return new JSONResponse((object)[]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @param string $mxRoomId
     * @param int[] $calendarIds
     * @param string $displayName
     * @return JSONResponse
     */
    public function addUser(string $userId, string $mxRoomId, array $calendarIds, string $displayName) {
        $groupId = $this->computeIdFromMxRoomId($mxRoomId);
        foreach ($calendarIds as $calendarId) {
            $ownerId = $this->getUserIdFromCalendarId($calendarId);
            $this->addUsersToGroup($groupId, [$userId], $ownerId);
            try {
                $this->renameForUser($userId, $calendarId, $displayName);
            } catch (NotFound $e) {
                $this->logger->warning($e->getMessage());
            }
        }
        return new JSONResponse((object)[]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param string $userId
     * @param string $mxRoomId
     * @return JSONResponse
     */
    public function removeUser(string $userId, string $mxRoomId) {
        $groupId = $this->computeIdFromMxRoomId($mxRoomId);
        $this->removeUserFromGroup($groupId, $userId);
        return new JSONResponse((object)[]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * 
     * @param int[] $calendarIds
     * @param string $mxRoomId
     * @param string $displayName
     * @return JSONResponse
     */
    public function rename(array $calendarIds, string $mxRoomId, string $displayName) {
        $groupId = $this->computeIdFromMxRoomId($mxRoomId);
        $this->renameGroup($groupId, $displayName);
        foreach ($calendarIds as $calendarId) {
            $this->renameForGroupMembers($groupId, $calendarId, $displayName);
        }
        return new JSONResponse((object)[]);
    }

    /**
     * @param int $calendarId
     * @return array
     */
    private function getFormatedCalendar(int $calendarId) {
        $calendar = $this->caldav->getCalendarById($calendarId);
        return [
            "id" => (int) $calendar["id"],
            "components" => $calendar[SUPPORTED_CALENDAR_COMPONENT_SET_KEY]->getValue(),
            "is_personal" => $this->getUserIdFromCalendarId($calendarId) !== $this->userId
        ];
    }

    /**
     * @param string $userId
     * @param int $calendarId
     * @return JSONResponse
     */
    private function getDisplayName(string $userId, int $calendarId) {
        $user = $this->userManager->get($userId);
        $server = Dav::getServerInstance($this->connection, $user);
        $propertyNames = [DISPLAYNAME_KEY];
        $principalUri = "principals/users/$userId";
        $calendars = $this->caldav->getCalendarsForUser($principalUri);
        foreach ($calendars as $calendar) {
            if ((int) $calendar["id"] !== $calendarId) {
                continue;
            }
            $uri = $calendar["uri"];
            $path = "calendars/$userId/$uri";
            $properties = $server->getProperties($path, $propertyNames);
            return $properties[DISPLAYNAME_KEY];
        }
        $message = "calendar $calendarId not available for user $userId, can't get displayname";
        $this->logger->warning($message);
        throw new GenericException($message, Http::STATUS_FORBIDDEN);
    }

    /**
     * @param string $userId
     * @param string $calendarUri
     * @param string $displayName
     * @return int
     */
    private function create(string $userId, string $calendarUri, string $displayName) {
        $server = Dav::getServerInstance();
        $path = "calendars/$userId/$calendarUri";
        if ($server->tree->nodeExists($path)) {
            $this->logger->warning("calendar $path already exists");
            return $this->getCalendarIdFromUri($userId, $calendarUri);
        }
        $principalUri = "principals/users/$userId";
        $properties = [DISPLAYNAME_KEY => $displayName];
        $calendarId = $this->caldav->createCalendar($principalUri, $calendarUri, $properties);
        $this->logger->info("calendar $calendarId ($displayName) created");
        return $calendarId;
    }

    /**
     * @param int $calendarId
     * @return void
     */
    private function delete(int $calendarId) {
        if (is_null($this->caldav->getCalendarById($calendarId))) {
            $this->logger->warning("calendar $calendarId no found, can't delete it");
            return;
        }
        list($major,) = Util::getVersion();
        if ($major > 21) {
            $this->caldav->deleteCalendar($calendarId, true);
        } else {
            $this->caldav->deleteCalendar($calendarId);
        }
        $this->logger->info("calendar $calendarId deleted");
    }

    /**
     * @param int $calendarId
     * @param array $add
     * @param array $remove
     * @return void
     * @throws GenericException
     * @throws Sabre\DAV\Exception\NotFound
     */
    private function updateShares(int $calendarId, array $add = [], array $remove = []) {
        $server = Dav::getServerInstance();
        $userId = $this->getUserIdFromCalendarId($calendarId);
        if (is_null($userId)) {
            if ($add) {
                $exception = new GenericException("no calendar $calendarId to add a share to");
                $this->logger->error($exception->getMessage(), ["exception" => $exception]);
                throw $exception;
            }
            $this->logger->warning("no calendar $calendarId to remove a share to");
            return;
        }
        $calendar = $this->caldav->getCalendarById($calendarId);
        $uri = $calendar["uri"];
        $path = "calendars/$userId/$uri";
        $node = $server->tree->getNodeForPath($path);
        $node->updateShares($add, $remove);
        $this->logger->info("share(s) for calendar $calendarId updated (add: {add} | remove: {remove})", [
            "add" => json_encode($add, JSON_UNESCAPED_SLASHES), "remove" => json_encode($remove, JSON_UNESCAPED_SLASHES)
        ]);
    }

    /**
     * @param string $groupId
     * @param string $displayName
     * @return void
     * @throws GenericException
     */
    private function createGroup(string $groupId, string $displayName) {
        if ($this->groupManager->groupExists($groupId)) {
            $this->logger->warning("group $groupId already exists");
            return;
        }
        $group = $this->groupManager->createGroup($groupId);
        if (!$group instanceof IGroup) {
            $exception = new GenericException("can't create $groupId");
            $this->logger->error($exception->getMessage(), ["exception" => $exception]);
            throw $exception;
        }
        $group->setDisplayName($displayName);
        $this->logger->info("group $groupId ($displayName) created");
    }

    /**
     * @param string $groupId
     * @return void
     */
    private function deleteGroup(string $groupId) {
        $group = $this->groupManager->get($groupId);
        if (is_null($group)) {
            $this->logger->warning("group $groupId no found, can't delete it");
            return;
        }
        if (!$group->delete()) {
            $exception = new GenericException("can't delete group $groupId");
            $this->logger->error($exception->getMessage(), ["exception" => $exception]);
            throw $exception;
        }
        $this->logger->info("group $groupId deleted");
    }

    /**
     * @param string $groupId
     * @param string $displayName
     * @return void
     */
    private function renameGroup(string $groupId, string $displayName) {
        $group = $this->groupManager->get($groupId);
        if (is_null($group)) {
            $this->logger->warning("group $groupId no found, can't rename it");
            return;
        }
        $group->setDisplayName($displayName);
        $this->logger->info("group $groupId renamed to $displayName");
    }

    /**
     * @param string $groupId
     * @param string[] $userIds
     * @param string $ownerId
     * @return void
     */
    private function addUsersToGroup(string $groupId, array $userIds, string $ownerId) {
        $group = $this->groupManager->get($groupId);
        if (is_null($group)) {
            $this->logger->warning("group $groupId no found, can't add users");
            return;
        }
        foreach ($userIds as $userId) {
            # never add calendar owner other than the service account to group
            if ($userId === $ownerId and $ownerId !== $this->userId) {
                $this->logger->info("user $userId is the calendar owner, addition to group $groupId skipped");
                continue;
            }
            $user = $this->userManager->get($userId);
            if (is_null($user)) {
                $this->logger->warning("user $userId no found, can't add it to group $groupId");
                continue;
            }
            $group->addUser($user);
            $this->logger->info("user $userId added to group $groupId");
        }
    }

    /**
     * @param string $groupId
     * @param string $userId
     * @return void
     */
    private function removeUserFromGroup(string $groupId, string $userId) {
        $group = $this->groupManager->get($groupId);
        if (is_null($group)) {
            $this->logger->warning("group $groupId no found, can't remove user");
            return;
        }
        $user = $this->userManager->get($userId);
        if (is_null($user)) {
            $this->logger->warning("user $userId no found, can't remove it from group $groupId");
            return;
        }
        $group->removeUser($user);
        $this->logger->info("user $userId remove from group $groupId");
    }

    /**
     * @param string $groupId
     * @param int $calendarId
     * @param string $displayName
     * @return void
     */
    private function renameForGroupMembers(string $groupId, int $calendarId, string $displayName) {
        $group = $this->groupManager->get($groupId);
        if (is_null($group)) {
            $this->logger->warning("group $groupId no found, can't rename calendar $calendarId for its members");
            return;
        }
        $users = $group->getUsers();
        foreach ($users as $user) {
            $userId = $user->getUID();
            try {
                $this->renameForUser($userId, $calendarId, $displayName);
            } catch (NotFound $e) {
                $this->logger->warning($e->getMessage());
            }
        }
    }

    /**
     * @param string $userId
     * @param int $calendarId
     * @param string $displayName
     * @return void
     * @throws GenericException
     */
    private function renameForUser(string $userId, int $calendarId, string $displayName) {
        $user = $this->userManager->get($userId);
        if (is_null($user)) {
            $this->logger->warning("user $userId no found, can't rename calendar $calendarId for them");
            return;
        }
        $server = Dav::getServerInstance($this->connection, $user);
        $calendar = $this->caldav->getCalendarById($calendarId);
        if (is_null($calendar)) {
            $this->logger->warning("calendar $calendarId no found, can't rename it for user $userId");
            return;
        }
        list(, $ownerId) = Uri\split($calendar["principaluri"]);
        # never rename a personal calendar other than those of the service account
        if ($ownerId === $userId and $ownerId !== $this->userId) {
            return;
        }
        $calendarUri = $ownerId === $userId ? $calendar["uri"] : $this->computeUriForSharedCalendar($calendar["uri"], $ownerId);
        $path = "calendars/$userId/$calendarUri";
        $properties = [DISPLAYNAME_KEY => $displayName];
        $result = $server->updateProperties($path, $properties);
        if (!in_array($result[DISPLAYNAME_KEY], [200, 204])) {
            $exception = new GenericException("can't rename calendar with URI $calendarUri for user $userId");
            $this->logger->error($exception->getMessage(), ["exception" => $exception]);
            throw $exception;
        }
        $this->logger->info("calendar $calendarId renamed to $displayName for user $userId");
    }

    /**
     * @param int $calendarId
     * @return string|null
     */
    private function getUserIdFromCalendarId(int $calendarId) {
        $calendar = $this->caldav->getCalendarById($calendarId);
        if (is_null($calendar)) {
            $this->logger->warning("calendar $calendarId no found, can't parse user ID");
            return;
        }
        $principalUri = $calendar["principaluri"];
        list(, $userId) = Uri\split($principalUri);
        return $userId;
    }

    /**
     * @param string $userId
     * @param string $calendarUri
     * @return int|null
     */
    private function getCalendarIdFromUri(string $userId, string $calendarUri) {
        $principalUri = "principals/users/$userId";
        $calendar = $this->caldav->getCalendarByUri($principalUri, $calendarUri);
        if (is_null($calendar)) {
            $this->logger->warning("calendar $calendarUri owned by $userId no found, can't get ID");
            return;
        }
        return (int) $calendar["id"];
    }

    /**
     * @param string $calendarUri
     * @param string $ownerId
     * @return string
     */
    private function computeUriForSharedCalendar(string $calendarUri, string $ownerId) {
        return $calendarUri . "_shared_by_" . $ownerId;
    }

    /**
     * @param string $mxRoomId
     * @return string
     */
    private function computeIdFromMxRoomId(string $mxRoomId) {
        return hash("sha256", $mxRoomId);
    }
}
