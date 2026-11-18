<?php

if (! defined('ABSPATH')) {
    exit;
}

/** The two endpoints the widget talks to, on this site's own REST API. */
class Selfcap_Rest
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        foreach (['challenge', 'verify'] as $route) {
            register_rest_route('selfcap/v1', '/'.$route, [
                'methods' => 'POST',
                'callback' => [self::class, $route],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    public static function challenge(WP_REST_Request $request): WP_REST_Response
    {
        if (! Selfcap_Plugin::is_enabled()) {
            return self::respond([403, ['success' => false, 'error' => 'site-disabled']]);
        }

        return self::respond(Selfcap_Plugin::engine()->challenge(Selfcap_Plugin::context()));
    }

    public static function verify(WP_REST_Request $request): WP_REST_Response
    {
        if (! Selfcap_Plugin::is_enabled()) {
            return self::respond([403, ['success' => false, 'error' => 'site-disabled']]);
        }

        $body = $request->get_json_params();
        $body = is_array($body) ? $body : [];

        return self::respond(Selfcap_Plugin::engine()->verify(
            Selfcap_Plugin::context(),
            $body['challenge'] ?? null,
            $body['nonce'] ?? null,
            $body['signals'] ?? null
        ));
    }

    private static function respond(array $result): WP_REST_Response
    {
        $response = new WP_REST_Response($result[1], $result[0]);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}
