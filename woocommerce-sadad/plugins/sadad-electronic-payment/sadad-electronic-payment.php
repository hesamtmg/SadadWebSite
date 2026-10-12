<?php
/**
 * Plugin Name: پرداخت الکترونیک سداد Sadad
 * Description: درگاه پرداخت مستقیم سداد بانک ملی برای ووکامرس به همراه داشبورد مدیریتی، گزارش تراکنش‌ها و تاریخ شمسی.
 * Version: 2.2.2
 * Text Domain: sadad-electronic-payment
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('SADAD_ELECTRONIC_PAYMENT_VERSION', '2.2.2');
define('SADAD_ELECTRONIC_PAYMENT_DB_VERSION', '1.0.0');
define('SADAD_ELECTRONIC_PAYMENT_FILE', __FILE__);

function sadad_electronic_payment_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'sadad_electronic_payment_transactions';
}

function sadad_electronic_payment_install_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = sadad_electronic_payment_table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint(20) unsigned NOT NULL DEFAULT 0,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        customer_name varchar(190) NOT NULL DEFAULT '',
        customer_phone varchar(60) NOT NULL DEFAULT '',
        amount_rial bigint(20) NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        bank_order_id varchar(100) NOT NULL DEFAULT '',
        token varchar(255) NOT NULL DEFAULT '',
        tracking_code varchar(190) NOT NULL DEFAULT '',
        res_code varchar(60) NOT NULL DEFAULT '',
        message text NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY user_id (user_id),
        KEY status (status),
        KEY updated_at (updated_at),
        KEY bank_order_id (bank_order_id)
    ) {$charset};";
    dbDelta($sql);
    update_option('sadad_electronic_payment_db_version', SADAD_ELECTRONIC_PAYMENT_DB_VERSION);
}

register_activation_hook(__FILE__, 'sadad_electronic_payment_install_table');

function sadad_electronic_payment_maybe_upgrade() {
    if (get_option('sadad_electronic_payment_db_version') !== SADAD_ELECTRONIC_PAYMENT_DB_VERSION) {
        sadad_electronic_payment_install_table();
    }
}
add_action('plugins_loaded', 'sadad_electronic_payment_maybe_upgrade', 5);


/**
 * One-time migration helper.
 * It intentionally discovers older Sadad-compatible settings/tables by schema,
 * so this package stays fully vendor-neutral and contains no previous branding.
 */
function sadad_electronic_payment_migrate_compatible_data() {
    global $wpdb;

    if (get_option('sadad_electronic_payment_migration_done') === 'yes') {
        return;
    }

    $new_option = 'woocommerce_sadad_electronic_payment_settings';
    $current_settings = get_option($new_option, array());

    if (empty($current_settings) || empty($current_settings['merchant_id']) || empty($current_settings['terminal_id'])) {
        $option_names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_%sadad%_settings'"
        );

        if (is_array($option_names)) {
            foreach ($option_names as $option_name) {
                if ($option_name === $new_option) {
                    continue;
                }
                $candidate = get_option($option_name, array());
                if (!is_array($candidate)) {
                    continue;
                }
                if (array_key_exists('merchant_id', $candidate) && array_key_exists('terminal_id', $candidate) && array_key_exists('terminal_key', $candidate)) {
                    update_option($new_option, $candidate);
                    break;
                }
            }
        }
    }

    $target_table = sadad_electronic_payment_table_name();
    $target_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$target_table}");

    if ($target_count === 0) {
        $all_tables = $wpdb->get_col('SHOW TABLES');
        $required_columns = array(
            'id','order_id','user_id','customer_name','customer_phone','amount_rial','status',
            'bank_order_id','token','tracking_code','res_code','message','created_at','updated_at'
        );

        if (is_array($all_tables)) {
            foreach ($all_tables as $source_table) {
                if ($source_table === $target_table) {
                    continue;
                }
                if (strpos($source_table, $wpdb->prefix) !== 0 || stripos($source_table, 'sadad') === false || stripos($source_table, 'transactions') === false) {
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9_]+$/', $source_table)) {
                    continue;
                }

                $columns = $wpdb->get_col("DESCRIBE `{$source_table}`", 0);
                if (count(array_intersect($required_columns, (array) $columns)) !== count($required_columns)) {
                    continue;
                }

                $column_sql = implode(',', array_map(static function ($column) {
                    return '`' . $column . '`';
                }, $required_columns));

                $wpdb->query("INSERT IGNORE INTO `{$target_table}` ({$column_sql}) SELECT {$column_sql} FROM `{$source_table}`");
                break;
            }
        }
    }

    update_option('sadad_electronic_payment_migration_done', 'yes', false);
}
add_action('plugins_loaded', 'sadad_electronic_payment_migrate_compatible_data', 7);

