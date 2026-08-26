<?php

namespace Pitchbar\Rest;

use Pitchbar\Support\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Pitchbar -> Plugin callback: a Lead was captured in chat. The plugin
 * mirrors it into WordPress as a customer (WC active) or subscriber
 * (no WC) so the store owner sees the contact in the WP admin without
 * leaving WordPress.
 *
 * Idempotent on (email, pitchbar_conversation_id): re-sending the same
 * lead updates user meta but does not create duplicates.
 */
final class LeadController extends RestController
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::NAMESPACE, '/leads', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $verify = $this->verifyOrReject($request);
        if ($verify instanceof WP_REST_Response) {
            return $verify;
        }

        $body = json_decode($verify, true);
        if (! is_array($body)) {
            return $this->badRequest('invalid_body', 'Body must be JSON.');
        }

        $email = isset($body['email']) ? sanitize_email((string) $body['email']) : '';
        if ($email === '' || ! is_email($email)) {
            return $this->badRequest('missing_email', 'A valid email is required.');
        }

        $name = isset($body['name']) ? sanitize_text_field((string) $body['name']) : '';
        $phone = isset($body['phone']) ? sanitize_text_field((string) $body['phone']) : '';
        $conversationId = isset($body['conversation_id']) ? sanitize_text_field((string) $body['conversation_id']) : '';
        $leadId = isset($body['pitchbar_lead_id']) ? sanitize_text_field((string) $body['pitchbar_lead_id']) : '';

        $userId = email_exists($email);
        if ($userId === false) {
            $userId = $this->createUser($email, $name, $phone);
            if ($userId instanceof \WP_Error) {
                Logger::warn('Lead user create failed', [
                    'email' => $email,
                    'error' => $userId->get_error_message(),
                ]);

                return $this->badRequest('user_create_failed', $userId->get_error_message());
            }
        }

        if ($name !== '') {
            wp_update_user(['ID' => $userId, 'first_name' => $name]);
        }
        if ($phone !== '') {
            update_user_meta($userId, 'billing_phone', $phone);
        }
        if ($leadId !== '') {
            update_user_meta($userId, 'pitchbar_lead_id', $leadId);
        }
        if ($conversationId !== '') {
            update_user_meta($userId, 'pitchbar_conversation_id', $conversationId);
        }

        return $this->ok(['user_id' => (int) $userId]);
    }

    /**
     * @return int|\WP_Error
     */
    private function createUser(string $email, string $name, string $phone)
    {
        if (class_exists('WC_Customer') && function_exists('wc_create_new_customer')) {
            $created = wc_create_new_customer($email, '', '', [
                'first_name' => $name,
            ]);
            if (is_int($created) && $created > 0) {
                return $created;
            }
            if ($created instanceof \WP_Error) {
                return $created;
            }
        }

        $username = $this->uniqueUsernameFor($email);
        $password = wp_generate_password(24, true, true);
        $created = wp_create_user($username, $password, $email);

        if (is_int($created) && $created > 0) {
            $user = get_user_by('id', $created);
            if ($user) {
                $user->set_role('subscriber');
            }
        }

        return $created;
    }

    private function uniqueUsernameFor(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true) ?: 'pitchbar', true);
        $candidate = $base;
        $suffix = 0;
        while (username_exists($candidate)) {
            $suffix++;
            $candidate = $base.$suffix;
        }

        return $candidate;
    }
}
