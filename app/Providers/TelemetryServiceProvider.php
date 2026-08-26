<?php

namespace App\Providers;

use App\Services\Telemetry\NoopTracer;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;

/**
 * Registers an OpenTelemetry tracer for hot-path spans.
 * No-op when OTEL_EXPORTER_OTLP_ENDPOINT is unset (local/dev/tests).
 */
class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('telemetry.tracer', function () {
            $endpoint = (string) env('OTEL_EXPORTER_OTLP_ENDPOINT', '');
            if ($endpoint === '') {
                return new NoopTracer;
            }

            // The real OTel SDK reads env vars (OTEL_*) at boot when
            // OTEL_PHP_AUTOLOAD_ENABLED=true. We rely on that wiring in production.
            return Globals::tracerProvider()->getTracer('pitchbar');
        });
    }
}
