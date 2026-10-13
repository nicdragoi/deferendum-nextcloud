<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
final class DataService {
    public function __construct(private HttpClient $http, private ConnectionService $connections) {}
    private function prefix(string $uid): string { return $this->connections->resource($uid) === Protocol::PARTICIPATION_RESOURCE ? '/api/v2' : '/api/v1'; }
    private function get(string $uid, string $path): array {
        try { $result = $this->connections->withConnection($uid,Protocol::SCOPE,function(array $connection)use($uid,$path):array{
            $prefix=$connection['resource']===Protocol::PARTICIPATION_RESOURCE?'/api/v2':'/api/v1';
            $route=preg_replace('~^/api/v[12](?=/)~',$prefix,$path);
            $response=$this->http->request('GET',$route,[],$connection['accessToken']);$this->connections->assertConnectionCurrent($uid,$connection);return $response;
        }); }
        catch (ConnectorException $e) { if (in_array($e->reason, ['unauthorized', 'forbidden'], true)) { $this->connections->invalidate($uid); } throw $e; }
        if (($result['readOnly'] ?? null) !== true || ($result['contentIsUntrusted'] ?? null) !== true || !isset($result['data']) || !is_array($result['data'])) { throw new ConnectorException(); }
        if (Protocol::date($result['fetchedAt'] ?? null) === null) { throw new ConnectorException(); } return $result;
    }
    private function page(array $result, int $offset, int $limit, callable $project): array {
        $data = $result['data'];
        if (!isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items']) || count($data['items']) > $limit || !array_key_exists('nextOffset', $data) || ($data['nextOffset'] !== null && (!is_int($data['nextOffset']) || $data['nextOffset'] !== $offset + $limit))) { throw new ConnectorException(); }
        $items = []; foreach ($data['items'] as $item) { if (!is_array($item)) { throw new ConnectorException(); } $items[] = $project($item); }
        return ['items' => $items, 'nextOffset' => $data['nextOffset'], 'fetchedAt' => $result['fetchedAt']];
    }
    private function pagination(int $offset, int $limit): void { if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 50) { throw new ConnectorException('invalid_input'); } }
    public function groups(string $uid, string $language, int $offset = 0, int $limit = 50): array {
        $this->pagination($offset, $limit);
        return $this->page($this->get($uid, $this->prefix($uid) . '/groups?' . http_build_query(['offset' => $offset, 'limit' => $limit, 'language' => Protocol::language($language)])), $offset, $limit, static fn(array $r): array => ['id' => Protocol::identifier($r['id'] ?? ''), 'name' => Protocol::text($r['name'] ?? null), 'url' => Protocol::sourceUrl($r['url'] ?? null)]);
    }
    public function topics(string $uid, string $groupId, string $language, string $kind = 'decision', string $view = 'active', int $offset = 0, int $limit = 50): array {
        Protocol::identifier($groupId); $this->pagination($offset, $limit);
        if (!in_array($kind, ['issue', 'decision'], true) || !in_array($view, ['active', 'history'], true)) { throw new ConnectorException('invalid_input'); }
        return $this->page($this->get($uid, $this->prefix($uid) . '/groups/' . $groupId . '/topics?' . http_build_query(['kind' => $kind, 'view' => $view, 'offset' => $offset, 'limit' => $limit, 'language' => Protocol::language($language)])), $offset, $limit, static function (array $r): array {
            $phase = Protocol::text($r['phase'] ?? null, 100);
            return ['id' => Protocol::identifier($r['id'] ?? ''), 'title' => Protocol::text($r['title'] ?? null), 'phase' => $phase, 'deadline' => Protocol::date($r['deadline'] ?? null), 'url' => Protocol::sourceUrl($r['url'] ?? null)];
        });
    }
    public function nextStep(string $uid, string $groupId, string $decisionId, string $language): array {
        Protocol::identifier($groupId); Protocol::identifier($decisionId);
        $result = $this->get($uid, $this->prefix($uid) . '/groups/' . $groupId . '/decisions/' . $decisionId . '/next-step?language=' . Protocol::language($language)); $r = $result['data'];
        return ['nextStep' => Protocol::text($r['nextStep'] ?? null), 'status' => Protocol::text($r['status'] ?? null, 100), 'responsibility' => Protocol::text($r['responsibility'] ?? null, 100), 'targetAt' => Protocol::date($r['targetAt'] ?? null), 'url' => Protocol::sourceUrl($r['url'] ?? null), 'fetchedAt' => $result['fetchedAt']];
    }
}
