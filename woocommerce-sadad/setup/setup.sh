#!/bin/sh
# One-shot installer, run by the "setup" service in docker-compose.yml.
# Safe to re-run: it skips anything that already exists.
set -eu

cd /var/www/html

echo "==> Waiting for WordPress files and database..."
i=0
until [ -f wp-config.php ] && wp db check >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -gt 90 ]; then echo "Timed out waiting for the database." >&2; exit 1; fi
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  echo "==> Installing WordPress"
  wp core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
  wp rewrite structure '/%postname%/'
  wp option update timezone_string 'Asia/Tehran'
  wp plugin delete akismet hello >/dev/null 2>&1 || true
fi

if ! wp plugin is-installed woocommerce; then
  echo "==> Installing WooCommerce $WOOCOMMERCE_VERSION"
  wp plugin install woocommerce --version="$WOOCOMMERCE_VERSION"
fi
wp plugin activate woocommerce sadad-electronic-payment

if [ -n "${WP_LOCALE:-}" ] && [ "$WP_LOCALE" != "en_US" ]; then
  echo "==> Installing language $WP_LOCALE"
  wp language core install "$WP_LOCALE" --activate || echo "   (language download failed, continuing in English)"
  wp language plugin install woocommerce "$WP_LOCALE" || true
fi

if [ "$(wp option get sadad_demo_setup_done 2>/dev/null || true)" != "yes" ]; then
  echo "==> Configuring the store"
  wp option update woocommerce_currency "$STORE_CURRENCY"
  wp option update woocommerce_default_country 'IR:THR'
  wp option update woocommerce_store_city 'تهران'
  wp option update woocommerce_store_address 'خیابان آزادی'
  wp option update woocommerce_store_postcode '1234567890'
  wp option update woocommerce_price_num_decimals 0
  wp option update woocommerce_price_thousand_sep ','
  wp option update woocommerce_currency_pos 'right_space'
  wp option update woocommerce_allowed_countries 'specific'
  wp option update woocommerce_specific_allowed_countries '["IR"]' --format=json
  wp option update woocommerce_ship_to_countries ''
  wp option update woocommerce_coming_soon 'no'
  wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
  wp option update woocommerce_show_marketplace_suggestions 'no'
  wp option update woocommerce_allow_tracking 'no'

  echo "==> Shipping: flat rate everywhere, free over the threshold"
  wp wc shipping_zone_method create 0 --method_id=flat_rate --enabled=true \
    --settings="{\"title\":\"ارسال پستی\",\"cost\":\"$SHIPPING_COST\"}" --user="$ADMIN_USER" >/dev/null
  wp wc shipping_zone_method create 0 --method_id=free_shipping --enabled=true \
    --settings="{\"title\":\"ارسال رایگان\",\"requires\":\"min_amount\",\"min_amount\":\"$FREE_SHIPPING_MIN\"}" --user="$ADMIN_USER" >/dev/null

  echo "==> Configuring the Sadad gateway"
  wp option update woocommerce_sadad_electronic_payment_settings "$(cat <<JSON
{"enabled":"yes","title":"پرداخت با کارت بانکی (سداد)","description":"پرداخت امن از طریق درگاه سداد بانک ملی با تمام کارت‌های عضو شتاب","merchant_id":"$SADAD_MERCHANT_ID","terminal_id":"$SADAD_TERMINAL_ID","terminal_key":"$SADAD_TERMINAL_KEY","currency_mode":"auto","debug":"yes"}
JSON
)" --format=json

  echo "==> Creating products"
  CAT_ELEC=$(wp wc product_cat create --name='کالای دیجیتال' --slug=digital --user="$ADMIN_USER" --porcelain)
  CAT_LIFE=$(wp wc product_cat create --name='سبک زندگی' --slug=lifestyle --user="$ADMIN_USER" --porcelain)
  CAT_BOOK=$(wp wc product_cat create --name='کتاب' --slug=books --user="$ADMIN_USER" --porcelain)

  # image | name | price (store currency) | sale price | category | short description
  while IFS='|' read -r img name price sale cat desc; do
    [ -z "$img" ] && continue
    media=$(wp media import "/setup/images/$img.jpg" --title="$name" --porcelain)
    wp wc product create --user="$ADMIN_USER" --porcelain \
      --name="$name" --type=simple --status=publish \
      --regular_price="$price" ${sale:+--sale_price="$sale"} \
      --short_description="$desc" --description="$desc" \
      --manage_stock=true --stock_quantity=50 \
      --categories="[{\"id\":$cat}]" --images="[{\"id\":$media}]" >/dev/null
    echo "   + $name"
  done <<EOF
headphones|هدفون بی‌سیم نویزکنسلینگ|4850000|4290000|$CAT_ELEC|هدفون بلوتوثی با حذف نویز فعال و ۳۰ ساعت شارژدهی.
watch|ساعت هوشمند ورزشی|6200000||$CAT_ELEC|ضربان‌سنج، GPS داخلی و ضدآب تا عمق ۵۰ متر.
keyboard|کیبورد مکانیکال|2750000||$CAT_ELEC|سوییچ قرمز، نور پس‌زمینه RGB و حروف فارسی حکاکی‌شده.
backpack|کوله‌پشتی لپ‌تاپ|1890000|1590000|$CAT_LIFE|ضدآب با جای لپ‌تاپ ۱۵.۶ اینچ و پورت USB.
mug|ماگ سرامیکی دست‌ساز|385000||$CAT_LIFE|ماگ لعابی ۳۵۰ میلی‌لیتری، مناسب ماشین ظرف‌شویی.
book|کتاب برنامه‌نویسی وب|520000||$CAT_BOOK|راهنمای کامل ساخت فروشگاه اینترنتی با وردپرس و ووکامرس.
EOF

  echo "==> Home page with the product grid"
  HOME_ID=$(wp post create --post_type=page --post_status=publish --post_title='فروشگاه نمونه سداد' --post_name=home --porcelain \
    --post_content='<!-- wp:paragraph --><p>محصولات را به سبد اضافه کنید و با درگاه سداد بانک ملی پرداخت کنید.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[products limit=12 columns=3 orderby=date order=ASC]<!-- /wp:shortcode -->')
  wp option update show_on_front page
  wp option update page_on_front "$HOME_ID"
  wp post delete 1 --force >/dev/null 2>&1 || true

  wp option update sadad_demo_setup_done yes
fi

# WooCommerce registers its shop/product URLs after the permalink structure was set.
wp rewrite flush

echo ""
echo "============================================================"
echo " Store:   $SITE_URL"
echo " Admin:   $SITE_URL/wp-admin   ($ADMIN_USER / $ADMIN_PASSWORD)"
echo " Sadad:   mode=$SADAD_MODE"
echo "============================================================"
