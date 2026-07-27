<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Service;

use OCA\Watcha\Service\GroupShareLocator;
use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
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
 * Scenarios mirror the ones measured on the affected deployment: members who
 * joined a room after its folder was shared, batches where a single member is
 * broken, and repeated runs of the remediation command.
 */
class ShareAcceptanceServiceTest extends TestCase {

    private const GROUP_ID = "c4d96a06b7_!room:example.org";

    private IShareManager&MockObject $shareManager;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private IRootFolder&MockObject $rootFolder;
    private GroupShareLocator&MockObject $locator;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private ShareAcceptanceService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->shareManager = $this->createMock(IShareManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->locator = $this->createMock(GroupShareLocator::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // By default every user exists and every user folder is reachable.
        $this->userManager->method("get")->willReturn($this->createMock(IUser::class));
        $this->rootFolder->method("getUserFolder")->willReturn($this->createMock(Folder::class));

        $this->service = new ShareAcceptanceService(
            $this->shareManager,
            $this->groupManager,
            $this->userManager,
            $this->rootFolder,
            $this->locator,
            $this->appConfig,
            $this->logger,
        );
    }

    private function share(int $status): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method("getStatus")->willReturn($status);
        $share->method("getTarget")->willReturn("/Nouveau dossier");
        return $share;
    }

    /**
     * The core regression: a member added to the group after the share was
     * created holds a pending share and must be accepted.
     */
    public function testAcceptsAPendingShareForALateJoiner(): void {
        $this->locator->method("findGroupShareIds")->with(self::GROUP_ID)->willReturn(["227"]);
        $pending = $this->share(IShare::STATUS_PENDING);
        $this->shareManager->method("getShareById")
            ->with("ocinternal:227", "alice")
            ->willReturn($pending);

        $this->shareManager->expects($this->once())
            ->method("acceptShare")
            ->with($pending, "alice");

        $this->assertSame(1, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    /**
     * The mount point must exist for the folder to be reachable on the very
     * next request; accepting the share alone is not enough.
     */
    public function testMaterialisesTheRecipientMountPoint(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_PENDING));

        $folder = $this->createMock(Folder::class);
        $folder->expects($this->once())->method("getDirectoryListing")->willReturn([]);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->expects($this->once())->method("getUserFolder")->with("alice")->willReturn($folder);

        $service = new ShareAcceptanceService(
            $this->shareManager,
            $this->groupManager,
            $this->userManager,
            $rootFolder,
            $this->locator,
            $this->appConfig,
            $this->logger,
        );

        $service->acceptPendingSharesForUser("alice", self::GROUP_ID);
    }

