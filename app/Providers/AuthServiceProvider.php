<?php

namespace App\Providers;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\IntegrationConnection;
use App\Models\Lead;
use App\Models\Source;
use App\Models\Workspace;
use App\Policies\AgentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\IntegrationConnectionPolicy;
use App\Policies\LeadPolicy;
use App\Policies\SourcePolicy;
use App\Policies\WorkspacePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Workspace::class => WorkspacePolicy::class,
        Agent::class => AgentPolicy::class,
        Conversation::class => ConversationPolicy::class,
        Source::class => SourcePolicy::class,
        Lead::class => LeadPolicy::class,
        IntegrationConnection::class => IntegrationConnectionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
