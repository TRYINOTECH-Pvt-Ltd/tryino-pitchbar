<?php

namespace Pitchbar\Settings;

/**
 * Sanitizes the settings array submitted by the Pitchbar admin form
 * before it lands in `wp_options`. Untrusted input is normalized here;
 * everything downstream reads the cleaned shape.
 */
final class SettingsValidator
{
    public const TOKEN_PATTERN = '/^pbar_[A-Za-z0-9]{48}$/';

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  mixed  $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        $baseUrl = isset($input['base_url']) ? esc_url_raw(trim((string) $input['base_url'])) : '';
        if ($baseUrl !== '' && ! $this->isAcceptableUrl($baseUrl)) {
            add_settings_error('pitchbar_settings', 'pitchbar_base_url', __('Base URL must start with http:// or https://.', 'pitchbar'));
            $baseUrl = '';
        }
        $baseUrl = rtrim($baseUrl, '/');

        $rawToken = isset($input['api_token']) ? trim((string) $input['api_token']) : '';
        if ($rawToken !== '' && ! preg_match(self::TOKEN_PATTERN, $rawToken)) {
            add_settings_error('pitchbar_settings', 'pitchbar_api_token', __('API token format looks wrong — it should start with "pbar_" followed by 48 alphanumeric characters.', 'pitchbar'));
            $rawToken = '';
        }

        $agentId = isset($input['agent_id']) ? trim((string) $input['agent_id']) : '';
        if ($agentId !== '' && ! preg_match(self::UUID_PATTERN, $agentId)) {
            add_settings_error('pitchbar_settings', 'pitchbar_agent_id', __('Agent ID must be a UUID.', 'pitchbar'));
            $agentId = '';
        }

        $workspaceId = isset($input['workspace_id']) ? sanitize_text_field((string) $input['workspace_id']) : '';
        $workspaceName = isset($input['workspace_name']) ? sanitize_text_field((string) $input['workspace_name']) : '';
        $shopperSigningSecret = isset($input['shopper_signing_secret'])
            ? sanitize_text_field((string) $input['shopper_signing_secret'])
            : '';

        $enabled = ! empty($input['enabled']);

        $rawTypes = isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])
            ? $input['enabled_post_types']
            : [];
        $enabledTypes = [];
        foreach ($rawTypes as $type) {
            $clean = sanitize_key((string) $type);
            if ($clean !== '') {
                $enabledTypes[] = $clean;
            }
        }
        $enabledTypes = array_values(array_unique($enabledTypes));
        if (empty($enabledTypes)) {
            $enabledTypes = ['post', 'page'];
        }

        return [
            'base_url' => $baseUrl,
            'api_token' => $rawToken,
            'agent_id' => $agentId,
            'workspace_id' => $workspaceId,
            'workspace_name' => $workspaceName,
            'enabled' => $enabled,
            'enabled_post_types' => $enabledTypes,
            'shopper_signing_secret' => $shopperSigningSecret,
        ];
    }

    private function isAcceptableUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }
}
