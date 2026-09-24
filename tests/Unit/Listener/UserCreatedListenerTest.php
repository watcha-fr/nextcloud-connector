<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Watcha\BackgroundJob\RegisterUserJob;
use OCA\Watcha\Listener\UserCreatedListener;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserCreatedListenerTest extends TestCase {

    private const SERVICE_ACCOUNT = "watcha";

    private $jobList;
    private $userSession;
    private $registrar;
    private UserCreatedListener $listener;

    protected function setUp(): void {
        parent::setUp();

        $this->jobList = $this->createMock(IJobList::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registrar = $this->createMock(SynapseRegistrar::class);
        $this->registrar->method("getServiceAccount")
            ->willReturn(self::SERVICE_ACCOUNT);

        $this->listener = new UserCreatedListener(
            $this->jobList,
            $this->userSession,
            $this->registrar,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function event(string $uid = "jdupont"): UserCreatedEvent {
        $user = $this->createMock(IUser::class);
        $user->method("getUID")->willReturn($uid);
        return new UserCreatedEvent($user, "");
    }

    private function actingAs(?string $uid): void {
        if ($uid === null) {
            $this->userSession->method("getUser")->willReturn(null);
            return;
        }
        $actor = $this->createMock(IUser::class);
        $actor->method("getUID")->willReturn($uid);
        $this->userSession->method("getUser")->willReturn($actor);
    }

    public function testAnAccountCreatedByAnAdministratorIsQueued(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");

        $this->jobList->expects($this->once())
            ->method("add")
            ->with(RegisterUserJob::class, ["uid" => "jdupont"]);

        $this->listener->handle($this->event());
    }

    /**
     * Sans cette garde, l'écriture que Synapse fait dans Nextcloud réveillerait
     * le listener qui vient de l'appeler : boucle, second courriel de
     * bienvenue et ligne d'audit en double.
     */
    public function testAnAccountCreatedByWatchaItselfIsIgnored(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs(self::SERVICE_ACCOUNT);

        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->event());
    }

    /**
     * `occ` n'ouvre pas de session : c'est un compte créé par un
     * administrateur, il doit remonter.
     */
    public function testAnAccountCreatedWithoutASessionIsQueued(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs(null);

        $this->jobList->expects($this->once())->method("add");

        $this->listener->handle($this->event());
    }

    /**
     * Le connecteur est installé chez des clients dont Synapse n'est pas le
     * maître d'identité : sans configuration, il ne se passe rien.
     */
    public function testNothingHappensWithoutConfiguration(): void {
        $this->registrar->method("isConfigured")->willReturn(false);

        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->event());
    }

    public function testAnUnrelatedEventIsIgnored(): void {
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(new Event());
    }
}
