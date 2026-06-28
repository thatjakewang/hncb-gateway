<?php
/**
 * Plugin Name: 華南銀行金流介接
 * Plugin URI: https://jakewang.dev/
 * Description: 本外掛為 Jake Wang 所開發的華南銀行金流介接模組,目前只適用於一期付款。相容 WooCommerce HPOS,支援測試/正式環境切換。
 * Version: 1.0.0
 * Author: Jake Wang
 * Author URI: https://jakewang.dev/
 * Text Domain: hncb-payment
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * 宣告相容 WooCommerce HPOS(自訂訂單資料表)。
 * 不宣告的話,後台會把本外掛標示為「不相容」並可能停用。
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', 'add_hncb_payments');
function add_hncb_payments() {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_hncb extends WC_Payment_Gateway {

        /** 華南正式環境授權網址(預設值) */
        const DEFAULT_PROD_URL = 'https://eposn.hncb.com.tw/transaction/api-auth/';

        public function __construct() {
            $this->id                 = 'hncb_payment';
            $this->has_fields         = false;
            $this->method_title       = '華南銀行金流';
            $this->method_description = '使用華南銀行金流平台進行信用卡付款(支援測試/正式環境切換)';

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title', '銀行線上刷卡');
            $this->enabled     = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_api_bank_cc_result_callback', array($this, 'result_callback'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'show_payment_result'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('啟用 / 停用', 'hncb-payment'),
                    'type'    => 'checkbox',
                    'label'   => __('啟用華南銀行支付', 'hncb-payment'),
                    'default' => 'no',
                ),
                'title' => array(
                    'title'       => __('結帳顯示名稱', 'hncb-payment'),
                    'type'        => 'text',
                    'description' => __('顧客在結帳頁看到的付款方式名稱。', 'hncb-payment'),
                    'default'     => '銀行線上刷卡',
                    'desc_tip'    => true,
                ),
                'environment' => array(
                    'title'       => __('連線環境', 'hncb-payment'),
                    'type'        => 'select',
                    'description' => __('導入新客戶時建議先用「測試」驗證一輪,再切換為「正式」。', 'hncb-payment'),
                    'default'     => 'production',
                    'options'     => array(
                        'test'       => __('測試環境', 'hncb-payment'),
                        'production' => __('正式環境', 'hncb-payment'),
                    ),
                    'desc_tip'    => true,
                ),
                'prod_url' => array(
                    'title'       => __('正式環境授權網址', 'hncb-payment'),
                    'type'        => 'text',
                    'description' => __('華南正式環境網址,一般不需更動。', 'hncb-payment'),
                    'default'     => self::DEFAULT_PROD_URL,
                    'desc_tip'    => true,
                ),
                'test_url' => array(
                    'title'       => __('測試環境授權網址', 'hncb-payment'),
                    'type'        => 'text',
                    'description' => __('請填入華南提供的測試環境網址。選擇「測試環境」時才會使用。', 'hncb-payment'),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'merchantName' => array(
                    'title' => __('公司名稱', 'hncb-payment'),
                    'type'  => 'text',
                ),
                'merchantID' => array(
                    'title' => __('特店代號', 'hncb-payment'),
                    'type'  => 'text',
                ),
                'terminalID' => array(
                    'title'       => __('端末代號(8 位數)', 'hncb-payment'),
                    'type'        => 'text',
                    'description' => __('華南端末代號為 8 碼數字。', 'hncb-payment'),
                    'desc_tip'    => true,
                ),
                'merID' => array(
                    'title' => __('特店代碼', 'hncb-payment'),
                    'type'  => 'text',
                ),
                'checkID' => array(
                    'title' => __('特店通行碼', 'hncb-payment'),
                    'type'  => 'password',
                ),
            );
        }

        /**
         * 後台儲存時做基本欄位驗證,錯誤直接在後台顯示提醒。
         */
        public function process_admin_options() {
            $saved = parent::process_admin_options();

            $terminal_id = $this->get_option('terminalID');
            if ($terminal_id !== '' && ! preg_match('/^\d{8}$/', $terminal_id)) {
                WC_Admin_Settings::add_error(__('端末代號應為 8 位數字,請確認後重新儲存。', 'hncb-payment'));
            }

            if ($this->get_option('environment') === 'test' && trim($this->get_option('test_url')) === '') {
                WC_Admin_Settings::add_error(__('已選擇測試環境,但尚未填入測試環境授權網址。', 'hncb-payment'));
            }

            return $saved;
        }

        /**
         * 依環境設定回傳實際使用的授權網址。
         */
        private function get_service_url() {
            if ($this->get_option('environment') === 'test') {
                return trim($this->get_option('test_url'));
            }
            $prod = trim($this->get_option('prod_url'));
            return $prod !== '' ? $prod : self::DEFAULT_PROD_URL;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            if (! $order) {
                wc_add_notice(__('找不到訂單,請重新嘗試。', 'hncb-payment'), 'error');
                return array('result' => 'failure');
            }
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            );
        }

        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            if (! $order) {
                echo '<p>' . esc_html__('找不到訂單。', 'hncb-payment') . '</p>';
                return;
            }

            $service_url  = $this->get_service_url();
            $merchantName = $this->get_option('merchantName');
            $checkID      = $this->get_option('checkID');
            $merChantID   = $this->get_option('merchantID');
            $terminalID   = $this->get_option('terminalID');
            $merID        = $this->get_option('merID');

            // 設定不完整時擋下,避免顧客在刷卡當下才出錯。
            if ($service_url === '' || $checkID === '' || $merChantID === '' || $terminalID === '' || $merID === '') {
                echo '<p>' . esc_html__('金流設定不完整,請聯絡網站管理員。', 'hncb-payment') . '</p>';
                return;
            }

            $lidm      = $order->get_id();
            $purchAmt  = $order->get_total();
            $AuthResURL = home_url('/wc-api/bank_cc_result_callback');

            /** 驗證公式(華南規格,維持原樣) **/
            $hv1        = md5($checkID . '|' . $lidm);
            $hv2        = md5($hv1 . '|' . $merChantID . '|' . $terminalID . '|' . $purchAmt);
            $checkValue = substr($hv2, -16);
            /** END **/
            ?>
            <h2><?php echo esc_html__('請稍候,將轉址至銀行網頁……', 'hncb-payment'); ?></h2>
            <form id="_bank_auto_form" method="POST" action="<?php echo esc_url($service_url); ?>">
                <input type="hidden" name="checkID"      value="<?php echo esc_attr($checkID); ?>">
                <input type="hidden" name="merID"        value="<?php echo esc_attr($merID); ?>">
                <input type="hidden" name="merchantName" value="<?php echo esc_attr($merchantName); ?>">
                <input type="hidden" name="terminalID"   value="<?php echo esc_attr($terminalID); ?>">
                <input type="hidden" name="merchantID"   value="<?php echo esc_attr($merChantID); ?>">
                <input type="hidden" name="lidm"         value="<?php echo esc_attr($lidm); ?>">
                <input type="hidden" name="txType"       value="0">
                <input type="hidden" name="autoCap"      value="1">
                <input type="hidden" name="purchAmt"     value="<?php echo esc_attr($purchAmt); ?>">
                <input type="hidden" name="checkValue"   value="<?php echo esc_attr($checkValue); ?>">
                <input type="hidden" name="authResURL"   value="<?php echo esc_url($AuthResURL); ?>">
            </form>
            <script>
                document.getElementById('_bank_auto_form').submit();
            </script>
            <?php
        }

        public function result_callback() {
            $checkID = $this->get_option('checkID');

            // 1) 淨化所有輸入
            $lidm          = isset($_POST['lidm'])          ? absint($_POST['lidm']) : 0;
            $status        = isset($_POST['status'])        ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
            $errcode       = isset($_POST['errcode'])       ? sanitize_text_field(wp_unslash($_POST['errcode'])) : '';
            $authCode      = isset($_POST['authCode'])      ? sanitize_text_field(wp_unslash($_POST['authCode'])) : '';
            $authAmt       = isset($_POST['authAmt'])       ? sanitize_text_field(wp_unslash($_POST['authAmt'])) : '';
            $xid           = isset($_POST['xid'])           ? sanitize_text_field(wp_unslash($_POST['xid'])) : '';
            $errDesc       = isset($_POST['errDesc'])       ? sanitize_text_field(wp_unslash($_POST['errDesc'])) : '';
            $Last4digitPAN = isset($_POST['Last4digitPAN']) ? sanitize_text_field(wp_unslash($_POST['Last4digitPAN'])) : '';
            $checkValue    = isset($_POST['checkValue'])    ? sanitize_text_field(wp_unslash($_POST['checkValue'])) : '';

            $order = wc_get_order($lidm);
            if (! $order) {
                wp_die('Invalid order', '', array('response' => 400));
            }

            // 2) 先驗證回傳驗證碼,通過才碰任何資料(用 hash_equals 防時序攻擊)
            $result1       = md5($checkID . '|' . $lidm);
            $result2       = md5($result1 . '|' . $status . '|' . $errcode . '|' . $authCode . '|' . $authAmt . '|' . $xid);
            $newCheckValue = substr($result2, -16);

            if (! hash_equals($newCheckValue, $checkValue)) {
                $order->add_order_note(__('回傳驗證碼不符,疑似偽造請求,已忽略。', 'hncb-payment'));
                wp_die('Invalid checkValue', '', array('response' => 400));
            }

            // 3) 防重複處理:訂單已完成付款就直接導回
            if (! $order->needs_payment()) {
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // 4) 驗證通過,開始寫入(HPOS:update_meta_data + save)
            $order->update_meta_data('_hncb_authAmt', $authAmt);
            $order->update_meta_data('_hncb_xid', $xid);
            $order->update_meta_data('_hncb_Last4digitPAN', $Last4digitPAN);

            $order_note  = '信用卡末四碼：' . $Last4digitPAN . '<br />';
            $order_note .= '授權金額：台幣' . $authAmt . '<br />';
            $order_note .= '交易序號：' . $xid . '<br />';

            // 多一層防線:比對授權金額與訂單金額
            $amount_matches = (string) $authAmt === (string) $order->get_total();

            if ($status === '0' && $amount_matches) {
                $order->update_meta_data('_hncb_authCode', $authCode);
                $order->update_meta_data('_hncb_authresponse', '成功');
                $order_note .= '授權碼：' . $authCode . '<br />';
                $order_note .= '授權結果：成功<br />';
                $order->add_order_note($order_note);
                $order->save();

                $order->payment_complete($xid);   // 內含狀態更新 + 扣庫存,取代 reduce_order_stock()

                if (WC()->cart) {
                    WC()->cart->empty_cart();
                }
            } else {
                $order->update_meta_data('_hncb_authresponse', '失敗');
                $order->update_meta_data('_hncb_errcode', $errcode);
                $order->update_meta_data('_hncb_errDesc', $errDesc);

                if ($status === '0' && ! $amount_matches) {
                    $errDesc = $errDesc !== '' ? $errDesc : '授權金額與訂單金額不符';
                }
                $order_note .= '錯誤代碼：' . $errcode . '<br />';
                $order_note .= '授權結果：失敗<br />';
                $order_note .= '失敗原因：' . $errDesc . '<br />';
                $order->add_order_note($order_note);
                $order->save();

                $order->update_status('failed');
            }

            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        public function show_payment_result($order_id) {
            $order = wc_get_order($order_id);
            if (! $order) {
                return;
            }

            $result = $order->get_meta('_hncb_authresponse');

            echo '<h2>' . esc_html__('信用卡資訊', 'hncb-payment') . '</h2>';
            echo '<pre>';
            echo '授權金額：NTD ' . esc_html($order->get_meta('_hncb_authAmt')) . "\n";
            echo '交易序號：' . esc_html($order->get_meta('_hncb_xid')) . "\n";

            if ($result === '成功') {
                echo '授權碼：' . esc_html($order->get_meta('_hncb_authCode')) . "\n";
                echo '卡號後四碼：' . esc_html($order->get_meta('_hncb_Last4digitPAN')) . "\n";
            } else {
                echo '失敗原因：' . esc_html($order->get_meta('_hncb_errDesc')) . "\n";
            }

            echo '授權結果：' . esc_html($result) . "\n";
            echo '</pre>';
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_to_hncb_payments');
function add_to_hncb_payments($gateways) {
    $gateways[] = 'WC_Gateway_hncb';
    return $gateways;
}