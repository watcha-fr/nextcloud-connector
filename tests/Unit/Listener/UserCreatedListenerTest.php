<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Watcha\BackgroundJob\RegisterUserJob;
use OCA\Watcha\Listener\UserCreatedListener;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserCreatedListenerTest extends TestCase {

    private const SERVICE_ACCOUNT = "watcha";

    private $jobList;
    private $userManager;
    private $userSession;
    private $registrar;
    private UserCreatedListener $listener;

    protected function setUp(): void {
        parent::setUp();

        $this->jobList = $this->createMock(IJobList::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registrar = $this->createMock(SynapseRegistrar::class);
        $this->registrar->method("getServiceAccount")
            ->willReturn(self::SERVICE_ACCOUNT);

        $this->listener = new UserCreatedListener(
            $this->jobList,
            $this->userManager,
            $this->userSession,
            $this->registrar,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function user(string $uid = "jdupont", ?string $email = "jdupont@example.org"): IUser {
        $user = $this->createMock(IUser::class);
        $user->method("getUID")->willReturn($uid);
        $user->method("getSystemEMailAddress")->willReturn($email);
        $user->method("getEMailAddress")->willReturn($email);
        $user->method("getDisplayName")->willReturn("Jean Dupont");
        return $user;
    }

    /** L'événement de création ne porte que l'identifiant : on relit le compte. */
    private function creation(IUser $user): UserCreatedEvent {
        $this->userManager->method("get")->willReturn($user);
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

    /**
     * Le cas courant : le compte naît avec son adresse, il part tout de suite.
     * Rien en file — c'est tout l'objet du changement.
     */
    public function testACompleteAccountIsDeclaredAtOnce(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");

        $this->registrar->expects($this->once())
            ->method("registerUser")
            ->with("jdupont", "jdupont@example.org", "Jean Dupont");
        $this->registrar->expects($this->once())->method("markDeclared")->with("jdupont");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->creation($this->user()));
    }

    /**
     * Nextcloud crée le compte d'abord et pose l'adresse ensuite. Sans elle il
     * n'y a rien à déclarer, et rien à mettre en file non plus : c'est
     * l'arrivée de l'adresse qui nous rappellera.
     */
    public function testAnAccountWithoutAnAddressWaits(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");

        $this->registrar->expects($this->never())->method("registerUser");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->creation($this->user("jdupont", null)));
    }

    /** L'adresse arrive : le compte devient déclarable, il part aussitôt. */
    public function testTheArrivingAddressDeclaresTheAccount(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");

        $this->registrar->expects($this->once())
            ->method("registerUser")
            ->with("jdupont", "jdupont@example.org", "Jean Dupont");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "eMailAddress", "jdupont@example.org", "")
        );
    }

    /** Un compte déjà déclaré ne repart pas si son adresse change plus tard. */
    public function testAnAlreadyDeclaredAccountIsNotDeclaredTwice(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->registrar->method("isDeclared")->willReturn(true);
        $this->actingAs("admin");

        $this->registrar->expects($this->never())->method("registerUser");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "eMailAddress", "autre@example.org", "jdupont@example.org")
        );
    }

    /** Les autres champs ne nous concernent pas. */
    public function testAnotherChangedFeatureIsIgnored(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");

        $this->registrar->expects($this->never())->method("registerUser");

        $this->listener->handle(
            new UserChangedEvent($this->user(), "quota", "5 GB", "1 GB")
        );
    }

    /**
     * Le geste doit survivre à une panne de Synapse : la file reprend le relais
     * plutôt que de perdre le compte.
     */
    public function testAnUnreachableSynapseFallsBackToTheQueue(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs("admin");
        $this->registrar->method("registerUser")
            ->willThrowException(new \RuntimeException("connexion refusée"));

        $this->registrar->expects($this->never())->method("markDeclared");
        $this->jobList->expects($this->once())
            ->method("add")
            ->with(RegisterUserJob::class, ["uid" => "jdupont"]);

        $this->listener->handle($this->creation($this->user()));
    }

    /**
     * Sans cette garde, l'écriture que Synapse fait dans Nextcloud réveillerait
     * le listener qui vient de l'appeler : boucle, second courriel de
     * bienvenue et ligne d'audit en double.
     */
    public function testAnAccountCreatedByWatchaItselfIsIgnored(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs(self::SERVICE_ACCOUNT);

        $this->registrar->expects($this->never())->method("registerUser");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->creation($this->user()));
    }

    /**
     * `occ` n'ouvre pas de session : c'est un compte créé par un
     * administrateur, il doit remonter.
     */
    public function testAnAccountCreatedWithoutASessionIsDeclared(): void {
        $this->registrar->method("isConfigured")->willReturn(true);
        $this->actingAs(null);

        $this->registrar->expects($this->once())->method("registerUser");

        $this->listener->handle($this->creation($this->user()));
    }

    /**
     * Le connecteur est installé chez des clients dont Synapse n'est pas le
     * maître d'identité : sans configuration, il ne se passe rien.
     */
    public function testNothingHappensWithoutConfiguration(): void {
        $this->registrar->method("isConfigured")->willReturn(false);

        $this->registrar->expects($this->never())->method("registerUser");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle($this->creation($this->user()));
    }

    public function testAnUnrelatedEventIsIgnored(): void {
        $this->registrar->method("isConfigured")->willReturn(true);

        $this->registrar->expects($this->never())->method("registerUser");
        $this->jobList->expects($this->never())->method("add");

        $this->listener->handle(new Event());
    }
}
