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

namespace OCA\Watcha\Service;

use OCA\Watcha\RoomGroup;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Resolves a room's document folder as it exists *for one specific user*.
 *
 * Why this exists
 * ---------------
 * The client used to locate the folder by name, building a URL from the folder
 * label. A mount name is not stable: every recipient of a Nextcloud share may
 * rename their own mount (`oc_share.file_target` on the child share), and
 * Nextcloud appends a suffix on collision. The same folder can therefore be
 * `/Nouveau dossier` for 24 members, `/Facilitateurs` for one and
 * `/FACILITATEURS` for another — so a name resolved for one person 404s for the
 * next. The file id *is* stable; this returns it, together with the path as
 * currently mounted for the caller.
 *
 * What a missing child share does NOT mean
 * ----------------------------------------
 * Access is carried by the **parent group share**. Nextcloud only materialises a
 * per-recipient child row (`share_type = 2`) *lazily* — when the recipient
 * renames, moves or rejects their mount. Its absence is the normal state and
 * says nothing about access, so `IShare::STATUS_PENDING` is deliberately treated
 * as reachable here. Reading it as a defect is what led to a wrong diagnosis
 * once already: the ratio of "unaccepted pairs" per share is essentially the
 * same on a healthy deployment as on a broken one.
 *
 * Only an explicit `STATUS_REJECTED` is a real denial: the recipient dismissed
 * the share, and Nextcloud removed their mount.
 *
 * Statuses returned:
 * - `ok`          reachable; `fileId` and `path` are usable
 * - `rejected`    the recipient explicitly dismissed this share
 * - `not-member`  the user does not belong to the room group
 * - `deleted`     the folder itself is gone
 * - `no-share`    no folder is bound to this room
 */
class RoomFolderResolver {

    public function __construct(
        private IShareManager $shareManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private GroupShareLocator $groupShareLocator,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The Nextcloud group backing a room, or null when the room has none.
     *
     * The hash prefix cannot be derived from the room id, so an existing group is
     * looked up when the conventional prefix does not match.
     */
    public function findRoomGroupId(string $roomId): ?string {
        $prefix = $this->appConfig->getValueString(
            "watcha",
            "room_group_prefix",
            RoomGroup::ID_PREFIX
        );
        $candidate = RoomGroup::buildId($roomId, $prefix);
        if ($this->groupManager->groupExists($candidate)) {
            return $candidate;
        }

        // Fall back to a search on the room's localpart, which survives the
        // 64-character truncation, in case the deployment uses another prefix.
        $localpart = explode(":", $roomId)[0];
        foreach ($this->groupManager->search($localpart) as $group) {
            $groupId = $group->getGID();
            if (RoomGroup::isRoomGroupId($groupId) && str_contains($groupId, $localpart)) {
                return $groupId;
            }
        }

        return null;
    }

    /**
     * @return array{status: string, fileId: int|null, path: string|null, shareId: string|null}
     */
    public function resolveForUser(string $groupId, string $uid): array {
        $group = $this->groupManager->get($groupId);
        $user = $this->userManager->get($uid);
        if ($group === null || $user === null) {
            return $this->result("no-share");
        }

        $shareIds = $this->groupShareLocator->findGroupShareIds($groupId);
        if ($shareIds === []) {
            return $this->result("no-share");
        }

        if (!$group->inGroup($user)) {
            // Reported before looking at the shares: a non-member cannot see the
            // group share at all, so any other diagnosis would be misleading.
            return $this->result("not-member");
        }

        // A room is expected to have a single folder share, but the share is
        // recreated whenever the bound folder changes and history can leave more
        // than one behind. Prefer a reachable one, and report the most
        // actionable status otherwise.
        $fallback = null;
        foreach ($shareIds as $shareId) {
            $resolved = $this->resolveShare($shareId, $uid);
            if ($resolved["status"] === "ok") {
                return $resolved;
            }
            $fallback ??= $resolved;
        }

        return $fallback ?? $this->result("no-share");
    }

    /**
     * @return array{status: string, fileId: int|null, path: string|null, shareId: string|null}
     */
    private function resolveShare(string $shareId, string $uid): array {
        try {
            $share = $this->shareManager->getShareById("ocinternal:" . $shareId, $uid);
        } catch (ShareNotFound $e) {
            return $this->result("no-share");
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not resolve share $shareId for user $uid: " . $e->getMessage(),
                ["app" => "watcha", "exception" => $e]
            );
            return $this->result("no-share");
        }

        if ($share->getStatus() === IShare::STATUS_REJECTED) {
            // The one status that really denies access. PENDING does not: see the
            // class docblock on lazy child shares.
            return $this->result("rejected", $share->getNodeId(), null, $shareId);
        }

        try {
            // Only way to tell a share of a deleted folder from a working one.
            $share->getNode();
        } catch (NotFoundException $e) {
            return $this->result("deleted", null, null, $shareId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not reach the node of share $shareId for user $uid: " . $e->getMessage(),
                ["app" => "watcha", "exception" => $e]
            );
            return $this->result("deleted", null, null, $shareId);
        }

        return $this->result("ok", $share->getNodeId(), $share->getTarget(), $shareId);
    }

    /**
     * @return array{status: string, fileId: int|null, path: string|null, shareId: string|null}
     */
    private function result(
        string $status,
        ?int $fileId = null,
        ?string $path = null,
        ?string $shareId = null,
    ): array {
        return [
            "status" => $status,
            "fileId" => $fileId,
            "path" => $path,
            "shareId" => $shareId,
        ];
    }
}
