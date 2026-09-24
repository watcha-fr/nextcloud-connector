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

namespace OCA\Watcha\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Déclare à Synapse un compte créé dans Nextcloud, pour qu'il existe aussi
 * dans Matrix et chez le fournisseur d'identité.
 *
 * Le connecteur est installé chez tous les clients qui ont Nextcloud, y compris
 * ceux sans Keycloak : sans jeton configuré, rien de tout cela ne s'active.
 */
class SynapseRegistrar {

    /** L'identifiant de l'application, sous lequel se rangent ses valeurs. */
    private const APP_ID = "watcha";

    /**
     * Le groupe Nextcloud qui marque une personne extérieure à l'organisation.
     * C'est celui que Synapse pose lui-même en provisionnant un partenaire ;
     * le renseigner à la création d'un compte ici produit le même statut.
     */
    public const PARTNER_GROUP = "partner";

    /** Le point d'entrée unique de provisionnement, côté Synapse. */
    private const REGISTER_PATH = "/_matrix/client/r0/watcha_register";

    /** Celui par lequel on signale ce qui arrive à un compte ici. */
    private const LIFECYCLE_PATH = "/_matrix/client/r0/watcha_nextcloud_user";

    /** Qui reprend le dossier d'un salon quand son propriétaire est supprimé. */
    private const HEIR_PATH = "/_matrix/client/r0/watcha_room_folder_heir";

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Le connecteur sait-il où joindre Synapse, et avec quoi s'authentifier ?
     */
    public function isConfigured(): bool {
        return $this->getSynapseUrl() !== "" && $this->getToken() !== "";
    }

    /**
     * Le compte de service par lequel Synapse agit sur Nextcloud. Les comptes
     * qu'il crée viennent de Synapse : les redéclarer bouclerait.
     */
    public function getServiceAccount(): string {
        return $this->config->getSystemValue("watcha_service_account", "watcha");
    }

    /**
     * Un compte se déclare une fois. Deux moments peuvent nous y amener — sa
     * création et l'arrivée de son adresse — et son adresse peut changer plus
     * tard : sans cette marque, la même personne serait annoncée deux fois à
     * Synapse, avec un second courriel de bienvenue à la clé.
     */
    public function isDeclared(string $uid): bool {
        return $this->config->getUserValue($uid, self::APP_ID, "declared", "no") === "yes";
    }

    public function markDeclared(string $uid): void {
        $this->config->setUserValue($uid, self::APP_ID, "declared", "yes");
    }

    /**
     * Déclare le compte. Le nom Nextcloud est imposé à Synapse : le compte
     * existe déjà ici sous ce nom, et laisser Synapse en dériver un autre lui
     * ferait créer un second compte à côté.
     *
     * @throws \Exception si Synapse refuse ou reste injoignable
     */
    public function registerUser(
        string $nextcloudUsername,
        string $email,
        ?string $displayName,
        bool $isPartner = false,
    ): void {
        $response = $this->clientService->newClient()->post(
            $this->getSynapseUrl() . self::REGISTER_PATH,
            [
                "headers" => [
                    "Authorization" => "Bearer " . $this->getToken(),
                    "Content-Type" => "application/json",
                ],
                "body" => json_encode([
                    "email" => $email,
                    "displayname" => $displayName ?? "",
                    "nextcloud_username" => $nextcloudUsername,
                    // Le groupe Nextcloud dit le statut. C'est le même que
                    // Synapse pose lui-même quand il provisionne un partenaire,
                    // et on le lui rend ici dans l'autre sens.
                    "is_partner" => $isPartner,
                ]),
                "timeout" => 30,
            ]
        );

        $this->logger->info(
            "[watcha] compte Nextcloud déclaré à Synapse",
            [
                "nextcloud_username" => $nextcloudUsername,
                "is_partner" => $isPartner,
                "status" => $response->getStatusCode(),
            ]
        );
    }

    /**
     * Signale ce qui vient d'arriver au compte ici.
     *
     * `disable` et `enable` sont réversibles des deux côtés. `delete` ne l'est
     * pas : Synapse supprime le compte Keycloak et efface le sien, ce qui
     * court-circuite la rétention — la personne ne pourra plus revenir sous la
     * même identité.
     *
     * @param string $action disable, enable ou delete
     * @throws \Exception si Synapse refuse ou reste injoignable
     */
    public function notifyLifecycle(string $nextcloudUsername, string $action): void {
        $response = $this->clientService->newClient()->post(
            $this->getSynapseUrl() . self::LIFECYCLE_PATH,
            [
                "headers" => [
                    "Authorization" => "Bearer " . $this->getToken(),
                    "Content-Type" => "application/json",
                ],
                "body" => json_encode([
                    "nextcloud_username" => $nextcloudUsername,
                    "action" => $action,
                ]),
                "timeout" => 30,
            ]
        );

        $this->logger->info(
            "[watcha] cycle de vie du compte signalé à Synapse",
            [
                "nextcloud_username" => $nextcloudUsername,
                "action" => $action,
                "status" => $response->getStatusCode(),
            ]
        );
    }

    /**
     * Qui reprend le dossier d'un salon quand son propriétaire s'en va.
     *
     * Seul Synapse peut répondre : l'appartenance aux salons et l'ancienneté
     * n'existent que chez lui. Une réponse nulle n'est pas un échec — elle dit
     * qu'aucun membre ne peut hériter, et le dossier suivra le compte.
     *
     * @return string|null le nom Nextcloud de l'héritier, ou null s'il n'y en a pas
     * @throws \Exception si Synapse refuse ou reste injoignable
     */
    public function findRoomFolderHeir(string $roomId, string $leavingUsername): ?string {
        $response = $this->clientService->newClient()->post(
            $this->getSynapseUrl() . self::HEIR_PATH,
            [
                "headers" => [
                    "Authorization" => "Bearer " . $this->getToken(),
                    "Content-Type" => "application/json",
                ],
                "body" => json_encode([
                    "room_id" => $roomId,
                    "nextcloud_username" => $leavingUsername,
                ]),
                "timeout" => 30,
            ]
        );

        $body = json_decode($response->getBody(), true);
        $heir = $body["nextcloud_username"] ?? null;

        $this->logger->info(
            "[watcha] héritier du dossier de salon désigné par Synapse",
            [
                "room_id" => $roomId,
                "leaving" => $leavingUsername,
                "heir" => $heir,
            ]
        );

        return is_string($heir) && $heir !== "" ? $heir : null;
    }

    /**
     * L'adresse de Synapse. `watcha_origin` sert de repli : sur la plupart des
     * déploiements, l'application web et le homeserver partagent le domaine.
     */
    private function getSynapseUrl(): string {
        $url = $this->config->getSystemValueString("watcha_synapse_url");
        if ($url === "") {
            $url = $this->config->getSystemValueString("watcha_origin");
        }
        return rtrim($url, "/");
    }

    private function getToken(): string {
        return $this->config->getSystemValueString("watcha_registration_token");
    }
}
