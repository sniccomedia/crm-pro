<?php

namespace FluentCampaign\App\Services\Funnel\Actions;

use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Includes\Helpers\Arr;
use FluentCrm\Includes\Parser\Parser;

class UserRegistrationAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'user_registration_action';
        $this->priority = 99;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => 'WordPress',
            'title'       => __('Create WP User', 'fluentcampaign-pro'),
            'description' => __('Create WP User with a role if user is not already registered with contact email', 'fluentcampaign-pro'),
            'icon'        => fluentCrmMix('images/funnel_icons/create_wp_user.svg'),
            'settings'    => [
                'user_role'                    => 'subscriber',
                'send_user_notification_email' => 'no',
                'auto_generate_password'       => 'yes',
                'custom_password'              => '',
                'meta_properties'              => [
                    [
                        'data_key'   => '',
                        'data_value' => ''
                    ]
                ]
            ]
        ];
    }

    public function getBlockFields()
    {

        return [
            'title'     => __('Create WordPress User', 'fluentcampaign-pro'),
            'sub_title' => __('Create WP User with a role if user is not already registered with contact email', 'fluentcampaign-pro'),
            'fields'    => [
                'user_role'                    => [
                    'type'    => 'select',
                    'label'   => 'User Role',
                    'options' => FunnelHelper::getUserRoles()
                ],
                'auto_generate_password'       => [
                    'type'        => 'yes_no_check',
                    'label'       => 'Password',
                    'check_label' => __('Generate Password Automatically', 'fluentcampaign-pro')
                ],
                'custom_password'              => [
                    'type'        => 'input-text-popper',
                    'placeholder' => 'Custom Password',
                    'label'       => 'Provide Custom User Password',
                    'inline_help' => 'If you leave blank then auto generated password will be set',
                    'dependency'  => [
                        'depends_on' => 'auto_generate_password',
                        'operator'   => '=',
                        'value'      => 'no'
                    ]
                ],
                'meta_properties'              => [
                    'label'                  => __('User Meta Mapping', 'fluentcampaign-pro'),
                    'type'                   => 'text-value-multi-properties',
                    'data_key_label'         => 'User Meta Key',
                    'data_value_label'       => 'User Meta Value',
                    'data_value_placeholder' => 'Meta Value',
                    'data_key_placeholder'   => 'Meta key',
                    'help'                   => 'If you want to map user meta properties you can add that here. This is totally optional',
                    'value_input_type'       => 'text-popper'
                ],
                'send_user_notification_email' => [
                    'type'        => 'yes_no_check',
                    'label'       => 'User Notification',
                    'check_label' => __('Send WordPress user notification email', 'fluentcampaign-pro')
                ]
            ]
        ];
    }

    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {

        $user = get_user_by('email', $subscriber->email);
        if ($user) {
            $funnelMetric->notes = 'Funnel Skipped because user already exist in the database';
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        $settings = $sequence->settings;

        if ($settings['auto_generate_password'] == 'yes' || empty($settings['custom_password'])) {
            $password = wp_generate_password(8);
        } else {
            $password = Parser::parse($settings['custom_password'], $subscriber);
        }

        $userId = wp_create_user($subscriber->email, $password, $subscriber->email);
        if (is_wp_error($userId)) {
            $funnelMetric->notes = 'Error when creating new User. ' . $userId->get_error_message();
            $funnelMetric->save();
            FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
            return false;
        }

        if ($userRole = Arr::get($settings, 'user_role')) {
            $user = new \WP_User($userId);
            $user->set_role($userRole);
        }

        $userMetas = [
            'first_name' => $subscriber->first_name,
            'last_name'  => $subscriber->last_name
        ];

        foreach ($settings['meta_properties'] as $pair) {
            if (empty($pair['data_key']) || empty($pair['data_value'])) {
                continue;
            }
            $userMetas[sanitize_text_field($pair['data_key'])] = Parser::parse($pair['data_value'], $subscriber);
        }

        $userMetas = array_filter($userMetas);
        foreach ($userMetas as $metaKey => $metaValue) {
            update_user_meta($userId, $metaKey, $metaValue);
        }

        if (Arr::get($settings, 'send_user_notification_email') == 'yes') {
            wp_send_new_user_notifications($userId, 'user');
        }

        $subscriber->user_id = $userId;
        $subscriber->save();

        //FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id);
    }
    
}