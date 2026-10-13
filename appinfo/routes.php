<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'proof#show', 'url' => '/instance-proof', 'verb' => 'GET'],
        ['name' => 'oauth#callback', 'url' => '/oauth/callback', 'verb' => 'GET'],
    ],
    'ocs' => [
        ['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
        ['name' => 'api#registerInstance', 'url' => '/api/instance', 'verb' => 'POST'],
        ['name' => 'api#connect', 'url' => '/api/connect', 'verb' => 'POST'],
        ['name' => 'api#disconnect', 'url' => '/api/connection', 'verb' => 'DELETE'],
        ['name' => 'api#groups', 'url' => '/api/groups', 'verb' => 'GET'],
        ['name' => 'api#topics', 'url' => '/api/groups/{groupId}/topics', 'verb' => 'GET'],
        ['name' => 'api#nextStep', 'url' => '/api/groups/{groupId}/decisions/{decisionId}/next-step', 'verb' => 'GET'],
        ['name' => 'participation#groupContext', 'url' => '/api/groups/{groupId}/context', 'verb' => 'GET'],
        ['name' => 'participation#decisionContext', 'url' => '/api/groups/{groupId}/decisions/{decisionId}/context', 'verb' => 'GET'],
        ['name' => 'participation#command', 'url' => '/api/groups/{groupId}/commands', 'verb' => 'POST'],
        ['name' => 'participation#operation', 'url' => '/api/groups/{groupId}/operations/{operationId}', 'verb' => 'GET'],
        ['name' => 'participation#pending', 'url' => '/api/pending-operation', 'verb' => 'GET'],
        ['name' => 'participation#reconcile', 'url' => '/api/groups/{groupId}/operations/{operationId}/reconcile', 'verb' => 'POST'],
        ['name' => 'participation#discard', 'url' => '/api/groups/{groupId}/operations/{operationId}/discard', 'verb' => 'POST'],
    ],
];
