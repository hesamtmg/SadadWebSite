<?php

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the Sadad gateway with the WooCommerce checkout block.
 */
final class Sadad_Electronic_Payment_Blocks extends AbstractPaymentMethodType {
    protected $name = 'sadad_electronic_payment';

    public function initialize() {
        $this->settings = get_option('woocommerce_sadad_electronic_payment_settings', array());
    }

    public function is_active() {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        return isset($gateways[$this->name]) && $gateways[$this->name]->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'sadad-electronic-payment-blocks',
            plugins_url('assets/blocks.js', SADAD_ELECTRONIC_PAYMENT_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            SADAD_ELECTRONIC_PAYMENT_VERSION,
            true
        );
        return array('sadad-electronic-payment-blocks');
    }

    public function get_payment_method_data() {
        return array(
            'title'       => $this->get_setting('title', 'پرداخت با کارت بانکی'),
            'description' => $this->get_setting('description', 'پرداخت امن از طریق درگاه سداد بانک ملی'),
            'icon'        => plugins_url('assets/sadad.png', SADAD_ELECTRONIC_PAYMENT_FILE),
            'features'    => array('پرداخت امن شاپرکی', 'تمام کارت‌های بانکی', 'تأیید خودکار سفارش'),
            'supports'    => array('products'),
        );
    }
}
