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
use OCP\IUserSession;
use OCP\User\Events\UserCreatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Un compte créé dans Nextcloud doit exister dans Matrix et chez le
 * fournisseur d'identité.
 *
 * @template-implements IEventListener<UserCreatedEvent>
 */
class UserCreatedListener implements IEventListener {

    public function __construct(
        private IJobList $jobList,
        private IUserSession $userSession,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserCreatedEvent) {
            return;
        }

        if (!$this->registrar->isConfigured()) {
            return;
        }

        if ($this->wasCreatedByWatcha()) {
            // Synapse vient de créer ce compte : le lui redéclarer bouclerait,
            // avec un second courriel de bienvenue et une ligne d'audit en
            // double à la clé.
            return;
        }

        // Différé : l'adresse et le nom d'affichage ne sont pas encore posés à
        // cet instant, cf. RegisterUserJob.
        $this->jobList->add(RegisterUserJob::class, ["uid" => $event->getUid()]);

        $this->logger->info(
            "[watcha] compte Nextcloud à déclarer à Synapse",
            ["uid" => $event->getUid()]
        );
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
