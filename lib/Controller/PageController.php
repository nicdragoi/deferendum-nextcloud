<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Util;
final class PageController extends Controller {
    public function __construct(IRequest $request, private IUserSession $users, private IGroupManager $groups) { parent::__construct('deferendum', $request); }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        Util::addScript('deferendum', 'experience'); Util::addScript('deferendum', 'main'); Util::addScript('deferendum', 'participation'); Util::addStyle('deferendum', 'main');
        $response = new TemplateResponse('deferendum', 'main', ['admin' => $this->groups->isAdmin($this->users->getUser()?->getUID() ?? '')]);
        $response->addHeader('Cache-Control', 'no-store'); $response->addHeader('Referrer-Policy', 'no-referrer'); return $response;
    }
}
