<?php
/**
 * Plugin Name: WooCommerce RO Validator (Safe)
 * Description: PF/PJ (fără CNP), preluare date firmă din InfoCUI, sugestii cod poștal (fără blocarea comenzii) + log comenzi cu probleme.
 * Version: 1.2.0
 * Author: Programare
 * Text Domain: wc-ro-validator-safe
 * Domain Path: /languages
 * Requires PHP: 7.0
 * Requires at least: 5.6
 * WC requires at least: 6.0
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WC_RO_Validator_Safe')):

final class WC_RO_Validator_Safe {

    const VERSION = '1.2.0';

    const OPT_API_KEY = 'wc_ro_validator_api_key';
    const OPT_LOGGING = 'wc_ro_validator_enable_logging';
    const OPT_LAST_ERROR = '_wc_ro_safe_last_api_error';

    const META_ISSUE = '_wc_ro_safe_postal_issue';

    const CACHE_TTL_COMPANY = DAY_IN_SECONDS;
    const CACHE_TTL_POSTCODES = DAY_IN_SECONDS;

    /** Maximum number of lookups a single IP may perform per window. */
    const RATE_LIMIT_MAX = 30;
    const RATE_LIMIT_WINDOW = 600;

    /** @var string */
    private $api_base = 'https://www.infocui.ro/system/api/';

    public function __construct() {
        add_action('init', array($this, 'load_textdomain'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        add_filter('woocommerce_checkout_fields', array($this, 'checkout_fields'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));

        add_action('wp_ajax_wc_ro_safe_get_company', array($this, 'ajax_get_company'));
        add_action('wp_ajax_nopriv_wc_ro_safe_get_company', array($this, 'ajax_get_company'));

        add_action('wp_ajax_wc_ro_safe_get_postcodes', array($this, 'ajax_get_postcodes'));
        add_action('wp_ajax_nopriv_wc_ro_safe_get_postcodes', array($this, 'ajax_get_postcodes'));

        add_action('woocommerce_after_checkout_validation', array($this, 'validate_checkout'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_order_meta'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'log_validation_issues'), 10, 3);
        add_action('wc_ro_safe_check_postal', array($this, 'check_postal_async'), 10, 4);
    }

    public function load_textdomain() {
        load_plugin_textdomain('wc-ro-validator-safe', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function admin_menu() {
        add_submenu_page('woocommerce', __('RO Validator (Safe)', 'wc-ro-validator-safe'), __('RO Validator (Safe)', 'wc-ro-validator-safe'), 'manage_woocommerce', 'wc-ro-validator-safe', array($this, 'settings_page'));
        add_submenu_page('woocommerce', __('Validări Adrese (Safe)', 'wc-ro-validator-safe'), __('Validări Adrese (Safe)', 'wc-ro-validator-safe'), 'manage_woocommerce', 'wc-ro-validator-safe-log', array($this, 'log_page'));
    }

    public function register_settings() {
        register_setting(
            'wc_ro_validator_safe',
            self::OPT_API_KEY,
            array('sanitize_callback' => 'sanitize_text_field')
        );
        register_setting(
            'wc_ro_validator_safe',
            self::OPT_LOGGING,
            array('sanitize_callback' => array($this, 'sanitize_logging_option'))
        );
    }

    public function sanitize_logging_option($value) {
        return (int) (bool) $value;
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) { return; }

        $api_key = (string) get_option(self::OPT_API_KEY, '');
        $constant_key = defined('WC_RO_VALIDATOR_API_KEY') && WC_RO_VALIDATOR_API_KEY !== '';
        $last = (string) get_option(self::OPT_LAST_ERROR, '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('RO Validator (Safe) - Setări', 'wc-ro-validator-safe'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('wc_ro_validator_safe'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_API_KEY); ?>"><?php esc_html_e('API Key InfoCUI', 'wc-ro-validator-safe'); ?></label></th>
                        <td>
                            <input type="password" autocomplete="off" id="<?php echo esc_attr(self::OPT_API_KEY); ?>" name="<?php echo esc_attr(self::OPT_API_KEY); ?>" value="<?php echo esc_attr($api_key); ?>" class="regular-text" <?php echo $constant_key ? 'readonly="readonly"' : ''; ?> />
                            <p class="description"><?php esc_html_e('Cheia API din contul tău InfoCUI.ro.', 'wc-ro-validator-safe'); ?></p>
                            <?php if ($constant_key) : ?>
                                <p class="description"><?php esc_html_e('Cheia este definită prin constanta WC_RO_VALIDATOR_API_KEY din wp-config.php și are prioritate.', 'wc-ro-validator-safe'); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($last)) : ?>
                                <p class="description" style="color:#b32d2e;"><strong><?php esc_html_e('Ultima eroare API:', 'wc-ro-validator-safe'); ?></strong> <?php echo esc_html($last); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_LOGGING); ?>"><?php esc_html_e('Logare probleme adresă', 'wc-ro-validator-safe'); ?></label></th>
                        <td>
                            <input type="checkbox" id="<?php echo esc_attr(self::OPT_LOGGING); ?>" name="<?php echo esc_attr(self::OPT_LOGGING); ?>" value="1" <?php checked(get_option(self::OPT_LOGGING), 1); ?> />
                            <label for="<?php echo esc_attr(self::OPT_LOGGING); ?>"><?php esc_html_e('Înregistrează în admin comenzile cu cod poștal lipsă/invalid.', 'wc-ro-validator-safe'); ?></label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function log_page() {
        if (!current_user_can('manage_woocommerce')) { return; }

        $orders = wc_get_orders(array(
            'limit'      => 200,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => array(
                array('key' => self::META_ISSUE, 'compare' => 'EXISTS'),
            ),
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Validări Adrese (Safe)', 'wc-ro-validator-safe'); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Comandă', 'wc-ro-validator-safe'); ?></th>
                    <th><?php esc_html_e('Data', 'wc-ro-validator-safe'); ?></th>
                    <th><?php esc_html_e('Email', 'wc-ro-validator-safe'); ?></th>
                    <th><?php esc_html_e('Problemă', 'wc-ro-validator-safe'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (empty($orders)) : ?>
                    <tr><td colspan="4"><?php esc_html_e('Nu există comenzi cu probleme înregistrate.', 'wc-ro-validator-safe'); ?></td></tr>
                <?php else : foreach ($orders as $order) :
                    $created = $order->get_date_created(); ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a></strong></td>
                        <td><?php echo esc_html($created ? wp_date('d.m.Y H:i', $created->getTimestamp()) : ''); ?></td>
                        <td><?php echo esc_html($order->get_billing_email()); ?></td>
                        <td><?php echo esc_html((string) $order->get_meta(self::META_ISSUE)); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function checkout_fields($fields) {
        $fields['billing']['billing_invoice_type'] = array(
            'type'     => 'select',
            'label'    => __('Tip factură', 'wc-ro-validator-safe'),
            'required' => true,
            'class'    => array('form-row-wide'),
            'options'  => array(
                'pf' => __('Persoană Fizică', 'wc-ro-validator-safe'),
                'pj' => __('Persoană Juridică (Companie)', 'wc-ro-validator-safe'),
            ),
            'priority' => 25,
        );

        $fields['billing']['billing_cui'] = array(
            'type'        => 'text',
            'label'       => __('CUI / CIF', 'wc-ro-validator-safe'),
            'required'    => false,
            'class'       => array('form-row-wide', 'wc-ro-safe-pj'),
            'placeholder' => 'Ex: RO12345678',
            'priority'    => 26,
        );

        if (isset($fields['billing']['billing_company'])) {
            $fields['billing']['billing_company']['class'][]  = 'wc-ro-safe-pj';
            $fields['billing']['billing_company']['required'] = false;
            $fields['billing']['billing_company']['priority'] = 27;
        }

        $fields['billing']['billing_reg_com'] = array(
            'type'     => 'text',
            'label'    => __('Nr. Reg. Com.', 'wc-ro-validator-safe'),
            'required' => false,
            'class'    => array('form-row-wide', 'wc-ro-safe-pj'),
            'priority' => 28,
        );

        // The dropdown is only useful for Romanian addresses; every other country
        // keeps the standard free text postcode input.
        if (isset($fields['billing']['billing_postcode']) && $this->is_ro_billing()) {
            $fields['billing']['billing_postcode']['type']     = 'select';
            $fields['billing']['billing_postcode']['label']    = __('Cod poștal', 'wc-ro-validator-safe');
            $fields['billing']['billing_postcode']['required'] = false;
            $fields['billing']['billing_postcode']['options']  = array('' => __('Selectează codul poștal...', 'wc-ro-validator-safe'));
        }

        return $fields;
    }

    private function is_ro_billing() {
        $country = '';
        if (function_exists('WC') && WC() && WC()->customer) {
            $country = (string) WC()->customer->get_billing_country();
        }
        if ($country === '' && function_exists('WC') && WC() && WC()->countries) {
            $country = (string) WC()->countries->get_base_country();
        }
        return $country === '' || $country === 'RO';
    }

    public function enqueue() {
        if (!is_checkout()) return;

        wp_enqueue_script('wc-ro-validator-safe', plugins_url('assets/js/validator.js', __FILE__), array('jquery'), self::VERSION, true);
        wp_enqueue_style('wc-ro-validator-safe', plugins_url('assets/css/validator.css', __FILE__), array(), self::VERSION);

        wp_localize_script('wc-ro-validator-safe', 'WCRoSafe', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wc_ro_safe_nonce'),
            'messages' => array(
                'loading'            => __('Se încarcă...', 'wc-ro-validator-safe'),
                'select_city'        => __('Selectează mai întâi localitatea', 'wc-ro-validator-safe'),
                'select_postcode'    => __('Selectează codul poștal...', 'wc-ro-validator-safe'),
                'no_postal'          => __('Nu s-au găsit coduri - introdu manual codul poștal', 'wc-ro-validator-safe'),
                'manual_placeholder' => __('Introdu codul poștal', 'wc-ro-validator-safe'),
                'api_error'          => __('Nu am putut verifica acum - introdu manual codul poștal', 'wc-ro-validator-safe'),
                'cui_loading'        => __('Se verifică CUI...', 'wc-ro-validator-safe'),
                'cui_invalid'        => __('Nu am găsit firma pe acest CUI (poți continua comanda)', 'wc-ro-validator-safe'),
                'cui_valid'          => __('Date companie încărcate', 'wc-ro-validator-safe'),
            ),
        ));
    }

    public function ajax_get_company() {
        check_ajax_referer('wc_ro_safe_nonce', 'nonce');

        if (!$this->check_rate_limit()) {
            wp_send_json_error(array('message' => __('Prea multe verificări. Încearcă din nou în câteva minute.', 'wc-ro-validator-safe')), 429);
        }

        $cui_raw = isset($_POST['cui']) ? sanitize_text_field(wp_unslash($_POST['cui'])) : '';
        $cui = preg_replace('/[^0-9]/', '', $cui_raw);

        if ($cui === '' || strlen($cui) < 4) {
            wp_send_json_error(array('message' => __('CUI invalid (prea scurt)', 'wc-ro-validator-safe')));
        }

        $cache_key = 'wc_ro_safe_cui_' . $cui;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            wp_send_json_success($cached);
        }

        $raw = $this->infocui_data($cui);
        if ($raw === false) {
            $this->remember_api_error(__('Eroare conexiune către InfoCUI (timeout/host).', 'wc-ro-validator-safe'));
            wp_send_json_error(array('api_offline' => true, 'message' => __('API offline', 'wc-ro-validator-safe')));
        }

        $data = $this->unwrap($raw);

        $company = $this->pick($data, array('denumire','denumire_firma','company','name','firma','nume'));
        $county  = $this->pick($data, array('judet','county'));

        if ($company !== '') {
            $payload = array(
                'company'     => $company,
                'address'     => $this->pick($data, array('adresa','address','address_1','sediu')),
                'reg_com'     => $this->pick($data, array('numar_reg_com','reg_com','nr_reg_com','registrul_comertului')),
                'city'        => $this->pick($data, array('localitate','city','oras')),
                'county'      => $county,
                'state_code'  => $this->map_county_to_ro_state($county),
                'postal_code' => $this->pick($data, array('cod_postal','postal_code','zipcode','zip')),
            );

            set_transient($cache_key, $payload, self::CACHE_TTL_COMPANY);
            $this->clear_api_error();
            wp_send_json_success($payload);
        }

        $msg = '';
        if (is_array($raw) && isset($raw['message']) && is_string($raw['message'])) {
            $msg = trim($raw['message']);
            if (strtolower($msg) === 'company data') $msg = '';
        }

        wp_send_json_error(array('message' => $msg ? $msg : __('CUI negăsit în InfoCUI', 'wc-ro-validator-safe')));
    }

    public function ajax_get_postcodes() {
        check_ajax_referer('wc_ro_safe_nonce', 'nonce');

        if (!$this->check_rate_limit()) {
            wp_send_json_error(array('message' => __('Prea multe verificări. Încearcă din nou în câteva minute.', 'wc-ro-validator-safe')), 429);
        }

        $city_in  = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        $state_in = isset($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
        // Map WooCommerce RO state codes (e.g., TM) to county names for InfoCUI
        if ($state_in !== '' && strlen($state_in) <= 3) {
            $mapped = $this->map_ro_state_to_county($state_in);
            if ($mapped !== '') {
                $state_in = $mapped;
            }
        }
        $addr1    = isset($_POST['addr1']) ? sanitize_text_field(wp_unslash($_POST['addr1'])) : '';
        $addr2    = isset($_POST['addr2']) ? sanitize_text_field(wp_unslash($_POST['addr2'])) : '';

        if ($city_in === '') wp_send_json_error(array('message' => __('Localitatea este necesară', 'wc-ro-validator-safe')));

        $city  = $this->normalize_ro($city_in);
        $state = $this->normalize_ro($state_in);

        $cache_key = 'wc_ro_safe_cp_' . md5(strtolower($state . '|' . $city . '|' . $addr1 . '|' . $addr2));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            wp_send_json_success(array('codes' => $cached, 'no_codes' => empty($cached), 'cached' => true));
        }

        $codes = array();

        if ($addr1 !== '') {
            $res = $this->infocui_cauta($state, $city, $addr1, $addr2);
            if ($res !== false) $codes = array_merge($codes, $this->extract_postcodes($res, $city_in));
        }

        if (empty($codes) && $state !== '') {
            $list = $this->infocui_localitati_by_judet($state);
            if ($list !== false) $codes = array_merge($codes, $this->extract_postcodes_for_city($list, $city, $city_in));
        }

        if (empty($codes)) {
            $list2 = $this->infocui_localitati($city, '');
            if ($list2 !== false) $codes = array_merge($codes, $this->extract_postcodes($list2, $city_in));
        }

        $uniq = array();
        foreach ($codes as $c) $uniq[$c['code']] = $c;
        $uniq = array_values($uniq);

        set_transient($cache_key, $uniq, self::CACHE_TTL_POSTCODES);
        if (!empty($uniq)) $this->clear_api_error();

        wp_send_json_success(array('codes' => $uniq, 'no_codes' => empty($uniq)));
    }

    /**
     * Server side counterpart of the client side "required" handling: a company
     * invoice without a CUI cannot be turned into a valid invoice.
     */
    public function validate_checkout($data, $errors) {
        $type = isset($data['billing_invoice_type']) ? (string) $data['billing_invoice_type'] : '';
        if ($type !== 'pj') return;

        $cui = isset($data['billing_cui']) ? preg_replace('/[^0-9]/', '', (string) $data['billing_cui']) : '';
        if ($cui === '' || strlen($cui) < 4) {
            $errors->add('billing_cui_required', __('Pentru factură pe firmă trebuie completat CUI-ul.', 'wc-ro-validator-safe'));
        }

        $company = isset($data['billing_company']) ? trim((string) $data['billing_company']) : '';
        if ($company === '') {
            $errors->add('billing_company_required', __('Pentru factură pe firmă trebuie completată denumirea firmei.', 'wc-ro-validator-safe'));
        }
    }

    /**
     * @param WC_Order $order
     */
    public function save_order_meta($order, $data) {
        $keys = array(
            'billing_invoice_type' => '_billing_invoice_type',
            'billing_cui'          => '_billing_cui',
            'billing_reg_com'      => '_billing_reg_com',
        );

        foreach ($keys as $posted => $meta_key) {
            if (isset($data[$posted])) {
                $order->update_meta_data($meta_key, sanitize_text_field((string) $data[$posted]));
            } elseif (isset($_POST[$posted])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checkout nonce verified by WooCommerce.
                $order->update_meta_data($meta_key, sanitize_text_field(wp_unslash($_POST[$posted])));
            }
        }
    }

    public function log_validation_issues($order_id, $posted_data, $order) {
        if (!get_option(self::OPT_LOGGING)) return;

        $city   = isset($posted_data['billing_city']) ? (string) $posted_data['billing_city'] : '';
        $state  = isset($posted_data['billing_state']) ? (string) $posted_data['billing_state'] : '';
        $postal = isset($posted_data['billing_postcode']) ? (string) $posted_data['billing_postcode'] : '';

        if ($city === '') return;

        if ($postal === '') {
            $this->store_issue($order_id, sprintf(
                /* translators: 1: city, 2: county */
                __('Cod poștal lipsă pentru %1$s (%2$s)', 'wc-ro-validator-safe'),
                $city,
                $state
            ));
            return;
        }

        // The remote check must never delay the checkout request itself.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('wc_ro_safe_check_postal', array($order_id, $postal, $city, $state), 'wc-ro-validator-safe');
        }
    }

    public function check_postal_async($order_id, $postal, $city, $state) {
        $ok = $this->infocui_codpostal($postal);
        if ($ok === false) return;

        if (empty($this->unwrap($ok))) {
            $this->store_issue((int) $order_id, sprintf(
                /* translators: 1: postcode, 2: city, 3: county */
                __('Cod poștal posibil invalid: %1$s pentru %2$s (%3$s)', 'wc-ro-validator-safe'),
                $postal,
                $city,
                $state
            ));
        }
    }

    private function store_issue($order_id, $message) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $order->update_meta_data(self::META_ISSUE, $message);
        $order->save();
    }

    /**
     * Simple per-IP throttle so the (public) lookup endpoints cannot be used to
     * drain the InfoCUI quota.
     */
    private function check_rate_limit() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if ($ip === '') return true;

        $key = 'wc_ro_safe_rl_' . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits >= self::RATE_LIMIT_MAX) return false;

        set_transient($key, $hits + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    private function remember_api_error($msg) {
        update_option(self::OPT_LAST_ERROR, wp_date('d.m.Y H:i') . ' - ' . (string) $msg, false);
    }

    private function clear_api_error() {
        if (get_option(self::OPT_LAST_ERROR, '') !== '') {
            delete_option(self::OPT_LAST_ERROR);
        }
    }

    private function pick($arr, $keys) {
        if (!is_array($arr)) return '';
        foreach ($keys as $k) {
            if (isset($arr[$k]) && is_scalar($arr[$k]) && trim((string)$arr[$k]) !== '') return (string) $arr[$k];
        }
        return '';
    }

    private function unwrap($raw) {
        if (!is_array($raw)) return array();
        if (isset($raw['data']) && is_array($raw['data'])) return $raw['data'];
        if (isset($raw['rezultate']) && is_array($raw['rezultate'])) return $raw['rezultate'];
        return $raw;
    }

    private function normalize_ro($s) {
        $s = trim((string) $s);
        if ($s === '') return $s;
        $map = array('ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','Ă'=>'A','Â'=>'A','Î'=>'I','Ș'=>'S','Ş'=>'S','Ț'=>'T','Ţ'=>'T');
        return strtr($s, $map);
    }

    private function is_list($data) {
        if (!is_array($data)) return false;
        if (empty($data)) return true;
        return array_keys($data) === range(0, count($data) - 1);
    }

    private function extract_postcodes($raw, $label_city) {
        $codes = array();
        $data = $this->unwrap($raw);
        if (!is_array($data) || empty($data)) return $codes;

        $rows = $this->is_list($data) ? $data : array($data);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $cp = $this->pick($row, array('cod_postal','codpostal','zipcode','zip'));
            if ($cp === '') continue;
            $name = $this->pick($row, array('nume','localitate','city'));
            $label = $cp . ' - ' . ($name !== '' ? $name : $label_city);
            $codes[] = array('code' => $cp, 'label' => $label);
        }
        return $codes;
    }

    private function extract_postcodes_for_city($raw, $city_norm, $label_city) {
        $codes = array();
        $data = $this->unwrap($raw);
        if (!is_array($data) || empty($data)) return $codes;

        $rows = $this->is_list($data) ? $data : array($data);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = $this->pick($row, array('nume','localitate','city'));
            if ($name === '') continue;
            if ($this->normalize_ro($name) !== $city_norm) continue;
            $codes = array_merge($codes, $this->extract_postcodes($row, $label_city));
        }
        return $codes;
    }

    private function ro_states() {
        if (!function_exists('WC') || !WC() || !isset(WC()->countries)) return array();
        $states = WC()->countries->get_states('RO');
        return is_array($states) ? $states : array();
    }

    private function map_ro_state_to_county($state_value) {
        $state_value = strtoupper(trim((string) $state_value));
        if ($state_value === '') return '';
        // If already looks like a name, return as-is
        if (strlen($state_value) > 3) return $state_value;

        $states = $this->ro_states();
        if (isset($states[$state_value])) {
            // Example: 'TM' => 'Timiș'
            return (string) $states[$state_value];
        }

        return $state_value; // fallback
    }

    /**
     * Reverse of map_ro_state_to_county(): WooCommerce stores the county code.
     */
    private function map_county_to_ro_state($county) {
        $county = trim((string) $county);
        if ($county === '') return '';

        $needle = strtolower($this->normalize_ro($county));
        foreach ($this->ro_states() as $code => $name) {
            if (strtolower($this->normalize_ro($name)) === $needle) {
                return (string) $code;
            }
        }
        return '';
    }

    private function api_key() {
        if (defined('WC_RO_VALIDATOR_API_KEY') && WC_RO_VALIDATOR_API_KEY !== '') {
            return (string) WC_RO_VALIDATOR_API_KEY;
        }
        return (string) get_option(self::OPT_API_KEY, '');
    }

    private function infocui_request($params, $endpoint) {
        $key = $this->api_key();
        if ($key === '') return false;

        $url = add_query_arg(array_merge(array('key' => $key), $params), $this->api_base . ltrim($endpoint, '/'));
        $resp = wp_remote_get($url, array('timeout' => 7));
        if (is_wp_error($resp)) return false;

        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if ($json === null && trim($body) !== '' && strtolower(trim($body)) !== 'null') {
            $this->remember_api_error(__('Răspuns nevalid de la InfoCUI.', 'wc-ro-validator-safe'));
            return false;
        }
        return $json;
    }

    private function infocui_data($cui) { return $this->infocui_request(array('cui' => $cui), 'data'); }
    private function infocui_localitati($city, $state) { return $this->infocui_request(array('nume' => $city, 'judet' => $state), 'localitati'); }
    private function infocui_localitati_by_judet($state) { return $this->infocui_request(array('judet' => $state), 'localitati'); }

    private function infocui_cauta($state, $city, $street, $unit) {
        $params = array('county' => $state, 'city' => $city, 'location' => $street);
        if (trim($unit) !== '') $params['unit'] = $unit;
        return $this->infocui_request($params, 'cauta');
    }

    private function infocui_codpostal($postal) { return $this->infocui_request(array('cod' => $postal), 'codpostal'); }
}

endif;

// Orders are not posts once High-Performance Order Storage is enabled.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Boot safely (no fatal if WooCommerce missing)
add_action('plugins_loaded', function(){
    if (!class_exists('WooCommerce')) return;
    if (class_exists('WC_RO_Validator_Safe')) new WC_RO_Validator_Safe();
});
