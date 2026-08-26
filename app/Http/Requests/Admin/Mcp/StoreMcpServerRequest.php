<?php

namespace App\Http\Requests\Admin\Mcp;

use App\Models\McpServer;
use App\Support\UrlSafetyGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the form to attach a new MCP server. SSRF rejection
 * happens HERE so the bad URL never lands in the DB.
 */
class StoreMcpServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'server_url' => [
                'required',
                'url:http,https',
                'max:2000',
                function (string $attribute, mixed $value, Closure $fail) {
                    $guard = app(UrlSafetyGuard::class);
                    if (! $guard->isSafe((string) $value, resolveHostnames: true)) {
                        $fail('Server URL is not allowed (private / loopback / metadata host).');
                    }
                },
            ],
            'auth_type' => [
                'required',
                'string',
                'in:'.McpServer::AUTH_NONE.','.McpServer::AUTH_BEARER.','.McpServer::AUTH_OAUTH2_PKCE,
            ],
            'api_key' => [
                'required_if:auth_type,'.McpServer::AUTH_BEARER,
                'nullable',
                'string',
                'min:8',
                'max:4000',
            ],
        ];
    }
}
