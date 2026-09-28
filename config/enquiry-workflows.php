<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default workflow
    |--------------------------------------------------------------------------
    */

    'default' => 'will_enquiry_v1',

    /*
    |--------------------------------------------------------------------------
    | Workflow definitions
    |--------------------------------------------------------------------------
    */

    'workflows' => [

        'will_enquiry_v1' => [
            'key' => 'will_enquiry_v1',
            'name' => 'Will Enquiry Questionnaire',
            'practice_area' => 'wills_and_probate',
            'version' => '2.0',
            'first_step' => 'urgent_will',

            'steps' => [

                /*
                |--------------------------------------------------------------------------
                | Urgency
                |--------------------------------------------------------------------------
                */

                'urgent_will' => [
                    'key' => 'urgent_will',
                    'question_key' => 'urgent_will',
                    'question' => 'Do you need an urgent Will?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'equals',
                                'value' => true,
                                'step' => 'urgent_will_details',
                            ],
                        ],

                        'default' => 'relationship_status',
                    ],
                ],

                /*
                 * The supplied diagram does not show where the Yes
                 * branch ends. Until the final business rule is supplied,
                 * capture the reason and continue through the questionnaire.
                 */
                'urgent_will_details' => [
                    'key' => 'urgent_will_details',
                    'question_key' => 'urgent_will_details',
                    'question' => 'Please briefly explain why the Will is urgent.',
                    'type' => 'textarea',
                    'required' => true,
                    'minimum_length' => 3,
                    'maximum_length' => 2000,

                    'next' => 'relationship_status',
                ],

                /*
                |--------------------------------------------------------------------------
                | About you
                |--------------------------------------------------------------------------
                |
                | Name, email and telephone are already populated from the
                | authenticated user account when the enquiry is created.
                |--------------------------------------------------------------------------
                */

                'relationship_status' => [
                    'key' => 'relationship_status',
                    'question_key' => 'relationship_status',
                    'question' => 'What is your relationship status?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'separated',
                            'label' => 'Separated',
                        ],
                        [
                            'value' => 'divorced',
                            'label' => 'Divorced',
                        ],
                        [
                            'value' => 'widowed',
                            'label' => 'Widowed or widower',
                        ],
                        [
                            'value' => 'single',
                            'label' => 'Single',
                        ],
                        [
                            'value' => 'married',
                            'label' => 'Married',
                        ],
                        [
                            'value' => 'cohabiting',
                            'label' => 'Cohabiting',
                        ],
                    ],

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'in',
                                'value' => [
                                    'separated',
                                    'divorced',
                                ],
                                'step' => 'family_law_support',
                            ],
                            [
                                'operator' => 'equals',
                                'value' => 'widowed',
                                'step' => 'probate_support',
                            ],
                            [
                                'operator' => 'equals',
                                'value' => 'cohabiting',
                                'step' => 'declaration_of_trust_support',
                            ],
                        ],

                        'default' => 'has_children',
                    ],
                ],

                /*
                |--------------------------------------------------------------------------
                | Additional legal support
                |--------------------------------------------------------------------------
                */

                'family_law_support' => [
                    'key' => 'family_law_support',
                    'question_key' => 'family_law_support',
                    'question' => 'Do you need support from our Family Law team as well?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'divorce',
                            'label' => 'Divorce',
                        ],
                        [
                            'value' => 'child_arrangements',
                            'label' => 'Child Arrangements',
                        ],
                        [
                            'value' => 'family_arrangements',
                            'label' => 'Family Arrangements',
                        ],
                        [
                            'value' => 'selling_property',
                            'label' => 'Selling Property',
                        ],
                        [
                            'value' => 'no',
                            'label' => 'No',
                        ],
                    ],

                    'next' => 'has_children',
                ],

                'probate_support' => [
                    'key' => 'probate_support',
                    'question_key' => 'probate_support',
                    'question' => 'Do you need support from our Probate team as well?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'probate',
                            'label' => 'Probate',
                        ],
                        [
                            'value' => 'lpa',
                            'label' => 'Lasting Power of Attorney',
                        ],
                        [
                            'value' => 'something_else',
                            'label' => 'Something else',
                        ],
                        [
                            'value' => 'no',
                            'label' => 'No',
                        ],
                    ],

                    'next' => 'has_children',
                ],

                'declaration_of_trust_support' => [
                    'key' => 'declaration_of_trust_support',
                    'question_key' => 'declaration_of_trust_support',
                    'question' => 'If you own property together, do you need a Declaration of Trust?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'yes',
                            'label' => 'Yes',
                        ],
                        [
                            'value' => 'no',
                            'label' => 'No',
                        ],
                        [
                            'value' => 'not_applicable',
                            'label' => 'Not applicable',
                        ],
                    ],

                    'next' => 'has_children',
                ],

                /*
                |--------------------------------------------------------------------------
                | Children
                |--------------------------------------------------------------------------
                */

                'has_children' => [
                    'key' => 'has_children',
                    'question_key' => 'has_children',
                    'question' => 'Do you have any children?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'equals',
                                'value' => true,
                                'step' => 'children_extra_protection',
                            ],
                        ],

                        'default' => 'owns_property',
                    ],
                ],

                'children_extra_protection' => [
                    'key' => 'children_extra_protection',
                    'question_key' => 'children_extra_protection',
                    'question' => 'Do you have any children for whom you would like to put extra protections in place?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'equals',
                                'value' => true,
                                'step' => 'children_extra_protection_details',
                            ],
                        ],

                        'default' => 'owns_property',
                    ],
                ],

                'children_extra_protection_details' => [
                    'key' => 'children_extra_protection_details',
                    'question_key' => 'children_extra_protection_details',
                    'question' => 'Please give us some more information about the protections you would like to put in place.',
                    'type' => 'textarea',
                    'required' => true,
                    'minimum_length' => 3,
                    'maximum_length' => 3000,

                    'next' => 'owns_property',
                ],

                /*
                |--------------------------------------------------------------------------
                | Property and location
                |--------------------------------------------------------------------------
                */

                'owns_property' => [
                    'key' => 'owns_property',
                    'question_key' => 'owns_property',
                    'question' => 'Do you own your property?',
                    'type' => 'boolean',
                    'required' => true,

                    /*
                     * Both routes in the supplied flow continue to the
                     * England and Wales question.
                     */
                    'next' => 'lives_in_england_or_wales',
                ],

                'lives_in_england_or_wales' => [
                    'key' => 'lives_in_england_or_wales',
                    'question_key' => 'lives_in_england_or_wales',
                    'question' => 'Do you live in England or Wales?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'equals',
                                'value' => false,
                                'step' => 'country_of_residence',
                            ],
                        ],

                        'default' => 'all_assets_in_uk',
                    ],
                ],

                'country_of_residence' => [
                    'key' => 'country_of_residence',
                    'question_key' => 'country_of_residence',
                    'question' => 'Where do you live?',
                    'type' => 'text',
                    'required' => true,
                    'minimum_length' => 2,
                    'maximum_length' => 255,

                    'next' => 'owns_business',
                ],

                'all_assets_in_uk' => [
                    'key' => 'all_assets_in_uk',
                    'question_key' => 'all_assets_in_uk',
                    'question' => 'Is everything you own located in the UK?',
                    'type' => 'boolean',
                    'required' => true,

                    /*
                     * The diagram continues from both Yes and No routes
                     * into the remaining suitability questions.
                     */
                    'next' => 'owns_business',
                ],

                /*
                |--------------------------------------------------------------------------
                | Business and charity
                |--------------------------------------------------------------------------
                */

                'owns_business' => [
                    'key' => 'owns_business',
                    'question_key' => 'owns_business',
                    'question' => 'Do you own a business?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => 'donate_to_cruk',
                ],

                'donate_to_cruk' => [
                    'key' => 'donate_to_cruk',
                    'question_key' => 'donate_to_cruk',
                    'question' => 'Would you like to leave a donation to Cancer Research UK in your Will?',
                    'type' => 'boolean',
                    'required' => true,

                    'next' => [
                        'rules' => [
                            [
                                'operator' => 'equals',
                                'value' => true,
                                'step' => 'cruk_confirmation',
                            ],
                        ],

                        'default' => 'will_delivery_method',
                    ],
                ],

                'cruk_confirmation' => [
                    'key' => 'cruk_confirmation',
                    'question_key' => 'cruk_confirmation',
                    'question' => 'Thank you. We will record this as a Cancer Research UK Will enquiry. How would you prefer to complete your Will?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'online',
                            'label' => 'Online',
                        ],
                        [
                            'value' => 'virtual',
                            'label' => 'Virtual appointment',
                        ],
                        [
                            'value' => 'face_to_face',
                            'label' => 'Face-to-face appointment',
                        ],
                    ],

                    'next' => null,
                ],

                /*
                |--------------------------------------------------------------------------
                | Result
                |--------------------------------------------------------------------------
                |
                | The supplied screenshot shows Online, CRUK Will, Virtual
                | and Face to Face outcomes, but does not expose the hidden
                | selection rules. This question lets the full journey be
                | tested safely until those rules are confirmed.
                |--------------------------------------------------------------------------
                */

                'will_delivery_method' => [
                    'key' => 'will_delivery_method',
                    'question_key' => 'will_delivery_method',
                    'question' => 'How would you prefer to complete your Will?',
                    'type' => 'single_choice',
                    'required' => true,

                    'options' => [
                        [
                            'value' => 'online',
                            'label' => 'Online',
                        ],
                        [
                            'value' => 'virtual',
                            'label' => 'Virtual appointment',
                        ],
                        [
                            'value' => 'face_to_face',
                            'label' => 'Face-to-face appointment',
                        ],
                    ],

                    'next' => null,
                ],
            ],
        ],
    ],
];