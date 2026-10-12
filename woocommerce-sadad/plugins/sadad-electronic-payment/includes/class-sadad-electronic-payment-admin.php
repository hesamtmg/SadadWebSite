<?php

defined('ABSPATH') || exit;

class Sadad_Electronic_Payment_Admin {
    const SLUG = 'sadad-electronic-payment';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function menu() {
        $capability = 'manage_woocommerce';
        add_menu_page(
            'پرداخت الکترونیک سداد',
            'پرداخت الکترونیک سداد',
            $capability,
            self::SLUG,
            array(__CLASS__, 'dashboard_page'),
            'dashicons-money-alt',
            56
        );
        add_submenu_page(self::SLUG, 'داشبورد سداد', 'داشبورد', $capability, self::SLUG, array(__CLASS__, 'dashboard_page'));
        add_submenu_page(self::SLUG, 'تراکنش‌های سداد', 'پرداخت‌ها', $capability, self::SLUG . '-transactions', array(__CLASS__, 'transactions_page'));
        add_submenu_page(self::SLUG, 'تنظیمات درگاه سداد', 'تنظیمات درگاه', $capability, self::SLUG . '-settings', array(__CLASS__, 'settings_page'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'sadad-electronic-payment') === false) {
            return;
        }
        wp_enqueue_style('sadad-electronic-payment-admin', plugins_url('assets/admin.css', SADAD_ELECTRONIC_PAYMENT_FILE), array(), SADAD_ELECTRONIC_PAYMENT_VERSION);
        wp_enqueue_script('sadad-electronic-payment-admin', plugins_url('assets/admin.js', SADAD_ELECTRONIC_PAYMENT_FILE), array(), SADAD_ELECTRONIC_PAYMENT_VERSION, true);
    }

