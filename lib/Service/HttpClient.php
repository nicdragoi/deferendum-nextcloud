<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
use OCP\Http\Client\IClientService;
final class HttpClient {
    public function __construct(private IClientService $clients) {}
    public function request(string $method, string $path, array $data = [], ?string $token = null, bool $form = false, ?string $issuer = null): array {
        $readPath = preg_match('~^/api/v[12]/groups(?:/[A-Za-z0-9_-]{1,200}/(?:topics|participation|decisions/[A-Za-z0-9_-]{1,200}/(?:next-step|participation)|operations/[a-f0-9-]{36}))?(?:\?[A-Za-z0-9_=&%:-]*)?$~D', $path) === 1;
        $commandPath = preg_match('~^/api/v2/groups/[A-Za-z0-9_-]{1,200}/commands$~D', $path) === 1;
        $reconcilePath = preg_match('~^/api/v2/groups/[A-Za-z0-9_-]{1,200}/operations/[a-f0-9-]{36}/reconcile$~D',$path)===1;
        $identityPath = preg_match('~^/(?:api/v[12]/instances(?:/challenges)?|oauth/(?:token|revoke))$~D', $path) === 1;
        if (!(($method === 'GET' && $readPath) || ($method === 'POST' && ($commandPath || $reconcilePath || $identityPath)))) { throw new ConnectorException('invalid_input'); }
        $routeIssuer=str_starts_with($path,'/api/v2/')?Protocol::PARTICIPATION_ISSUER:Protocol::ISSUER;
        if(str_starts_with($path,'/oauth/'))$routeIssuer=Protocol::issuer($issuer??Protocol::ISSUER);
        elseif($issuer!==null && Protocol::issuer($issuer)!==$routeIssuer) { throw new ConnectorException('invalid_input'); }
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'Deferendum-Nextcloud/0.2.0'];
        if ($token !== null) { if (!preg_match('/^[A-Za-z0-9._~-]{1,8192}$/D', $token)) { throw new ConnectorException('unauthorized'); } $headers['Authorization'] = 'Bearer ' . $token; }
        $options = ['headers' => $headers, 'timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false, 'verify' => true, 'http_errors' => false];
        if ($method === 'POST') { $options[$form ? 'form_params' : 'json'] = $data; }
        try {
            $response = $this->clients->newClient()->{strtolower($method)}($routeIssuer . $path, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) { throw new ConnectorException(match ($status) {401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 400 => 'invalid_input', 409 => 'conflict', 422 => 'validation', 428 => 'precondition_required', 429 => 'rate_limited', default => $commandPath ? 'outcome_unknown' : 'unavailable'}); }
            $body = $response->getBody();
            if (is_resource($body)) { $body = stream_get_contents($body, 1000001); }
            if ($path === '/oauth/revoke' && ($body === '' || $body === null)) { return []; }
            if (!is_string($body) || strlen($body) > 1000000) { throw new ConnectorException($commandPath ? 'outcome_unknown' : 'unavailable'); }
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result)) { throw new ConnectorException($commandPath ? 'outcome_unknown' : 'unavailable'); }
            return $result;
        } catch (ConnectorException $e) { throw $e; } catch (\Throwable) { throw new ConnectorException($commandPath ? 'outcome_unknown' : 'unavailable'); }
    }
}
