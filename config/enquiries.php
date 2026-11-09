<?php

return [
    'classification_confidence_threshold' => 0.80,
    'intent_confidence_threshold' => 0.80,
    'legal_intents' => ['seek_legal_help', 'legal_advice', 'start_enquiry', 'instruct_solicitor', 'request_consultation', 'book_consultation'],
    'explicit_consultation_reasons' => ['user_requested_consultation', 'solicitor_review_requested', 'matter_requires_solicitor'],
    'matter_types' => [
        'conveyancing_purchase' => ['practice_area' => 'property_law', 'aliases' => ['conveyancing purchase']],
        'buying_a_property' => ['practice_area' => 'property_law', 'aliases' => ['buying', 'property_purchase', 'buying_property', 'purchase']],
        'selling_a_property' => ['practice_area' => 'property_law', 'aliases' => ['selling', 'property_sale', 'selling_property', 'sale']],
        'remortgage' => ['practice_area' => 'property_law', 'aliases' => ['remortgaging']],
        'divorce_and_separation' => ['practice_area' => 'family_law', 'aliases' => ['divorce', 'separation']],
        'making_a_will' => ['practice_area' => 'wills_and_probate', 'aliases' => ['will', 'wills', 'making_will', 'will_enquiry_v1']],
        'probate' => ['practice_area' => 'wills_and_probate', 'aliases' => ['estate_administration', 'grant_of_probate']],
    ],
];