    private static function ensure_access() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('شما اجازه دسترسی به این بخش را ندارید.');
        }
    }

    private static function header($title, $subtitle = '') {
        $logo = plugins_url('assets/sadad.png', SADAD_ELECTRONIC_PAYMENT_FILE);
        $today = sadad_electronic_payment_jalali_datetime(current_time('mysql'), false);
        ?>
        <div class="wrap sadad-ui-admin" dir="rtl">
            <div class="sadad-ui-hero">
                <div class="sadad-ui-hero-copy">
                    <div class="sadad-ui-eyebrow"><span class="sadad-ui-live-dot"></span> مدیریت درگاه بانک ملی</div>
                    <h1><?php echo esc_html($title); ?></h1>
                    <?php if ($subtitle) : ?><p><?php echo esc_html($subtitle); ?></p><?php endif; ?>
                    <div class="sadad-ui-date">امروز <?php echo esc_html($today); ?></div>
                </div>
                <div class="sadad-ui-hero-logo"><img src="<?php echo esc_url($logo); ?>" alt="سداد بانک ملی"></div>
            </div>
        <?php
    }

    private static function footer() {
        echo '</div>';
    }

    public static function dashboard_page() {
        self::ensure_access();
        global $wpdb;
        $table = sadad_electronic_payment_table_name();

        $today_start = current_time('Y-m-d') . ' 00:00:00';
        $month_start = current_time('Y-m-01') . ' 00:00:00';

        $today_income = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_rial),0) FROM {$table} WHERE status='success' AND updated_at >= %s", $today_start));
        $month_income = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_rial),0) FROM {$table} WHERE status='success' AND updated_at >= %s", $month_start));
        $failed_month_amount = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_rial),0) FROM {$table} WHERE status='failed' AND updated_at >= %s", $month_start));
        $success_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='success'");
        $failed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='failed'");
        $decided = $success_count + $failed_count;
        $success_rate = $decided > 0 ? round(($success_count / $decided) * 100, 1) : 0;

        $days = self::chart_days(14);
        $chart = self::chart_data($days);
        $recent = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 8");

        self::header('پرداخت الکترونیک سداد', 'نمای زنده از درآمد، وضعیت تراکنش‌ها و عملکرد درگاه');
        ?>
        <div class="sadad-ui-toolbar">
            <div class="sadad-ui-tabs">
                <a class="is-active" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">داشبورد</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">همه پرداخت‌ها</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>">تنظیمات درگاه</a>
            </div>
            <a class="sadad-ui-button sadad-ui-button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">مشاهده گزارش کامل</a>
        </div>

        <div class="sadad-ui-stat-grid">
            <?php self::stat_card('درآمد موفق امروز', sadad_electronic_payment_money($today_income), 'ریال', 'dashicons-chart-line', 'positive'); ?>
            <?php self::stat_card('درآمد موفق این ماه', sadad_electronic_payment_money($month_income), 'ریال', 'dashicons-calendar-alt', 'primary'); ?>
            <?php self::stat_card('پرداخت موفق', sadad_electronic_payment_number($success_count), 'تراکنش', 'dashicons-yes-alt', 'positive'); ?>
            <?php self::stat_card('پرداخت ناموفق', sadad_electronic_payment_number($failed_count), 'تراکنش', 'dashicons-dismiss', 'negative'); ?>
            <?php self::stat_card('مبلغ ناموفق این ماه', sadad_electronic_payment_money($failed_month_amount), 'ریال', 'dashicons-warning', 'negative'); ?>
            <?php self::stat_card('نرخ موفقیت', sadad_electronic_payment_number($success_rate), 'درصد', 'dashicons-performance', 'primary'); ?>
        </div>

        <div class="sadad-ui-chart-grid">
            <section class="sadad-ui-panel">
                <div class="sadad-ui-panel-head"><div><h2>درآمد پرداخت‌های موفق</h2><p>۱۴ روز اخیر — مبالغ بر حسب ریال</p></div><span class="sadad-ui-pill sadad-ui-pill-success">موفق</span></div>
                <div class="sadad-ui-chart-wrap"><canvas class="sadad-ui-chart" data-chart="bar" data-labels='<?php echo esc_attr(wp_json_encode($chart['labels'], JSON_UNESCAPED_UNICODE)); ?>' data-values='<?php echo esc_attr(wp_json_encode($chart['success_amounts'])); ?>' data-value-label="ریال"></canvas></div>
            </section>
            <section class="sadad-ui-panel">
                <div class="sadad-ui-panel-head"><div><h2>مبلغ پرداخت‌های ناموفق</h2><p>۱۴ روز اخیر — مبالغ تلاش‌های ناموفق بر حسب ریال</p></div><span class="sadad-ui-pill sadad-ui-pill-failed">ناموفق</span></div>
                <div class="sadad-ui-chart-wrap"><canvas class="sadad-ui-chart" data-chart="line" data-labels='<?php echo esc_attr(wp_json_encode($chart['labels'], JSON_UNESCAPED_UNICODE)); ?>' data-values='<?php echo esc_attr(wp_json_encode($chart['failed_amounts'])); ?>' data-value-label="ریال"></canvas></div>
            </section>
        </div>

        <section class="sadad-ui-panel sadad-ui-recent-panel">
            <div class="sadad-ui-panel-head">
                <div><h2>آخرین پرداخت‌ها</h2><p>آخرین تلاش‌های ثبت‌شده در درگاه سداد</p></div>
                <a class="sadad-ui-text-link" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">نمایش همه</a>
            </div>
            <?php self::transactions_table($recent, false); ?>
        </section>
        <?php
        self::footer();
    }

    private static function stat_card($label, $value, $unit, $icon, $tone) {
        ?>
        <div class="sadad-ui-stat sadad-ui-stat-<?php echo esc_attr($tone); ?>">
            <div class="sadad-ui-stat-icon"><span class="dashicons <?php echo esc_attr($icon); ?>"></span></div>
            <div class="sadad-ui-stat-copy"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html($value); ?></strong><small><?php echo esc_html($unit); ?></small></div>
        </div>
        <?php
    }

    private static function chart_days($count) {
        $days = array();
        $base = new DateTimeImmutable(current_time('Y-m-d') . ' 12:00:00', wp_timezone());
        for ($i = $count - 1; $i >= 0; $i--) {
            $days[] = $base->modify('-' . $i . ' days')->format('Y-m-d');
        }
        return $days;
    }

    private static function chart_data($days) {
        global $wpdb;
        $table = sadad_electronic_payment_table_name();
        $start = reset($days) . ' 00:00:00';
        $end = end($days) . ' 23:59:59';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(updated_at) d,
                    SUM(CASE WHEN status='success' THEN amount_rial ELSE 0 END) success_amount,
                    SUM(CASE WHEN status='failed' THEN amount_rial ELSE 0 END) failed_amount
             FROM {$table}
             WHERE updated_at BETWEEN %s AND %s
             GROUP BY DATE(updated_at)",
            $start,
            $end
        ), OBJECT_K);

        $labels = $success_amounts = $failed_amounts = array();
        foreach ($days as $day) {
            $labels[] = sadad_electronic_payment_jalali_datetime($day . ' 00:00:00', true, 'm/d');
            $success_amounts[] = isset($rows[$day]) ? (int) $rows[$day]->success_amount : 0;
            $failed_amounts[] = isset($rows[$day]) ? (int) $rows[$day]->failed_amount : 0;
        }
        return compact('labels', 'success_amounts', 'failed_amounts');
    }

    public static function transactions_page() {
        self::ensure_access();
        global $wpdb;
        $table = sadad_electronic_payment_table_name();
        $per_page = 25;
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $status = isset($_GET['tx_status']) ? sanitize_key(wp_unslash($_GET['tx_status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where = 'WHERE 1=1';
        $params = array();
        if (in_array($status, array('success', 'failed', 'pending'), true)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (CAST(order_id AS CHAR) LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR tracking_code LIKE %s OR bank_order_id LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
        $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);
        $offset = ($page - 1) * $per_page;
        $data_sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $data_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, $data_params));
        $pages = max(1, (int) ceil($total / $per_page));

        self::header('لیست پرداخت‌های سداد', 'جستجو، فیلتر و بررسی تمام تلاش‌های پرداخت کاربران');
        ?>
        <div class="sadad-ui-toolbar">
            <div class="sadad-ui-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">داشبورد</a>
                <a class="is-active" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">همه پرداخت‌ها</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>">تنظیمات درگاه</a>
            </div>
        </div>
        <section class="sadad-ui-panel">
            <form method="get" class="sadad-ui-filterbar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG . '-transactions'); ?>">
                <div class="sadad-ui-search"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="شماره سفارش، نام، موبایل یا کد پیگیری"></div>
                <select name="tx_status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="success" <?php selected($status, 'success'); ?>>موفق</option>
                    <option value="failed" <?php selected($status, 'failed'); ?>>ناموفق</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>در انتظار</option>
                </select>
                <button class="sadad-ui-button" type="submit">اعمال فیلتر</button>
                <?php if ($status || $search) : ?><a class="sadad-ui-button sadad-ui-button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">پاک کردن</a><?php endif; ?>
                <span class="sadad-ui-result-count"><?php echo esc_html(sadad_electronic_payment_number($total)); ?> رکورد</span>
            </form>
            <?php self::transactions_table($rows, true); ?>
            <?php if ($pages > 1) : ?>
                <div class="sadad-ui-pagination">
                    <?php
                    echo wp_kses_post(paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $page,
                        'total' => $pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    )));
                    ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        self::footer();
    }

    private static function transactions_table($rows, $show_message) {
        ?>
        <div class="sadad-ui-table-wrap">
            <table class="sadad-ui-table">
                <thead><tr><th>تراکنش</th><th>سفارش</th><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>پیگیری</th><th>تاریخ شمسی</th><?php if ($show_message) : ?><th>جزئیات</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="<?php echo $show_message ? '8' : '7'; ?>" class="sadad-ui-empty">هنوز تراکنشی برای نمایش ثبت نشده است.</td></tr>
                <?php else : foreach ($rows as $row) :
                    $order = function_exists('wc_get_order') ? wc_get_order((int) $row->order_id) : false;
                    $order_url = $order ? $order->get_edit_order_url() : '';
                    $status_meta = self::status_meta($row->status);
                    ?>
                    <tr>
                        <td><strong>#<?php echo esc_html(sadad_electronic_payment_number($row->id)); ?></strong></td>
                        <td><?php if ($order_url) : ?><a class="sadad-ui-order-link" href="<?php echo esc_url($order_url); ?>">#<?php echo esc_html(sadad_electronic_payment_number($row->order_id)); ?></a><?php else : ?>#<?php echo esc_html(sadad_electronic_payment_number($row->order_id)); ?><?php endif; ?></td>
                        <td><div class="sadad-ui-customer"><strong><?php echo esc_html($row->customer_name ?: 'مهمان'); ?></strong><small><?php echo esc_html(sadad_electronic_payment_digits($row->customer_phone)); ?></small></div></td>
                        <td><strong><?php echo esc_html(sadad_electronic_payment_money((int) $row->amount_rial)); ?></strong><small class="sadad-ui-unit"> ریال</small></td>
                        <td><span class="sadad-ui-status <?php echo esc_attr($status_meta['class']); ?>"><i></i><?php echo esc_html($status_meta['label']); ?></span></td>
                        <td><?php echo $row->tracking_code ? '<code>' . esc_html(sadad_electronic_payment_digits($row->tracking_code)) . '</code>' : '—'; ?></td>
                        <td><span class="sadad-ui-date-cell"><?php echo esc_html(sadad_electronic_payment_jalali_datetime($row->updated_at, false)); ?></span></td>
                        <?php if ($show_message) : ?><td><span class="sadad-ui-message" title="<?php echo esc_attr($row->message); ?>"><?php echo esc_html($row->message ?: '—'); ?></span></td><?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function status_meta($status) {
        if ($status === 'success') return array('label' => 'موفق', 'class' => 'is-success');
        if ($status === 'failed') return array('label' => 'ناموفق', 'class' => 'is-failed');
        return array('label' => 'در انتظار', 'class' => 'is-pending');
    }

    public static function settings_page() {
        self::ensure_access();
        $option_name = 'woocommerce_sadad_electronic_payment_settings';
        $settings = get_option($option_name, array());
        $notice = '';
        $notice_type = 'success';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sadad_electronic_payment_save_settings'])) {
            check_admin_referer('sadad_electronic_payment_save_settings');
            $old_key = isset($settings['terminal_key']) ? (string) $settings['terminal_key'] : '';
            $new_key = isset($_POST['terminal_key']) ? preg_replace('/[\x00-\x20\x7f]+/', '', (string) wp_unslash($_POST['terminal_key'])) : '';
            if ($new_key === '') $new_key = $old_key;

            $settings = array(
                'enabled' => isset($_POST['enabled']) ? 'yes' : 'no',
                'title' => isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'پرداخت با کارت بانکی',
                'description' => isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '',
                'merchant_id' => isset($_POST['merchant_id']) ? sadad_electronic_payment_ascii_digits(sanitize_text_field(wp_unslash($_POST['merchant_id']))) : '',
                'terminal_id' => isset($_POST['terminal_id']) ? sadad_electronic_payment_ascii_digits(sanitize_text_field(wp_unslash($_POST['terminal_id']))) : '',
                'terminal_key' => $new_key,
                'currency_mode' => isset($_POST['currency_mode']) && in_array($_POST['currency_mode'], array('auto', 'rial', 'toman'), true) ? sanitize_key($_POST['currency_mode']) : 'auto',
                'debug' => isset($_POST['debug']) ? 'yes' : 'no',
            );

            $key_valid = true;
            if ($settings['terminal_key'] !== '') {
                $decoded = base64_decode($settings['terminal_key'], true);
                $key_valid = is_string($decoded) && strlen($decoded) === 24;
            }
            if (!$key_valid) {
                $notice = 'TerminalKey معتبر نیست؛ مقدار باید Base64 باشد و پس از Decode دقیقاً ۲۴ بایت شود.';
                $notice_type = 'error';
                $settings['terminal_key'] = $old_key;
            } else {
                update_option($option_name, $settings);
                $notice = 'تنظیمات درگاه سداد با موفقیت ذخیره شد.';
            }
        }

        $enabled = isset($settings['enabled']) ? $settings['enabled'] : 'no';
        $title = isset($settings['title']) ? $settings['title'] : 'پرداخت با کارت بانکی';
        $description = isset($settings['description']) ? $settings['description'] : 'پرداخت امن از طریق درگاه سداد بانک ملی';
        $merchant_id = isset($settings['merchant_id']) ? $settings['merchant_id'] : '';
        $terminal_id = isset($settings['terminal_id']) ? $settings['terminal_id'] : '';
        $currency_mode = isset($settings['currency_mode']) ? $settings['currency_mode'] : 'auto';
        $debug = isset($settings['debug']) ? $settings['debug'] : 'no';
        $has_key = !empty($settings['terminal_key']);
        $return_url = function_exists('WC') && WC() ? WC()->api_request_url('WC_Gateway_Sadad') : home_url('/wc-api/WC_Gateway_Sadad/');

        $openssl_ok = extension_loaded('openssl');
        $cipher_ok = $openssl_ok && (in_array('des-ede3', openssl_get_cipher_methods(), true) || in_array('des-ede3-ecb', openssl_get_cipher_methods(), true));
        $key_decoded = $has_key ? base64_decode((string) $settings['terminal_key'], true) : false;
        $key_ok = is_string($key_decoded) && strlen($key_decoded) === 24;

        self::header('تنظیمات درگاه سداد', 'اطلاعات پذیرنده، کلید تراکنش و تنظیمات نمایش در تسویه‌حساب');
        ?>
        <div class="sadad-ui-toolbar">
            <div class="sadad-ui-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">داشبورد</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-transactions')); ?>">همه پرداخت‌ها</a>
                <a class="is-active" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '-settings')); ?>">تنظیمات درگاه</a>
            </div>
        </div>
        <?php if ($notice) : ?><div class="sadad-ui-notice sadad-ui-notice-<?php echo esc_attr($notice_type); ?>"><?php echo esc_html($notice); ?></div><?php endif; ?>

        <div class="sadad-ui-settings-grid">
            <section class="sadad-ui-panel">
                <form method="post" class="sadad-ui-settings-form">
                    <?php wp_nonce_field('sadad_electronic_payment_save_settings'); ?>
                    <div class="sadad-ui-field sadad-ui-switch-field">
                        <div><label for="enabled">فعال‌سازی درگاه</label><p>نمایش سداد بانک ملی در صفحه پرداخت ووکامرس</p></div>
                        <label class="sadad-ui-switch"><input id="enabled" type="checkbox" name="enabled" value="1" <?php checked($enabled, 'yes'); ?>><span></span></label>
                    </div>
                    <div class="sadad-ui-field"><label for="gateway-title">عنوان درگاه</label><input id="gateway-title" type="text" name="title" value="<?php echo esc_attr($title); ?>"></div>
                    <div class="sadad-ui-field"><label for="description">توضیحات</label><textarea id="description" name="description" rows="3"><?php echo esc_textarea($description); ?></textarea></div>
                    <div class="sadad-ui-field-row">
                        <div class="sadad-ui-field"><label for="merchant-id">MerchantId — کد پذیرنده</label><input id="merchant-id" type="text" inputmode="numeric" name="merchant_id" value="<?php echo esc_attr($merchant_id); ?>" autocomplete="off"></div>
                        <div class="sadad-ui-field"><label for="terminal-id">TerminalId — کد ترمینال</label><input id="terminal-id" type="text" inputmode="numeric" name="terminal_id" value="<?php echo esc_attr($terminal_id); ?>" autocomplete="off"></div>
                    </div>
                    <div class="sadad-ui-field"><label for="terminal-key">TerminalKey — کلید تراکنش</label><div class="sadad-ui-password"><input id="terminal-key" type="password" name="terminal_key" value="" placeholder="<?php echo $has_key ? 'برای حفظ کلید فعلی خالی بگذارید' : 'کلید Base64 دریافتی از سداد'; ?>" autocomplete="new-password"><button type="button" class="sadad-ui-password-toggle" data-target="terminal-key">نمایش</button></div><p class="sadad-ui-help">کلید باید پس از Base64 Decode دقیقاً ۲۴ بایت باشد.</p></div>
                    <div class="sadad-ui-field"><label for="currency-mode">واحد مبلغ فروشگاه</label><select id="currency-mode" name="currency_mode"><option value="auto" <?php selected($currency_mode, 'auto'); ?>>تشخیص خودکار از ووکامرس</option><option value="rial" <?php selected($currency_mode, 'rial'); ?>>ریال</option><option value="toman" <?php selected($currency_mode, 'toman'); ?>>تومان — تبدیل ×۱۰ به ریال</option></select></div>
                    <div class="sadad-ui-field sadad-ui-switch-field"><div><label for="debug">گزارش فنی</label><p>ثبت لاگ فنی در ووکامرس ← وضعیت ← گزارش‌ها</p></div><label class="sadad-ui-switch"><input id="debug" type="checkbox" name="debug" value="1" <?php checked($debug, 'yes'); ?>><span></span></label></div>
                    <div class="sadad-ui-form-actions"><button class="sadad-ui-button sadad-ui-button-primary" type="submit" name="sadad_electronic_payment_save_settings" value="1">ذخیره تنظیمات</button></div>
                </form>
            </section>

            <aside class="sadad-ui-side-stack">
                <section class="sadad-ui-panel sadad-ui-health">
                    <div class="sadad-ui-panel-head"><div><h2>وضعیت فنی</h2><p>بررسی پیش‌نیازهای رمزنگاری</p></div></div>
                    <?php self::health_row('OpenSSL', $openssl_ok, $openssl_ok ? 'فعال و آماده' : 'در دسترس نیست'); ?>
                    <?php self::health_row('DES-EDE3', $cipher_ok, $cipher_ok ? 'فعال و آماده' : 'در دسترس نیست'); ?>
                    <?php self::health_row('TerminalKey', $key_ok, $key_ok ? 'معتبر — ۲۴ بایت' : ($has_key ? 'نیازمند بررسی' : 'ثبت نشده')); ?>
                </section>
                <section class="sadad-ui-panel sadad-ui-callback-card">
                    <div class="sadad-ui-panel-head"><div><h2>آدرس بازگشت</h2><p>این URL را در پنل سداد مجاز کنید.</p></div></div>
                    <div class="sadad-ui-copybox"><code id="sadad-ui-callback-url"><?php echo esc_html($return_url); ?></code><button type="button" class="sadad-ui-copy" data-copy-target="sadad-ui-callback-url">کپی</button></div>
                </section>
            </aside>
        </div>
        <?php
        self::footer();
    }

    private static function health_row($label, $ok, $text) {
        ?><div class="sadad-ui-health-row"><div><span class="sadad-ui-health-dot <?php echo $ok ? 'is-ok' : 'is-bad'; ?>"></span><strong><?php echo esc_html($label); ?></strong></div><span><?php echo esc_html($text); ?></span></div><?php
    }
}

Sadad_Electronic_Payment_Admin::init();
