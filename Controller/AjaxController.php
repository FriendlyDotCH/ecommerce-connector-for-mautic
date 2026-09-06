<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use MauticPlugin\EcommerceConnectorBundle\Service\PrivacyUpgradeNotice;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AjaxController extends CommonAjaxController
{
    public function dismissPrivacyNoticeAction(PrivacyUpgradeNotice $privacyUpgradeNotice): JsonResponse
    {
        if (true !== $this->security->isGranted('plugin:plugins:manage')) {
            return $this->sendJsonResponse(['success' => 0], Response::HTTP_FORBIDDEN);
        }

        $privacyUpgradeNotice->dismiss();

        return $this->sendJsonResponse(['success' => 1]);
    }
}
