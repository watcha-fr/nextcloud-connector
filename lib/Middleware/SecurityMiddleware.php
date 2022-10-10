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

namespace OCA\Watcha\Middleware;

use OCP\AppFramework\Middleware;
use OCP\IConfig;

use OCA\Watcha\Exception\NotServiceAccountException;

class SecurityMiddleware extends Middleware {

    /** @var string */
    private $userId;

	/** @var IConfig */
    private $config;

    public function __construct(string $userId, IConfig $config) {
        $this->userId = $userId;
        $this->config = $config;
    }

    /**
     * @param Controller $controller
     * @param string $methodName
     * @throws NotServiceAccountException
     */
    public function beforeController($controller, $methodName) {
        if ($this->userId !== $this->config->getSystemValue("watcha_service_account", "watcha")) {
            throw new NotServiceAccountException();
        };
    }
}