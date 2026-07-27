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

namespace OCA\Watcha\Listener;

use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use Psr\Log\LoggerInterface;

/**
 * Accepts a room folder's pending shares as soon as a member joins the room
 * group, so the document space is reachable on the member's next request.
 *
 * Relationship with Nextcloud's own listener
 * -----------------------------------------
 * `files_sharing` ships `UserAddedToGroupListener`, which covers the same
 * generic case. This listener deliberately duplicates part of that behaviour
 * for room groups only, because the upstream one is unsuitable here:
 *
 * - It is **discretionary**: it returns early unless the recipient's
 *   `files_sharing/default_accept` user preference resolves to `yes` and
 *   `sharing.force_share_accept` is off. A Watcha room's document space is not
 *   an optional personal share — membership of the room *is* the decision — so
 *   acceptance must not depend on a per-user sharing preference.
 * - It discovers shares with `getSharedWith()`, which resolves the recipient's
 *   group list through a request-scoped cache. We are invoked microseconds
 *   after the membership was written, so that list can still be stale; this
 *   listener discovers by group id instead (see ShareAcceptanceService).
 * - It does not materialise the recipient's mount points, and it neither logs
 *   nor retries, so a transient SQLite write lock leaves no trace.
 *
 * Both listeners running is harmless: acceptance is idempotent.
 *
 * Only room groups are touched (`<hash>_!<room id>`); ordinary Nextcloud groups
 * keep their standard, user-controlled sharing behaviour.
 *
 * @template-implements IEventListener<UserAddedEvent>
 */
class RoomGroupMembershipListener implements IEventListener {

    public function __construct(
        private ShareAcceptanceService $shareAcceptanceService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserAddedEvent)) {
            return;
        }

        $groupId = $event->getGroup()->getGID();
        if (!$this->shareAcceptanceService->isRoomGroupId($groupId)) {
            return;
        }

        $uid = $event->getUser()->getUID();

        // The listener runs inside the request that changed the membership
        // (typically Synapse calling the provisioning API). It must never make
        // that request fail, whatever happens here.
        try {
            $this->shareAcceptanceService->acceptPendingSharesForUser($uid, $groupId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Failed to accept pending shares of room group $groupId for user $uid: " . $e->getMessage(),
                ["app" => "watcha", "exception" => $e]
            );
        }
    }
}
