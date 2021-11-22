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

namespace OCA\Watcha;

class App {
    public static function extendJsConfig($settings) {
        $appConfig = json_decode($settings["array"]["oc_appconfig"], true);

        $watchaOrigin = \OC::$server->getConfig()->getSystemValueString("watcha_origin");

        $appConfig["watcha"] = [
            "origin" => $watchaOrigin
        ];

        $settings["array"]["oc_appconfig"] = json_encode($appConfig);
    }
}
