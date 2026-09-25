<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
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

namespace OCA\Watcha\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;

use OCA\Watcha\Listener\AddContentSecurityPolicyListener;
use OCA\Watcha\Listener\EmailShareListener;
use OCA\Watcha\Listener\RoomFolderInheritanceListener;
use OCA\Watcha\Listener\UserCreatedListener;
use OCA\Watcha\Listener\UserLifecycleListener;
use OCA\Watcha\Middleware\SecurityMiddleware;

/**
 * Class Application
 *
 * @package OCA\Watcha\AppInfo
 */
class Application extends App implements IBootstrap {

    /** @var string */
    public const APP_ID = "watcha";

    /**
     * @param array $params
     */
    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);

        Util::addScript(self::APP_ID, "refine-iframe");
    }

    /**
     * @inheritDoc
     */
    public function register(IRegistrationContext $context): void {
        $context->registerMiddleware(SecurityMiddleware::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, AddContentSecurityPolicyListener::class);
		// Le connecteur sert aussi des instances sans Synapse pour maître
		// d'identité : le listener s'enregistre toujours, mais ne fait rien
		// tant que `watcha_registration_token` n'est pas configuré.
		$context->registerEventListener(UserCreatedEvent::class, UserCreatedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserLifecycleListener::class);
		// Avant que la suppression ne détruise les fichiers : un dossier partagé
		// au salon appartient à celui qui l'a lié, et son départ le faisait
		// disparaître pour tous les membres. Le listener transmet, ou lève — et
		// lever ici laisse le compte entier, la suppression n'ayant pas commencé.
		$context->registerEventListener(BeforeUserDeletedEvent::class, RoomFolderInheritanceListener::class);
		// `UserChangedEvent` couvre bien des champs, et deux listeners s'y
		// accrochent sur des champs distincts : `enabled` pour le cycle de vie,
		// `eMailAddress` pour la déclaration. Nextcloud ne l'émet que si la
		// valeur change vraiment.
		$context->registerEventListener(UserChangedEvent::class, UserLifecycleListener::class);
		// L'adresse arrive après la création du compte : c'est elle qui le rend
		// déclarable, et l'attendre ici évite les cinq minutes du cron.
		$context->registerEventListener(UserChangedEvent::class, UserCreatedListener::class);
		// Partager un fichier à une adresse inconnue crée la personne, au lieu
		// de fabriquer un lien à jeton vers quelqu'un qui n'existe nulle part.
		// Sur `BeforeShareCreatedEvent` et pas après : le type du partage y est
		// encore modifiable, et un échec n'y laisse rien derrière lui.
		$context->registerEventListener(BeforeShareCreatedEvent::class, EmailShareListener::class);
    }

    /**
     * @inheritDoc
     */
    public function boot(IBootContext $context): void {
        $this->registerHooks();
    }

    private function registerHooks(): void {
        Util::connectHook("\OCP\Config", "js", "\OCA\Watcha\App", "extendJsConfig");
    }
}
