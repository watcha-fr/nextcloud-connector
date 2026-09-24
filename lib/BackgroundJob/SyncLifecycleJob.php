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
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Signale à Synapse la désactivation, la réactivation ou la suppression d'un
 * compte Nextcloud.
 *
 * Différé pour la même raison que la création : la disponibilité de Synapse ne
 * doit pas conditionner une opération d'administration dans Nextcloud.
 */
class SyncLifecycleJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{uid: string, action: string} $argument
     */
    protected function run($argument): void {
        $uid = $argument["uid"] ?? "";
        $action = $argument["action"] ?? "";
        if ($uid === "" || $action === "") {
            return;
        }

        try {
            $this->registrar->notifyLifecycle($uid, $action);
        } catch (\Throwable $e) {
            // Le compte reste dans l'état voulu ici ; c'est la propagation qui
            // a manqué. Relancer le travail est sans danger.
            $this->logger->error(
                "[watcha] échec du signalement du cycle de vie à Synapse",
                ["uid" => $uid, "action" => $action, "exception" => $e]
            );
        }
    }
}
