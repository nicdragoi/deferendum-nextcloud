<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
use OCP\Lock\ILockingProvider;
final class ParticipationService {
    public const COMMANDS = ['issue.create','issue.rate','argument.create','argument.react','comment.create','framing.propose','framing.support','wording.propose','wording.support','wording.review','governance.propose','governance.vote','decision.vote'];
    private const RULE_VALUES = [
        'minimum_coverage_bps'=>[2000,3000,4000,5000,6000,7000], 'minimum_urgency_tenths'=>[30,35,40,45,50,55],
        'promotion_cadence'=>['weekly','immediate'], 'framing_days'=>[1,2,3,5,7,10], 'debate_days'=>[2,3,5,7,10,14],
        'voting_hours'=>[24,48,72,120,168], 'quorum_bps'=>[3000,4000,5000,6000,6700,7500],
        'allow_abstention'=>[true,false], 'ballot_default'=>['confidential','transparent'], 'allow_masked_issues'=>[true,false], 'allow_masked_arguments'=>[true,false],
    ];
    public function __construct(private HttpClient $http, private ConnectionService $connections, private Vault $vault, private ILockingProvider $locks) {}
    private static function exact(array $value, array $required, array $optional = []): void {
        if (array_diff($required, array_keys($value)) || array_diff(array_keys($value), array_merge($required, $optional))) { throw new ConnectorException('invalid_input'); }
    }
    private static function inputText(mixed $value, int $min, int $max): string {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen(mb_convert_encoding(preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u','',$value),'UTF-16LE','UTF-8'))/2 < $min || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2 > $max || str_contains($value, "\0")) { throw new ConnectorException('invalid_input'); } return $value;
    }
    public static function command(string $operationId, string $command, array $payload, ?string $expectedVersion): array {
        Protocol::operationId($operationId);
        if (!in_array($command, self::COMMANDS, true)) { throw new ConnectorException('invalid_input'); }
        if (!str_starts_with($command, 'issue.') && $expectedVersion === null) { throw new ConnectorException('precondition_required'); }
        if ($expectedVersion !== null) { self::inputText($expectedVersion, 1, 200); }
        $id = static function($value): string { if($value==='new') { throw new ConnectorException('invalid_input'); } return Protocol::identifier(is_string($value)?$value:''); };
        $boolean = static function($value): void { if (!is_bool($value)) { throw new ConnectorException('invalid_input'); } };
        $choice = static function($value): void { if (!in_array($value, ['yes','no','abstain'], true)) { throw new ConnectorException('invalid_input'); } };
        switch ($command) {
            case 'issue.create':
                self::exact($payload, ['title'], ['direction','supportingLink','masked']); self::inputText($payload['title'],8,10000);
                if (array_key_exists('direction',$payload)) { self::inputText($payload['direction'],0,10000); }
                if (array_key_exists('supportingLink',$payload)) { self::inputText($payload['supportingLink'],1,4096); $url=parse_url($payload['supportingLink']); if (!$url || !in_array($url['scheme'] ?? '', ['http','https'],true) || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) { throw new ConnectorException('invalid_input'); } }
                break;
            case 'issue.rate': self::exact($payload,['issueId','rating']); $id($payload['issueId']); if (!is_int($payload['rating']) || $payload['rating']<1 || $payload['rating']>6) { throw new ConnectorException('invalid_input'); } break;
            case 'argument.create': self::exact($payload,['decisionId','side','body'],['masked']); $id($payload['decisionId']); if (!in_array($payload['side'],['for','against'],true)) { throw new ConnectorException('invalid_input'); } self::inputText($payload['body'],1,200); break;
            case 'argument.react': self::exact($payload,['decisionId','argumentId','reaction']); $id($payload['decisionId']); $id($payload['argumentId']); if (!in_array($payload['reaction'],[null,'like','dislike'],true)) { throw new ConnectorException('invalid_input'); } break;
            case 'comment.create': self::exact($payload,['decisionId','argumentId','body'],['masked']); $id($payload['decisionId']); $id($payload['argumentId']); self::inputText($payload['body'],1,10000); break;
            case 'framing.propose': self::exact($payload,['expectedQuestionId','wording'],['changeNote','masked']); $id($payload['expectedQuestionId']); self::inputText($payload['wording'],8,10000); if (array_key_exists('changeNote',$payload)) { self::inputText($payload['changeNote'],0,1000); } break;
            case 'framing.support': self::exact($payload,['expectedQuestionId','versionId','supported']); $id($payload['expectedQuestionId']); $id($payload['versionId']); $boolean($payload['supported']); break;
            case 'wording.propose': self::exact($payload,['decisionId','wording','changeNote','argumentIds']); $id($payload['decisionId']); self::inputText($payload['wording'],8,10000); self::inputText($payload['changeNote'],8,1000); if (!is_array($payload['argumentIds']) || !array_is_list($payload['argumentIds']) || count($payload['argumentIds'])<1 || count($payload['argumentIds'])>30 || count(array_unique($payload['argumentIds']))!==count($payload['argumentIds'])) { throw new ConnectorException('invalid_input'); } foreach($payload['argumentIds'] as $value) { $id($value); } break;
            case 'wording.support': self::exact($payload,['decisionId','versionId','supported']); $id($payload['decisionId']); $id($payload['versionId']); $boolean($payload['supported']); break;
            case 'wording.review': self::exact($payload,['decisionId','versionId','argumentId','response']); $id($payload['decisionId']); $id($payload['versionId']); $id($payload['argumentId']); if (!in_array($payload['response'],['confirmed','contested'],true)) { throw new ConnectorException('invalid_input'); } break;
            case 'governance.propose': self::exact($payload,['ruleKey','proposedValue','rationale']); if (!is_string($payload['ruleKey']) || !isset(self::RULE_VALUES[$payload['ruleKey']]) || !in_array($payload['proposedValue'],self::RULE_VALUES[$payload['ruleKey']],true)) { throw new ConnectorException('invalid_input'); } self::inputText($payload['rationale'],20,2000); break;
            case 'governance.vote': self::exact($payload,['amendmentId','choice']); $id($payload['amendmentId']); $choice($payload['choice']); break;
            case 'decision.vote': self::exact($payload,['decisionId','choice']); $id($payload['decisionId']); $choice($payload['choice']); break;
        }
        if (array_key_exists('masked',$payload)) { $boolean($payload['masked']); }
        $result=['operationId'=>$operationId,'command'=>$command,'payload'=>$payload]; if ($expectedVersion!==null) { $result['expectedVersion']=$expectedVersion; }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>32768) { throw new ConnectorException('invalid_input'); } return $result;
    }
    private function lease(string $uid, string $scope, callable $fn): mixed {
        return $this->connections->withConnection($uid,$scope,function(array $connection)use($scope,$fn):mixed{
            if($connection['resource']!==Protocol::PARTICIPATION_RESOURCE||$connection['issuer']!==Protocol::PARTICIPATION_ISSUER)throw new ConnectorException('consent_required');
            if($scope===Protocol::PARTICIPATE_SCOPE){if(!is_string($connection['connectionBinding']))throw new ConnectorException('consent_required');Protocol::connectionBinding($connection['connectionBinding']);}
            return $fn($connection);
        });
    }
    public function groupContext(string $uid, string $groupId, string $language): array { Protocol::identifier($groupId); return $this->context($uid,'/api/v2/groups/'.$groupId.'/participation?language='.Protocol::language($language)); }
    public function decisionContext(string $uid, string $groupId, string $decisionId, string $language): array { Protocol::identifier($groupId); Protocol::identifier($decisionId); return $this->context($uid,'/api/v2/groups/'.$groupId.'/decisions/'.$decisionId.'/participation?language='.Protocol::language($language)); }
    private function context(string $uid, string $path): array {
        return $this->lease($uid,Protocol::DISCUSSION_SCOPE,function(array $connection)use($uid,$path):array{
            $r=$this->http->request('GET',$path,[],$connection['accessToken']);
            $this->connections->assertConnectionCurrent($uid,$connection);
            if (($r['contentIsUntrusted']??null)!==true || !is_array($r['data']??null) || Protocol::date($r['fetchedAt']??null)===null) { throw new ConnectorException(); }
            return ['data'=>ParticipationDto::context($r['data']),'fetchedAt'=>$r['fetchedAt'],'contentIsUntrusted'=>true];
        });
    }
    public function submit(string $uid, string $groupId, string $operationId, string $command, array $payload, ?string $expectedVersion): array {
        Protocol::identifier($groupId); $request=self::command($operationId,$command,$payload,$expectedVersion);
        $key='deferendum:operation:'.hash('sha256',$uid); $this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            if($this->vault->operation($uid)!==null) { throw new ConnectorException('operation_pending'); }
            return $this->lease($uid,Protocol::PARTICIPATE_SCOPE,function(array $connection)use($uid,$groupId,$operationId,$command,$request):array{
                $pending=['operationId'=>$operationId,'groupId'=>$groupId,'command'=>$command,'requestHash'=>hash('sha256',Protocol::canonicalJson($request)),'createdAt'=>gmdate('Y-m-d\TH:i:s.000\Z'),'connectionBinding'=>$connection['connectionBinding'],'instanceBaseUrl'=>$connection['instanceBaseUrl'],'instanceId'=>$connection['instanceId'],'issuer'=>$connection['issuer']];
                $this->connections->assertConnectionCurrent($uid,$connection);$this->vault->saveOperation($uid,$pending);
                try { $response=$this->http->request('POST','/api/v2/groups/'.$groupId.'/commands',$request,$connection['accessToken']); }
                catch(ConnectorException $e) {
                    if(in_array($e->reason,['invalid_input','validation','conflict','precondition_required','rate_limited','not_found'],true)) { $this->connections->assertConnectionCurrent($uid,$connection);$this->assertPendingCurrent($uid,$pending);$this->vault->clearOperation($uid); throw $e; }
                    throw new ConnectorException('outcome_unknown');
                }
                try { $receipt=ParticipationDto::receipt($response['data']??[]); if ($receipt['operationId']!==$operationId || $receipt['command']!==$command) { throw new ConnectorException(); } }
                catch (\Throwable) { throw new ConnectorException('outcome_unknown'); }
                $this->connections->assertConnectionCurrent($uid,$connection);$this->assertPendingCurrent($uid,$pending);$this->checkBinding($pending,$connection,$groupId,$operationId);
                $this->vault->clearOperation($uid); return $receipt;
            });
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
    public function pending(string $uid): array {
        $r=$this->vault->operation($uid); if($r===null) { return ['pending'=>null]; }
        return ['pending'=>['operationId'=>Protocol::operationId($r['operationId']??''),'groupId'=>Protocol::identifier($r['groupId']??''),'command'=>Protocol::text($r['command']??null,100),'createdAt'=>Protocol::date($r['createdAt']??null),'canDiscard'=>$this->expiredPending($r)]];
    }
    private function expiredPending(array $pending): bool { $at=Protocol::date($pending['createdAt']??null);return $at!==null && strtotime($at)<time()-31*86400; }
    public function operation(string $uid, string $groupId, string $operationId): array {
        Protocol::identifier($groupId); Protocol::operationId($operationId);
        $key='deferendum:operation:'.hash('sha256',$uid);$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try{return $this->lease($uid,Protocol::PARTICIPATE_SCOPE,function(array $connection)use($uid,$groupId,$operationId):array{
            $known=$this->vault->operation($uid);if($known!==null)$this->checkBinding($known,$connection,$groupId,$operationId);
            $wrapped=$this->http->request('GET','/api/v2/groups/'.$groupId.'/operations/'.$operationId,[],$connection['accessToken']);$response=$wrapped['data']??[];
            $this->connections->assertConnectionCurrent($uid,$connection);
            if(($response['operationId']??null)!==$operationId)throw new ConnectorException();
            if(($response['status']??null)==='not_found')return ['operationId'=>$operationId,'status'=>'not_found'];
            $receipt=ParticipationDto::receipt($response);$pending=$this->vault->operation($uid);
            if($pending!==null && $pending['groupId']===$groupId && $pending['operationId']===$operationId && $pending['command']===$receipt['command']){$this->checkBinding($pending,$connection,$groupId,$operationId);if($known!==null)$this->assertPendingCurrent($uid,$known);$this->vault->clearOperation($uid);}
            return $receipt;
        });}finally{$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
    private function assertPendingCurrent(string $uid, array $expected): void { $current=$this->vault->operation($uid);if($current===null||!hash_equals(hash('sha256',Protocol::canonicalJson($expected)),hash('sha256',Protocol::canonicalJson($current))))throw new ConnectorException('outcome_unknown'); }
    private function checkBinding(array $pending, array $current, string $groupId, string $operationId): void {
        if(($pending['groupId']??null)!==$groupId || ($pending['operationId']??null)!==$operationId || !is_string($pending['connectionBinding']??null) || !is_string($current['connectionBinding']??null) || !hash_equals($pending['connectionBinding'],$current['connectionBinding']) || ($pending['instanceBaseUrl']??null)!==$current['instanceBaseUrl'] || ($pending['issuer']??null)!==Protocol::PARTICIPATION_ISSUER || $current['issuer']!==Protocol::PARTICIPATION_ISSUER) { throw new ConnectorException('recovery_binding_mismatch'); }
        Protocol::connectionBinding($pending['connectionBinding']);
    }
    public function reconcile(string $uid, string $groupId, string $operationId): array {
        Protocol::identifier($groupId);Protocol::operationId($operationId);$key='deferendum:operation:'.hash('sha256',$uid);$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try {
            return $this->lease($uid,Protocol::PARTICIPATE_SCOPE,function(array $connection)use($uid,$groupId,$operationId):array{
            $pending=$this->vault->operation($uid);if($pending===null)throw new ConnectorException('not_found');$this->checkBinding($pending,$connection,$groupId,$operationId);
            if(!is_string($pending['requestHash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$pending['requestHash'])||Protocol::date($pending['createdAt']??null)===null)throw new ConnectorException('recovery_binding_mismatch');
            $wrapped=$this->http->request('POST','/api/v2/groups/'.$groupId.'/operations/'.$operationId.'/reconcile',['connectionBinding'=>$pending['connectionBinding'],'pendingCreatedAt'=>$pending['createdAt'],'requestHash'=>$pending['requestHash']],$connection['accessToken']);$response=$wrapped['data']??[];
            if(!is_array($response)||($response['operationId']??null)!==$operationId)throw new ConnectorException('outcome_unknown');
            if(($response['status']??null)==='committed') { $result=ParticipationDto::receipt($response);if($result['command']!==$pending['command'])throw new ConnectorException('outcome_unknown'); }
            elseif(($response['status']??null)==='not_committed' && count($response)===3 && Protocol::date($response['resolvedAt']??null)!==null) { $result=['operationId'=>$operationId,'status'=>'not_committed','resolvedAt'=>$response['resolvedAt']]; }
            else throw new ConnectorException('outcome_unknown');
            $this->connections->assertConnectionCurrent($uid,$connection);$this->assertPendingCurrent($uid,$pending);$this->checkBinding($pending,$connection,$groupId,$operationId);$this->vault->clearOperation($uid);return $result;
            });
        } finally { $this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE); }
    }
    /** Explicit local-only recovery after the hosted retention window. This
     * never contacts the command/receipt API, retries, votes or undoes anything.
     * Reconnection to the original source actor is still required before clearing
     * an owner-bound block; missing legacy actor bindings fail closed.
     */
    public function discard(string $uid, string $groupId, string $operationId): array {
        Protocol::identifier($groupId);Protocol::operationId($operationId);$key='deferendum:operation:'.hash('sha256',$uid);$this->locks->acquireLock($key,ILockingProvider::LOCK_EXCLUSIVE);
        try{return $this->lease($uid,Protocol::SCOPE,function(array $connection)use($uid,$groupId,$operationId):array{
            $pending=$this->vault->operation($uid);if($pending===null)throw new ConnectorException('not_found');
            $this->checkBinding($pending,$connection,$groupId,$operationId);if(!$this->expiredPending($pending))throw new ConnectorException('pending_not_expired');
            $this->connections->assertConnectionCurrent($uid,$connection);$this->assertPendingCurrent($uid,$pending);$this->vault->clearOperation($uid);
            return ['operationId'=>$operationId,'status'=>'discarded_local','originalOutcome'=>'unknown'];
        });}finally{$this->locks->releaseLock($key,ILockingProvider::LOCK_EXCLUSIVE);}
    }
}
