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

use OCP\IConfig;
use OCP\Server;

class App {
    public static function extendJsConfig($settings) {
        $appConfig = json_decode($settings["array"]["oc_appconfig"], true);

        // watcha+
        // Nextcloud 34 removed the `\OC::$server->getXxx()` getters. `OCP\Server::get()`
        // is the supported replacement, and the only option here: this is a legacy
        // `OC_Hook` slot — a static method the server calls with nothing injected.
        // Left unfixed, the call fataled inside the hook, `oc_appconfig.watcha` never
        // reached the page, and `refine-iframe.js` then posted its URL to an empty
        // target origin, which is a hard SyntaxError — so the room document panel
        // stopped reporting its location and became unusable.
        // +watcha
        $watchaOrigin = Server::get(IConfig::class)->getSystemValueString("watcha_origin");

        $appConfig["watcha"] = [
            "origin" => $watchaOrigin
        ];

        $settings["array"]["oc_appconfig"] = json_encode($appConfig);
    }
}
