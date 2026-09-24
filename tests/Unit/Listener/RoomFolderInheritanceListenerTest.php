<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Files\Service\OwnershipTransferService;
use OCA\Watcha\Listener\RoomFolderInheritanceListener;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\EventDispatcher\Event;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\User\Events\BeforeUserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Le dossier d'un salon appartient à celui qui l'a lié : sa suppression le
 * détruisait pour tous les membres. Il doit changer de mains avant.
 */
class RoomFolderInheritanceListenerTest extends TestCase {

    private const ROOM_ID = "!abc:watchatest.watcha.fr";
    private const GROUP_ID = "c4d96a06b7_!abc:watchatest.watcha.fr";

    private $shareManager;
    private $userManager;
    private $transferService;
    private $registrar;
    private RoomFolderInheritanceListener $listener;

    protected function setUp(): void {
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->transferService = $this->createMock(OwnershipTransferService::class);
        $this->registrar = $this->createMock(SynapseRegistrar::class);
        $this->registrar->method("isConfigured")->willReturn(true);

        $this->listener = new RoomFolderInheritanceListener(
            $this->shareManager,
            $this->userManager,
            $this->transferService,
            $this->registrar,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function user(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method("getUID")->willReturn($uid);
        return $user;
    }

    private function share(string $groupId, string $name = "Dossier du salon"): IShare {
        $node = $this->createMock(Node::class);
        $node->method("getName")->willReturn($name);

        $share = $this->createMock(IShare::class);
        $share->method("getSharedWith")->willReturn($groupId);
        $share->method("getNode")->willReturn($node);
        return $share;
    }

    public function testTheFolderGoesToTheHeirBeforeTheAccountIsDestroyed(): void {
        $leaving = $this->user("partant");
        $heir = $this->user("heritier");

        $this->shareManager->method("getSharesBy")
            ->willReturn([$this->share(self::GROUP_ID)]);
        $this->registrar->expects($this->once())
            ->method("findRoomFolderHeir")
            ->with(self::ROOM_ID, "partant")
            ->willReturn("heritier");
        $this->userManager->method("get")->with("heritier")->willReturn($heir);

        $this->transferService->expects($this->once())
            ->method("transfer")
            ->with($leaving, $heir, "Dossier du salon");

        $this->listener->handle(new BeforeUserDeletedEvent($leaving));
    }

    public function testWithoutAnHeirTheFolderIsLeftToDie(): void {
        $leaving = $this->user("partant");

        $this->shareManager->method("getSharesBy")
            ->willReturn([$this->share(self::GROUP_ID)]);
        $this->registrar->method("findRoomFolderHeir")->willReturn(null);

        $this->transferService->expects($this->never())->method("transfer");

        $this->listener->handle(new BeforeUserDeletedEvent($leaving));
    }

    public function testAnOrdinaryGroupShareIsLeftAlone(): void {
        $leaving = $this->user("partant");

        $this->shareManager->method("getSharesBy")
            ->willReturn([$this->share("comptabilite")]);

        $this->registrar->expects($this->never())->method("findRoomFolderHeir");
        $this->transferService->expects($this->never())->method("transfer");

        $this->listener->handle(new BeforeUserDeletedEvent($leaving));
    }

    public function testAFailedTransferAbortsTheDeletion(): void {
        $leaving = $this->user("partant");
        $heir = $this->user("heritier");

        $this->shareManager->method("getSharesBy")
            ->willReturn([$this->share(self::GROUP_ID)]);
        $this->registrar->method("findRoomFolderHeir")->willReturn("heritier");
        $this->userManager->method("get")->willReturn($heir);
        $this->transferService->method("transfer")
            ->willThrowException(new \RuntimeException("quota insuffisant"));

        $this->expectException(\RuntimeException::class);

        $this->listener->handle(new BeforeUserDeletedEvent($leaving));
    }

    public function testAnHeirUnknownToNextcloudAbortsTheDeletion(): void {
        $leaving = $this->user("partant");

        $this->shareManager->method("getSharesBy")
            ->willReturn([$this->share(self::GROUP_ID)]);
        $this->registrar->method("findRoomFolderHeir")->willReturn("fantome");
        $this->userManager->method("get")->willReturn(null);

        $this->transferService->expects($this->never())->method("transfer");
        $this->expectException(\RuntimeException::class);

        $this->listener->handle(new BeforeUserDeletedEvent($leaving));
    }

    public function testAnInstanceWithoutSynapseIsUntouched(): void {
        $registrar = $this->createMock(SynapseRegistrar::class);
        $registrar->method("isConfigured")->willReturn(false);
        $registrar->expects($this->never())->method("findRoomFolderHeir");

        $listener = new RoomFolderInheritanceListener(
            $this->shareManager,
            $this->userManager,
            $this->transferService,
            $registrar,
            $this->createMock(LoggerInterface::class),
        );

        $this->transferService->expects($this->never())->method("transfer");

        $listener->handle(new BeforeUserDeletedEvent($this->user("partant")));
    }
}
