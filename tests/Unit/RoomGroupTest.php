<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit;

use OCA\Watcha\RoomGroup;
use PHPUnit\Framework\TestCase;

class RoomGroupTest extends TestCase {

    public function testBuildIdPrefixesTheRoomId(): void {
        $this->assertSame(
            "c4d96a06b7_!LaAcXIakmvSjMbNoeu:equipes.villeurbanne.fr",
            RoomGroup::buildId("!LaAcXIakmvSjMbNoeu:equipes.villeurbanne.fr")
        );
    }

    /**
     * Nextcloud refuses group ids longer than 64 characters and Synapse
     * truncates to match; the two must agree or the room ends up with two
     * groups and its resources become invisible to part of its members.
     */
    public function testBuildIdTruncatesToNextcloudLimit(): void {
        $roomId = "!" . str_repeat("a", 40) . ":a-very-long-server-name.example.org";
        $groupId = RoomGroup::buildId($roomId);

        $this->assertSame(RoomGroup::ID_MAX_LENGTH, strlen($groupId));
        $this->assertSame(substr(RoomGroup::ID_PREFIX . $roomId, 0, 64), $groupId);
    }

    public function testBuildIdHonoursACustomPrefix(): void {
        $this->assertSame(
            "0123456789_!room:example.org",
            RoomGroup::buildId("!room:example.org", "0123456789_")
        );
    }

    public function testIsRoomGroupIdAcceptsRoomGroups(): void {
        $this->assertTrue(RoomGroup::isRoomGroupId("c4d96a06b7_!abc:example.org"));
        // Any 10-hex prefix, so a deployment that changed the hash still works.
        $this->assertTrue(RoomGroup::isRoomGroupId("0123456789_!abc:example.org"));
    }

    public function testIsRoomGroupIdRejectsOrdinaryGroups(): void {
        // Ordinary Nextcloud groups must keep their standard, user-controlled
        // sharing behaviour: the listener relies on this to stay out of the way.
        $this->assertFalse(RoomGroup::isRoomGroupId("admin"));
        $this->assertFalse(RoomGroup::isRoomGroupId("Direction générale"));
        // Right shape but no room id (no leading '!').
        $this->assertFalse(RoomGroup::isRoomGroupId("c4d96a06b7_abc:example.org"));
        // Hash-like but not hex.
        $this->assertFalse(RoomGroup::isRoomGroupId("zzzzzzzzzz_!abc:example.org"));
        // Prefix in the middle rather than at the start.
        $this->assertFalse(RoomGroup::isRoomGroupId("x-c4d96a06b7_!abc:example.org"));
    }
}
