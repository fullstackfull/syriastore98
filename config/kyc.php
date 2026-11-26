<?php

/*
|--------------------------------------------------------------------------
| KYC — verification of individuals and companies
|--------------------------------------------------------------------------
|
| KYC-001 … KYC-003 of the owner-approved Master Product Plan (Phase 06,
| section 06-C "التوثيق والامتثال"). The three approved sentences are:
|
|   KYC-001  توثيق الأفراد والشركات.
|            Verification of individuals and companies.
|   KYC-002  إدارة الوثائق الخاصة والمراجعات والتجديد وسجلات الوصول.
|            Manage private documents, reviews, renewal and access logs.
|   KYC-003  فصل توثيق Store Syria عن توثيق مزودي الدفع والتراخيص الحكومية.
|            Separate Store Syria's verification from payment-provider and
|            government-licence verification.
|
| THIS FILE CARRIES THE MECHANISM'S SETTINGS AND NOT THE POLICY. The three
| sentences state no document list, no validity period, no approver and no
| consequence for a lapse, and those are owner decisions (recorded as
| `D-P6-46` in docs/development/phase-6/PHASE-06-OWNER-DECISION-PACKAGE.md).
| So `document_kinds` ships EMPTY, and an empty list refuses every upload.
|
| THAT IS DELIBERATE AND IS THE SAFEST DEFAULT AVAILABLE. Identity papers are
| sensitive personal data under this project's standing safety boundary, so a
| mechanism that collects them BEFORE anyone has decided which papers are
| required would be collecting data nobody asked for. Until the owner names
| the kinds, the door is built, gated, logged — and closed.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The disk private documents live on
    |--------------------------------------------------------------------------
    |
    | A LOCAL DISK WHOSE ROOT IS NOT ANY PUBLIC PATH, and never the uploads
    | tree. `App\Services\Kyc\KycDocumentStore::assertPrivateRoot()` refuses a
    | root that resolves inside `public/`, `uploads/` or `storage/app/public`,
    | and `KycPrivateStorageTest` executes that refusal in both directions.
    |
    | The disk entry itself is declared in config/filesystems.php and
    | deliberately carries NO `url` key, so `Storage::disk('kyc')->url()`
    | throws rather than minting a public address for an identity document.
    |
    */

    'disk' => env('KYC_DISK', 'kyc'),

    /*
    |--------------------------------------------------------------------------
    | Which documents are collected — OWNER DECISION, EMPTY BY DEFAULT
    |--------------------------------------------------------------------------
    |
    | Keyed by subject type (`individual`, `company`) and then by authority, so
    | KYC-003's separation reaches the document list as well as the record: the
    | papers Store Syria asks for are not the papers a payment provider asks
    | for, and nothing here lets one list stand in for another.
    |
    | A kind is a short machine name (`[a-z0-9_]{1,64}`). The human label is a
    | translation key (`kyc.kind.<name>`), so naming a kind here does not
    | commit the product to an English string.
    |
    */

    'document_kinds' => [
        'individual' => [
            'store_syria' => [],
            'payment_provider' => [],
            'government_licence' => [],
        ],
        'company' => [
            'store_syria' => [],
            'payment_provider' => [],
            'government_licence' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | What a document file may be
    |--------------------------------------------------------------------------
    |
    | A TECHNICAL SAFETY LIST, NOT A POLICY ONE. These are the formats a
    | reviewer can open and that carry no script on this application's own
    | origin — the same reasoning as `PlatformUploadPolicy::SHOPPER_DENIED`,
    | which is why `svg` is absent. Widening it is a safety change, not a
    | product one.
    |
    */

    'accepted_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],

    'max_kilobytes' => (int) env('KYC_MAX_KILOBYTES', 8192),

    /*
    |--------------------------------------------------------------------------
    | Renewal
    |--------------------------------------------------------------------------
    |
    | `expiry_grace_days` is how long after `expires_on` a document is still
    | treated as current. NOT a validity period: how long a passport or a
    | commercial registration is accepted for is the owner's decision, so a
    | document's own `expires_on` is supplied per document and is never derived
    | from a default here. Zero means "expired the moment it is past".
    |
    | `renewal_notice_days` is how far ahead `KycRenewal::dueForRenewal()`
    | looks when it reports what is about to lapse. It changes what a report
    | SHOWS and never what a gate decides.
    |
    */

    'expiry_grace_days' => 0,

    'renewal_notice_days' => 30,

];
