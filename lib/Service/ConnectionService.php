<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
final class ConnectionService {
    public function __construct(private Vault $vault, private HttpClient $http, private ISession $session, private IURLGenerator $urls, private ILockingProvider $locks) {}
    private static function random(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private function locked(string $key, callable $fn, int $mode = ILockingProvider::LOCK_EXCLUSIVE): mixed {
        $lock = 'deferendum:' . hash('sha256', $key);
        $this->locks->acquireLock($lock, $mode);
        try { return $fn(); } finally { $this->locks->releaseLock($lock, $mode); }
    }
    private function instanceLease(callable $fn): mixed { return $this->locked('instance-registration',$fn,ILockingProvider::LOCK_SHARED); }
    private function epoch(string $uid): string { $value=$this->vault->connectionEpoch($uid);if($value===null){$value=self::random();$this->vault->saveConnectionEpoch($uid,$value);}return $value; }
    public function register(string $baseUrl, bool $replace = false): void {
        $this->locked('instance-registration', function () use ($baseUrl, $replace): void {
            $old = $this->vault->instance();
            if ($old !== null && !$replace) { throw new ConnectorException('already_registered'); }
            $baseUrl = Protocol::baseUrl($baseUrl);
            if ($old !== null && ($old['baseUrl'] ?? null) !== $baseUrl) { throw new ConnectorException('invalid_input'); }
            // The administrator cannot enroll some unrelated site from this instance.
            $own = $this->urls->linkToRouteAbsolute('deferendum.oauth.callback');
            $ownPath = parse_url($own, PHP_URL_PATH) ?? '';
            if (!str_ends_with($ownPath, '/apps/deferendum/oauth/callback')) { throw new ConnectorException('invalid_input'); }
            $expectedCallback = $baseUrl . Protocol::CALLBACK_SUFFIX;
            $ownParts = parse_url($own); $baseParts = parse_url($baseUrl);
            if (($ownParts['scheme'] ?? '') !== 'https' || strtolower($ownParts['host'] ?? '') !== $baseParts['host'] || (isset($ownParts['port']) && $ownParts['port'] !== 443) || !in_array($ownPath, [($baseParts['path'] ?? '') . '/apps/deferendum/oauth/callback', ($baseParts['path'] ?? '') . Protocol::CALLBACK_SUFFIX], true)) { throw new ConnectorException('invalid_input'); }
            $challenge = $this->http->request('POST', '/api/v2/instances/challenges', ['baseUrl' => $baseUrl]);
            if (!is_string($challenge['challengeId'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{16,200}$/D', $challenge['challengeId']) || !is_string($challenge['challenge'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $challenge['challenge']) || !is_string($challenge['registrationToken'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge['registrationToken']) || ($challenge['redirectUri'] ?? null) !== $expectedCallback || ($challenge['proofUrl'] ?? null) !== $baseUrl . Protocol::PROOF_SUFFIX . '?challengeId=' . rawurlencode($challenge['challengeId']) || strtotime($challenge['expiresAt'] ?? '') <= time() || strtotime($challenge['expiresAt']) > time() + 601) { throw new ConnectorException(); }
            $this->vault->savePending($challenge);
            try {
                $instance = $this->http->request('POST', '/api/v2/instances', ['challengeId' => $challenge['challengeId'], 'registrationToken' => $challenge['registrationToken']]);
                foreach (['instanceId', 'clientId', 'clientSecret'] as $key) { if (!is_string($instance[$key] ?? null) || strlen($instance[$key]) < 16 || strlen($instance[$key]) > 512) { throw new ConnectorException(); } }
                if (($instance['redirectUri'] ?? null) !== $expectedCallback || ($instance['issuer'] ?? null) !== Protocol::PARTICIPATION_ISSUER || ($instance['resource']??null)!==Protocol::PARTICIPATION_RESOURCE || ($instance['scope'] ?? null) !== Protocol::SCOPE) { throw new ConnectorException(); }
                if (($instance['resource']??null)===Protocol::PARTICIPATION_RESOURCE && (($instance['permissionsVersion']??null)!==2 || Protocol::scopes(implode(' ',$instance['allowedScopes']??[]))!==Protocol::scopes(Protocol::SCOPE.' '.Protocol::DISCUSSION_SCOPE.' '.Protocol::PARTICIPATE_SCOPE))) { throw new ConnectorException(); }
                $instance['baseUrl'] = $baseUrl; $this->vault->saveInstance($instance); $this->vault->clearAllUsers();
            } finally { $this->vault->clearPending(); }
        });
    }
    public function proof(string $challengeId): ?array {
        $pending = $this->vault->pending();
        if ($pending === null || !hash_equals($pending['challengeId'] ?? '', $challengeId) || strtotime($pending['expiresAt'] ?? '') <= time()) { return null; }
        return ['challengeId' => $pending['challengeId'], 'challenge' => $pending['challenge']];
    }
    public function status(string $uid): array {
        $instance = $this->vault->instance(); $tokens = $this->vault->user($uid);
        $active = $instance !== null && $tokens !== null && ($tokens['instanceId'] ?? null) === $instance['instanceId'] && ($tokens['grantExpiresAt'] ?? 0) > time();
        return ['registered' => $instance !== null, 'connected' => $active && ($tokens['quarantined'] ?? false) !== true, 'canWithdraw' => $active, 'scopes' => $tokens === null ? [] : ($tokens['scopes'] ?? [Protocol::SCOPE]), 'participationAvailable' => ($instance['resource']??null)===Protocol::PARTICIPATION_RESOURCE && ($instance['issuer']??null)===Protocol::PARTICIPATION_ISSUER, 'authorizeIssuer'=>$instance['issuer']??Protocol::ISSUER, 'expiresAt' => $tokens === null ? null : gmdate('c', $tokens['grantExpiresAt'] ?? 0)];
    }
    public function begin(string $uid, string $requestedScopes = Protocol::SCOPE): string {
        $scopes = Protocol::scopes($requestedScopes);
        return $this->instanceLease(fn()=> $this->locked('user:' . $uid, function () use ($uid, $scopes): string {
        $instance = $this->vault->instance(); if ($instance === null) { throw new ConnectorException('not_registered'); }
        $resource=$instance['resource']??Protocol::RESOURCE;
        $issuer=Protocol::issuer($instance['issuer']??Protocol::ISSUER);
        if(($resource===Protocol::PARTICIPATION_RESOURCE)!==($issuer===Protocol::PARTICIPATION_ISSUER)) { throw new ConnectorException(); }
        if ($resource!==Protocol::PARTICIPATION_RESOURCE && count($scopes)>1) { throw new ConnectorException('registration_upgrade_required'); }
        $existing = $this->vault->user($uid);
        if ($existing !== null && ($existing['instanceId'] ?? null) === $instance['instanceId'] && ($existing['grantExpiresAt'] ?? 0) > time()) { throw new ConnectorException('already_connected'); }
        $state = self::random(); $verifier = self::random(48);
        $this->session->set('deferendum.oauth', ['uid' => $uid, 'instanceId' => $instance['instanceId'], 'state' => $state, 'verifier' => $verifier, 'issuer'=>$issuer, 'resource' => $resource, 'scopes' => $scopes, 'connectionEpoch'=>$this->epoch($uid), 'expiresAt' => time() + 600]);
        return $issuer . '/oauth/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $instance['clientId'], 'redirect_uri' => $instance['redirectUri'], 'scope' => Protocol::scopeString($scopes), 'resource' => $resource, 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
        }));
    }
    public function finish(string $uid, string $state, string $code, string $iss): void {
        $this->instanceLease(fn()=> $this->locked('user:' . $uid, function () use ($uid, $state, $code, $iss): void {
        $pending = $this->session->get('deferendum.oauth');
        $this->session->remove('deferendum.oauth');
        $instance = $this->vault->instance();
        if (!is_array($pending) || $instance === null || ($pending['uid'] ?? null) !== $uid || ($pending['instanceId'] ?? null) !== $instance['instanceId'] || ($pending['expiresAt'] ?? 0) <= time() || !is_string($pending['state'] ?? null) || !hash_equals($pending['state'], $state) || $iss !== ($pending['issuer']??Protocol::ISSUER) || $iss!==($instance['issuer']??Protocol::ISSUER) || strlen($code) > 4096 || !preg_match('/^[A-Za-z0-9._~-]{1,4096}$/D', $code)) { throw new ConnectorException('invalid_callback'); }
            $existing=$this->vault->user($uid);
            if($existing!==null && ($existing['instanceId']??null)===$instance['instanceId'] && ($existing['grantExpiresAt']??0)>time())throw new ConnectorException('already_connected');
            if(!is_string($pending['connectionEpoch']??null)||!hash_equals($this->epoch($uid),$pending['connectionEpoch']))throw new ConnectorException('invalid_callback');
            $resource = $pending['resource'] ?? Protocol::RESOURCE; $scopes = $pending['scopes'] ?? [Protocol::SCOPE];
            $response = $this->http->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $instance['clientId'], 'client_secret' => $instance['clientSecret'], 'code' => $code, 'redirect_uri' => $instance['redirectUri'], 'code_verifier' => $pending['verifier'], 'resource' => $resource], null, true,$iss);
            $record = $this->tokenRecord($response, $instance, time() + 2592000, null, $scopes, $resource);
            $this->vault->saveUser($uid, $record);
            // A successful connection invalidates pending callbacks in every
            // other Nextcloud browser session, not merely this PHP session.
            $this->vault->saveConnectionEpoch($uid,self::random());
        }));
    }
    private function tokenRecord(array $response, array $instance, int $grantExpiresAt, ?string $previousRefresh = null, array $scopes = [Protocol::SCOPE], string $resource = Protocol::RESOURCE): array {
        $actualScopes=Protocol::scopes($response['scope']??'');
        if (($response['token_type'] ?? '') !== 'Bearer' || !is_int($response['expires_in'] ?? null) || $response['expires_in'] < 1 || $response['expires_in'] > 900 || !is_string($response['access_token'] ?? null) || !preg_match('/^[A-Za-z0-9._~-]{16,8192}$/D', $response['access_token']) || array_diff($actualScopes,Protocol::scopes(implode(' ',$scopes)))) { throw new ConnectorException(); }
        $refresh = $response['refresh_token'] ?? $previousRefresh;
        if (!is_string($refresh) || !preg_match('/^[A-Za-z0-9._~-]{16,8192}$/D', $refresh)) { throw new ConnectorException(); }
        if (isset($response['grant_expires_at'])) { $supplied = is_int($response['grant_expires_at']) ? $response['grant_expires_at'] : strtotime($response['grant_expires_at']); if (!$supplied || $supplied <= time()) { throw new ConnectorException('unauthorized'); } $grantExpiresAt = min($grantExpiresAt, $supplied); }
        $binding=array_key_exists('connection_binding',$response)?Protocol::connectionBinding($response['connection_binding']):null;
        if(in_array(Protocol::PARTICIPATE_SCOPE,$actualScopes,true) && $binding===null) { throw new ConnectorException(); }
        return ['instanceId' => $instance['instanceId'], 'instanceBaseUrl'=>$instance['baseUrl'], 'issuer'=>$instance['issuer']??Protocol::ISSUER, 'accessToken' => $response['access_token'], 'refreshToken' => $refresh, 'expiresAt' => time() + $response['expires_in'], 'grantExpiresAt' => $grantExpiresAt, 'scopes' => $actualScopes, 'resource' => $resource, 'connectionBinding'=>$binding];
    }
    public function accessToken(string $uid, string $requiredScope = Protocol::SCOPE): string {
        return $this->withConnection($uid,$requiredScope,static fn(array $connection):string=>$connection['accessToken']);
    }
    /** Server-only immutable connection snapshot. Lock order for participation
     * is operation -> instance shared -> user. Refresh runs inside this lease,
     * never by reacquiring the user lock. OAuth callbacks and withdrawal use
     * the same user lock; registration uses the matching exclusive instance lock.
     */
    public function withConnection(string $uid, string $requiredScope, callable $fn): mixed {
        return $this->instanceLease(fn()=> $this->locked('user:'.$uid,function()use($uid,$requiredScope,$fn):mixed{
            $record=$this->accessRecord($uid,$requiredScope);
            $instance=$this->vault->instance();if($instance===null)throw new ConnectorException('unauthorized');
            $connection=['accessToken'=>$record['accessToken'],'resource'=>$record['resource']??Protocol::RESOURCE,'connectionBinding'=>$record['connectionBinding']??null,'instanceId'=>$record['instanceId'],'instanceBaseUrl'=>$record['instanceBaseUrl']??$instance['baseUrl'],'issuer'=>$record['issuer']??$instance['issuer']??Protocol::ISSUER,'connectionEpoch'=>$this->epoch($uid)];
            return $fn($connection);
        }));
    }
    public function assertConnectionCurrent(string $uid, array $connection): void {
        $record=$this->vault->user($uid);$instance=$this->vault->instance();
        if($record===null||$instance===null||($instance['instanceId']??null)!==$connection['instanceId']||($record['instanceId']??null)!==$connection['instanceId']||($record['instanceBaseUrl']??$instance['baseUrl'])!==$connection['instanceBaseUrl']||($record['issuer']??$instance['issuer']??Protocol::ISSUER)!==$connection['issuer']||($record['connectionBinding']??null)!==$connection['connectionBinding']||($record['accessToken']??null)!==$connection['accessToken']||$this->vault->connectionEpoch($uid)!==$connection['connectionEpoch'])throw new ConnectorException('recovery_binding_mismatch');
    }
    private function accessRecord(string $uid, string $requiredScope): array {
            $instance = $this->vault->instance(); $record = $this->vault->user($uid);
            if ($instance === null || $record === null || ($record['instanceId'] ?? null) !== $instance['instanceId'] || ($record['grantExpiresAt'] ?? 0) <= time()) { $this->vault->clearUser($uid); throw new ConnectorException('unauthorized'); }
            if (($record['quarantined'] ?? false) === true) { throw new ConnectorException('unauthorized'); }
            if (!in_array($requiredScope, $record['scopes'] ?? [Protocol::SCOPE], true)) { throw new ConnectorException('consent_required'); }
            if (($record['expiresAt'] ?? 0) <= time() + 30) {
                try { $response = $this->http->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $instance['clientId'], 'client_secret' => $instance['clientSecret'], 'refresh_token' => $record['refreshToken'], 'resource' => $record['resource'] ?? Protocol::RESOURCE], null, true,$record['issuer']??$instance['issuer']??Protocol::ISSUER); $fresh = $this->tokenRecord($response, $instance, $record['grantExpiresAt'], $record['refreshToken'], $record['scopes'] ?? [Protocol::SCOPE], $record['resource'] ?? Protocol::RESOURCE); if(($record['connectionBinding']??null)!==null && ($fresh['connectionBinding']??null)!==$record['connectionBinding']) { throw new ConnectorException('unauthorized'); } $record=$fresh; $this->vault->saveUser($uid, $record); }
                catch (ConnectorException $e) { if (in_array($e->reason, ['unauthorized', 'forbidden', 'invalid_input'], true)) { unset($record['accessToken']); $record['expiresAt'] = 0; $record['quarantined'] = true; $this->vault->saveUser($uid, $record); } throw $e; }
            }
            return $record;
    }
    public function resource(string $uid): string { return $this->vault->user($uid)['resource'] ?? Protocol::RESOURCE; }
    public function registrationIssuer(): string { return Protocol::issuer($this->vault->instance()['issuer']??Protocol::ISSUER); }
    public function connectionContext(string $uid): array { $r=$this->vault->user($uid);if($r===null)throw new ConnectorException('unauthorized');return ['connectionBinding'=>$r['connectionBinding']??null,'instanceId'=>$r['instanceId']??null,'instanceBaseUrl'=>$r['instanceBaseUrl']??null,'issuer'=>$r['issuer']??Protocol::ISSUER]; }
    public function invalidate(string $uid): void {
        $this->locked('user:' . $uid, function () use ($uid): void {
            $record = $this->vault->user($uid);
            if ($record !== null) { unset($record['accessToken']); $record['expiresAt'] = 0; $record['quarantined'] = true; $this->vault->saveUser($uid, $record); }
        });
    }
    public function disconnect(string $uid): void {
        $this->instanceLease(fn()=> $this->locked('user:' . $uid, function () use ($uid): void {
            $this->session->remove('deferendum.oauth'); $instance = $this->vault->instance(); $record = $this->vault->user($uid);
            // Invalidate old callbacks even when another browser owns them or
            // remote withdrawal fails; never resurrect a disconnected session.
            $this->vault->saveConnectionEpoch($uid,self::random());
            if ($record === null) { return; }
            // Do not falsely report remote withdrawal when revocation cannot be confirmed.
            if ($instance === null || ($record['instanceId'] ?? null) !== $instance['instanceId']) { throw new ConnectorException(); }
            $this->http->request('POST', '/oauth/revoke', ['client_id' => $instance['clientId'], 'client_secret' => $instance['clientSecret'], 'token' => $record['refreshToken'], 'token_type_hint' => 'refresh_token'], null, true,$record['issuer']??$instance['issuer']??Protocol::ISSUER);
            $this->vault->clearUser($uid);
        }));
    }
}
