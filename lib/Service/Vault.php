<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
use OCP\IConfig;
use OCP\Security\ICrypto;
final class Vault {
    public function __construct(private IConfig $config, private ICrypto $crypto) {}
    public function instance(): ?array { return $this->decode($this->config->getAppValue('deferendum', 'instance', ''), 'instance'); }
    public function saveInstance(array $record): void { $this->config->setAppValue('deferendum', 'instance', $this->encode($record, 'instance')); }
    public function pending(): ?array { return $this->decode($this->config->getAppValue('deferendum', 'proof', ''), 'proof'); }
    public function savePending(array $record): void { $this->config->setAppValue('deferendum', 'proof', $this->encode($record, 'proof')); }
    public function clearPending(): void { $this->config->deleteAppValue('deferendum', 'proof'); }
    public function user(string $uid): ?array { return $this->decode($this->config->getUserValue($uid, 'deferendum', 'tokens', ''), 'user:' . $uid); }
    public function saveUser(string $uid, array $record): void { $this->config->setUserValue($uid, 'deferendum', 'tokens', $this->encode($record, 'user:' . $uid)); }
    public function clearUser(string $uid): void { $this->config->deleteUserValue($uid, 'deferendum', 'tokens'); }
    public function connectionEpoch(string $uid): ?string { $r=$this->decode($this->config->getUserValue($uid,'deferendum','connection_epoch',''),'epoch:'.$uid); if($r===null)return null; if(!is_string($r['value']??null))throw new ConnectorException(); return Protocol::connectionBinding($r['value']); }
    public function saveConnectionEpoch(string $uid, string $value): void { $this->config->setUserValue($uid,'deferendum','connection_epoch',$this->encode(['value'=>Protocol::connectionBinding($value)],'epoch:'.$uid)); }
    public function operation(string $uid): ?array { return $this->decode($this->config->getUserValue($uid,'deferendum','pending_operation',''),'operation:'.$uid); }
    public function saveOperation(string $uid, array $record): void { $this->config->setUserValue($uid,'deferendum','pending_operation',$this->encode($record,'operation:'.$uid)); }
    public function clearOperation(string $uid): void { $this->config->deleteUserValue($uid,'deferendum','pending_operation'); }
    public function clearAllUsers(): void {
        // Registration replacement changes the instanceId. ConnectionService
        // refuses every old bound token before using it. Do not bulk-delete
        // preferences: that would erase unresolved operation references.
        // Expired/stale tokens are removed per user on their next access.
    }
    private function encode(array $record, string $owner): string { return $this->crypto->encrypt(json_encode(['version' => 1, 'owner' => $owner, 'record' => $record], JSON_THROW_ON_ERROR)); }
    private function decode(string $value, string $owner): ?array {
        if ($value === '') { return null; }
        try { $data = json_decode($this->crypto->decrypt($value), true, 32, JSON_THROW_ON_ERROR); if (($data['version'] ?? null) !== 1 || ($data['owner'] ?? null) !== $owner || !is_array($data['record'] ?? null)) { throw new ConnectorException(); } return $data['record']; } catch (\Throwable) { throw new ConnectorException(); }
    }
}
