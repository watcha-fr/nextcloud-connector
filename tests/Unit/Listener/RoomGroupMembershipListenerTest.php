<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Watcha\Listener\RoomGroupMembershipListener;
use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RoomGroupMembershipListenerTest extends TestCase {

    private ShareAcceptanceService&MockObject $service;
    private LoggerInterface&MockObject $logger;
    private RoomGroupMembershipListener $listener;

    protected function setUp(): void {
        parent::setUp();

        $this->service = $this->createMock(ShareAcceptanceService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new RoomGroupMembershipListener($this->service, $this->logger);

        // Delegate the room-group test to the real implementation so the
        // listener's gating is exercised against the actual naming rule.
        $this->service->method("isRoomGroupId")->willReturnCallback(
            fn (string $gid): bool => preg_match('/^[0-9a-f]{10}_!/', $gid) === 1
        );
    }

    private function event(string $groupId, string $uid): UserAddedEvent {
        $group = $this->createMock(IGroup::class);
        $group->method("getGID")->willReturn($groupId);
        $user = $this->createMock(IUser::class);
        $user->method("getUID")->willReturn($uid);

        return new UserAddedEvent($group, $user);
    }

    public function testAcceptsPendingSharesWhenAMemberJoinsARoomGroup(): void {
        $this->service->expects($this->once())
            ->method("acceptPendingSharesForUser")
            ->with("alice", "c4d96a06b7_!room:example.org")
            ->willReturn(1);

        $this->listener->handle($this->event("c4d96a06b7_!room:example.org", "alice"));
    }

    /**
     * Ordinary Nextcloud groups must keep Nextcloud's own, user-controlled
     * sharing behaviour: this app has no business auto-accepting there.
     */
    public function testIgnoresOrdinaryGroups(): void {
        $this->service->expects($this->never())->method("acceptPendingSharesForUser");

        $this->listener->handle($this->event("Direction générale", "alice"));
    }

    public function testIgnoresUnrelatedEvents(): void {
        $this->service->expects($this->never())->method("acceptPendingSharesForUser");

        $group = $this->createMock(IGroup::class);
        $user = $this->createMock(IUser::class);
        $this->listener->handle(new UserRemovedEvent($group, $user));
    }

    /**
     * The listener runs inside the request that changed the membership —
     * typically Synapse calling the provisioning API. A failure here must be
     * logged, but must never make that request fail: the member is in the room
     * either way, and the sync endpoint and occ command remain as recovery.
     */
    public function testNeverPropagatesAFailureToTheCaller(): void {
        $this->service->method("acceptPendingSharesForUser")
            ->willThrowException(new \RuntimeException("database is locked"));

        $this->logger->expects($this->once())->method("warning");

        $this->listener->handle($this->event("c4d96a06b7_!room:example.org", "alice"));
    }
}
