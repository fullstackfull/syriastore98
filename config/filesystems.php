<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'theme' => [
            'driver' => 'local',
            'root' => base_path(''),
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
        
        'wasabi' => [
            'driver' => 's3',
            'key' => env('WAS_ACCESS_KEY_ID'),
            'secret' => env('WAS_SECRET_ACCESS_KEY'),
            'region' => env('WAS_DEFAULT_REGION'),
            'bucket' => env('WAS_BUCKET'),
            'endpoint' => env('WAS_URL')
        ],

        /*
         * KYC-002 — private identity documents. NOT a public disk, and deliberately WITHOUT a
         * `url` key so `Storage::disk('kyc')->url()` throws instead of minting a public address
         * for somebody's passport. `App\Services\Kyc\KycDocumentStore::assertPrivateRoot()`
         * additionally refuses a root that resolves inside `public/`, `uploads/` or
         * `storage/app/public`, which is what a well-meaning deployment change would do.
         *
         * `storage/app/` is also the one tree the WRITTEN (inactive) deny rules already cover:
         * docs/security/p1-sec-40-apache-deny.conf GROUP 2 refuses `^/storage/(logs|framework|app)`.
         * On the live host those rules are NOT active -- the document root is the repository root
         * and the owner has a standing instruction not to change public access -- so the file's
         * own protection is its 64-hex-character server-generated name, never its directory. See
         * KycDocumentStore's docblock for what that does and does not buy.
         */
        'kyc' => [
            'driver' => 'local',
            'root' => env('KYC_DISK_ROOT', storage_path('app/private/kyc')),
            'visibility' => 'private',
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
