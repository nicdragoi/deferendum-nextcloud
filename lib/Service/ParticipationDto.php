<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
/** Allowlisted projection: never forwards author, voter or credential fields. */
final class ParticipationDto {
    private static function object(mixed $v): array { if (!is_array($v) || ($v!==[] && array_is_list($v))) { throw new ConnectorException(); } return $v; }
    private static function list(mixed $v, callable $project, int $max=1000): array { if (!is_array($v) || !array_is_list($v) || count($v)>$max) { throw new ConnectorException(); } return array_map($project,$v); }
    private static function scalar(mixed $v): string|int|bool { if (is_bool($v) || is_int($v)) { return $v; } return Protocol::text($v,200); }
    private static function text(mixed $value, int $maximum): string { if(!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || strlen(mb_convert_encoding($value,'UTF-16LE','UTF-8'))/2>$maximum) { throw new ConnectorException(); } return $value; }
    private static function shape(mixed $raw, array $fields): array {
        $v=self::object($raw); $out=[];
        foreach ($fields as $key=>$type) {
            if (!array_key_exists($key,$v)) { throw new ConnectorException(); }
            $value=$v[$key]; $nullable=str_starts_with($type,'?'); if($nullable && $value===null) { $out[$key]=null; continue; } $type=ltrim($type,'?');
            $out[$key]=match($type) {'id'=>Protocol::identifier(is_string($value)?$value:''),'text'=>self::text($value,10000),'short'=>self::text($value,200),'bool'=>Protocol::boolean($value),'int'=>Protocol::integer($value),'number'=>Protocol::number($value),'date'=>Protocol::date($value),'scalar'=>self::scalar($value),default=>throw new ConnectorException()};
            if ($type==='date' && !$nullable && $out[$key]===null) { throw new ConnectorException(); }
        } return $out;
    }
    private static function claim(mixed $raw, string $idKey): array {
        $fields=[$idKey=>'id','status'=>'short']; if($idKey==='versionId'){$fields['canReview']='bool';}else{$fields['claimedByMe']='bool';$fields['respondedAt']='?date';}
        $r=self::shape($raw,$fields); if(!in_array($r['status'],['claimed','confirmed','contested'],true)) { throw new ConnectorException(); } return $r;
    }
    public static function context(array $raw): array {
        $r=self::shape($raw,['version'=>'short']); $r['group']=self::shape($raw['group']??null,['id'=>'id','name'=>'text']);
        $cap=self::object($raw['capabilities']??null);
        $r['capabilities']=self::shape($cap,['readIssues'=>'bool','readDiscussion'=>'bool','readGovernance'=>'bool']);
        $r['capabilities']['commands']=self::list($cap['commands']??null,static function($command): string { if(!is_string($command) || !in_array($command,ParticipationService::COMMANDS,true)) { throw new ConnectorException(); } return $command; },30);
        if(count(array_unique($r['capabilities']['commands']))!==count($r['capabilities']['commands'])) { throw new ConnectorException(); }
        if(array_key_exists('issues',$raw)) { $r['issues']=self::list($raw['issues'],static function($value): array {
            $row=self::shape($value,['id'=>'id','title'=>'text','body'=>'text','direction'=>'?text','supportingLink'=>'?text','status'=>'short','createdAt'=>'date','urgency'=>'?number','ratingCount'=>'int','myRating'=>'?int']);
            if($row['myRating']!==null && ($row['myRating']<1 || $row['myRating']>6)) { throw new ConnectorException(); } return $row;
        }); }
        if(array_key_exists('question',$raw)) {
            $r['question']=self::shape($raw['question'],['id'=>'id','kind'=>'short','governanceAmendmentId'=>'?id','phase'=>'short','phaseEndsAt'=>'?date','outcome'=>'?short','ballotPrivacy'=>'short','sourceIssueTitle'=>'?text','currentVersionId'=>'?id','canParticipate'=>'bool','canProposeRevision'=>'bool','supportTarget'=>'int','allowAbstention'=>'bool']);
            if(!in_array($r['question']['phase'],['framing','debate','voting','closed'],true) || !in_array($r['question']['ballotPrivacy'],['confidential','transparent'],true) || !in_array($r['question']['outcome'],[null,'passed','rejected','no_quorum','cancelled'],true)) { throw new ConnectorException(); }
        }
        if(array_key_exists('versions',$raw)) { $r['versions']=self::list($raw['versions'],static function($value): array { $v=self::object($value); $row=self::shape($v,['id'=>'id','versionNumber'=>'int','wording'=>'text','changeNote'=>'?text','status'=>'short','supportCount'=>'int','supportedByMe'=>'bool','isAuthor'=>'bool']); $row['claims']=self::list($v['claims']??null,static fn($claim)=>self::claim($claim,'argumentId'),30); return $row; }); }
        if(array_key_exists('arguments',$raw)) { $r['arguments']=self::list($raw['arguments'],static function($value): array {
            $v=self::object($value); $row=self::shape($v,['id'=>'id','side'=>'short','body'=>'text','createdAt'=>'date','likes'=>'int','dislikes'=>'int','myReaction'=>'?short','isAuthor'=>'bool','contentHidden'=>'bool','personallyBlocked'=>'bool']);
            if(!in_array($row['side'],['for','against'],true) || !in_array($row['myReaction'],[null,'like','dislike'],true)) { throw new ConnectorException(); }
            $row['claims']=self::list($v['claims']??null,static fn($claim)=>self::claim($claim,'versionId'),30);
            $row['comments']=self::list($v['comments']??null,static fn($comment)=>self::shape($comment,['id'=>'id','body'=>'text','createdAt'=>'date','contentHidden'=>'bool','personallyBlocked'=>'bool'])); return $row;
        }); }
        if(array_key_exists('vote',$raw)) {
            $v=self::object($raw['vote']); $r['vote']=self::shape($v,['countsAvailable'=>'bool','castCount'=>'int','eligibleCount'=>'int','isEligible'=>'bool','myChoice'=>'?short','castAt'=>'?date']);
            $r['vote']['counts']=self::shape($v['counts']??null,['yes'=>'int','no'=>'int','abstain'=>'int']); if(!in_array($r['vote']['myChoice'],[null,'yes','no','abstain'],true)) { throw new ConnectorException(); }
        }
        if(array_key_exists('governance',$raw)) {
            $g=self::object($raw['governance']); $r['governance']=self::shape($g,['version'=>'int']);
            $r['governance']['rules']=self::list($g['rules']??null,static function($value): array { $v=self::object($value); $row=self::shape($v,['key'=>'short','label'=>'text','description'=>'text','currentValue'=>'scalar','currentLabel'=>'text']); $row['options']=self::list($v['options']??null,static fn($o)=>self::shape($o,['value'=>'scalar','label'=>'text']),30); return $row; },30);
            $r['governance']['amendments']=self::list($g['amendments']??null,static fn($v)=>self::shape($v,['id'=>'id','ruleKey'=>'short','ruleLabel'=>'text','previousLabel'=>'text','proposedLabel'=>'text','rationale'=>'text','status'=>'short','createdAt'=>'date','appliedAt'=>'?date','proposedByMe'=>'bool','questionId'=>'?id','wording'=>'?text','phase'=>'?short','phaseEndsAt'=>'?date','outcome'=>'?short','myVote'=>'?short']));
        }
        if(array_key_exists('nextStep',$raw)) { $r['nextStep']=self::shape($raw['nextStep'],['nextStep'=>'text','status'=>'short','responsibility'=>'short','targetAt'=>'?date']); }
        return $r;
    }
    public static function receipt(array $raw): array {
        if(array_diff(array_keys($raw),['operationId','command','status','completedAt','entityId','version'])) { throw new ConnectorException(); }
        $r=self::shape($raw,['operationId'=>'short','command'=>'short','status'=>'short','completedAt'=>'date']); Protocol::operationId($r['operationId']);
        if($r['status']!=='committed' || !in_array($r['command'],ParticipationService::COMMANDS,true)) { throw new ConnectorException(); }
        if(array_key_exists('entityId',$raw)) {$r['entityId']=Protocol::identifier($raw['entityId']);} if(array_key_exists('version',$raw)) {$r['version']=Protocol::text($raw['version'],200);} return $r;
    }
}
