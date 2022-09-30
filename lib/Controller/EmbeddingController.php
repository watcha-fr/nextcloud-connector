<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Watcha <contact@watcha.fr>
 *
 * @author Charlie Calendre <c-cal@watcha.fr>
 *
 * @license AGPL-3.0-or-later
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

namespace OCA\Watcha\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Util;

class EmbeddingController extends Controller {

    /** @var String */
    protected $appName;

    /** @var IAppManager */
    private $appManager;

    /** @var IConfig */
    private $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IAppManager $appManager,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->appName = $appName;
        $this->appManager = $appManager;
        $this->config = $config;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @NoSameSiteCookieRequired
     *
     * @return TemplateResponse
     */
    public function embed() {
        Util::addStyle($this->appName, "embed");
        Util::addScript($this->appName, "embed");

        $response = new TemplateResponse($this->appName, "embed", [], TemplateResponse::RENDER_AS_BASE);

        $oidcAppEnabled = $this->appManager->isInstalled("oidc_login");
        $oidcIssuerUrl = $this->config->getSystemValueString("oidc_login_provider_url");
        if ($oidcAppEnabled && $oidcIssuerUrl !== "") {
            $policy = new ContentSecurityPolicy();
            $allowedFrameDomain = rtrim($oidcIssuerUrl, "/") . "/";
            $policy->addAllowedFrameDomain($allowedFrameDomain);
            $response->setContentSecurityPolicy($policy);
        }

        return $response;
    }
}
