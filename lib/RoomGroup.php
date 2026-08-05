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

namespace OCA\Watcha;

/**
 * Naming convention of the Nextcloud group backing a Watcha room.
 *
 * Single source of truth inside this app: document sharing, calendar sharing
 * and the room member sync endpoint must all derive the *same* group id for a
 * given room, otherwise a room ends up with several groups and resources become
 * invisible to part of its members.
 *
 * Must stay in sync with `NEXTCLOUD_GROUP_ID_PREFIX` and
 * `NEXTCLOUD_GROUP_ID_LENGHT_LIMIT` in
 * `synapse/synapse/handlers/watcha_nextcloud.py`.
 */
final class RoomGroup {

    /** `echo -n watcha | md5sum | head -c 10`, then an underscore. */
    public const ID_PREFIX = "c4d96a06b7_";

    /** Nextcloud refuses group ids longer than this. */
    public const ID_MAX_LENGTH = 64;

    /**
     * A room group is `<10 hex chars>_<room id>`, and a Matrix room id always
     * starts with '!'. Matching the shape rather than a literal hash keeps the
     * detection valid for deployments that changed the prefix.
     */
    private const ID_PATTERN = '/^[0-9a-f]{10}_!/';

    /**
     * Before calendar and document sharing were unified onto the room group,
     * calendars were shared with a per-calendar group named after a raw sha256
     * hex digest. Those legacy groups have a frozen membership, disconnected from
     * room membership sync, which makes the calendar invisible to anyone who
     * joined the room after it was shared. They are migrated onto the room group
     * on the fly. The 64-hex shape is distinctive: a room group (`<prefix>_!…`)
     * never matches it, and neither does an ordinary Nextcloud group — which
     * keeps the destructive cleanup strictly to Watcha's own legacy groups.
     */
    private const LEGACY_CALENDAR_ID_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Build the group id of a room, applying Synapse's truncation.
     */
    public static function buildId(string $roomId, string $prefix = self::ID_PREFIX): string {
        return substr($prefix . $roomId, 0, self::ID_MAX_LENGTH);
    }

    /**
     * Whether a group id designates a Watcha room group rather than an ordinary
     * Nextcloud group.
     */
    public static function isRoomGroupId(string $groupId): bool {
        return preg_match(self::ID_PATTERN, $groupId) === 1;
    }

    /**
     * Whether a group id is a legacy per-calendar sha256 group that should be
     * migrated onto the room group and then removed.
     */
    public static function isLegacyCalendarGroupId(string $groupId): bool {
        return preg_match(self::LEGACY_CALENDAR_ID_PATTERN, $groupId) === 1;
    }
}