function sadad_electronic_payment_ascii_digits($value) {
    return strtr(trim((string) $value), array(
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
    ));
}

function sadad_electronic_payment_digits($value) {
    return strtr((string) $value, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
}

function sadad_electronic_payment_number($value) {
    if (is_float($value) || strpos((string) $value, '.') !== false) {
        $out = number_format_i18n((float) $value, 1);
    } else {
        $out = number_format_i18n((int) $value, 0);
    }
    return sadad_electronic_payment_digits($out);
}

function sadad_electronic_payment_money($amount) {
    return sadad_electronic_payment_digits(number_format((int) $amount));
}

function sadad_electronic_payment_gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int) floor(($gy2 + 3) / 4) - (int) floor(($gy2 + 99) / 100) + (int) floor(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int) floor($days / 12053));
    $days %= 12053;
    $jy += 4 * (int) floor($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int) floor(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int) floor($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int) floor(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return array($jy, $jm, $jd);
}

function sadad_electronic_payment_jalali_datetime($mysql_datetime, $date_only = false, $date_format = 'Y/m/d') {
    try {
        $dt = new DateTimeImmutable((string) $mysql_datetime, wp_timezone());
    } catch (Throwable $e) {
        return sadad_electronic_payment_digits((string) $mysql_datetime);
    }
    list($jy, $jm, $jd) = sadad_electronic_payment_gregorian_to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    if ($date_format === 'm/d') {
        $date = sprintf('%02d/%02d', $jm, $jd);
    } else {
        $date = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
    if (!$date_only) {
        $date .= ' - ' . $dt->format('H:i');
    }
    return sadad_electronic_payment_digits($date);
}

function sadad_electronic_payment_tx_create($order, $amount_rial, $bank_order_id) {
    global $wpdb;
    if (!$order) return 0;
    $name = trim($order->get_formatted_billing_full_name());
    if ($name === '') $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    $now = current_time('mysql');
    $ok = $wpdb->insert(
        sadad_electronic_payment_table_name(),
        array(
            'order_id' => (int) $order->get_id(),
            'user_id' => (int) $order->get_user_id(),
            'customer_name' => sanitize_text_field($name),
            'customer_phone' => sanitize_text_field($order->get_billing_phone()),
            'amount_rial' => (int) $amount_rial,
            'status' => 'pending',
            'bank_order_id' => sanitize_text_field((string) $bank_order_id),
            'created_at' => $now,
            'updated_at' => $now,
        ),
        array('%d','%d','%s','%s','%d','%s','%s','%s','%s')
    );
    return $ok ? (int) $wpdb->insert_id : 0;
}

function sadad_electronic_payment_tx_update($tx_id, array $data) {
    global $wpdb;
    $tx_id = absint($tx_id);
    if (!$tx_id) return false;
    $allowed = array('status', 'token', 'tracking_code', 'res_code', 'message', 'amount_rial', 'bank_order_id');
    $clean = array();
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $data)) continue;
        if ($key === 'amount_rial') $clean[$key] = (int) $data[$key];
        elseif ($key === 'message') $clean[$key] = sanitize_textarea_field((string) $data[$key]);
        else $clean[$key] = sanitize_text_field((string) $data[$key]);
    }
    $clean['updated_at'] = current_time('mysql');
    return $wpdb->update(sadad_electronic_payment_table_name(), $clean, array('id' => $tx_id)) !== false;
}

function sadad_electronic_payment_tx_resolve($order, $token = '') {
    global $wpdb;
    if ($token !== '') {
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . sadad_electronic_payment_table_name() . " WHERE token=%s ORDER BY id DESC LIMIT 1", $token));
        if ($id) return $id;
    }
    $meta_id = (int) $order->get_meta('_sadad_electronic_payment_tx_id', true);
    if ($meta_id) return $meta_id;
    return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . sadad_electronic_payment_table_name() . " WHERE order_id=%d ORDER BY id DESC LIMIT 1", $order->get_id()));
}

