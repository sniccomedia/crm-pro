<?php

namespace FluentCampaign\App\Services\Integrations\LearnDash;

use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Includes\Helpers\Arr;

class LessonCompletedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'learndash_lesson_completed';
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => 'LearnDash',
            'label'       => __('Completes a Lesson', 'fluentcampaign-pro'),
            'description' => __('This Funnel will start a student completes a lesson', 'fluentcampaign-pro')
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Completes a Lesson', 'fluentcampaign-pro'),
            'sub_title' => __('This Funnel will start when a student completes a lesson', 'fluentcampaign-pro'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluentcampaign-pro'),
                    'placeholder' => 'Select Status'
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>'.__('An Automated double-optin email will be sent for new subscribers', 'fluentcampaign-pro').'</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type' => 'update', // skip_all_actions, skip_update_if_exist
            'course_id'   => '',
            'lesson_id'   => []
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'update_type' => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluentcampaign-pro'),
                'help'    => __('Please specify what will happen if the subscriber already exist in the database', 'fluentcampaign-pro'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'course_id'   => [
                'type'        => 'reload_field_selection',
                'label'       => __('Target Course', 'fluentcampaign-pro'),
                'help'        => __('Select Course to find out Lesson', 'fluentcampaign-pro'),
                'options'     => Helper::getCourses(),
                'inline_help' => __('You must select a course', 'fluentcampaign-pro')
            ],
            'lesson_ids'  => [
                'type'        => 'multi-select',
                'multiple'    => true,
                'label'       => __('Target Lesson', 'fluentcampaign-pro'),
                'help'        => __('Select Lesson to find out Topic', 'fluentcampaign-pro'),
                'options'     => Helper::getLessonsByCourse($funnel->conditions['course_id']),
                'inline_help' => __('Leave empty to target any lesson of this course', 'fluentcampaign-pro')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $data = $originalArgs[0];

        $subscriberData = FunnelHelper::prepareUserData($data['user']);

        $subscriberData['source'] = 'LearnDash';

        if (empty($subscriberData['email']) || !is_email($subscriberData['email'])) {
            return;
        }

        $lessonId = $data['lesson']->ID;
        $courseId = $data['course']->ID;
        $willProcess = $this->isProcessable($funnel, $courseId, $lessonId, $subscriberData);

        Helper::startProcessing($this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs, $lessonId);
    }

    private function isProcessable($funnel, $courseId, $lessonId, $subscriberData)
    {
        $conditions = $funnel->conditions;

        if (Arr::get($conditions, 'course_id') != $courseId) {
            return false;
        }

        // check the products ids
        if ($conditions['lesson_ids']) {
            if(!in_array($lessonId, $conditions['lesson_ids'])) {
                return false;
            }
        }

        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);
        if ($subscriber && $updateType == 'skip_all_if_exist') {
            return false;
        }

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            return false;
        }

        return true;
    }
}
