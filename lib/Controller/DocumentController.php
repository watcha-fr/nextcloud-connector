<?php

declare(strict_types=1);

namespace OCA\Watcha\Controller;

use Psr\Log\LoggerInterface;

use OCA\Files_Sharing\Controller\ShareAPIController;
use OCA\Watcha\Service\ShareAcceptanceService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IDateTimeZone;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\Mail\IMailer;
use OCP\Mail\IEmailValidator;
use OCP\Share\IProviderFactory;
use OCP\ITagManager;
use OCA\Federation\TrustedServers;

class DocumentController extends ShareAPIController {

    private LoggerInterface $logger;

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
        IAppConfig $appConfig,
        IAppManager $appManager,
        IServerContainer $serverContainer,
        IUserStatusManager $userStatusManager,
        IPreview $previewManager,
        private IDateTimeZone $dateTimeZone,
        LoggerInterface $logger,
        IProviderFactory $factory,
        IMailer $mailer,
        ITagManager $tagManager,
        IEmailValidator $emailValidator,
        ?TrustedServers $trustedServers,
        private ShareAcceptanceService $shareAcceptanceService,
        ?string $userId = null,
    ) {
        $requester = $request->getParam("requester");
        parent::__construct(
            $appName,
            $request,
            $shareManager,
            $groupManager,
            $userManager,
            $rootFolder,
            $urlGenerator,
            $l10n,
            $config,
            $appConfig,
            $appManager,
            $serverContainer,
            $userStatusManager,
            $previewManager,
            $dateTimeZone,
            $logger,
            $factory,
            $mailer,
            $tagManager,
            $emailValidator,
            $trustedServers,
            $requester,
        );
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createShare(
        ?string $path = null,
        ?int $permissions = null,
        int $shareType = -1,
        ?string $shareWith = null,
        ?string $publicUpload = null,
        string $password = '',
        ?string $sendPasswordByTalk = null,
        ?string $expireDate = null,
        string $note = '',
        string $label = '',
        ?string $attributes = null,
        ?string $sendMail = null
    ): DataResponse {
        $requester = $this->request->getParam('requester');
        $this->logger->info("document at $path shared with $shareWith");
        $this->userId = $requester;

        $response = parent::createShare(
            $path,
            $permissions,
            $shareType,
            $shareWith,
            $publicUpload,
            $password,
            $sendPasswordByTalk,
            $expireDate,
            $note,
            $label,
            $attributes,
            $sendMail
        );

        // Accept the freshly created share for the members present right now.
        // Members who join later are covered by RoomGroupMembershipListener and
        // by the room member sync endpoint — this call is *not* the mechanism
        // that keeps the estate consistent, only a fast path for the common
        // case where the folder is shared with an already-populated room.
        $responseData = $response->getData();
        if (isset($responseData['id']) && $shareWith !== null && $shareType === IShare::TYPE_GROUP) {
            $this->shareAcceptanceService->acceptPendingSharesForGroup($shareWith);
        }

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteShare(string $id): DataResponse {
        $this->logger->info("document sharing $id deleted");
        return parent::deleteShare($id);
    }
}
