/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const el=id=>document.getElementById('deferendum-'+id);
    if (!el('participation')) return;
    const tr=text=>t('deferendum',text), language=(document.documentElement.lang||'en').split(/[-_]/)[0];
    const pane=el('participation');
    let generation=0, pendingEpoch=0, connection=null, context=null, selected=null, pending=null, pendingKnown=false, busy=false, confirming=false;
    const node=(tag,text)=>{const n=document.createElement(tag); if(text!==undefined)n.textContent=text; return n;};
    const api=async(path,method='GET',body)=>{
        try {
            const url=OC.generateUrl('/ocs/v2.php/apps/deferendum/api/'+path)+(path.includes('?')?'&':'?')+'format=json';
            const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','OCS-APIRequest':'true',requesttoken:OC.requestToken,...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
            const json=await response.json(), data=json.ocs?.data;
            if(!response.ok||!data||data.error)throw new Error(data?.error||'unavailable'); return data;
        } catch(error) { if(method==='POST' && !['invalid_input','validation','conflict','precondition_required','rate_limited','operation_pending','pending_not_expired','consent_required','recovery_binding_mismatch'].includes(error.message))throw new Error('outcome_unknown'); throw error; }
    };
    const reason=code=>tr(({conflict:'The source changed. Read it again before taking another action.',validation:'Check the entered values.',invalid_input:'Check the entered values.',precondition_required:'Read the current source before submitting.',rate_limited:'Too many attempts. Wait before trying again.',consent_required:'Participation requires new explicit consent.',recovery_binding_mismatch:'Reconnect with the original Deferendum account on the same instance to resolve this action.',operation_pending:'A previous action has an unresolved outcome.',pending_not_expired:'The recovery window has not expired. Check or resolve the operation outcome.',outcome_unknown:'The outcome is unknown. Do not repeat the action; check its status.'})[code]||'Data unavailable. Previous content is not shown.');
    const writable=()=>connection?.connected && connection.scopes.includes('deferendum:nextcloud:participate') && pendingKnown && !pending && !busy;
    const locks=()=>{for(const button of pane.querySelectorAll('[data-command]'))button.disabled=!writable();for(const statusButton of el('operation-status').querySelectorAll('button'))statusButton.disabled=busy;};
    const operationStatus=message=>{
        const area=el('operation-status'); area.replaceChildren(node('p',message||''));
        if(pending) { area.append(node('p',tr('Operation reference')+': '+pending.operationId)); const button=node('button',tr('Check operation status')); button.disabled=busy; button.addEventListener('click',reconcile); area.append(button);const resolve=node('button',tr('Resolve operation outcome'));resolve.disabled=busy;resolve.addEventListener('click',resolveOutcome);area.append(resolve);if(pending.canDiscard===true){const discard=node('button',tr('Discard expired pending operation'));discard.disabled=busy;discard.addEventListener('click',discardExpired);area.append(discard);} }
        locks();
    };
    const recoverPending=async()=>{const epoch=pendingEpoch;try{const result=await api('pending-operation');if(epoch!==pendingEpoch||busy)return;pending=result.pending;pendingEpoch++;pendingKnown=true;operationStatus(pending?reason('outcome_unknown'):'');}catch{if(epoch!==pendingEpoch||busy)return;pendingKnown=false;operationStatus(tr('Operation status unavailable. New actions are blocked.'));}};
    const reconcile=async()=>{
        if(!pending||busy)return; busy=true;locks();const target={...pending};
        try{const result=await api('groups/'+encodeURIComponent(target.groupId)+'/operations/'+encodeURIComponent(target.operationId));
            if(result.status==='committed' && result.operationId===target.operationId && result.command===target.command){pending=null;pendingEpoch++;pendingKnown=true;operationStatus(tr('Action recorded by Deferendum.'));if(selected)await read(selected);}
            else operationStatus(tr('No confirmed receipt yet. Do not repeat this action.'));
        }catch(error){operationStatus(error.message==='recovery_binding_mismatch'?reason(error.message):tr('Operation status unavailable. New actions are blocked.'));}finally{busy=false;locks();}
    };
    const resolveOutcome=async()=>{
        if(!pending||busy)return;const target={...pending},epoch=pendingEpoch;
        if(!await confirm([tr('Resolve operation outcome'),tr('Operation reference')+': '+target.operationId,tr('Action')+': '+target.command,tr('This checks the receipt or prevents an uncommitted operation from arriving late. It does not repeat the original action.')].join('\n\n'))||busy||epoch!==pendingEpoch)return;
        busy=true;locks();try{const result=await api('groups/'+encodeURIComponent(target.groupId)+'/operations/'+encodeURIComponent(target.operationId)+'/reconcile','POST',{});
            if(result.operationId!==target.operationId||!['committed','not_committed'].includes(result.status)||(result.status==='committed'&&result.command!==target.command))throw new Error('outcome_unknown');
            pending=null;pendingEpoch++;pendingKnown=true;operationStatus(tr(result.status==='committed'?'Action recorded by Deferendum.':'The operation was not committed and can no longer arrive late.'));if(selected)await read(selected);
        }catch(error){operationStatus(reason(error.message));}finally{busy=false;locks();}
    };
    const discardExpired=async()=>{
        if(!pending||pending.canDiscard!==true||busy)return;const target={...pending},epoch=pendingEpoch;
        if(!await confirm([tr('Discard expired pending operation'),tr('Operation reference')+': '+target.operationId,tr('Outcome no longer recoverable here; original action may have committed. Check Deferendum first. Removing this local block does not undo or retry it.')].join('\n\n'),true)||busy||epoch!==pendingEpoch)return;
        busy=true;locks();try{const result=await api('groups/'+encodeURIComponent(target.groupId)+'/operations/'+encodeURIComponent(target.operationId)+'/discard','POST',{});
            if(result.operationId!==target.operationId||result.status!=='discarded_local'||result.originalOutcome!=='unknown')throw new Error('outcome_unknown');
            pending=null;pendingEpoch++;pendingKnown=true;operationStatus(tr('Expired local block removed. The original action outcome remains unknown.'));if(selected)await read(selected);
        }catch(error){operationStatus(reason(error.message));}finally{busy=false;locks();}
    };
    const clear=()=>{generation++;context=null;selected=null;pane.replaceChildren();pane.hidden=true;const d=el('confirmation');if(d.open)d.close('cancel');};
    const confirm=(preview,localOnly=false)=>new Promise(resolve=>{
        if(confirming){resolve(false);return;} confirming=true;let accepted=false;const dialog=el('confirmation');dialog.returnValue='cancel';el('confirmation-text').textContent=preview;
        const impact=el('confirmation-impact');if(impact)impact.textContent=tr(localOnly?'This removes only the local pending-operation block. It does not change Deferendum.':'This changes the hosted Deferendum record. Votes cannot be changed after submission.');
        const finish=event=>{accepted=event.target===el('confirmation-accept');dialog.close(accepted?'confirm':'cancel');};
        const cancel=()=>{accepted=false;};
        const close=()=>{el('confirmation-cancel').removeEventListener('click',finish);el('confirmation-accept').removeEventListener('click',finish);dialog.removeEventListener('close',close);dialog.removeEventListener('cancel',cancel);confirming=false;resolve(accepted);};
        el('confirmation-cancel').addEventListener('click',finish);el('confirmation-accept').addEventListener('click',finish);dialog.addEventListener('close',close);dialog.addEventListener('cancel',cancel);dialog.showModal();el('confirmation-cancel').focus();
    });
    const submit=async(command,payload,label)=>{
        if(!writable()||!context?.capabilities.commands.includes(command))return;
        const view=generation, snapshot=context, location={...selected};
        const current=snapshot.versions?.find(v=>v.id===snapshot.question?.currentVersionId),amendment=command==='governance.vote'?snapshot.governance?.amendments.find(a=>a.id===payload.amendmentId):null;
        if(command==='governance.vote'&&!amendment && !(snapshot.question?.kind==='governance'&&snapshot.question.governanceAmendmentId===payload.amendmentId&&current)){operationStatus(tr('Read the current source before submitting.'));return;}
        const wordingPreview=amendment?[tr('Current wording')+': '+amendment.wording,tr('Previous value')+': '+amendment.previousLabel,tr('Proposed value')+': '+amendment.proposedLabel,tr('Vote choice')+': '+tr(payload.choice)]:current?[tr('Current wording')+': '+current.wording,tr('Wording version')+': '+current.versionNumber]:[];
        const preview=[tr('Group')+': '+snapshot.group.name,tr('Action')+': '+label,...wordingPreview,JSON.stringify(payload,null,2)].join('\n\n');
        if(!await confirm(preview)||view!==generation||!writable())return;
        const operationId=crypto.randomUUID();const body={operationId,command,payload,...(!command.startsWith('issue.')?{expectedVersion:snapshot.version}:{})};
        pending={operationId,groupId:location.groupId,command};pendingEpoch++;busy=true;operationStatus(tr('Submitting once. Do not repeat this action.'));
        try{const result=await api('groups/'+encodeURIComponent(location.groupId)+'/commands','POST',body);
            if(result.status!=='committed'||result.operationId!==operationId||result.command!==command)throw new Error('outcome_unknown');
            pending=null;pendingEpoch++;pendingKnown=true;operationStatus(tr('Action recorded by Deferendum.'));if(view===generation)await read(location);
        }catch(error){
            if(['invalid_input','validation','conflict','precondition_required','rate_limited','consent_required','operation_pending'].includes(error.message)){busy=false;await recoverPending();operationStatus(reason(error.message));}
            else operationStatus(reason('outcome_unknown'));
        }finally{busy=false;locks();}
    };
    const section=title=>{const s=node('section');s.className='deferendum-detail';s.append(node('h2',tr(title)));pane.append(s);return s;};
    const text=(parent,label,value)=>parent.append(node('p',tr(label)+': '+(value===null||value===undefined?tr('Not recorded'):String(value))));
    const allowed=command=>context.capabilities.commands.includes(command)&&connection?.scopes.includes('deferendum:nextcloud:participate');
    const button=(parent,command,label,payload)=>{if(!allowed(command))return;const b=node('button',tr(label));b.dataset.command=command;b.addEventListener('click',()=>submit(command,payload,tr(label)));parent.append(b);};
    const field=(form,label,type='textarea',options)=>{
        const wrapper=node('label',tr(label));let input;
        if(type==='select'){input=node('select');for(const option of options){const o=node('option',tr(option.label));o.value=String(option.value);input.append(o);}}
        else {input=node(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;}
        wrapper.append(input);form.append(wrapper);return input;
    };
    const form=(parent,command,label,build)=>{if(!allowed(command))return;const f=node('form');f.className='deferendum-action-form';const payload=build(f);const b=node('button',tr(label));b.type='submit';b.dataset.command=command;f.append(b);f.addEventListener('submit',event=>{event.preventDefault();try{const value=payload();submit(command,value,tr(label));}catch{operationStatus(tr('Check the entered values.'));}});parent.append(f);};
    const issues=()=>{
        if(!context.issues)return;const area=section('Concerns');
        form(area,'issue.create','Add concern',f=>{const title=field(f,'Concern title'),direction=field(f,'Suggested direction'),link=field(f,'Supporting link','url');title.required=true;title.minLength=8;title.maxLength=10000;direction.maxLength=10000;return()=>({title:title.value,...(direction.value?{direction:direction.value}:{}),...(link.value?{supportingLink:link.value}:{})});});
        for(const item of context.issues){const box=node('article');box.append(node('h3',item.title),node('p',item.body));text(box,'Saved status',tr(item.status));text(box,'Urgency',item.urgency);text(box,'Rating count',item.ratingCount);text(box,'Your rating',item.myRating);if(item.direction)text(box,'Suggested direction',item.direction);
            if(['open','queued','qualified'].includes(item.status))form(box,'issue.rate','Rate concern',f=>{const rating=field(f,'Your rating','select',Array.from({length:6},(_,i)=>({value:i+1,label:String(i+1)})));if(item.myRating)rating.value=String(item.myRating);return()=>({issueId:item.id,rating:Number(rating.value)});});area.append(box);}
    };
    const discussion=()=>{
        if(!context.arguments)return;const area=section('Arguments and comments');area.append(node('p',tr('Likes and dislikes are reactions, not votes or consensus. Comments have no reaction score.')));
        form(area,'argument.create','Add argument',f=>{const side=field(f,'Position','select',[{value:'for',label:'For'},{value:'against',label:'Against'}]),body=field(f,'Argument');body.required=true;body.maxLength=200;return()=>({decisionId:context.question.id,side:side.value,body:body.value});});
        for(const argument of context.arguments){const box=node('article');box.append(node('h3',tr(argument.side==='for'?'For':'Against')),node('p',argument.contentHidden||argument.personallyBlocked?tr('Content unavailable under current visibility rules.'):argument.body));text(box,'Likes',argument.likes);text(box,'Dislikes',argument.dislikes);text(box,'Your reaction',argument.myReaction?tr(argument.myReaction):null);
            if(!argument.contentHidden&&!argument.personallyBlocked){for(const reaction of ['like','dislike',null])button(box,'argument.react',reaction==='like'?'Like':reaction==='dislike'?'Dislike':'Remove reaction',{decisionId:context.question.id,argumentId:argument.id,reaction});
                for(const comment of argument.comments)box.append(node('p',comment.contentHidden||comment.personallyBlocked?tr('Content unavailable under current visibility rules.'):comment.body));
                form(box,'comment.create','Add comment',f=>{const body=field(f,'Comment');body.required=true;body.maxLength=10000;return()=>({decisionId:context.question.id,argumentId:argument.id,body:body.value});});}
            for(const claim of argument.claims){text(box,'Accommodation',tr(claim.status));if(claim.canReview)for(const response of ['confirmed','contested'])button(box,'wording.review',response==='confirmed'?'Confirm accommodation':'Contest accommodation',{decisionId:context.question.id,versionId:claim.versionId,argumentId:argument.id,response});}
            area.append(box);}
    };
    const wordings=()=>{
        if(!context.versions||!context.question)return;const area=section('Question wording');
        text(area,'Saved phase',tr(context.question.phase));text(area,'Deadline',context.question.phaseEndsAt);text(area,'Support target',context.question.supportTarget);
        for(const version of context.versions){const box=node('article');text(box,'Wording version',version.versionNumber);box.append(node('p',version.wording));if(version.changeNote)text(box,'Reason for change',version.changeNote);text(box,'Saved status',tr(version.status));text(box,'Support count',version.supportCount);text(box,'Your support',tr(version.supportedByMe?'Yes':'No'));
            const isFraming=context.question.phase==='framing',command=isFraming?'framing.support':'wording.support',payload={versionId:version.id,supported:!version.supportedByMe,...(isFraming?{expectedQuestionId:context.question.id}:{decisionId:context.question.id})};if(isFraming || (context.question.phase==='debate'&&version.status==='proposed'&&version.claims.length))button(box,command,version.supportedByMe?'Withdraw wording support':'Support wording',payload);for(const claim of version.claims)text(box,'Accommodation',tr(claim.status));area.append(box);}
        if(context.question.phase==='framing')form(area,'framing.propose','Propose wording',f=>{const wording=field(f,'Proposed wording'),note=field(f,'Reason for change');wording.required=true;wording.minLength=8;wording.maxLength=10000;note.maxLength=1000;return()=>({expectedQuestionId:context.question.id,wording:wording.value,...(note.value?{changeNote:note.value}:{})});});
        else if(context.question.canProposeRevision)form(area,'wording.propose','Propose revised wording',f=>{const wording=field(f,'Proposed wording'),change=field(f,'Reason for change');wording.required=true;wording.minLength=8;wording.maxLength=10000;change.required=true;change.minLength=8;change.maxLength=1000;const list=node('fieldset');list.append(node('legend',tr('Arguments addressed')));const boxes=[];for(const argument of context.arguments||[]){if(argument.contentHidden||argument.personallyBlocked)continue;const label=node('label',argument.body),input=node('input');input.type='checkbox';label.prepend(input);list.append(label);boxes.push({id:argument.id,input});}f.append(list);return()=>({decisionId:context.question.id,wording:wording.value,changeNote:change.value,argumentIds:boxes.filter(x=>x.input.checked).map(x=>x.id)});});
    };
    const governance=()=>{
        if(!context.governance)return;const area=section('Governing rules');text(area,'Rules version',context.governance.version);
        for(const rule of context.governance.rules){const box=node('article');box.append(node('h3',rule.label),node('p',rule.description));text(box,'Current value',rule.currentLabel);form(box,'governance.propose','Propose rule change',f=>{const option=field(f,'Proposed value','select',rule.options.map((x,index)=>({value:index,label:x.label}))),rationale=field(f,'Rationale');rationale.required=true;rationale.minLength=20;rationale.maxLength=2000;return()=>({ruleKey:rule.key,proposedValue:rule.options[Number(option.value)].value,rationale:rationale.value});});area.append(box);}
        for(const amendment of context.governance.amendments){const box=node('article');box.append(node('h3',amendment.ruleLabel));text(box,'Previous value',amendment.previousLabel);text(box,'Proposed value',amendment.proposedLabel);text(box,'Rationale',amendment.rationale);text(box,'Saved phase',amendment.phase?tr(amendment.phase):null);text(box,'Current wording',amendment.wording);text(box,'Your vote',amendment.myVote?tr(amendment.myVote):null);
            const open=node('button',tr('Read decision workspace'));open.addEventListener('click',()=>read({groupId:selected.groupId,decisionId:amendment.questionId}));box.append(open);area.append(box);}
    };
    const ballot=()=>{
        if(!context.vote||!context.question)return;const area=section('Vote');const q=context.question,v=context.vote,current=context.versions?.find(x=>x.id===q.currentVersionId);if(current)text(area,'Current wording',current.wording);text(area,'Ballot privacy',tr(q.ballotPrivacy));text(area,'Your vote',v.myChoice?tr(v.myChoice):null);text(area,'Votes cast',v.castCount);text(area,'Eligible members',v.eligibleCount);
        if(v.countsAvailable)for(const [choice,count]of Object.entries(v.counts))text(area,tr(choice),count);else area.append(node('p',tr('Vote totals are not available in this phase.')));
        if(q.phase==='voting'&&v.isEligible&&v.myChoice===null&&current&&(q.kind==='decision'||(q.kind==='governance'&&q.governanceAmendmentId)))form(area,q.kind==='governance'?'governance.vote':'decision.vote','Cast vote',f=>{const options=[{value:'yes',label:'Yes'},{value:'no',label:'No'},...(q.allowAbstention?[{value:'abstain',label:'Abstain'}]:[])],choice=field(f,'Vote choice','select',options),placeholder=node('option',tr('Choose a vote option'));placeholder.value='';placeholder.disabled=true;placeholder.selected=true;choice.prepend(placeholder);choice.required=true;f.append(node('p',tr('Your confirmed vote cannot be changed.')));return()=>({...q.kind==='governance'?{amendmentId:q.governanceAmendmentId}:{decisionId:q.id},choice:choice.value});});
        else area.append(node('p',tr('Voting is unavailable for this account or phase.')));
    };
    const render=()=>{pane.replaceChildren();pane.hidden=false;pane.append(node('h2',context.group.name));pane.append(node('p',tr('Available controls remain subject to your current Deferendum role, rules and phase.')));if(!context.capabilities.commands.length)pane.append(node('p',tr('This workspace is read-only under the current permissions.')));issues();wordings();discussion();governance();ballot();if(context.nextStep){const area=section('Next step');text(area,'Next step',context.nextStep.nextStep);text(area,'Saved status',tr(context.nextStep.status));text(area,'Responsibility',tr(context.nextStep.responsibility));}locks();};
    const read=async(location)=>{const current=++generation;context=null;selected=location;pane.replaceChildren(node('p',tr('Reading current source…')));pane.hidden=false;
        try{const path='groups/'+encodeURIComponent(location.groupId)+(location.decisionId?'/decisions/'+encodeURIComponent(location.decisionId):'')+'/context?language='+encodeURIComponent(language),result=await api(path);if(current!==generation)return;context=result.data;render();text(pane,'Read at',result.fetchedAt);}catch(error){if(current!==generation)return;context=null;pane.replaceChildren(node('p',reason(error.message)));}
    };
    el('workspace').addEventListener('click',()=>{const groupId=el('groups').value;if(groupId)read({groupId});});
    document.addEventListener('deferendum:decision',event=>read(event.detail));
    document.addEventListener('deferendum:connection',event=>{connection=event.detail;locks();recoverPending();});
    document.addEventListener('deferendum:clear',clear);
    document.addEventListener('visibilitychange',()=>{if(document.hidden)clear();});
    recoverPending();
})();