if (is_admin()) {
    require_once __DIR__ . '/includes/class-sadad-electronic-payment-admin.php';
}

add_action('plugins_loaded', 'sadad_electronic_payment_bootstrap', 20);

function sadad_electronic_payment_bootstrap() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Sadad extends WC_Payment_Gateway {
        const REQUEST_URL = 'https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest';
        const VERIFY_URL  = 'https://sadad.shaparak.ir/vpg/api/v0/Advice/Verify';
        const PAYMENT_URL = 'https://sadad.shaparak.ir/VPG/Purchase?Token=';

        public function __construct() {
            $this->id                 = 'sadad_electronic_payment';
            $this->method_title       = 'پرداخت الکترونیک سداد Sadad';
            $this->method_description = 'اتصال مستقیم و امن به وب‌سرویس سداد بانک ملی';
            $this->has_fields         = false;
            $this->supports           = array('products');
            $this->icon               = plugins_url('assets/sadad.png', SADAD_ELECTRONIC_PAYMENT_FILE);

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', 'پرداخت با کارت بانکی');
            $this->description = $this->get_option('description', 'پرداخت امن از طریق درگاه سداد بانک ملی');
            $this->enabled     = $this->get_option('enabled', 'no');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_sadad', array($this, 'callback'));
            add_action('wp_enqueue_scripts', array($this, 'frontend_style'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array('title' => 'فعال‌سازی', 'type' => 'checkbox', 'label' => 'درگاه سداد فعال باشد', 'default' => 'no'),
                'title' => array('title' => 'عنوان', 'type' => 'text', 'default' => 'پرداخت با کارت بانکی'),
                'description' => array('title' => 'توضیحات', 'type' => 'textarea', 'default' => 'پرداخت امن از طریق درگاه سداد بانک ملی'),
                'merchant_id' => array('title' => 'MerchantId (کد پذیرنده)', 'type' => 'text'),
                'terminal_id' => array('title' => 'TerminalId (کد ترمینال)', 'type' => 'text'),
                'terminal_key' => array('title' => 'TerminalKey (کلید تراکنش)', 'type' => 'password', 'description' => 'کلید Base64 دریافتی از سداد؛ پس از Decode باید دقیقاً ۲۴ بایت باشد.'),
                'currency_mode' => array(
                    'title' => 'واحد مبلغ فروشگاه', 'type' => 'select', 'default' => 'auto',
                    'options' => array('auto' => 'تشخیص خودکار از ووکامرس', 'rial' => 'ریال', 'toman' => 'تومان (تبدیل ×۱۰ به ریال)'),
                ),
                'debug' => array('title' => 'گزارش فنی', 'type' => 'checkbox', 'label' => 'ثبت گزارش امن در ووکامرس ← وضعیت ← گزارش‌ها', 'default' => 'no'),
            );
        }

        public function frontend_style() {
            wp_register_style('sadad-electronic-payment-inline', false, array(), SADAD_ELECTRONIC_PAYMENT_VERSION);
            wp_enqueue_style('sadad-electronic-payment-inline');
            wp_add_inline_style('sadad-electronic-payment-inline',
                '.payment_method_sadad_electronic_payment>label{display:flex!important;align-items:center;gap:.65rem;font-weight:700}' .
                '.payment_method_sadad_electronic_payment>label img{width:2rem!important;height:2rem!important;max-width:2rem!important;object-fit:contain;vertical-align:middle;margin:0!important}' .
                '.sadad-electronic-payment-checkout{margin:.75rem 0 .25rem;padding:1rem 1.1rem;border:1px solid #b9d3ff;border-radius:14px;background:linear-gradient(135deg,#f5f9ff,#fff);color:#14213d;box-shadow:0 6px 22px rgba(21,94,239,.06)}' .
                '.sadad-electronic-payment-checkout p{margin:0 0 .65rem;line-height:1.9}' .
                '.sadad-electronic-payment-features{display:flex;flex-wrap:wrap;gap:.45rem}' .
                '.sadad-electronic-payment-features span{font-size:.78rem;padding:.28rem .6rem;border-radius:999px;background:#fff;border:1px solid #cfe0ff;color:#244a87}'
            );
        }

        public function payment_fields() {
            $description = $this->get_description();
            echo '<div class="sadad-electronic-payment-checkout">';
            if ($description) echo '<p>' . wp_kses_post(wpautop($description)) . '</p>';
            echo '<div class="sadad-electronic-payment-features" aria-label="ویژگی‌های پرداخت">';
            echo '<span>پرداخت امن شاپرکی</span><span>تمام کارت‌های بانکی</span><span>تأیید خودکار سفارش</span>';
            echo '</div></div>';
        }

        public function admin_options() {
            $dashboard_url = admin_url('admin.php?page=sadad-electronic-payment');
            ?>
            <div style="direction:rtl;max-width:1000px;margin:18px 0;padding:20px;border:1px solid #dbe4f0;border-radius:16px;background:#fff">
                <h2 style="margin-top:0">درگاه پرداخت سداد بانک ملی</h2>
                <p>تنظیمات این صفحه همچنان فعال است. برای مشاهده داشبورد درآمد، نمودارها و لیست پرداخت‌ها از منوی مستقل «پرداخت الکترونیک سداد» استفاده کنید.</p>
                <p><a class="button button-primary" href="<?php echo esc_url($dashboard_url); ?>">ورود به پنل مدیریت سداد</a></p>
            </div>
            <?php
            parent::admin_options();
        }

        public function validate_terminal_key_field($key, $value) {
            $value = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $value);
            if ($value === '' && $key !== '') return $key;
            return $value;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('سفارش پیدا نشد.', 'error');
                return array('result' => 'failure');
            }

            $tx_id = 0;
            try {
                $terminal_id = $this->required_digits('terminal_id', 'کد ترمینال');
                $merchant_id = $this->required_digits('merchant_id', 'کد پذیرنده');
                $key         = $this->decoded_key(true);
                $amount      = $this->rial_amount($order);
                $bank_order  = $this->bank_order_id($order);
                $return_url  = add_query_arg(array('wc_order' => $order->get_id(), 'key' => $order->get_order_key()), WC()->api_request_url('WC_Gateway_Sadad'));
                $sign_source = $terminal_id . ';' . $bank_order . ';' . $amount;

                $tx_id = sadad_electronic_payment_tx_create($order, $amount, $bank_order);
                if ($tx_id) $order->update_meta_data('_sadad_electronic_payment_tx_id', $tx_id);

                $payload = array(
                    'TerminalId'    => $terminal_id,
                    'MerchantId'    => $merchant_id,
                    'Amount'        => $amount,
                    'SignData'      => $this->encrypt($sign_source, $key),
                    'ReturnUrl'     => $return_url,
                    'LocalDateTime' => $this->local_datetime(),
                    'OrderId'       => $bank_order,
                );

                $order->update_meta_data('_sadad_electronic_payment_bank_order_id', (string) $bank_order);
                $order->update_meta_data('_sadad_electronic_payment_amount_rial', (string) $amount);
                $order->save();

                $this->log('info', 'PaymentRequest prepared.', array(
                    'wc_order_id' => $order->get_id(), 'transaction_record_id' => $tx_id, 'bank_order_id' => $bank_order, 'amount_rial' => $amount,
                    'sign_source' => $sign_source, 'sign_sha256' => hash('sha256', $payload['SignData']),
                    'key_fingerprint' => substr(hash('sha256', $key), 0, 12), 'local_date_time' => $payload['LocalDateTime'],
                    'return_url' => $return_url, 'json_types' => array_map('gettype', $payload),
                ));

                $response = $this->post_json($this->endpoint('request'), $payload);
                if ((string) ($response['ResCode'] ?? '') !== '0' || empty($response['Token'])) {
                    $code = isset($response['ResCode']) ? (string) $response['ResCode'] : 'no-code';
                    $desc = isset($response['Description']) ? sanitize_text_field($response['Description']) : 'پاسخ نامعتبر از سداد';
                    sadad_electronic_payment_tx_update($tx_id, array('status'=>'failed','res_code'=>$code,'message'=>$desc));
                    $this->log('error', 'PaymentRequest rejected.', array('res_code' => $code, 'description' => $desc));
                    throw new Exception(sprintf('درخواست پرداخت توسط سداد پذیرفته نشد: %s [ResCode: %s]', $desc, $code));
                }

                $token = sanitize_text_field((string) $response['Token']);
                sadad_electronic_payment_tx_update($tx_id, array('status'=>'pending','token'=>$token,'res_code'=>'0','message'=>'توکن پرداخت دریافت شد و کاربر به بانک هدایت شد.'));
                $order->update_meta_data('_sadad_electronic_payment_token', $token);
                $order->add_order_note('توکن پرداخت سداد دریافت شد.');
                $order->save();

                return array('result' => 'success', 'redirect' => $this->endpoint('payment') . rawurlencode($token));
            } catch (Throwable $e) {
                if ($tx_id) sadad_electronic_payment_tx_update($tx_id, array('status'=>'failed','message'=>$e->getMessage()));
                $this->log('error', 'Payment start failed.', array('message' => $e->getMessage()));
                wc_add_notice(esc_html($e->getMessage()), 'error');
                return array('result' => 'failure');
            }
        }

        public function callback() {
            $posted_order = isset($_POST['OrderId']) ? $this->clean_digits(wp_unslash($_POST['OrderId'])) : '';
            $wc_order_id  = isset($_GET['wc_order']) ? absint($_GET['wc_order']) : 0;
            $order        = $wc_order_id ? wc_get_order($wc_order_id) : $this->find_order($posted_order);

            if (!$order || ($wc_order_id && (!isset($_GET['key']) || !hash_equals($order->get_order_key(), sanitize_text_field(wp_unslash($_GET['key'])))))) {
                wp_die('سفارش معتبر پیدا نشد.', 'خطای پرداخت', array('response' => 400));
            }

            if ($order->is_paid()) {
                wp_safe_redirect($this->get_return_url($order));
                exit;
            }

            $res_code = isset($_POST['ResCode']) ? sanitize_text_field(wp_unslash($_POST['ResCode'])) : '';
            $token    = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : (isset($_POST['Token']) ? sanitize_text_field(wp_unslash($_POST['Token'])) : '');
            $expected_bank_order = (string) $order->get_meta('_sadad_electronic_payment_bank_order_id', true);
            $tx_id = sadad_electronic_payment_tx_resolve($order, $token);

            try {
                if ($res_code !== '0' || $token === '') {
                    throw new Exception('پرداخت در بانک انجام نشد یا توسط کاربر لغو شد. [ResCode: ' . ($res_code ?: 'missing') . ']');
                }
                if ($posted_order !== '' && !hash_equals($expected_bank_order, $posted_order)) {
                    throw new Exception('شناسه سفارش بازگشتی با درخواست اولیه تطابق ندارد.');
                }
                $stored_token = (string) $order->get_meta('_sadad_electronic_payment_token', true);
                if ($stored_token !== '' && !hash_equals($stored_token, $token)) {
                    throw new Exception('توکن بازگشتی با درخواست اولیه تطابق ندارد.');
                }

                $verify = $this->post_json($this->endpoint('verify'), array('Token' => $token, 'SignData' => $this->encrypt($token, $this->decoded_key(true))));
                if ((string) ($verify['ResCode'] ?? '') !== '0') {
                    throw new Exception('تأیید تراکنش در سداد ناموفق بود. [ResCode: ' . (string) ($verify['ResCode'] ?? 'no-code') . ']');
                }

                $paid_amount = isset($verify['Amount']) ? (int) $verify['Amount'] : null;
                $expected_amount = (int) $order->get_meta('_sadad_electronic_payment_amount_rial', true);
                if ($paid_amount !== null && $paid_amount !== $expected_amount) {
                    throw new Exception('مبلغ تأییدشده با مبلغ سفارش تطابق ندارد.');
                }

                $tracking = sanitize_text_field((string) ($verify['SystemTraceNo'] ?? $verify['RetrivalRefNo'] ?? $token));
                $order->payment_complete($tracking);
                $order->add_order_note('پرداخت سداد تأیید شد. شماره پیگیری: ' . $tracking);
                sadad_electronic_payment_tx_update($tx_id, array(
                    'status' => 'success',
                    'token' => $token,
                    'tracking_code' => $tracking,
                    'res_code' => (string) ($verify['ResCode'] ?? '0'),
                    'message' => 'پرداخت با موفقیت تأیید شد.'
                ));
                WC()->cart && WC()->cart->empty_cart();
                wp_safe_redirect($this->get_return_url($order));
                exit;
            } catch (Throwable $e) {
                sadad_electronic_payment_tx_update($tx_id, array('status'=>'failed','token'=>$token,'res_code'=>$res_code,'message'=>$e->getMessage()));
                $order->add_order_note('خطای پرداخت سداد: ' . $e->getMessage());
                wc_add_notice($e->getMessage(), 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }
        }

        /**
         * Sadad endpoint URLs. Filterable so a local sandbox can stand in for the bank.
         */
        private function endpoint($name) {
            $endpoints = apply_filters('sadad_electronic_payment_endpoints', array(
                'request' => self::REQUEST_URL,
                'verify'  => self::VERIFY_URL,
                'payment' => self::PAYMENT_URL,
            ));
            return $endpoints[$name];
        }

        private function post_json($url, array $payload) {
            $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) throw new Exception('ساخت JSON درخواست ناموفق بود.');
            $response = wp_remote_post($url, array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'),
                'body' => $json,
                'data_format' => 'body',
            ));
            if (is_wp_error($response)) throw new Exception('ارتباط با سداد برقرار نشد: ' . $response->get_error_message());
            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            if ($status < 200 || $status >= 300 || !is_array($decoded)) throw new Exception('پاسخ نامعتبر از سداد دریافت شد. [HTTP: ' . $status . ']');
            return $decoded;
        }

        private function encrypt($plain, $key) {
            $cipher = openssl_encrypt((string) $plain, 'DES-EDE3', $key, OPENSSL_RAW_DATA);
            if ($cipher === false) throw new Exception('رمزنگاری TripleDES روی این سرور ناموفق بود.');
            return base64_encode($cipher);
        }

        private function decoded_key($throw = true) {
            $encoded = preg_replace('/[\x00-\x20\x7f]+/', '', (string) $this->get_option('terminal_key', ''));
            $key = base64_decode($encoded, true);
            if (!is_string($key) || strlen($key) !== 24) {
                if ($throw) throw new Exception('TerminalKey باید Base64 معتبر باشد و پس از Decode دقیقاً ۲۴ بایت شود.');
                return false;
            }
            return $key;
        }

        private function required_digits($option, $label) {
            $value = $this->clean_digits($this->get_option($option, ''));
            if ($value === '' || !ctype_digit($value)) throw new Exception($label . ' باید فقط شامل ارقام انگلیسی باشد.');
            return $value;
        }

        private function clean_digits($value) {
            return sadad_electronic_payment_ascii_digits($value);
        }

        private function rial_amount($order) {
            $decimal = wc_format_decimal($order->get_total(), wc_get_price_decimals());
            $mode = $this->get_option('currency_mode', 'auto');
            $currency = strtoupper($order->get_currency());
            $is_toman = $mode === 'toman' || ($mode === 'auto' && in_array($currency, array('IRT', 'TOMAN'), true));
            $multiplier = $is_toman ? 10 : 1;
            $amount = (int) round((float) $decimal * $multiplier, 0, PHP_ROUND_HALF_UP);
            if ($amount <= 0) throw new Exception('مبلغ سفارش برای ارسال به سداد معتبر نیست.');
            return $amount;
        }

        private function bank_order_id($order) {
            $existing = $this->clean_digits($order->get_meta('_sadad_electronic_payment_bank_order_id', true));
            if ($existing !== '') return $existing;
            return (string) $order->get_id();
        }

        private function local_datetime() {
            $date = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
            return $date->format('m/d/Y h:i:s A');
        }

        private function find_order($bank_order_id) {
            if ($bank_order_id === '') return false;
            $orders = wc_get_orders(array('limit' => 1, 'meta_key' => '_sadad_electronic_payment_bank_order_id', 'meta_value' => $bank_order_id, 'return' => 'objects'));
            return $orders ? reset($orders) : false;
        }

        private function log($level, $message, array $context = array()) {
            if ($this->get_option('debug', 'no') !== 'yes') return;
            wc_get_logger()->log($level, $message, array_merge(array('source' => 'sadad-electronic-payment'), $context));
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Sadad';
        return $gateways;
    });
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SADAD_ELECTRONIC_PAYMENT_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SADAD_ELECTRONIC_PAYMENT_FILE, true);
    }
});

// Checkout block support: without it the gateway only shows on the shortcode checkout.
add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists(\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) {
        return;
    }
    require_once __DIR__ . '/includes/class-sadad-electronic-payment-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new Sadad_Electronic_Payment_Blocks());
    });
});
