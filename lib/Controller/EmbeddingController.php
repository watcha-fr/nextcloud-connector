<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Watcha <contact@watcha.fr>
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

namespace OCA\Watcha\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Util;

class EmbeddingController extends Controller {

    /** @var String */
    protected $appName;

    /** @var IConfig */
    private $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->appName = $appName;
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

        $url = $this->request->getParam("url");

        $response = new TemplateResponse($this->appName, "embed", ["url" => $url], TemplateResponse::RENDER_AS_BASE);

        $oidcIssuerUrl = $this->config->getSystemValueString("oidc_login_provider_url");
        if ($oidcIssuerUrl !== "") {
            $policy = new ContentSecurityPolicy();
            $policy->addAllowedFrameDomain($oidcIssuerUrl);
            $response->setContentSecurityPolicy($policy);
        }

        return $response;
    }
}
