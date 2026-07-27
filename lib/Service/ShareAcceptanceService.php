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
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Accepts the pending Nextcloud shares that back a Watcha room's document space.
 *
 * Context
 * -------
 * A room's folder is shared with the room group (`<hash>_<room_id>`, share_type
 * = TYPE_GROUP). Nextcloud materialises that group share for each recipient as
 * a child share (share_type = TYPE_USER, `parent` = the group share id) holding
 * its own acceptance status. A member who joins the group *after* the group
 * share was created gets no child row, so nothing is mounted and the document
 * space answers "folder not found". `shareapi_auto_accept_share` does not apply
 * retroactively, which is why the historical workaround was to kick and
 * re-invite the member (that recreated the share and re-accepted the whole
 * group).
 *
 * Design notes
 * ------------
 * - Acceptance always goes through the public share API (`getShareById()` +
 *   `acceptShare()`). A direct `UPDATE` on `oc_share` is deliberately *not*
 *   used: it does not create the mount point and leaves the recipient in an
 *   inconsistent state.
 * - Discovery of the candidate group shares is delegated to
 *   {@see GroupShareLocator}, which reads `oc_share` by group id rather than
 *   using the recipient-centric `IShareManager::getSharedWith()`. See that
 *   class for why.
 * - Every write is retried with backoff: Watcha deployments may still run
 *   Synapse and Nextcloud against SQLite, where a single writer lock makes
 *   failures intermittent rather than deterministic.
 * - Operations are idempotent and failure is isolated per user: one member
 *   failing must never abort the batch, and must never fail silently either.
 */
class ShareAcceptanceService {

    private const RETRY_ATTEMPTS = 3;
    private const RETRY_BASE_DELAY_MICROSECONDS = 100000; // 100 ms

    public function __construct(
        private IShareManager $shareManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private GroupShareLocator $groupShareLocator,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether a group id designates a Watcha room group.
     */
    public function isRoomGroupId(string $groupId): bool {
        return RoomGroup::isRoomGroupId($groupId);
    }

    /**
     * Build the group id Synapse would use for a room, applying the same
     * truncation. The hash prefix cannot be derived from the room id, so an
     * existing group is looked up when the conventional prefix does not match.
     *
     * @return string|null the room group id, or null when the room has no group
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
            if ($this->isRoomGroupId($groupId) && str_contains($groupId, $localpart)) {
                return $groupId;
            }
        }

        return null;
    }

    /**
     * Accept every pending share that the given group grants to the given user.
     *
     * Idempotent: shares already accepted are left untouched.
     *
     * @return int the number of shares actually accepted
     */
    public function acceptPendingSharesForUser(string $uid, string $groupId): int {
        $accepted = 0;
        foreach ($this->groupShareLocator->findGroupShareIds($groupId) as $shareId) {
            if ($this->acceptShareForUser($shareId, $uid, $groupId)) {
                $accepted++;
            }
        }

        if ($accepted > 0) {
            $this->logger->info(
                "Accepted $accepted pending share(s) of group $groupId for user $uid",
                ["app" => "watcha"]
            );
        }

        return $accepted;
    }

    /**
     * Accept the pending shares of a group for all of its current members.
     *
     * A failure on one member is logged and does not interrupt the batch.
     *
     * @return int the number of shares actually accepted, across all members
     */
    public function acceptPendingSharesForGroup(string $groupId): int {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            $this->logger->warning(
                "Cannot accept shares: Nextcloud group $groupId does not exist",
                ["app" => "watcha"]
            );
            return 0;
        }

        $shareIds = $this->groupShareLocator->findGroupShareIds($groupId);
        if ($shareIds === []) {
            return 0;
        }

        $accepted = 0;
        foreach ($group->getUsers() as $user) {
            $uid = $user->getUID();
            foreach ($shareIds as $shareId) {
                if ($this->acceptShareForUser($shareId, $uid, $groupId)) {
                    $accepted++;
                }
            }
        }

        if ($accepted > 0) {
            $this->logger->info(
                "Accepted $accepted pending share(s) of group $groupId",
                ["app" => "watcha"]
            );
        }

        return $accepted;
    }

