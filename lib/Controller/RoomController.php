<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Watcha <contact@watcha.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 or
 * (at your option) any later version.
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

use OCA\Watcha\RoomGroup;
use OCA\Watcha\Service\RoomFolderResolver;
use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Room-scoped operations driven by Synapse.
 *
 * Access is restricted to the Watcha service account by
 * {@see \OCA\Watcha\Middleware\SecurityMiddleware}, which is registered for the
 * whole app.
 */
class RoomController extends Controller {

    public function __construct(
        string $AppName,
        IRequest $request,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private ShareAcceptanceService $shareAcceptanceService,
        private RoomFolderResolver $roomFolderResolver,
        private LoggerInterface $logger,
    ) {
        parent::__construct($AppName, $request);
    }

    /**
     * Resolve a room's document folder for one user, by stable identifier.
     *
     * The client must never locate the folder by name: each recipient of a share
     * may rename their own mount, and Nextcloud appends a suffix on collision, so
     * the same folder has different names for different members. This returns the
     * file id — which is stable — plus the path as currently mounted for the user,
     * and a status the client can act on.
     *
     *     {
     *       "roomId": "!abc:example.org",
     *       "userId": "1b4ea9d9-...",
     *       "groupId": "c4d96a06b7_!abc:example.org",
     *       "status": "ok" | "pending" | "not-member" | "deleted" | "no-share",
     *       "fileId": 12345,
     *       "path": "/FACILITATEURS",
     *       "shareId": "59"
     *     }
     *
     * @param string $roomId the Matrix room id
     * @param string $requester the *Nextcloud* username the folder is resolved for
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getFolder(string $roomId, string $requester): JSONResponse {
        if ($this->userManager->get($requester) === null) {
            return new JSONResponse(
                ["message" => "Unknown Nextcloud user $requester"],
                Http::STATUS_NOT_FOUND
            );
        }

        $groupId = $this->shareAcceptanceService->findRoomGroupId($roomId);
        if ($groupId === null) {
            return new JSONResponse([
                "roomId" => $roomId,
                "userId" => $requester,
                "groupId" => null,
                "status" => "no-share",
                "fileId" => null,
                "path" => null,
                "shareId" => null,
            ]);
        }

        return new JSONResponse([
            "roomId" => $roomId,
            "userId" => $requester,
            "groupId" => $groupId,
            ...$this->roomFolderResolver->resolveForUser($groupId, $requester),
        ]);
    }

    /**
     * Guarantee, in one idempotent operation, that a room member can reach the
     * room's document space: membership of the room group *and* acceptance of
     * the folder shares granted through it.
     *
     * Synapse calls this after adding a member to the group, instead of relying
     * on a side effect of share creation (which only ever covered the members
     * present when the share was created).
     *
     * The response reports what was changed and what was already in place, so
     * the caller can log a meaningful outcome and a human can verify it:
     *
     *     {
     *       "roomId": "!abc:example.org",
     *       "userId": "1b4ea9d9-...",
     *       "groupId": "c4d96a06b7_!abc:example.org",
     *       "groupMembership": "added" | "already-member",
     *       "sharesAccepted": 1,
     *       "shares": [ { "shareId": "227", "status": "accepted", "target": "/Nouveau dossier" } ]
     *     }
     *
     * @param string $roomId the Matrix room id
     * @param string $userId the *Nextcloud* username of the member
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function syncMember(string $roomId, string $userId): JSONResponse {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            $this->logger->warning(
                "Cannot sync room member: Nextcloud user $userId does not exist",
                ["app" => "watcha"]
            );
            return new JSONResponse(
                ["message" => "Unknown Nextcloud user $userId"],
                Http::STATUS_NOT_FOUND
            );
        }

        // The group may not exist yet: a room only gets one once a resource has
        // been shared with it. That is not an error, it just means there is
        // nothing to synchronise.
        $groupId = $this->shareAcceptanceService->findRoomGroupId($roomId);
        if ($groupId === null) {
            return new JSONResponse([
                "roomId" => $roomId,
                "userId" => $userId,
                "groupId" => null,
                "groupMembership" => "no-group",
                "sharesAccepted" => 0,
                "shares" => [],
            ]);
        }

        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return new JSONResponse(
                ["message" => "Nextcloud group $groupId does not exist"],
                Http::STATUS_NOT_FOUND
            );
        }

        $membership = "already-member";
        if (!$group->inGroup($user)) {
            $group->addUser($user);
            $membership = "added";
            $this->logger->info(
                "Added user $userId to room group $groupId",
                ["app" => "watcha"]
            );
        }

        // Adding the user to the group already triggers our own
        // UserAddedEvent listener, but we must not depend on that: the call
        // above is a no-op when the user is already a member, which is exactly
        // the case this endpoint exists to repair.
        $accepted = $this->shareAcceptanceService->acceptPendingSharesForUser($userId, $groupId);

        return new JSONResponse([
            "roomId" => $roomId,
            "userId" => $userId,
            "groupId" => $groupId,
            "groupMembership" => $membership,
            "sharesAccepted" => $accepted,
            "shares" => $this->shareAcceptanceService->inspectSharesForUser($userId, $groupId),
        ]);
    }
}
