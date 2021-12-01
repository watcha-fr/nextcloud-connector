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

namespace OCA\Watcha\Controller;

use Psr\Log\LoggerInterface;

use OCA\Files_Sharing\Controller\ShareAPIController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\UserStatus\IManager as IUserStatusManager;

class DocumentController extends ShareAPIController {

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        IManager $shareManager,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        IL10N $l10n,
        IConfig $config,
        IAppManager $appManager,
        IServerContainer $serverContainer,
        IUserStatusManager $userStatusManager,
        IPreview $previewManager,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $appName,
            $request,
            $shareManager,
            $groupManager,
            $userManager,
            $rootFolder,
            $urlGenerator,
            $request->getParam("requester"), // the user who made the sharing request (not the service account)
            $l10n,
            $config,
            $appManager,
            $serverContainer,
            $userStatusManager,
            $previewManager
        );
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $path
     * @param int $permissions
     * @param int $shareType
     * @param string $shareWith
     * @param string $publicUpload
     * @param string $password
     * @param string $sendPasswordByTalk
     * @param string $expireDate
     * @param string $label
     *
     * @return DataResponse
     * @throws NotFoundException
     * @throws OCSBadRequestException
     * @throws OCSException
     * @throws OCSForbiddenException
     * @throws OCSNotFoundException
     * @throws InvalidPathException
     * @suppress PhanUndeclaredClassMethod
     */
    public function createShare(
        string $path = null,
        int $permissions = null,
        int $shareType = -1,
        string $shareWith = null,
        string $publicUpload = 'false',
        string $password = '',
        string $sendPasswordByTalk = null,
        string $expireDate = '',
        string $label = ''
    ): DataResponse {
        $this->logger->info("document at $path shared with $shareWith");
        return parent::createShare(
            $path,
            $permissions,
            $shareType,
            $shareWith,
            $publicUpload,
            $password,
            $sendPasswordByTalk,
            $expireDate,
            $label
        );
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id
     * @return DataResponse
     * @throws OCSNotFoundException
     */
    public function deleteShare(string $id): DataResponse {
        $this->logger->info("document sharing $id deleted");
        return parent::deleteShare($id);
    }
}
