<?php

return [
    'roles' => [
        'super_admin' => [
            'dashboard.view',
            'enquiries.view',
            'work.view',
            'follow_ups.view',
            'quotes.view',
            'conversations.view',
            'escalations.view',
            'team.view',
            'team.manage',
            'reports.view',
            'settings.view',
            'settings.manage',
        ],
        'team_member' => [
            'dashboard.view',
            'enquiries.view',
            'work.view',
            'follow_ups.view',
            'quotes.view',
            'conversations.view',
            'escalations.view',
        ],
        'basic' => [
            'dashboard.view',
            'work.view',
            'follow_ups.view',
            'quotes.view',
        ],
    ],

    'role_priority' => ['super_admin', 'team_member', 'basic'],
];
