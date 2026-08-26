<?php

use App\Providers\AppServiceProvider;
use App\Providers\AppSettingsOverrideServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelemetryServiceProvider;

return [
    // AppSettingsOverrideServiceProvider has to boot BEFORE AppServiceProvider
    // so its config() merges are visible to the OpenAiClient + QdrantClient
    // singleton resolvers that read services.*.key on first resolution.
    AppSettingsOverrideServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    TelemetryServiceProvider::class,
];
