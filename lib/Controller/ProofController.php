<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Controller;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\IRequest;
use OCA\Deferendum\Service\ConnectionService;
final class ProofController extends Controller {
    public function __construct(IRequest $request, private ConnectionService $connections) { parent::__construct('deferendum', $request); }
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 20, period: 60)]
    public function show(string $challengeId = ''): JSONResponse {
        try { $proof = strlen($challengeId) <= 200 ? $this->connections->proof($challengeId) : null; } catch (\Throwable) { $proof = null; }
        $response = new JSONResponse($proof ?? ['error' => 'not_found'], $proof === null ? 404 : 200);
        $response->addHeader('Cache-Control', 'no-store'); $response->addHeader('Referrer-Policy', 'no-referrer'); return $response;
    }
}
