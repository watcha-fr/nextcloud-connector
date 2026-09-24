<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Watcha\BackgroundJob\SyncLifecycleJob;
use OCA\Watcha\Listener\UserLifecycleListener;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserLifecycleListenerTest extends TestCase {

    private const SERVICE_ACCOUNT = "watcha";

    private $jobList;
    private $userSession;
    private $registrar;
    private UserLifecycleListener $listener;

    protected function setUp(): void {
        parent::setUp();

        $this->jobList = $this->createMock(IJobList::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registrar = $this->createMock(SynapseRegistrar::class);
        $this->registrar->method("getServiceAccount")
            ->willReturn(self::SERVICE_ACCOUNT);
        $this->registrar->method("isConfigured")->willReturn(true);

        $this->listener = new UserLifecycleListener(
            $this->jobList,
            $this->userSession,
            $this->registrar,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function user(string $uid = "jdupont"): IUser {
        $user = $this->createMock(IUser::class);
        $user->method("getUID")->willReturn($uid);
        return $user;
    }

    private function actingAs(?string $uid): void {
        if ($uid === null) {
            $this->userSession->method("getUser")->willReturn(null);
            return;
        }
        $this->userSession->method("getUser")->willReturn($this->user($uid));
    }

    public function testDeletionAsksForTheHardPath(): void {
        $this->actingAs("admin");

        $this->registrar->expects($this->once())
            ->method("notifyLifecycle")
            ->with("jdupont", "delete");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(new UserDeletedEvent($this->user()));
    }

    public function testDisablingAsksForTheReversiblePath(): void {
        $this->actingAs("admin");

        $this->registrar->expects($this->once())
            ->method("notifyLifecycle")
            ->with("jdupont", "disable");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "enabled", false, true)
        );
    }

    public function testEnablingBringsTheAccountBack(): void {
        $this->actingAs("admin");

        $this->registrar->expects($this->once())
            ->method("notifyLifecycle")
            ->with("jdupont", "enable");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "enabled", true, false)
        );
    }

    /**
     * Le geste est porté tout de suite, mais il ne doit pas se perdre si
     * Synapse ne répond pas : la file reprend le relais.
     */
    public function testAnUnreachableSynapseFallsBackToTheQueue(): void {
        $this->actingAs("admin");

        $this->registrar->method("notifyLifecycle")
            ->willThrowException(new \RuntimeException("connexion refusée"));

        $this->jobList->expects($this->once())
            ->method("add")
            ->with(SyncLifecycleJob::class, ["uid" => "jdupont", "action" => "disable"]);

        $this->listener->handle(
            new UserChangedEvent($this->user(), "enabled", false, true)
        );
    }

    /**
     * `UserChangedEvent` couvre bien des champs ; un changement de quota n'a
     * rien à voir avec le cycle de vie du compte.
     */
    public function testAnotherChangedFeatureIsIgnored(): void {
        $this->actingAs("admin");

        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "quota", "5 GB", "1 GB")
        );
    }

    /**
     * Synapse verrouille les comptes Nextcloud par son compte de service : lui
     * renvoyer l'écho de sa propre écriture bouclerait.
     */
    public function testWhatWatchaDidItselfIsIgnored(): void {
        $this->actingAs(self::SERVICE_ACCOUNT);

        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "enabled", false, true)
        );
    }

    public function testNothingHappensWithoutConfiguration(): void {
        $registrar = $this->createMock(SynapseRegistrar::class);
        $registrar->method("isConfigured")->willReturn(false);
        $listener = new UserLifecycleListener(
            $this->jobList,
            $this->userSession,
            $registrar,
            $this->createMock(LoggerInterface::class)
        );

        $this->jobList->expects($this->never())->method("add");

        $listener->handle(new UserDeletedEvent($this->user()));
    }

    public function testAnUnrelatedEventIsIgnored(): void {
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(new Event());
    }
}
