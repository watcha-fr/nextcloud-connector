<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Service;

use OCA\Watcha\Service\GroupShareLocator;
use OCA\Watcha\Service\RoomFolderResolver;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * The folder must be resolved by file id, never by name: a mount name is
 * per-recipient, so the same folder is `/Nouveau dossier` for its owner and
 * `/FACILITATEURS` for a member who renamed it.
 */
class RoomFolderResolverTest extends TestCase {

    private const GROUP_ID = "c4d96a06b7_!room:example.org";

    private IShareManager&MockObject $shareManager;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private GroupShareLocator&MockObject $locator;
    private RoomFolderResolver $resolver;

    protected function setUp(): void {
        parent::setUp();

        $this->shareManager = $this->createMock(IShareManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->locator = $this->createMock(GroupShareLocator::class);

        $this->userManager->method("get")->willReturn($this->createMock(IUser::class));
        $this->groupManager->method("get")->willReturn($this->groupContaining(true));

        $this->resolver = new RoomFolderResolver(
            $this->shareManager,
            $this->groupManager,
            $this->userManager,
            $this->locator,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function groupContaining(bool $isMember): IGroup&MockObject {
        $group = $this->createMock(IGroup::class);
        $group->method("inGroup")->willReturn($isMember);
        return $group;
    }

    private function share(int $status, string $target, bool $nodeExists = true): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method("getStatus")->willReturn($status);
        $share->method("getNodeId")->willReturn(59);
        $share->method("getTarget")->willReturn($target);
        if ($nodeExists) {
            $share->method("getNode")->willReturn($this->createMock(Node::class));
        } else {
            $share->method("getNode")->willThrowException(new NotFoundException());
        }
        return $share;
    }

    public function testReturnsTheFileIdAndTheMountedPath(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);
        $this->shareManager->method("getShareById")
            ->willReturn($this->share(IShare::STATUS_ACCEPTED, "/FACILITATEURS"));

        $this->assertSame(
            ["status" => "ok", "fileId" => 59, "path" => "/FACILITATEURS", "shareId" => "59"],
            $this->resolver->resolveForUser(self::GROUP_ID, "alice")
        );
    }

    /**
     * Same folder, different mount names per recipient. The file id is identical,
     * which is precisely why the client must use it.
     */
    public function testReportsThePerRecipientPathWhileTheFileIdStaysStable(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);
        $this->shareManager->method("getShareById")->willReturnCallback(
            fn (string $id, string $uid): IShare => $this->share(
                IShare::STATUS_ACCEPTED,
                $uid === "alice" ? "/Facilitateurs" : "/FACILITATEURS"
            )
        );

        $alice = $this->resolver->resolveForUser(self::GROUP_ID, "alice");
        $bob = $this->resolver->resolveForUser(self::GROUP_ID, "bob");

        $this->assertSame("/Facilitateurs", $alice["path"]);
        $this->assertSame("/FACILITATEURS", $bob["path"]);
        $this->assertSame($alice["fileId"], $bob["fileId"]);
    }

    /**
     * The case behind the whole fix: a member who joined after the folder was
     * shared. Reported as recoverable, and the file id is returned even though
     * nothing is mounted yet.
     */
    public function testReportsAPendingShareAsRecoverable(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);
        $this->shareManager->method("getShareById")
            ->willReturn($this->share(IShare::STATUS_PENDING, "/Nouveau dossier"));

        $result = $this->resolver->resolveForUser(self::GROUP_ID, "alice");

        $this->assertSame("pending", $result["status"]);
        $this->assertSame(59, $result["fileId"]);
        $this->assertNull($result["path"], "nothing is mounted yet, so there is no path to report");
    }

    public function testReportsANonMemberBeforeLookingAtShares(): void {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method("get")->willReturn($this->groupContaining(false));
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);

        $resolver = new RoomFolderResolver(
            $this->shareManager,
            $groupManager,
            $this->userManager,
            $this->locator,
            $this->createMock(LoggerInterface::class),
        );

        // Any other diagnosis would be misleading: a non-member cannot see the
        // group share at all.
        $this->shareManager->expects($this->never())->method("getShareById");

        $this->assertSame("not-member", $resolver->resolveForUser(self::GROUP_ID, "alice")["status"]);
    }

    /**
     * An accepted share whose folder is gone is the one case the client must not
     * try to repair — a sync would succeed and change nothing.
     */
    public function testReportsADeletedFolder(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);
        $this->shareManager->method("getShareById")
            ->willReturn($this->share(IShare::STATUS_ACCEPTED, "/Nouveau dossier", nodeExists: false));

        $result = $this->resolver->resolveForUser(self::GROUP_ID, "alice");

        $this->assertSame("deleted", $result["status"]);
        $this->assertNull($result["fileId"]);
    }

    public function testReportsNoShareWhenNoFolderIsBound(): void {
        $this->locator->method("findGroupShareIds")->willReturn([]);

        $this->assertSame("no-share", $this->resolver->resolveForUser(self::GROUP_ID, "alice")["status"]);
    }

    public function testReportsNoShareWhenTheGroupDoesNotExist(): void {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method("get")->willReturn(null);

        $resolver = new RoomFolderResolver(
            $this->shareManager,
            $groupManager,
            $this->userManager,
            $this->locator,
            $this->createMock(LoggerInterface::class),
        );

        $this->assertSame("no-share", $resolver->resolveForUser(self::GROUP_ID, "alice")["status"]);
    }

    /**
     * Rebinding a room to another folder leaves the previous share behind, so a
     * reachable folder must win over a stale one whatever the order.
     */
    public function testPrefersAReachableShareOverAStaleOne(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["11", "59"]);
        $this->shareManager->method("getShareById")->willReturnCallback(
            fn (string $id): IShare => $id === "ocinternal:11"
                ? $this->share(IShare::STATUS_ACCEPTED, "/Ancien", nodeExists: false)
                : $this->share(IShare::STATUS_ACCEPTED, "/Nouveau dossier")
        );

        $result = $this->resolver->resolveForUser(self::GROUP_ID, "alice");

        $this->assertSame("ok", $result["status"]);
        $this->assertSame("59", $result["shareId"]);
    }

    public function testSurvivesAShareThatCannotBeResolved(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["59"]);
        $this->shareManager->method("getShareById")->willThrowException(new ShareNotFound());

        $this->assertSame("no-share", $this->resolver->resolveForUser(self::GROUP_ID, "alice")["status"]);
    }
}