    /**
     * Per-user, per-share acceptance state, without mutating anything.
     *
     * Used to report a verifiable state to callers (the room member sync
     * endpoint) and to drive the `--dry-run` mode of the remediation command.
     *
     * @return array<int, array{shareId: string, status: string, target: string|null}>
     */
    public function inspectSharesForUser(string $uid, string $groupId): array {
        $report = [];
        foreach ($this->groupShareLocator->findGroupShareIds($groupId) as $shareId) {
            try {
                $share = $this->shareManager->getShareById("ocinternal:" . $shareId, $uid);
            } catch (ShareNotFound $e) {
                $report[] = ["shareId" => $shareId, "status" => "missing", "target" => null];
                continue;
            } catch (\Throwable $e) {
                $report[] = ["shareId" => $shareId, "status" => "error", "target" => null];
                continue;
            }

            $report[] = [
                "shareId" => $shareId,
                "status" => $share->getStatus() === IShare::STATUS_ACCEPTED ? "accepted" : "pending",
                "target" => $share->getTarget(),
            ];
        }

        return $report;
    }

    /**
     * Every room group that currently holds at least one group share.
     *
     * @return string[]
     */
    public function findRoomGroupsWithShares(): array {
        return $this->groupShareLocator->findRoomGroupsWithShares();
    }

    /**
     * Accept one share for one recipient, tolerating and reporting failure.
     *
     * @return bool true when this call moved the share to accepted
     */
    private function acceptShareForUser(string $shareId, string $uid, string $groupId): bool {
        if ($this->userManager->get($uid) === null) {
            $this->logger->warning(
                "Cannot accept share $shareId: Nextcloud user $uid does not exist",
                ["app" => "watcha"]
            );
            return false;
        }

        try {
            $share = $this->withRetry(
                fn () => $this->shareManager->getShareById("ocinternal:" . $shareId, $uid),
                "resolve share $shareId for user $uid"
            );

            if ($share->getStatus() === IShare::STATUS_ACCEPTED) {
                return false;
            }

            $this->withRetry(
                fn () => $this->shareManager->acceptShare($share, $uid),
                "accept share $shareId for user $uid"
            );

            $this->logger->info(
                "Accepted share $shareId of group $groupId for user $uid",
                ["app" => "watcha"]
            );
        } catch (\Throwable $e) {
            // Never abort the batch, and never fail silently.
            $this->logger->warning(
                "Failed to accept share $shareId of group $groupId for user $uid: " . $e->getMessage(),
                ["app" => "watcha", "exception" => $e]
            );
            return false;
        }

        // Build the recipient's mount points so the folder is reachable on the
        // very next request. Best effort on purpose: the share *is* accepted at
        // this point, and Nextcloud will mount it on first access anyway, so a
        // failure here must not be reported as a failed acceptance.
        try {
            $this->rootFolder->getUserFolder($uid)->getDirectoryListing();
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Accepted share $shareId for user $uid but could not build their mount points: " . $e->getMessage(),
                ["app" => "watcha", "exception" => $e]
            );
        }

        return true;
    }

    /**
     * Run an operation, retrying transient failures with exponential backoff.
     *
     * SQLite-backed deployments serialise writers, so a locked database is a
     * transient condition rather than a real error.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function withRetry(callable $operation, string $description) {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (ShareNotFound $e) {
                // Not transient: the share genuinely is not there.
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= self::RETRY_ATTEMPTS) {
                    throw $e;
                }
                $this->logger->debug(
                    "Retrying to $description (attempt $attempt failed: " . $e->getMessage() . ")",
                    ["app" => "watcha"]
                );
                usleep(self::RETRY_BASE_DELAY_MICROSECONDS * (2 ** ($attempt - 1)));
            }
        }
    }
}
