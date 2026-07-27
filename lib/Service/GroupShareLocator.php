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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;

/**
 * Read-only discovery of the group shares that back room folders.
 *
 * Why SQL rather than `IShareManager::getSharedWith()`
 * ---------------------------------------------------
 * `getSharedWith()` is recipient-centric: it resolves the recipient's group
 * list through a request-scoped cache. Acceptance runs from a `UserAddedEvent`
 * listener, microseconds after the membership row was written, so that cache can
 * still be stale and the freshly joined group would be missing — exactly the
 * case we exist to repair. Reading `oc_share` by group id is cache-immune,
 * indexed, and mirrors the audit query used to size the problem.
 *
 * This class only ever reads. Every mutation goes through the public share API
 * in {@see ShareAcceptanceService}: a direct `UPDATE` on `oc_share` would not
 * create the recipient's mount point and would leave them in an inconsistent
 * state.
 */
class GroupShareLocator {

    public function __construct(
        private IDBConnection $connection,
    ) {
    }

    /**
     * The ids of the group shares held by a group.
     *
     * @return string[]
     */
    public function findGroupShareIds(string $groupId): array {
        $query = $this->connection->getQueryBuilder();
        $query->select("id")
            ->from("share")
            ->where($query->expr()->eq(
                "share_type",
                $query->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)
            ))
            ->andWhere($query->expr()->eq(
                "share_with",
                $query->createNamedParameter($groupId)
            ));

        $result = $query->executeQuery();
        $shareIds = [];
        while ($row = $result->fetch()) {
            $shareIds[] = (string)$row["id"];
        }
        $result->closeCursor();

        return $shareIds;
    }

    /**
     * Every room group that currently holds at least one group share.
     *
     * @return string[]
     */
    public function findRoomGroupsWithShares(): array {
        $query = $this->connection->getQueryBuilder();
        $query->selectDistinct("share_with")
            ->from("share")
            ->where($query->expr()->eq(
                "share_type",
                $query->createNamedParameter(IShare::TYPE_GROUP, IQueryBuilder::PARAM_INT)
            ));

        $result = $query->executeQuery();
        $groupIds = [];
        while ($row = $result->fetch()) {
            $groupId = (string)$row["share_with"];
            if (RoomGroup::isRoomGroupId($groupId)) {
                $groupIds[] = $groupId;
            }
        }
        $result->closeCursor();

        return $groupIds;
    }
}
