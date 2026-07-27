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

use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Resolves a room's document folder as it exists *for one specific user*.
 *
 * Why this endpoint exists
 * -----------------------
 * The client used to locate the folder by name, building a URL from the folder
 * label. A mount name is not stable: every recipient of a Nextcloud share may
 * rename their own mount (`oc_share.file_target` on the child share), and
 * Nextcloud appends a suffix on collision. The same folder can therefore be
 * `/Nouveau dossier` for its owner, `/Facilitateurs` for one member and
 * `/FACILITATEURS` for another — so a name resolved for one person 404s for
 * the next.
 *
 * The file id *is* stable. This resolver returns it, together with the path as
 * currently mounted for the caller, so the client never has to guess.
 *
 * It also distinguishes the reasons the folder may be unreachable, so the client
 * can act on them instead of showing one dead end with a "retry" button that
 * cannot change anything:
 *
 * - `ok`          the folder is reachable; `fileId` and `path` are usable
 * - `pending`     the share exists but has not been accepted for this user —
 *                 the caller should request a member sync and retry
 * - `not-member`  the user does not belong to the room group
 * - `deleted`     the share exists and is accepted, but the folder is gone
 * - `no-share`    no folder is bound to this room
 */
class RoomFolderResolver {

    public function __construct(
        private IShareManager $shareManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private GroupShareLocator $groupShareLocator,
        private LoggerInterface $logger,
    ) {
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

        if ($share->getStatus() !== IShare::STATUS_ACCEPTED) {
            // Deliberately still reports the file id: it is stable and valid, it
            // is only the mount that is missing. The client can show the folder
            // as soon as a member sync accepts the share.
            return $this->result("pending", $share->getNodeId(), null, $shareId);
        }

        try {
            // Only way to tell an accepted share of a deleted folder from a
            // working one.
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
