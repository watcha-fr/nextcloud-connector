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

use OCA\Files\Service\OwnershipTransferService;
use OCA\Watcha\RoomGroup;
use OCA\Watcha\Service\SynapseRegistrar;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Transmet les dossiers de salon avant que la suppression ne les détruise.
 *
 * Supprimer un compte Nextcloud détruit ses fichiers, et un dossier partagé au
 * salon appartient à celui qui l'a lié : son départ le faisait disparaître pour
 * **tous** les membres, sans préavis. La règle retenue est celle déjà arrêtée
 * pour la purge après rétention — le dossier revient au plus ancien membre
 * encore présent.
 *
 * Le moment compte. `BeforeUserDeletedEvent` est émis avant que Nextcloud pose
 * son drapeau de suppression et appelle le backend : lever ici laisse le compte
 * entier, donc le geste réessayable. C'est la seule fenêtre où les fichiers
 * existent encore et où l'on peut encore refuser.
 *
 * Ce transfert vaut pour les deux chemins de suppression. Qu'elle parte de
 * Nextcloud ou de Synapse — qui appelle l'OCS, lequel émet le même événement —
 * le dossier change de mains avant de disparaître.
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class RoomFolderInheritanceListener implements IEventListener {

    public function __construct(
        private IShareManager $shareManager,
        private IUserManager $userManager,
        private OwnershipTransferService $transferService,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof BeforeUserDeletedEvent)) {
            return;
        }
        if (!$this->registrar->isConfigured()) {
            return;
        }

        $user = $event->getUser();
        $uid = $user->getUID();

        foreach ($this->roomFolderShares($uid) as $share) {
            $roomId = $this->roomIdOf($share);
            if ($roomId === null) {
                continue;
            }

            $heirUid = $this->registrar->findRoomFolderHeir($roomId, $uid);
            if ($heirUid === null) {
                // Salon vide, ou n'y restent que des partenaires : personne ne
                // peut hériter. Le dossier suit le compte, et on le dit.
                $this->logger->warning(
                    "[watcha] aucun héritier pour ce dossier de salon, il sera détruit",
                    ["uid" => $uid, "room_id" => $roomId, "path" => $share->getNode()->getName()]
                );
                continue;
            }

            $this->transfer($user, $heirUid, $share, $roomId);
        }
    }

    /**
     * Les partages de cette personne qui adossent un dossier de salon.
     *
     * @return IShare[]
     */
    private function roomFolderShares(string $uid): array {
        $shares = $this->shareManager->getSharesBy(
            $uid,
            IShare::TYPE_GROUP,
            null,
            false,
            -1
        );

        return array_values(array_filter(
            $shares,
            fn(IShare $share) => RoomGroup::isRoomGroupId($share->getSharedWith())
        ));
    }

    /**
     * Le salon derrière un partage de groupe.
     *
     * L'identifiant de groupe est `<préfixe>_<room id>`, et Synapse le tronque
     * à 64 caractères : un identifiant de salon n'atteint jamais cette limite,
     * mais mieux vaut le lire tel quel que le reconstruire.
     */
    private function roomIdOf(IShare $share): ?string {
        $groupId = $share->getSharedWith();
        $separator = strpos($groupId, "_");
        if ($separator === false) {
            return null;
        }
        $roomId = substr($groupId, $separator + 1);
        return $roomId !== "" ? $roomId : null;
    }

    /**
     * @throws \Exception si le transfert échoue, ce qui interrompt la suppression
     */
    private function transfer(
        \OCP\IUser $source,
        string $heirUid,
        IShare $share,
        string $roomId,
    ): void {
        $heir = $this->userManager->get($heirUid);
        if ($heir === null) {
            throw new \RuntimeException(
                "[watcha] héritier introuvable dans Nextcloud : " . $heirUid
            );
        }

        $path = $share->getNode()->getName();

        $this->transferService->transfer($source, $heir, $path);

        $this->logger->info(
            "[watcha] dossier de salon transmis avant suppression",
            [
                "room_id" => $roomId,
                "from" => $source->getUID(),
                "to" => $heirUid,
                "path" => $path,
            ]
        );
    }
}
