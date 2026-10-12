<?php
/**
 * Plugin Name: Sadad sandbox bridge
 * Description: When SADAD_MODE=sandbox, sends the Sadad gateway to the local mock bank instead of sadad.shaparak.ir.
 */

defined('ABSPATH') || exit;

if (getenv('SADAD_MODE') !== 'sandbox') {
    return;
}

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

add_action('admin_notices', function () {
    echo '<div class="notice notice-warning inline" style="margin:15px 20px 0 0"><p><strong>Sadad sandbox mode:</strong> payments go to the local test bank, not Bank Melli. Set <code>SADAD_MODE=live</code> in <code>.env</code> to use the real gateway.</p></div>';
});
