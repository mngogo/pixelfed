<?php

namespace App\Util\ActivityPub;

use Cache, DB, Log, Purify, Redis, Validator;
use App\{
    Activity,
    Follower,
    FollowRequest,
    Like,
    Notification,
    Profile,
    Status,
    StatusHashtag,
};
use Carbon\Carbon;
use App\Util\ActivityPub\Helpers;
use App\Jobs\LikePipeline\LikePipeline;
use App\Jobs\FollowPipeline\FollowPipeline;

use App\Util\ActivityPub\Validator\Accept as AcceptValidator;
use App\Util\ActivityPub\Validator\Announce as AnnounceValidator;
use App\Util\ActivityPub\Validator\Follow as FollowValidator;
use App\Util\ActivityPub\Validator\Like as LikeValidator;
use App\Util\ActivityPub\Validator\UndoFollow as UndoFollowValidator;

class Inbox
{
    protected $headers;
    protected $profile;
    protected $payload;
    protected $logger;

    public function __construct($headers, $profile, $payload)
    {
        $this->headers = $headers;
        $this->profile = $profile;
        $this->payload = $payload;
    }

    public function handle()
    {
        $this->handleVerb();

        // if(!Activity::where('data->id', $this->payload['id'])->exists()) {
        //     (new Activity())->create([
        //         'to_id' => $this->profile->id,
        //         'data' => json_encode($this->payload)
        //     ]);
        // }

        return;

    }

    public function handleVerb()
    {
        $verb = (string) $this->payload['type'];
        switch ($verb) {
            case 'Create':
                $this->handleCreateActivity();
                break;

            case 'Follow':
                if(FollowValidator::validate($this->payload) == false) { return; }
                $this->handleFollowActivity();
                break;

            case 'Announce':
                if(AnnounceValidator::validate($this->payload) == false) { return; }
                $this->handleAnnounceActivity();
                break;

            case 'Accept':
                if(AcceptValidator::validate($this->payload) == false) { return; }
                $this->handleAcceptActivity();
                break;

            case 'Delete':
                $this->handleDeleteActivity();
                break;

            case 'Like':
                if(LikeValidator::validate($this->payload) == false) { return; }
                $this->handleLikeActivity();
                break;

            case 'Reject':
                $this->handleRejectActivity();
                break;

            case 'Undo':
                $this->handleUndoActivity();
                break;

            default:
                // TODO: decide how to handle invalid verbs.
                break;
        }
    }

    public function verifyNoteAttachment()
    {
        $activity = $this->payload['object'];

        if(isset($activity['inReplyTo']) && 
            !empty($activity['inReplyTo']) && 
            Helpers::validateUrl($activity['inReplyTo'])
        ) {
            // reply detected, skip attachment check
            return true;
        }

        $valid = Helpers::verifyAttachments($activity);

        return $valid;
    }

    public function actorFirstOrCreate($actorUrl)
    {
        return Helpers::profileFetch($actorUrl);
    }

    public function handleCreateActivity()
    {
        $activity = $this->payload['object'];
        if(!$this->verifyNoteAttachment()) {
            return;
        }
        if($activity['type'] == 'Note' && !empty($activity['inReplyTo'])) {
            $this->handleNoteReply();

        } elseif($activity['type'] == 'Note' && !empty($activity['attachment'])) {
            $this->handleNoteCreate();
        }
    }

    public function handleNoteReply()
    {
        $activity = $this->payload['object'];
        $actor = $this->actorFirstOrCreate($this->payload['actor']);
        if(!$actor || $actor->domain == null) {
            return;
        }

        $inReplyTo = $activity['inReplyTo'];
        $url = isset($activity['url']) ? $activity['url'] : $activity['id'];
        
        Helpers::statusFirstOrFetch($url, true);
        return;
    }