    /**
     * The share is accepted before the mount points are built, so a failure
     * there must not be reported as a failed acceptance — otherwise the occ
     * command's counters would claim the share is still pending when it is not.
     */
    public function testStillCountsTheShareWhenBuildingMountPointsFails(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_PENDING));

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method("getUserFolder")
            ->willThrowException(new \RuntimeException("cannot set up filesystem"));

        $service = new ShareAcceptanceService(
            $this->shareManager,
            $this->groupManager,
            $this->userManager,
            $rootFolder,
            $this->locator,
            $this->appConfig,
            $this->logger,
        );

        $this->shareManager->expects($this->once())->method("acceptShare");

        $this->assertSame(1, $service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    /**
     * Idempotence: the listener, the sync endpoint and the occ command may all
     * run over the same share. Only the first does anything.
     */
    public function testLeavesAnAlreadyAcceptedShareAlone(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_ACCEPTED));

        $this->shareManager->expects($this->never())->method("acceptShare");

        $this->assertSame(0, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    /**
     * A rejected share is a deliberate user decision on an ordinary share, but
     * for a room folder the room membership is the decision, so it is accepted.
     */
    public function testAcceptsAPreviouslyRejectedShare(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $rejected = $this->share(IShare::STATUS_REJECTED);
        $this->shareManager->method("getShareById")->willReturn($rejected);

        $this->shareManager->expects($this->once())->method("acceptShare")->with($rejected, "alice");

        $this->assertSame(1, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    public function testReportsNothingWhenTheGroupHoldsNoShare(): void {
        $this->locator->method("findGroupShareIds")->willReturn([]);
        $this->shareManager->expects($this->never())->method("acceptShare");

        $this->assertSame(0, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    /**
     * Worst case measured in production: share 227 had 182 members, none
     * accepted. The whole batch must be processed.
     */
    public function testAcceptsForEveryMemberOfTheGroup(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->groupManager->method("get")->with(self::GROUP_ID)
            ->willReturn($this->groupOf("alice", "bob", "carol"));
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_PENDING));

        $this->shareManager->expects($this->exactly(3))->method("acceptShare");

        $this->assertSame(3, $this->service->acceptPendingSharesForGroup(self::GROUP_ID));
    }

    /**
     * Failure isolation: one broken member must not deprive the others of
     * their folder, and the failure must be logged rather than swallowed.
     */
    public function testOneFailingMemberDoesNotAbortTheBatch(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->groupManager->method("get")->willReturn($this->groupOf("alice", "bob", "carol"));

        $this->shareManager->method("getShareById")->willReturnCallback(
            function (string $id, string $uid): IShare {
                if ($uid === "bob") {
                    throw new ShareNotFound("no share for bob");
                }
                return $this->share(IShare::STATUS_PENDING);
            }
        );

        $this->logger->expects($this->atLeastOnce())->method("warning");

        $this->assertSame(2, $this->service->acceptPendingSharesForGroup(self::GROUP_ID));
    }

    public function testSkipsAMemberWithNoNextcloudAccount(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method("get")->with("ghost")->willReturn(null);

        $service = new ShareAcceptanceService(
            $this->shareManager,
            $this->groupManager,
            $userManager,
            $this->rootFolder,
            $this->locator,
            $this->appConfig,
            $this->logger,
        );

        $this->shareManager->expects($this->never())->method("acceptShare");
        $this->logger->expects($this->atLeastOnce())->method("warning");

        $this->assertSame(0, $service->acceptPendingSharesForUser("ghost", self::GROUP_ID));
    }

    public function testReportsAMissingGroupWithoutThrowing(): void {
        $this->groupManager->method("get")->willReturn(null);
        $this->logger->expects($this->atLeastOnce())->method("warning");

        $this->assertSame(0, $this->service->acceptPendingSharesForGroup(self::GROUP_ID));
    }

    /**
     * SQLite serialises writers, so a locked database is transient. The write
     * must be retried rather than reported as a failure.
     */
    public function testRetriesATransientWriteFailure(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_PENDING));

        $attempts = 0;
        $this->shareManager->method("acceptShare")->willReturnCallback(
            function () use (&$attempts): IShare {
                $attempts++;
                if ($attempts === 1) {
                    throw new \RuntimeException("database is locked");
                }
                return $this->createMock(IShare::class);
            }
        );

        $this->assertSame(1, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
        $this->assertSame(2, $attempts, "the write should have been retried once");
    }

    public function testGivesUpAfterRepeatedFailuresAndLogs(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willReturn($this->share(IShare::STATUS_PENDING));
        $this->shareManager->method("acceptShare")
            ->willThrowException(new \RuntimeException("database is locked"));

        $this->logger->expects($this->atLeastOnce())->method("warning");

        $this->assertSame(0, $this->service->acceptPendingSharesForUser("alice", self::GROUP_ID));
    }

    public function testInspectReportsPerShareStatusWithoutMutating(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227", "8844"]);
        $this->shareManager->method("getShareById")->willReturnCallback(
            fn (string $id): IShare => $id === "ocinternal:227"
                ? $this->share(IShare::STATUS_ACCEPTED)
                : $this->share(IShare::STATUS_PENDING)
        );

        $this->shareManager->expects($this->never())->method("acceptShare");

        $this->assertSame(
            [
                ["shareId" => "227", "status" => "accepted", "target" => "/Nouveau dossier"],
                ["shareId" => "8844", "status" => "pending", "target" => "/Nouveau dossier"],
            ],
            $this->service->inspectSharesForUser("alice", self::GROUP_ID)
        );
    }

    public function testInspectReportsAMissingShare(): void {
        $this->locator->method("findGroupShareIds")->willReturn(["227"]);
        $this->shareManager->method("getShareById")->willThrowException(new ShareNotFound());

        $this->assertSame(
            [["shareId" => "227", "status" => "missing", "target" => null]],
            $this->service->inspectSharesForUser("alice", self::GROUP_ID)
        );
    }

    public function testFindRoomGroupIdUsesTheConventionalPrefix(): void {
        $this->appConfig->method("getValueString")->willReturnArgument(2);
        $this->groupManager->method("groupExists")->with(self::GROUP_ID)->willReturn(true);

        $this->assertSame(self::GROUP_ID, $this->service->findRoomGroupId("!room:example.org"));
    }

    /**
     * A deployment whose prefix hash differs must still resolve, otherwise the
     * sync endpoint would silently do nothing.
     */
    public function testFindRoomGroupIdFallsBackToASearch(): void {
        $this->appConfig->method("getValueString")->willReturnArgument(2);
        $this->groupManager->method("groupExists")->willReturn(false);

        $found = $this->createMock(IGroup::class);
        $found->method("getGID")->willReturn("0123456789_!room:example.org");
        $unrelated = $this->createMock(IGroup::class);
        $unrelated->method("getGID")->willReturn("some-other-group");

        $this->groupManager->method("search")->with("!room")->willReturn([$unrelated, $found]);

        $this->assertSame("0123456789_!room:example.org", $this->service->findRoomGroupId("!room:example.org"));
    }

    public function testFindRoomGroupIdReturnsNullWhenTheRoomHasNoGroup(): void {
        $this->appConfig->method("getValueString")->willReturnArgument(2);
        $this->groupManager->method("groupExists")->willReturn(false);
        $this->groupManager->method("search")->willReturn([]);

        $this->assertNull($this->service->findRoomGroupId("!room:example.org"));
    }

    private function groupOf(string ...$uids): IGroup&MockObject {
        $users = [];
        foreach ($uids as $uid) {
            $user = $this->createMock(IUser::class);
            $user->method("getUID")->willReturn($uid);
            $users[] = $user;
        }

        $group = $this->createMock(IGroup::class);
        $group->method("getUsers")->willReturn($users);
        return $group;
    }
}
