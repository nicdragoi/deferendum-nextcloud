/* SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const root = document.getElementById('deferendum-app');
    if (!root) return;
    const el = id => document.getElementById('deferendum-' + id);
    const tr = text => t('deferendum', text);
    const language = (document.documentElement.lang || 'en').split(/[-_]/)[0];
    let page = null; let generation = 0; let discussionEnabled = false;
    const api = async (path, method = 'GET', body = undefined) => {
        const url = OC.generateUrl('/ocs/v2.php/apps/deferendum/api/' + path) + (path.includes('?') ? '&' : '?') + 'format=json';
        const response = await fetch(url, {method, credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken, ...(body ? {'Content-Type': 'application/json'} : {})}, ...(body ? {body: JSON.stringify(body)} : {})});
        const json = await response.json(); const data = json.ocs?.data;
        if (!response.ok || !data || data.error) throw new Error(data?.error || 'unavailable');
        return data;
    };
    const clear = () => { generation++; el('topics').replaceChildren(); el('fetched').textContent = ''; el('more').hidden = true; page = null; document.dispatchEvent(new CustomEvent('deferendum:clear')); };
    const fail = () => { clear(); el('groups').replaceChildren(); el('browser').hidden = true; el('status').textContent = tr('Data unavailable. Previous content is not shown.'); };
    const run = fn => async () => { try { await fn(); } catch { fail(); } };
    const status = async () => {
        const s = await api('status');
        discussionEnabled = s.connected && s.participationAvailable && s.scopes.includes('deferendum:nextcloud:discussion');
        el('workspace').hidden = !discussionEnabled;
        el('permission-label').hidden = s.canWithdraw || !s.registered;
        for (const option of el('permission').options) option.disabled = option.value !== 'read' && !s.participationAvailable;
        el('permission-status').textContent = !s.participationAvailable ? tr('An administrator must register the participation service before using expanded permissions.') : s.connected && !s.scopes.includes('deferendum:nextcloud:participate') ? tr('For native actions, withdraw this connection and reconnect with participation permissions.') : '';
        document.dispatchEvent(new CustomEvent('deferendum:connection', {detail:s}));
        el('connect').hidden = !s.registered || s.canWithdraw; el('disconnect').hidden = !s.canWithdraw; el('refresh').hidden = !s.connected;
        if (el('registration')) { el('registration').hidden = false; el('replace-label').hidden = !s.registered; }
        el('status').textContent = s.connected ? tr('Connected. Saved source text is shown without automatic translation.') : s.canWithdraw ? tr('Reading is blocked. Withdraw access before connecting again.') : s.registered ? tr('Connect your own Deferendum account') : tr('Ask your administrator to register this instance.');
        if (!s.connected) { clear(); el('groups').replaceChildren(); el('browser').hidden = true; }
        return s;
    };
    const sourceLink = (url, text) => {
        const target = new URL(url); if (target.protocol !== 'https:' || target.hostname !== 'app.deferendum.com' || target.username || target.password || target.port) throw new Error('invalid_source');
        const a = document.createElement('a'); a.href = target.href; a.textContent = text; a.target = '_blank'; a.rel = 'noopener noreferrer'; return a;
    };
    const date = value => value ? new Date(value).toLocaleString() : tr('Not recorded');
    const topics = async (append = false) => {
        if (!append) clear();
        const current = generation; const group = el('groups').value; if (!group) return;
        const kind = el('kind').value; const view = el('view').value; const offset = append ? page : 0;
        if (offset === null) return;
        const result = await api('groups/' + encodeURIComponent(group) + '/topics?' + new URLSearchParams({language, kind, view, offset, limit: 50}));
        if (current !== generation) return;
        const fragment = document.createDocumentFragment();
        for (const item of result.items) {
            const li = document.createElement('li'); li.append(sourceLink(item.url, item.title));
            const meta = document.createElement('p'); meta.textContent = tr('Saved phase') + ': ' + tr(item.phase) + ' · ' + tr('Deadline') + ': ' + date(item.deadline); li.append(meta);
            if (kind === 'decision' && discussionEnabled) { const button=document.createElement('button'); button.textContent=tr('Read decision workspace'); button.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('deferendum:decision',{detail:{groupId:group,decisionId:item.id}}))); li.append(button); }
            if (kind === 'decision' && item.phase === 'closed') {
                try { const step = await api('groups/' + encodeURIComponent(group) + '/decisions/' + encodeURIComponent(item.id) + '/next-step?language=' + encodeURIComponent(language));
                    if (current !== generation) return;
                    const next = document.createElement('p'); next.textContent = tr('Next step') + ': ' + step.nextStep + '\n' + tr('Saved status') + ': ' + tr(step.status) + ' · ' + tr('Responsibility') + ': ' + tr(step.responsibility) + ' · ' + tr('Target date') + ': ' + date(step.targetAt); li.append(next);
                } catch (e) { if (e.message !== 'not_found') throw e; }
            }
            fragment.append(li);
        }
        if (current !== generation) return;
        el('topics').append(fragment); page = result.nextOffset; el('more').hidden = page === null;
        el('fetched').textContent = tr('Read at') + ': ' + date(result.fetchedAt);
        if (!append && result.items.length === 0) el('topics').textContent = tr('No items in this view.');
    };
    const load = async () => {
        clear(); el('groups').replaceChildren();
        if (!(await status()).connected) return;
        const current = generation; const all = []; let offset = 0;
        do { const result = await api('groups?' + new URLSearchParams({language, offset, limit: 50})); if (current !== generation) return; all.push(...result.items); offset = result.nextOffset; if (all.length > 100) throw new Error('invalid_groups'); } while (offset !== null);
        const fragment = document.createDocumentFragment(); for (const group of all) { const option = document.createElement('option'); option.value = group.id; option.textContent = group.name; fragment.append(option); }
        el('groups').append(fragment); el('browser').hidden = false; await topics();
    };
    if (el('register')) el('register').addEventListener('click', run(async () => { el('register').disabled = true; try { clear(); await api('instance', 'POST', {baseUrl: el('base-url').value, replace: el('replace').checked}); el('replace').checked = false; await status(); } finally { el('register').disabled = false; } }));
    el('connect').addEventListener('click', run(async () => { clear(); const scopes=['deferendum:nextcloud:read']; if (['discussion','participate'].includes(el('permission').value)) scopes.push('deferendum:nextcloud:discussion'); if (el('permission').value==='participate') scopes.push('deferendum:nextcloud:participate'); const result = await api('connect', 'POST', {requestedScopes:scopes.join(' ')}); const issuers=['https://app.deferendum.com/integrations/nextcloud','https://app.deferendum.com/integrations/nextcloud-participation']; if(!issuers.includes(result.authorizeIssuer))throw new Error('invalid_authorize');const issuer=new URL(result.authorizeIssuer),url = new URL(result.authorizeUrl); if (url.origin !== issuer.origin || url.pathname !== issuer.pathname+'/oauth/authorize' || url.username || url.password || url.hash) throw new Error('invalid_authorize'); window.location.assign(url.href); }));
    el('disconnect').addEventListener('click', run(async () => { clear(); await api('connection', 'DELETE'); await status(); }));
    el('refresh').addEventListener('click', run(load));
    el('more').addEventListener('click', run(() => topics(true)));
    for (const id of ['groups', 'kind', 'view']) el(id).addEventListener('change', run(() => topics()));
    document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); else run(load)(); });
    // No member content persists in localStorage, service workers, browser cache or application database.
    run(load)();
})();
