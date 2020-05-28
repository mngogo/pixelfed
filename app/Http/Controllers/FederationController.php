<?php

namespace App\Http\Controllers;

use App\Jobs\InboxPipeline\{
    InboxWorker,
    InboxValidator
};
use App\Jobs\RemoteFollowPipeline\RemoteFollowPipeline;
use App\{
    AccountLog,
    Like,
    Profile,
    Status,
    User
};
use App\Util\Lexer\Nickname;
use App\Util\Webfinger\Webfinger;
use Auth;
use Cache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use League\Fractal;
use App\Util\Site\Nodeinfo;
use App\Util\ActivityPub\{
    Helpers,
    HttpSignature,
    Outbox
};
use Zttp\Zttp;

class FederationController extends Controller
{
    public function nodeinfoWellKnown()
    {
        abort_if(!config('federation.nodeinfo.enabled'), 404);
        return response()->json(Nodeinfo::wellKnown());
    }

    public function nodeinfo()
    {
        abort_if(!config('federation.nodeinfo.enabled'), 404);
        return response()->json(Nodeinfo::get())
            ->header('Access-Control-Allow-Origin','*');
    }

    public function webfinger(Request $request)
    {
        abort_if(!config('federation.webfinger.enabled'), 400);

        abort_if(!$request->filled('resource'), 400);

        $resource = $request->input('resource');
        $parsed = Nickname::normalizeProfileUrl($resource);
        if($parsed['domain'] !== config('pixelfed.domain.app')) {
            abort(400);
        }
        $username = $parsed['username'];
        $profile = Profile::whereNull('domain')->whereUsername($username)->firstOrFail();
        if($profile->status != null) {
            return ProfileController::accountCheck($profile);
        }
        $webfinger = (new Webfinger($profile))->generate();

        return response()->json($webfinger, 200, [], JSON_PRETTY_PRINT);
    }

    public function hostMeta(Request $request)
    {
        abort_if(!config('federation.webfinger.enabled'), 404);

        $path = route('well-known.webfinger');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0"><Link rel="lrdd" type="application/xrd+xml" template="'.$path.'?resource={uri}"/></XRD>';

        return response($xml)->header('Content-Type', 'application/xrd+xml');
    }

    public function userOutbox(Request $request, $username)
    {
        abort_if(!config('federation.activitypub.enabled'), 404);
        abort_if(!config('federation.activitypub.outbox'), 404);

        $profile = Profile::whereNull('domain')
            ->whereNull('status')
            ->whereIsPrivate(false)
            ->whereUsername($username)
            ->firstOrFail();

        $key = 'ap:outbox:latest_10:pid:' . $profile->id;
        $ttl = now()->addMinutes(15);
        $res = Cache::remember($key, $ttl, function() use($profile) {
            return Outbox::get($profile);
        });

        return response(json_encode($res, JSON_UNESCAPED_SLASHES))->header('Content-Type', 'application/activity+json');
    }

    public function userInbox(Request $request, $username)
    {
        abort_if(!config('federation.activitypub.enabled'), 404);
        abort_if(!config('federation.activitypub.inbox'), 404);

        $headers = $request->headers->all();
        $payload = $request->getContent();
        dispatch(new InboxValidator($username, $headers, $payload))->onQueue('high');
        return;
    }

    public function userFollowing(Request $request, $username)
    {
        abort_if(!config('federation.activitypub.enabled'), 404);

        $profile = Profile::whereNull('remote_url')
            ->whereUsername($username)
            ->whereIsPrivate(false)
            ->firstOrFail();
            
        if($profile->status != null) {
            abort(404);
        }

        $obj = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $request->getUri(),
            'type'     => 'OrderedCollectionPage',
            'totalItems' => 0,
            'orderedItems' => []
        ];
        return response()->json($obj); 
    }

    public function userFollowers(Request $request, $username)
    {
        abort_if(!config('federation.activitypub.enabled'), 404);

        $profile = Profile::whereNull('remote_url')
            ->whereUsername($username)
            ->whereIsPrivate(false)
            ->firstOrFail();

        if($profile->status != null) {
            abort(404);
        }

        $obj = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $request->getUri(),
            'type'     => 'OrderedCollectionPage',
            'totalItems' => 0,
            'orderedItems' => []
        ];

        return response()->json($obj); 
    }
}
