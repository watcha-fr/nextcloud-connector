<?php

declare(strict_types=1);

namespace OCA\Watcha\Controller;

use Psr\Log\LoggerInterface;

use OCA\Files_Sharing\Controller\ShareAPIController;
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
    private IManager $shareManager;
    private IGroupManager $groupManager;
    private IRootFolder $rootFolder;

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
        $this->shareManager = $shareManager;
        $this->groupManager = $groupManager;
        $this->rootFolder = $rootFolder;
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

        $responseData = $response->getData();
        if (isset($responseData['id']) && $shareWith !== null) {
            $shareId = $responseData['id'];
            $group = $this->groupManager->get($shareWith);
            if ($group !== null) {
                foreach ($group->getUsers() as $user) {
                    $uid = $user->getUID();
                    try {
                        $userShare = $this->shareManager->getShareById(
                            'ocinternal:' . $shareId,
                            $uid
                        );
                        if ($userShare->getStatus() === IShare::STATUS_PENDING) {
                            $this->shareManager->acceptShare($userShare, $uid);
                            $this->rootFolder->getUserFolder($uid)->getDirectoryListing();
                            $this->logger->info("Auto-accepted share $shareId for user $uid");
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning("Failed to auto-accept share $shareId for user $uid: " . $e->getMessage());
                    }
                }
            }
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
