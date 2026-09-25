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

use OCA\Watcha\Service\SynapseRegistrar;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Partager un fichier à une adresse inconnue crée la personne.
 *
 * Le champ « Partages externes » de Nextcloud accepte une adresse de courriel.
 * Jusqu'ici, elle produisait un partage à jeton : un lien, pas un compte. La
 * personne recevait le fichier sans exister nulle part, donc sans messagerie,
 * sans annuaire, et sans rien qui la relie à l'organisation.
 *
 * Désormais le geste crée la personne dans les trois systèmes, puis le partage
 * devient un partage utilisateur ordinaire — le fichier atterrit dans son
 * espace documentaire au lieu de pendre au bout d'une URL.
 *
 * Elle est créée **partenaire**, puisqu'elle vient de l'extérieur. Synapse la
 * reclasse en membre de plein droit si son adresse relève d'un domaine de
 * l'organisation : la règle vit dans `watcha.partner_email_whitelist`, côté
 * serveur, et c'est bien là qu'il faut la lire — pas ici.
 *
 * ⚠️ Le même champ accepte aussi un identifiant de cloud fédéré. On ne touche
 * qu'au type `TYPE_EMAIL` : un partage fédéré doit continuer de partir vers
 * l'autre instance, sans créer personne.
 *
 * @template-implements IEventListener<BeforeShareCreatedEvent>
 */
class EmailShareListener implements IEventListener {

    public function __construct(
        private IUserManager $userManager,
        private SynapseRegistrar $registrar,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeShareCreatedEvent) {
            return;
        }

        if (!$this->registrar->isConfigured()) {
            // Instance sans Synapse pour maître d'identité : le partage par
            // courriel garde son comportement d'origine.
            return;
        }

        $share = $event->getShare();
        if ($share->getShareType() !== IShare::TYPE_EMAIL) {
            return;
        }

        $email = trim((string)$share->getSharedWith());
        if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Nextcloud a déjà validé ; si ce n'est pas une adresse, ce n'est
            // pas notre affaire.
            return;
        }

        $connus = $this->userManager->getByEmail($email);
        if (count($connus) > 1) {
            // Deux comptes pour une adresse : on ne devine pas lequel, et en
            // créer un troisième serait pire. Le partage par courriel part
            // comme avant, ce qui est le comportement le moins surprenant.
            $this->logger->warning(
                "[watcha] plusieurs comptes portent cette adresse, partage laissé en courriel",
                ["email" => $email, "nombre" => count($connus)]
            );
            return;
        }

        $username = count($connus) === 1 ? $connus[0]->getUID() : null;

        if ($username === null) {
            try {
                $username = $this->registrar->registerUser(
                    // Aucun nom à imposer : le compte n'existe pas encore ici,
                    // c'est Synapse qui le dérive de l'adresse et le déduplique.
                    null,
                    $email,
                    null,
                    true,
                    false,
                    $share->getSharedBy()
                );
            } catch (\Throwable $e) {
                // Échouer franchement plutôt que de créer un partage à jeton
                // que personne n'attend. Le partage n'a pas encore été écrit :
                // arrêter ici ne laisse rien derrière.
                $this->logger->error(
                    "[watcha] création du compte impossible, partage interrompu",
                    ["email" => $email, "exception" => $e]
                );
                $event->setError(
                    "Le compte de cette personne n'a pas pu être créé. Le partage n'a pas été effectué."
                );
                $event->stopPropagation();
                return;
            }

            if ($username === "") {
                $this->logger->error(
                    "[watcha] Synapse n'a pas rendu de nom Nextcloud",
                    ["email" => $email]
                );
                $event->setError(
                    "Le compte de cette personne n'a pas pu être créé. Le partage n'a pas été effectué."
                );
                $event->stopPropagation();
                return;
            }

            // Toutes les instances ne donnent pas d'espace documentaire aux
            // partenaires : `external_authentication_for_partners` décide, côté
            // Synapse. Quand elle vaut faux, le compte existe dans la messagerie
            // et l'annuaire mais pas ici — il n'y a alors personne à qui
            // partager, et le partage par courriel reste le bon comportement.
            if ($this->userManager->get($username) === null) {
                $this->logger->info(
                    "[watcha] compte créé sans espace documentaire, partage laissé en courriel",
                    ["email" => $email, "nextcloud_username" => $username]
                );
                return;
            }
        }

        // Le partage devient un partage utilisateur. L'événement est émis avant
        // que le fournisseur ne soit choisi (`Manager::createShare`), donc le
        // changement de type décide bien du fournisseur. Les contrôles propres
        // au partage utilisateur ont été sautés, mais aucun ne peut échouer
        // ici : le destinataire vient d'être créé, ce n'est pas le propriétaire,
        // et il ne peut pas exister de partage en double vers lui.
        $share->setShareType(IShare::TYPE_USER);
        $share->setSharedWith($username);

        $this->logger->info(
            "[watcha] partage par courriel converti en partage utilisateur",
            [
                "email" => $email,
                "nextcloud_username" => $username,
                "partage_par" => $share->getSharedBy(),
            ]
        );
    }

}
