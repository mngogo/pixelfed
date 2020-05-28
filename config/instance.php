<?php

return [

	'description' => env('INSTANCE_DESCRIPTION', null),

	'contact' => [
		'enabled' => env('INSTANCE_CONTACT_FORM', false),
		'max_per_day' => env('INSTANCE_CONTACT_MAX_PER_DAY', 1),
	],

	'discover' => [
		'loops' => [
			'enabled' => env('EXP_LOOPS', false),
		],
		'tags' => [
			'is_public' => env('INSTANCE_PUBLIC_HASHTAGS', false)
		],
	],
	
	'email' => env('INSTANCE_CONTACT_EMAIL'),

	'timeline' => [
		'local' => [
			'is_public' => env('INSTANCE_PUBLIC_LOCAL_TIMELINE', false)
		]
	],

	'page' => [
		'404' => [
			'header' => env('PAGE_404_HEADER', 'Sorry, this page isn\'t available.'),
			'body' => env('PAGE_404_BODY', 'The link you followed may be broken, or the page may have been removed. <a href="/">Go back to Pixelfed.</a>')
		],
		'503' => [
			'header' => env('PAGE_503_HEADER', 'Service Unavailable'),
			'body' => env('PAGE_503_BODY', 'Our service is in maintenance mode, please try again later.')
		]
	],
	'username' => [
		'banned' => env('BANNED_USERNAMES'),
		'remote' => [
			'formats' => ['@', 'from', 'custom'],
			'format' => in_array(env('USERNAME_REMOTE_FORMAT', '@'), ['@','from','custom']) ? env('USERNAME_REMOTE_FORMAT', '@') : '@',
			'custom' => env('USERNAME_REMOTE_CUSTOM_TEXT', null)
		]
	],

	'stories' => [
		'enabled' => env('STORIES_ENABLED', false),
	],

	'restricted' => [
		'enabled' => env('RESTRICTED_INSTANCE', false),
		'level' => 1
	],

	'oauth' => [
		'pat' => [
			'enabled' => env('OAUTH_PAT_ENABLED', false),
			'id' 	  => env('OAUTH_PAT_ID'),
		]
	]
];