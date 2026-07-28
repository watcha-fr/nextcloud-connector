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

use OCA\Watcha\Service\RoomFolderResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

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
        private RoomFolderResolver $roomFolderResolver,
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
     *       "status": "ok" | "rejected" | "not-member" | "deleted" | "no-share",
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

        $groupId = $this->roomFolderResolver->findRoomGroupId($roomId);
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
}
