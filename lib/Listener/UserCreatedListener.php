<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026, Watcha <contact@watcha.fr>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
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

namespace OCA\Watcha\Listener;

use OCA\Watcha\BackgroundJob\RegisterUserJob;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Un compte créé dans Nextcloud doit exister dans Matrix et chez le
 * fournisseur d'identité.
 *
 * On le déclare dès qu'il est complet, sans attendre le cron. « Complet » veut
 * dire : porteur d'une adresse, puisque le fournisseur d'identité en exige une.
 * Or Nextcloud crée le compte d'abord et pose l'adresse ensuite — quelques
 * lignes plus loin dans `occ user:add`, quelques lignes plus loin dans la
 * console. On écoute donc les deux moments, et le premier qui nous donne un
 * compte complet l'emporte.
 *
 * @template-implements IEventListener<UserCreatedEvent|UserChangedEvent>
 */
class UserCreatedListener implements IEventListener {

    public function __construct(
        private IJobList $jobList,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$this->registrar->isConfigured()) {
            return;
        }

        if ($event instanceof UserCreatedEvent) {
            $user = $this->userManager->get($event->getUid());
            if ($user !== null) {
                $this->declare($user);
            }
            return;
        }

        // L'adresse arrive après la création : c'est elle qui rend le compte
        // déclarable, et c'est donc souvent ici que tout se joue.
        if ($event instanceof UserChangedEvent && $event->getFeature() === "eMailAddress") {
            $this->declare($event->getUser());
        }
    }

    private function declare(IUser $user): void {
        if ($this->wasCreatedByWatcha()) {
            // Synapse vient de créer ce compte : le lui redéclarer bouclerait,
            // avec un second courriel de bienvenue et une ligne d'audit en
            // double à la clé.
            return;
        }

        if ($this->registrar->isDeclared($user->getUID())) {
            return;
        }

        $email = $user->getSystemEMailAddress() ?: $user->getEMailAddress();
        if (!$email) {
            // Le compte n'est pas encore déclarable. On ne met rien en file et
            // on ne renonce pas : l'arrivée de l'adresse nous rappellera. Un
            // compte qui n'en recevrait jamais reste local à Nextcloud, ce qui
            // est le seul état correct sans identité à créer.
            return;
        }

        try {
            $this->registrar->registerUser(
                $user->getUID(),
                $email,
                $user->getDisplayName(),
                $this->isPartner($user),
                $this->isAdmin($user)
            );
            $this->registrar->markDeclared($user->getUID());
        } catch (\Throwable $e) {
            $this->logger->warning(
                "[watcha] Synapse injoignable, compte remis en file",
                ["uid" => $user->getUID(), "exception" => $e]
            );
            $this->jobList->add(
                RegisterUserJob::class,
                ["uid" => $user->getUID()]
            );
        }
    }

    /**
     * Le groupe dit le statut. Nextcloud ajoute aux groupes avant de poser
     * l'adresse — dans `occ user:add` comme dans la console — et c'est
     * l'adresse qui déclenche la déclaration : le groupe est donc connu quand
     * on arrive ici.
     *
     * ⚠️ Cela ne vaut qu'à la création. Synapse n'écrit le statut qu'une fois
     * et ne le resynchronise jamais : ajouter quelqu'un au groupe plus tard ne
     * le rendra pas partenaire dans Matrix.
     */
    private function isPartner(IUser $user): bool {
        return $this->groupManager->isInGroup(
            $user->getUID(),
            SynapseRegistrar::PARTNER_GROUP
        );
    }

    /**
     * Un compte créé administrateur ici doit l'être dans les trois systèmes,
     * comme lorsque le geste part de la console d'administration. Sans cela il
     * n'était administrateur que de son espace documentaire, et membre
     * ordinaire dans la messagerie et l'annuaire.
     *
     * `isAdmin()` plutôt que le groupe `admin` nommé en dur : c'est l'API que
     * Nextcloud expose pour cette question, et elle vaut quelle que soit la
     * façon dont l'installation range ses administrateurs.
     *
     * ⚠️ Même limite que pour le statut partenaire : cela ne vaut qu'à la
     * création. Promouvoir quelqu'un plus tard ne le rendra pas administrateur
     * dans Matrix — Synapse n'écrit ce statut qu'une fois.
     */
    private function isAdmin(IUser $user): bool {
        return $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * La garde anti-boucle ne stocke rien : elle regarde qui agit. Synapse
     * passe par l'API de provisionnement authentifié avec son compte de
     * service, ce qui suffit à le reconnaître.
     */
    private function wasCreatedByWatcha(): bool {
        $actor = $this->userSession->getUser();
        if ($actor === null) {
            // Ni session ni requête : `occ`, par exemple. C'est un compte créé
            // par un administrateur, il doit remonter.
            return false;
        }
        return $actor->getUID() === $this->registrar->getServiceAccount();
    }
}
