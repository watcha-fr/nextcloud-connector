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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\IShare;

/**
 * Read-only discovery of the group shares that back room folders.
 *
 * Keyed on the group id rather than going through the recipient-centric
 * `IShareManager::getSharedWith()`, which resolves the caller's group list via a
 * request-scoped cache. Reading `oc_share` by group id is cache-immune and
 * indexed.
 *
 * This class only ever reads.
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
}
