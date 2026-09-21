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

use OCA\Watcha\BackgroundJob\SyncLifecycleJob;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Ce qui arrive à un compte ici doit arriver à ses jumeaux.
 *
 * Les deux gestes n'ont pas la même portée : désactiver est réversible des
 * trois côtés, supprimer emporte le compte du fournisseur d'identité et efface
 * le compte Matrix.
 *
 * @template-implements IEventListener<UserDeletedEvent|UserChangedEvent>
 */
class UserLifecycleListener implements IEventListener {

    public function __construct(
        private IJobList $jobList,
        private IUserSession $userSession,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$this->registrar->isConfigured()) {
            return;
        }

        if ($event instanceof UserDeletedEvent) {
            $this->propagate($event->getUid(), "delete");
            return;
        }

        if ($event instanceof UserChangedEvent && $event->getFeature() === "enabled") {
            $this->propagate(
                $event->getUser()->getUID(),
                $event->getValue() ? "enable" : "disable"
            );
        }
    }

    private function propagate(string $uid, string $action): void {
        if ($this->wasDoneByWatcha()) {
            // Synapse verrouille les comptes Nextcloud par son compte de
            // service : lui renvoyer l'écho de sa propre écriture bouclerait.
            return;
        }

        $this->jobList->add(
            SyncLifecycleJob::class,
            ["uid" => $uid, "action" => $action]
        );

        $this->logger->info(
            "[watcha] cycle de vie du compte à signaler à Synapse",
            ["uid" => $uid, "action" => $action]
        );
    }

    private function wasDoneByWatcha(): bool {
        $actor = $this->userSession->getUser();
        if ($actor === null) {
            return false;
        }
        return $actor->getUID() === $this->registrar->getServiceAccount();
    }
}
