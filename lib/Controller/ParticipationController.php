<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Controller;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Deferendum\Service\{ParticipationService,ConnectorException};
final class ParticipationController extends OCSController {
    public function __construct(IRequest $request, private IUserSession $users, private ParticipationService $participation) { parent::__construct('deferendum',$request); }
    private function uid(): string { $uid=$this->users->getUser()?->getUID(); if($uid===null) { throw new ConnectorException('unauthorized'); } return $uid; }
    private function result(callable $call): DataResponse {
        try { $response=new DataResponse($call()); }
        catch(ConnectorException $e) { $response=new DataResponse(['error'=>$e->reason],match($e->reason) {'unauthorized'=>401,'forbidden','consent_required','recovery_binding_mismatch'=>403,'not_found'=>404,'invalid_input'=>400,'conflict','operation_pending','pending_not_expired'=>409,'precondition_required'=>428,'validation'=>422,'rate_limited'=>429,default=>503}); }
        catch(\Throwable) { $response=new DataResponse(['error'=>'unavailable'],503); }
        $response->addHeader('Cache-Control','no-store'); $response->addHeader('Referrer-Policy','no-referrer'); return $response;
    }
    #[NoAdminRequired]
    #[UserRateLimit(limit:60,period:60)]
    public function groupContext(string $groupId, string $language='en'): DataResponse { return $this->result(fn()=>$this->participation->groupContext($this->uid(),$groupId,$language)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:60,period:60)]
    public function decisionContext(string $groupId, string $decisionId, string $language='en'): DataResponse { return $this->result(fn()=>$this->participation->decisionContext($this->uid(),$groupId,$decisionId,$language)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:30,period:60)]
    public function command(string $groupId, string $operationId='', string $command='', array $payload=[], ?string $expectedVersion=null): DataResponse { return $this->result(fn()=>$this->participation->submit($this->uid(),$groupId,$operationId,$command,$payload,$expectedVersion)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:60,period:60)]
    public function operation(string $groupId, string $operationId): DataResponse { return $this->result(fn()=>$this->participation->operation($this->uid(),$groupId,$operationId)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:60,period:60)]
    public function pending(): DataResponse { return $this->result(fn()=>$this->participation->pending($this->uid())); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:30,period:60)]
    public function reconcile(string $groupId, string $operationId): DataResponse { return $this->result(fn()=>$this->participation->reconcile($this->uid(),$groupId,$operationId)); }
    #[NoAdminRequired]
    #[UserRateLimit(limit:30,period:60)]
    public function discard(string $groupId, string $operationId): DataResponse { return $this->result(fn()=>$this->participation->discard($this->uid(),$groupId,$operationId)); }
}