    public function handleNoteCreate()
    {
        $activity = $this->payload['object'];
        $actor = $this->actorFirstOrCreate($this->payload['actor']);
        if(!$actor || $actor->domain == null) {
            return;
        }

        if($actor->followers()->count() == 0) {
            return;
        }

        $url = isset($activity['url']) ? $activity['url'] : $activity['id'];
        if(Status::whereUrl($url)->exists()) {
            return;
        }
        Helpers::statusFetch($url);
        return;
    }

    public function handleFollowActivity()
    {
        $actor = $this->actorFirstOrCreate($this->payload['actor']);
        $target = $this->profile;
        if(!$actor || $actor->domain == null || $target->domain !== null) {
            return;
        }
        if(
            Follower::whereProfileId($actor->id)
                ->whereFollowingId($target->id)
                ->exists() ||
            FollowRequest::whereFollowerId($actor->id)
                ->whereFollowingId($target->id)
                ->exists()
        ) {
            return;
        }
        if($target->is_private == true) {
            FollowRequest::firstOrCreate([
                'follower_id' => $actor->id,
                'following_id' => $target->id
            ]);
        } else {
            $follower = new Follower;
            $follower->profile_id = $actor->id;
            $follower->following_id = $target->id;
            $follower->local_profile = empty($actor->domain);
            $follower->save();

            FollowPipeline::dispatch($follower);

            // send Accept to remote profile
            $accept = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id'       => $target->permalink().'#accepts/follows/' . $follower->id,
                'type'     => 'Accept',
                'actor'    => $target->permalink(),
                'object'   => [
                    'id'        => $this->payload['id'],
                    'actor'     => $actor->permalink(),
                    'type'      => 'Follow',
                    'object'    => $target->permalink()
                ]
            ];
            Helpers::sendSignedObject($target, $actor->inbox_url, $accept);
        }
    }

    public function handleAnnounceActivity()
    {
        $actor = $this->actorFirstOrCreate($this->payload['actor']);
        $activity = $this->payload['object'];

        if(!$actor || $actor->domain == null) {
            return;
        }

        if(Helpers::validateLocalUrl($activity) == false) {
            return;
        }

        $parent = Helpers::statusFetch($activity);

        if(empty($parent)) {
            return;
        }

        $status = Status::firstOrCreate([
            'profile_id' => $actor->id,
            'reblog_of_id' => $parent->id,
            'type' => 'share'
        ]);

        Notification::firstOrCreate([
            'profile_id' => $parent->profile->id,
            'actor_id' => $actor->id,
            'action' => 'share',
            'message' => $status->replyToText(),
            'rendered' => $status->replyToHtml(),
            'item_id' => $parent->id,
            'item_type' => 'App\Status'
        ]);

        $parent->reblogs_count = $parent->shares()->count();
        $parent->save();
    }

    public function handleAcceptActivity()
    {

        $actor = $this->payload['object']['actor'];
        $obj = $this->payload['object']['object'];
        $type = $this->payload['object']['type'];

        if($type !== 'Follow') {
            return;
        }

        $actor = Helpers::validateLocalUrl($actor);
        $target = Helpers::validateUrl($obj);

        if(!$actor || !$target) {
            return;
        }
        $actor = Helpers::profileFetch($actor);
        $target = Helpers::profileFetch($target);

        $request = FollowRequest::whereFollowerId($actor->id)
            ->whereFollowingId($target->id)
            ->whereIsRejected(false)
            ->first();

        if(!$request) {
            return;
        }

        $follower = Follower::firstOrCreate([
            'profile_id' => $actor->id,
            'following_id' => $target->id,
        ]);
        FollowPipeline::dispatch($follower);

        $request->delete();
    }

    public function handleDeleteActivity()
    {
        if(!isset(
            $this->payload['actor'], 
            $this->payload['object'], 
        )) {
            return;
        }
        $actor = $this->payload['actor'];
        $obj = $this->payload['object'];
        if(is_string($obj) == true) {
            return;
        }
        $type = $this->payload['object']['type'];
        $typeCheck = in_array($type, ['Person', 'Tombstone']);
        if(!Helpers::validateUrl($actor) || !Helpers::validateUrl($obj['id']) || !$typeCheck) {
            return;
        }
        if(parse_url($obj['id'], PHP_URL_HOST) !== parse_url($actor, PHP_URL_HOST)) {
            return;
        }
        $id = $this->payload['object']['id'];
        switch ($type) {
            case 'Person':
                    // todo: fix race condition
                    return; 
                    $profile = Helpers::profileFetch($actor);
                    if(!$profile || $profile->private_key != null) {
                        return;
                    }
                    Notification::whereActorId($profile->id)->delete();
                    $profile->avatar()->delete();
                    $profile->followers()->delete();
                    $profile->following()->delete();
                    $profile->likes()->delete();
                    $profile->media()->delete();
                    $profile->statuses()->delete();
                    $profile->delete();
                return;
                break;

            case 'Tombstone':
                    $profile = Helpers::profileFetch($actor);
                    $status = Status::whereProfileId($profile->id)
                        ->whereUri($id)
                        ->orWhere('url', $id)
                        ->orWhere('object_url', $id)
                        ->first();
                    if(!$status) {
                        return;
                    }
                    $status->media()->delete();
                    $status->likes()->delete();
                    $status->shares()->delete();
                    $status->delete();
                    return;
                break;
            
            default:
                return;
                break;
        }
    }

    public function handleLikeActivity()
    {
        $actor = $this->payload['actor'];

        if(!Helpers::validateUrl($actor)) {
            return;
        }

        $profile = self::actorFirstOrCreate($actor);
        $obj = $this->payload['object'];
        if(!Helpers::validateUrl($obj)) {
            return;
        }
        $status = Helpers::statusFirstOrFetch($obj);
        if(!$status || !$profile) {
            return;
        }
        $like = Like::firstOrCreate([
            'profile_id' => $profile->id,
            'status_id' => $status->id
        ]);

        if($like->wasRecentlyCreated == true) {
            $status->likes_count = $status->likes()->count();
            $status->save();
            LikePipeline::dispatch($like);
        }

        return;
    }


    public function handleRejectActivity()
    {

    }

    public function handleUndoActivity()
    {
        $actor = $this->payload['actor'];
        $profile = self::actorFirstOrCreate($actor);
        $obj = $this->payload['object'];

        switch ($obj['type']) {
            case 'Accept':
                break;
                
            case 'Announce':
                $obj = $obj['object'];
                if(!Helpers::validateLocalUrl($obj)) {
                    return;
                }
                $status = Helpers::statusFetch($obj);
                if(!$status) {
                    return;
                }
                Status::whereProfileId($profile->id)
                    ->whereReblogOfId($status->id)
                    ->forceDelete();
                Notification::whereProfileId($status->profile->id)
                    ->whereActorId($profile->id)
                    ->whereAction('share')
                    ->whereItemId($status->reblog_of_id)
                    ->whereItemType('App\Status')
                    ->forceDelete();
                break;

            case 'Block':
                break;

            case 'Follow':
                $following = self::actorFirstOrCreate($obj['object']);
                if(!$following) {
                    return;
                }
                Follower::whereProfileId($profile->id)
                    ->whereFollowingId($following->id)
                    ->delete();
                Notification::whereProfileId($following->id)
                    ->whereActorId($profile->id)
                    ->whereAction('follow')
                    ->whereItemId($following->id)
                    ->whereItemType('App\Profile')
                    ->forceDelete();
                break;
                
            case 'Like':
                $status = Helpers::statusFirstOrFetch($obj['object']);
                if(!$status) {
                    return;
                }
                Like::whereProfileId($profile->id)
                    ->whereStatusId($status->id)
                    ->forceDelete();
                Notification::whereProfileId($status->profile->id)
                    ->whereActorId($profile->id)
                    ->whereAction('like')
                    ->whereItemId($status->id)
                    ->whereItemType('App\Status')
                    ->forceDelete();
                break;
        }
        return;
    }
}
