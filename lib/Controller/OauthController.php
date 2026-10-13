<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCA\Deferendum\Service\ConnectionService;
final class OauthController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private IURLGenerator $urls, private ConnectionService $connections) { parent::__construct('deferendum', $request); }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    #[UserRateLimit(limit: 10, period: 60)]
    public function callback(string $state = '', string $code = '', string $iss = '', string $error = ''): RedirectResponse {
        $status = 'connection_failed';
        try { $uid = $this->users->getUser()?->getUID(); if ($uid !== null && $error === '') { $this->connections->finish($uid, $state, $code, $iss); $status = 'connected'; } } catch (\Throwable) {}
        $response = new RedirectResponse($this->urls->linkToRouteAbsolute('deferendum.page.index') . '?' . http_build_query(['status' => $status]));
        $response->addHeader('Cache-Control', 'no-store'); $response->addHeader('Referrer-Policy', 'no-referrer'); return $response;
    }
}
