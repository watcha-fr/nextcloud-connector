<?php

declare(strict_types=1);

namespace OCA\Watcha\Tests\Unit\Listener;

use OCA\Watcha\Listener\EmailShareListener;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EmailShareListenerTest extends TestCase {

    private const EMAIL = "jean.dupont@exterieur.fr";

    private $userManager;
    private $registrar;
    private EmailShareListener $listener;

    protected function setUp(): void {
        parent::setUp();

        $this->userManager = $this->createMock(IUserManager::class);
        $this->registrar = $this->createMock(SynapseRegistrar::class);
        $this->registrar->method("isConfigured")->willReturn(true);

        $this->listener = new EmailShareListener(
            $this->userManager,
            $this->registrar,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function share(int $type = IShare::TYPE_EMAIL, string $with = self::EMAIL) {
        $share = $this->createMock(IShare::class);
        $share->method("getShareType")->willReturn($type);
        $share->method("getSharedWith")->willReturn($with);
        $share->method("getSharedBy")->willReturn("apartageur");
        return $share;
    }

    private function knownAs(string ...$uids): void {
        $users = [];
        foreach ($uids as $uid) {
            $u = $this->createMock(IUser::class);
            $u->method("getUID")->willReturn($uid);
            $users[] = $u;
        }
        $this->userManager->method("getByEmail")->willReturn($users);
    }

    /**
     * Le cas de la demande : une adresse que personne ne porte. La personne est
     * créée partenaire — c'est Synapse qui la reclassera en membre si son
     * domaine appartient à l'organisation — et le fichier atterrit chez elle.
     */
    public function testAnUnknownAddressCreatesThePerson(): void {
        $this->knownAs();
        $this->userManager->method("get")->willReturn($this->createMock(IUser::class));

        $this->registrar->expects($this->once())
            ->method("registerUser")
            ->with(null, self::EMAIL, null, true, false, "apartageur")
            ->willReturn("jean.dupont");

        $share = $this->share();
        $share->expects($this->once())->method("setShareType")->with(IShare::TYPE_USER);
        $share->expects($this->once())->method("setSharedWith")->with("jean.dupont");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    /** Une adresse déjà connue ne crée personne : elle reçoit le partage. */
    public function testAKnownAddressOnlyGetsTheShare(): void {
        $this->knownAs("jean.dupont");

        $this->registrar->expects($this->never())->method("registerUser");

        $share = $this->share();
        $share->expects($this->once())->method("setShareType")->with(IShare::TYPE_USER);
        $share->expects($this->once())->method("setSharedWith")->with("jean.dupont");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    /**
     * Le même champ accepte un identifiant de cloud fédéré. Y toucher casserait
     * la fédération, qui doit continuer de partir vers l'autre instance.
     */
    public function testAFederatedShareIsLeftAlone(): void {
        $this->registrar->expects($this->never())->method("registerUser");

        $share = $this->share(IShare::TYPE_REMOTE, "jean@autre-instance.fr");
        $share->expects($this->never())->method("setShareType");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    public function testALinkShareIsLeftAlone(): void {
        $this->registrar->expects($this->never())->method("registerUser");

        $share = $this->share(IShare::TYPE_LINK, "");
        $share->expects($this->never())->method("setShareType");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    /**
     * Le connecteur sert aussi des instances dont Synapse n'est pas le maître
     * d'identité : le partage par courriel y garde son comportement d'origine.
     */
    public function testNothingChangesWithoutConfiguration(): void {
        $registrar = $this->createMock(SynapseRegistrar::class);
        $registrar->method("isConfigured")->willReturn(false);
        $registrar->expects($this->never())->method("registerUser");

        $listener = new EmailShareListener(
            $this->userManager,
            $registrar,
            $this->createMock(LoggerInterface::class)
        );

        $share = $this->share();
        $share->expects($this->never())->method("setShareType");

        $listener->handle(new BeforeShareCreatedEvent($share));
    }

    /**
     * Synapse injoignable : mieux vaut refuser le partage que fabriquer un lien
     * à jeton vers quelqu'un qui n'existe nulle part. Rien n'est encore écrit à
     * ce stade, l'interruption ne laisse donc aucun résidu.
     */
    public function testAnUnreachableSynapseAbortsTheShare(): void {
        $this->knownAs();
        $this->registrar->method("registerUser")
            ->willThrowException(new \RuntimeException("connexion refusée"));

        $share = $this->share();
        $share->expects($this->never())->method("setShareType");

        $event = new BeforeShareCreatedEvent($share);
        $this->listener->handle($event);

        $this->assertNotNull($event->getError());
        $this->assertTrue($event->isPropagationStopped());
    }

    /**
     * Deux comptes pour une adresse : on ne devine pas lequel, et en créer un
     * troisième serait pire. Le partage par courriel part comme avant.
     */
    public function testAnAmbiguousAddressCreatesNobody(): void {
        $this->knownAs("jean.dupont", "j.dupont");

        $this->registrar->expects($this->never())->method("registerUser");

        $share = $this->share();
        $share->expects($this->never())->method("setShareType");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    /**
     * Toutes les instances ne donnent pas d'espace documentaire aux
     * partenaires. Quand le compte naît sans, il n'y a personne à qui
     * partager : le courriel reste le bon chemin.
     */
    public function testAnAccountWithoutADocumentSpaceKeepsTheMailShare(): void {
        $this->knownAs();
        $this->userManager->method("get")->willReturn(null);
        $this->registrar->method("registerUser")->willReturn("jean.dupont");

        $share = $this->share();
        $share->expects($this->never())->method("setShareType");

        $this->listener->handle(new BeforeShareCreatedEvent($share));
    }

    public function testAnUnrelatedEventIsIgnored(): void {
        $this->registrar->expects($this->never())->method("registerUser");

        $this->listener->handle(new Event());
    }
}
