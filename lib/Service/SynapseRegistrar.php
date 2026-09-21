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

    /** Le point d'entrée unique de provisionnement, côté Synapse. */
    private const REGISTER_PATH = "/_matrix/client/r0/watcha_register";

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
                ]),
                "timeout" => 30,
            ]
        );

        $this->logger->info(
            "[watcha] compte Nextcloud déclaré à Synapse",
            [
                "nextcloud_username" => $nextcloudUsername,
                "status" => $response->getStatusCode(),
            ]
        );
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
