<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Controller;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Deferendum\Service\ConnectionService;
use OCA\Deferendum\Service\DataService;
use OCA\Deferendum\Service\ConnectorException;
final class ApiController extends OCSController {
    public function __construct(IRequest $request, private IUserSession $users, private ConnectionService $connections, private DataService $data) { parent::__construct('deferendum', $request); }
    private function uid(): string { $uid = $this->users->getUser()?->getUID(); if ($uid === null) { throw new ConnectorException('unauthorized'); } return $uid; }
    private function result(callable $call): DataResponse {
        try { $response = new DataResponse($call()); }
        catch (ConnectorException $e) { $response = new DataResponse(['error' => $e->reason], match ($e->reason) {'unauthorized' => 401, 'forbidden' => 403, 'not_found' => 404, 'invalid_input', 'invalid_callback' => 400, default => 503}); }
        catch (\Throwable) { $response = new DataResponse(['error' => 'unavailable'], 503); }
        $response->addHeader('Cache-Control', 'no-store'); $response->addHeader('Referrer-Policy', 'no-referrer'); return $response;
    }
    #[NoAdminRequired]
    public function status(): DataResponse { return $this->result(fn() => $this->connections->status($this->uid())); }
    #[UserRateLimit(limit: 10, period: 3600)]
    public function registerInstance(string $baseUrl = '', bool $replace = false): DataResponse { return $this->result(function () use ($baseUrl, $replace) { $this->connections->register($baseUrl, $replace); return ['registered' => true]; }); }
    #[NoAdminRequired]
    #[UseSession]
    #[UserRateLimit(limit: 10, period: 60)]
    public function connect(string $requestedScopes = \OCA\Deferendum\Service\Protocol::SCOPE): DataResponse { return $this->result(fn() => ['authorizeUrl' => $this->connections->begin($this->uid(), $requestedScopes),'authorizeIssuer'=>$this->connections->registrationIssuer()]); }
    #[NoAdminRequired]
    #[UseSession]
    public function disconnect(): DataResponse { return $this->result(function () { $this->connections->disconnect($this->uid()); return ['connected' => false, 'remoteRevocationConfirmed' => true]; }); }
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function groups(string $language = 'en', int $offset = 0, int $limit = 50): DataResponse { return $this->result(fn() => $this->data->groups($this->uid(), $language, $offset, $limit)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function topics(string $groupId, string $language = 'en', string $kind = 'decision', string $view = 'active', int $offset = 0, int $limit = 50): DataResponse { return $this->result(fn() => $this->data->topics($this->uid(), $groupId, $language, $kind, $view, $offset, $limit)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function nextStep(string $groupId, string $decisionId, string $language = 'en'): DataResponse { return $this->result(fn() => $this->data->nextStep($this->uid(), $groupId, $decisionId, $language)); }
}
