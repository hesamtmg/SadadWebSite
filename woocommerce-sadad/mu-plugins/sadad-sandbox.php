<?php
/**
 * Plugin Name: Sadad sandbox bridge
 * Description: SADAD_MODE=sandbox sends the Sadad gateway to the local mock bank with built-in test credentials. SADAD_MODE=live leaves the real gateway untouched and warns about leftover sandbox credentials.
 */

defined('ABSPATH') || exit;

const SADAD_DEMO_SETTINGS_OPTION = 'woocommerce_sadad_electronic_payment_settings';

function sadad_demo_sandbox_credentials() {
    return array(
        'merchant_id'  => (string) getenv('SADAD_SANDBOX_MERCHANT_ID'),
        'terminal_id'  => (string) getenv('SADAD_SANDBOX_TERMINAL_ID'),
        'terminal_key' => (string) getenv('SADAD_SANDBOX_TERMINAL_KEY'),
    );
}

if (getenv('SADAD_MODE') === 'sandbox') {
    add_filter('sadad_electronic_payment_endpoints', function ($endpoints) {
        // Server-to-server calls go over the Docker network; the customer's browser uses the published port.
        $internal = rtrim(getenv('SADAD_SANDBOX_INTERNAL_URL') ?: 'http://sadad-mock', '/');
        $public   = rtrim(getenv('SADAD_SANDBOX_PUBLIC_URL') ?: 'http://localhost:8081', '/');
        return array(
            'request' => $internal . '/vpg/api/v0/Request/PaymentRequest',
            'verify'  => $internal . '/vpg/api/v0/Advice/Verify',
            'payment' => $public . '/VPG/Purchase?Token=',
        );
    });

    // Payments (checkout, Store API, bank callback) use the sandbox credentials.
    // Admin screens keep showing and saving the real ones, so they are never overwritten.
    if (!is_admin() && !(defined('WP_CLI') && WP_CLI)) {
        add_filter('option_' . SADAD_DEMO_SETTINGS_OPTION, function ($settings) {
            return array_merge(is_array($settings) ? $settings : array(), sadad_demo_sandbox_credentials());
        });
    }

    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning inline" style="margin:15px 20px 0 0"><p><strong>Sadad sandbox mode:</strong> payments go to the local test bank with built-in test credentials, not to Bank Melli. Credentials saved here are used only in live mode (<code>SADAD_MODE=live</code> in <code>.env</code>).</p></div>';
    });
    return;
}

add_action('admin_notices', function () {
    $settings = get_option(SADAD_DEMO_SETTINGS_OPTION, array());
    $sandbox  = sadad_demo_sandbox_credentials();
    if ($sandbox['terminal_key'] !== '' && isset($settings['terminal_key']) && hash_equals($sandbox['terminal_key'], (string) $settings['terminal_key'])) {
        echo '<div class="notice notice-error inline" style="margin:15px 20px 0 0"><p><strong>Sadad live mode is using the sandbox TerminalKey.</strong> The bank will reject every payment with ResCode 1025. Enter the real MerchantId, TerminalId and TerminalKey from Sadad.</p></div>';
    }
});
