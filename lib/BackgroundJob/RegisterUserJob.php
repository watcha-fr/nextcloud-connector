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

namespace OCA\Watcha\BackgroundJob;

use OCA\Watcha\Service\SynapseRegistrar;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Déclare à Synapse un compte fraîchement créé dans Nextcloud.
 *
 * Pourquoi un travail différé plutôt qu'un traitement dans l'événement : au
 * moment où `UserCreatedEvent` est émis, `createUser()` vient tout juste de
 * rendre la main et **ni l'adresse ni le nom d'affichage ne sont encore
 * posés** — l'API de provisionnement les renseigne après. Un traitement
 * synchrone ne verrait qu'un identifiant. Cela évite en prime de suspendre la
 * création d'un compte Nextcloud à la disponibilité de Synapse.
 */
class RegisterUserJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private SynapseRegistrar $registrar,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{uid: string} $argument
     */
    protected function run($argument): void {
        $uid = $argument["uid"] ?? "";
        if ($uid === "") {
            return;
        }

        $user = $this->userManager->get($uid);
        if ($user === null) {
            // Créé puis supprimé avant que la file ne soit dépilée.
            $this->logger->info(
                "[watcha] compte disparu avant sa déclaration à Synapse",
                ["uid" => $uid]
            );
            return;
        }

        if ($this->registrar->isDeclared($uid)) {
            // L'écouteur a déclaré le compte sans attendre ce travail, qui
            // n'existe plus que comme repli.
            return;
        }

        $email = $user->getSystemEMailAddress() ?: $user->getEMailAddress();
        if (!$email) {
            // Keycloak a besoin d'une adresse. Sans elle, le compte reste
            // local à Nextcloud, et le dire vaut mieux que le taire. Rien à
            // reprendre : c'est l'arrivée de l'adresse qui déclarera le compte.
            $this->logger->warning(
                "[watcha] compte sans adresse : rien n'a été déclaré à Synapse",
                ["uid" => $uid]
            );
            return;
        }

        try {
            $this->registrar->registerUser(
                $uid,
                $email,
                $user->getDisplayName(),
                $this->groupManager->isInGroup($uid, SynapseRegistrar::PARTNER_GROUP),
                $this->groupManager->isAdmin($uid)
            );
            $this->registrar->markDeclared($uid);
        } catch (\Throwable $e) {
            // Le compte Nextcloud existe déjà ; le relancer plus tard est sans
            // danger, `watcha_register` étant idempotent. On remet donc le
            // travail en file — `QueuedJob` étant à usage unique, ne rien faire
            // ici le perdait, et le compte restait orphelin en silence.
            $this->logger->error(
                "[watcha] échec de la déclaration du compte à Synapse, remis en file",
                ["uid" => $uid, "exception" => $e]
            );
            $this->jobList->add(self::class, ["uid" => $uid]);
        }
    }
}
