
<?php

return [
    'roles' => [
        'super_admin' => [
            'dashboard.view',
            'enquiries.view',
            'enquiries.assign',
            'enquiries.update_status',
            'enquiries.notes.create',
            'work.view',
            'follow_ups.view',
            'quotes.view',
            'conversations.view',
            'escalations.view',
            'team.view',
            'team.manage',
            'roles.manage',
            'reports.view',
            'reports.export',
            'audit.view',
            'settings.view',
            'settings.manage',
        ],

        'enquiry_team_manager' => [
            'dashboard.view',
            'enquiries.view',
            'enquiries.assign',
            'enquiries.update_status',
            'enquiries.notes.create',
            'work.view',
            'follow_ups.view',
            'quotes.view',
            'conversations.view',
            'escalations.view',
            'team.view',
            'reports.view',
        ],

        'enquiry_team_member' => [
            'dashboard.view',
            'enquiries.view',
            'enquiries.update_status',
            'enquiries.notes.create',
            'work.view',
            'follow_ups.view',
            'quotes.view',
            'conversations.view',
            'escalations.view',
        ],

        'solicitor' => [
            'dashboard.view',
            'enquiries.view',
            'enquiries.update_status',
            'enquiries.notes.create',
            'work.view',
            'conversations.view',
            'escalations.view',
        ],

        'reporting_only' => [
            'dashboard.view',
            'reports.view',
            'reports.export',
        ],

        // Legacy roles retained during migration.
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

    'role_priority' => [
        'super_admin',
        'enquiry_team_manager',
        'enquiry_team_member',
        'solicitor',
        'reporting_only',
        'team_member',
        'basic',
    ],
];
