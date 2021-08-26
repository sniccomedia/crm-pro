<?php

namespace FluentCampaign\App\Http\Controllers;

use FluentCampaign\App\Models\Sequence;
use FluentCampaign\App\Models\SequenceMail;
use FluentCampaign\App\Models\SequenceTracker;
use FluentCrm\App\Http\Controllers\Controller;
use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Includes\Helpers\Arr;
use FluentCrm\Includes\Request\Request;
use FluentValidator\ValidationException;

class SequenceController extends Controller
{
    public function sequences(Request $request)
    {
        $order = $request->get('order') ?: 'desc';
        $orderBy = $request->get('orderBy') ?: 'id';

        $sequences = Sequence::orderBy($orderBy, ($order == 'ascending' ? 'asc' : 'desc'))->paginate();

        $with = $request->get('with', []);
        if (in_array('stats', $with)) {
            foreach ($sequences as $sequence) {
                $sequence->stats = $sequence->stat();
            }
        }

        return $this->sendSuccess(compact('sequences'));
    }

    public function create(Request $request)
    {
        try {
            $data = $this->validate($request->only('title'), [
                'title' => 'required|unique:fc_campaigns',
            ]);

            return $this->sendSuccess([
                'sequence' => Sequence::create($data),
                'message'  => __('Sequence has been created', 'fluentcampaign-pro')
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrors($e);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $this->validate($request->only(['title', 'settings', 'id']), [
                // The title must be unique because the slug
                'title' => 'required'
            ]);

            $existing = Sequence::findOrFail($id);

            if (isset($data['settings']) && empty($data['settings'])) {
                unset($data['settings']);
            } else {
                $mailerSettings = Arr::get($data, 'settings.mailer_settings');
                $existingMailerSettings = Arr::get($existing->settings, 'mailer_settings', []);
                if (array_diff($existingMailerSettings, $mailerSettings)) {
                    // It's a change
                    $data['settings']['mailer_settings'] = $mailerSettings;
                    $sequenceMails = SequenceMail::where('parent_id', $id)->get();
                    foreach ($sequenceMails as $sequenceMail) {
                        $sequenceMail->updateMailerSettings($mailerSettings);
                    }
                }
            }

            $existing->fill($data)->save();

            return $this->sendSuccess([
                'sequence' => $existing,
                'message'  => __('Sequence has been updated', 'fluentcampaign-pro')
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrors($e);
        }
    }

    public function duplicate(Request $request, $id)
    {
        $sequence = Sequence::findOrFail($id);

        $sequenceData = [
            'title' => '[Duplicate] '.$sequence->title,
            'settings' => $sequence->settings,
            'design_template' => $sequence->design_template
        ];

        $createdSequence = Sequence::create($sequenceData);

        $sequenceEmails = SequenceMail::where('parent_id', $id)
            ->orderBy('delay', 'ASC')
            ->get()->toArray();

        foreach ($sequenceEmails as $email) {
            $emailData = Arr::only($email, [
                'title',
                'type',
                'available_urls',
                'status',
                'template_id',
                'email_subject',
                'email_pre_header',
                'email_body',
                'delay',
                'utm_status',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'design_template',
                'scheduled_at',
                'settings'
            ]);

            $emailData['template_id'] = intval($emailData['template_id']);

            $emailData = array_filter($emailData);

            $emailData['parent_id'] = $createdSequence->id;

            SequenceMail::create($emailData);
        }

        return [
            'sequence' => $createdSequence,
            'message' => __('Selected sequence has been successfully duplicated', 'fluentcampaign-pro')
        ];

    }

    public function sequence(Request $request, $id)
    {
        $sequence = Sequence::find($id);
        $data['sequence'] = $sequence;
        $with = $request->get('with', []);
        if (in_array('sequence_emails', $with)) {
            $sequenceEmails = SequenceMail::where('parent_id', $id)
                ->orderBy('delay', 'ASC')
                ->get();
            if (in_array('email_stats', $with)) {
                foreach ($sequenceEmails as $sequenceEmail) {
                    $sequenceEmail->stats = $sequenceEmail->stats();
                }
            }
            $data['sequence_emails'] = $sequenceEmails;
        }

        return $this->sendSuccess($data);
    }

    public function delete(Request $request, $id)
    {
        Sequence::where('id', $id)->delete();
        $sequenceCampaignIds = SequenceMail::where('parent_id', $id)->get()->pluck('id');
        if ($sequenceCampaignIds) {
            SequenceMail::where('parent_id', $id)->delete();
            CampaignEmail::whereIn('campaign_id', $sequenceCampaignIds)->delete();
            CampaignUrlMetric::whereIn('campaign_id', $sequenceCampaignIds)->delete();
        }

        do_action('fluentcrm_sequence_deleted', $id);

        return $this->sendSuccess([
            'message' => __('Email sequence successfully deleted', 'fluentcampaign-pro')
        ]);
    }

    public function subscribe(Request $request, $sequenceId)
    {
        $page = intval($request->get('page', 1));
        $subscribersSettings = [
            'subscribers'         => $request->get('subscribers'),
            'excludedSubscribers' => $request->get('excludedSubscribers'),
            'sending_filter'      => $request->get('sending_filter', 'list_tag'),
            'dynamic_segment'     => $request->get('dynamic_segment')
        ];

        $campaign = new Campaign;

        $data = $campaign->getSubscriberIdsBySegmentSettings($subscribersSettings);
        $subscriberIds = $data['subscriber_ids'];
        $inTotal = count($subscriberIds);
        if (!count($subscriberIds)) {
            return $this->sendError([
                'message' => 'No Subscribers found based on your selection'
            ]);
        }

        $alreadySubscriberIds = SequenceTracker::where('campaign_id', $sequenceId)->get()->pluck('subscriber_id');

        $subscriberIds = array_diff($subscriberIds, $alreadySubscriberIds);

        $totalSubscribers = count($subscriberIds);

        $processPerRequest = intval(apply_filters('fluentcrm_process_subscribers_per_request', 200));

        $subscriberIds = array_slice($subscriberIds, 0, $processPerRequest);

        $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();

        Sequence::find($sequenceId)->subscribe($subscribers);

        $remaining = $totalSubscribers - count($subscribers);

        if ($remaining <= 0) {
            $remaining = 0;
        }

        return $this->sendSuccess([
            'total'      => $totalSubscribers,
            'remaining'  => $remaining,
            'next_page'  => $page + 1,
            'page_total' => ceil($totalSubscribers / $processPerRequest),
            'in_total'   => $inTotal
        ]);

    }

    public function getSubscribers(Request $request, $sequenceId)
    {
        return SequenceTracker::where('campaign_id', $sequenceId)
            ->orderBy('id', 'DESC')
            ->with('subscriber')
            ->paginate();
    }

    public function deleteSubscribes(Request $request, $sequenceId)
    {
        $sequence = Sequence::findOrFail($sequenceId);

        if ($trackerIds = $request->get('tracker_ids', [])) {
            SequenceTracker::where('campaign_id', $sequence->id)
                ->whereIn('id', $trackerIds)
                ->delete();
        } else if($subscriberIds = $request->get('subscriber_ids', [])) {
            SequenceTracker::where('campaign_id', $sequence->id)
                ->whereIn('subscriber_id', $subscriberIds)
                ->delete();
        }

        return [
            'message' => __('Selected subscribers has been successfully removed from this sequence', 'fluentcampaign-pro')
        ];

    }

    public function subscriberSequences(Request $request, $subscriberId)
    {
        $sequenceTrackers = SequenceTracker::where('subscriber_id', $subscriberId)
            ->with(['sequence', 'last_sequence', 'next_sequence'])
            ->orderBy('id', 'DESC')
            ->paginate();

        return [
            'sequence_trackers' => $sequenceTrackers
        ];
    }
}
