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
use OCP\Util;

use OCA\Watcha\Listener\AddContentSecurityPolicyListener;
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

        Util::addScript(self::APP_ID, "embed");
    }

    /**
     * @inheritDoc
     */
    public function register(IRegistrationContext $context): void {
        $context->registerMiddleware(SecurityMiddleware::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, AddContentSecurityPolicyListener::class);
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
