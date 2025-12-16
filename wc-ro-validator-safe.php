<?php
/**
 * Plugin Name: WooCommerce RO Validator (Safe)
 * Description: PF/PJ (fără CNP), preluare date firmă din InfoCUI, sugestii cod poștal (fără blocarea comenzii) + log comenzi cu probleme.
 * Version: 1.1.4
 * Author: Programare
 * Text Domain: wc-ro-validator-safe
 * Requires PHP: 7.0
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WC_RO_Validator_Safe_114')):

final class WC_RO_Validator_Safe_114 {

    const OPT_API_KEY = 'wc_ro_validator_api_key';
    const OPT_LOGGING = 'wc_ro_validator_enable_logging';

    /** @var string */
    private $api_base = 'https://www.infocui.ro/system/api/';

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        add_filter('woocommerce_checkout_fields', array($this, 'checkout_fields'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));

        add_action('wp_ajax_wc_ro_safe_get_company', array($this, 'ajax_get_company'));
        add_action('wp_ajax_nopriv_wc_ro_safe_get_company', array($this, 'ajax_get_company'));

        add_action('wp_ajax_wc_ro_safe_get_postcodes', array($this, 'ajax_get_postcodes'));
        add_action('wp_ajax_nopriv_wc_ro_safe_get_postcodes', array($this, 'ajax_get_postcodes'));

        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_order_meta'));
        add_action('woocommerce_checkout_order_processed', array($this, 'log_validation_issues'), 10, 3);
    }

    public function admin_menu() {
        add_submenu_page('woocommerce', 'RO Validator (Safe)', 'RO Validator (Safe)', 'manage_woocommerce', 'wc-ro-validator-safe', array($this, 'settings_page'));
        add_submenu_page('woocommerce', 'Validări Adrese (Safe)', 'Validări Adrese (Safe)', 'manage_woocommerce', 'wc-ro-validator-safe-log', array($this, 'log_page'));
    }

    public function register_settings() {
        register_setting('wc_ro_validator_safe', self::OPT_API_KEY);
        register_setting('wc_ro_validator_safe', self::OPT_LOGGING);
    }

    public function settings_page() {
        $api_key = (string) get_option(self::OPT_API_KEY, '');
        $last = (string) get_option('_wc_ro_safe_last_api_error', '');
        ?>
        <div class="wrap">
            <h1>RO Validator (Safe) - Setări</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wc_ro_validator_safe'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_API_KEY); ?>">API Key InfoCUI</label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr(self::OPT_API_KEY); ?>" name="<?php echo esc_attr(self::OPT_API_KEY); ?>" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <p class="description">Cheia API din contul tău InfoCUI.ro.</p>
                            <?php if (!empty($last)) : ?>
                                <p class="description" style="color:#b32d2e;"><strong>Ultima eroare API:</strong> <?php echo esc_html($last); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPT_LOGGING); ?>">Logare probleme adresă</label></th>
                        <td>
                            <input type="checkbox" id="<?php echo esc_attr(self::OPT_LOGGING); ?>" name="<?php echo esc_attr(self::OPT_LOGGING); ?>" value="1" <?php checked(get_option(self::OPT_LOGGING), 1); ?> />
                            <label for="<?php echo esc_attr(self::OPT_LOGGING); ?>">Înregistrează în admin comenzile cu cod poștal lipsă/invalid.</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function log_page() {
        global $wpdb;
        $orders = $wpdb->get_results("
            SELECT p.ID, p.post_date, pm1.meta_value AS issue, pm2.meta_value AS email
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_wc_ro_safe_postal_issue'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_email'
            WHERE p.post_type = 'shop_order' AND pm1.meta_value IS NOT NULL
            ORDER BY p.post_date DESC
            LIMIT 200
        ");
        ?>
        <div class="wrap">
            <h1>Validări Adrese (Safe)</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Comandă</th><th>Data</th><th>Email</th><th>Problemă</th></tr></thead>
                <tbody>
                <?php if (empty($orders)) : ?>
                    <tr><td colspan="4">Nu există comenzi cu probleme înregistrate.</td></tr>
                <?php else : foreach ($orders as $o) : ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url(admin_url('post.php?post=' . $o->ID . '&action=edit')); ?>">#<?php echo esc_html($o->ID); ?></a></strong></td>
                        <td><?php echo esc_html(date('d.m.Y H:i', strtotime($o->post_date))); ?></td>
                        <td><?php echo esc_html($o->email); ?></td>
                        <td><?php echo esc_html($o->issue); ?></td>
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
            'label'    => 'Tip factură',
            'required' => true,
            'class'    => array('form-row-wide'),
            'options'  => array('pf' => 'Persoană Fizică', 'pj' => 'Persoană Juridică (Companie)'),
            'priority' => 25,
        );

        $fields['billing']['billing_cui'] = array(
            'type'        => 'text',
            'label'       => 'CUI / CIF',
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
            'label'    => 'Nr. Reg. Com.',
            'required' => false,
            'class'    => array('form-row-wide', 'wc-ro-safe-pj'),
            'priority' => 28,
        );

        if (isset($fields['billing']['billing_postcode'])) {
            $fields['billing']['billing_postcode']['type']     = 'select';
            $fields['billing']['billing_postcode']['label']    = 'Cod poștal';
            $fields['billing']['billing_postcode']['required'] = false;
            $fields['billing']['billing_postcode']['options']  = array('' => 'Selectează codul poștal...');
        }

        return $fields;
    }

    public function enqueue() {
        if (!is_checkout()) return;

        $js = plugins_url('assets/js/validator.js', __FILE__);
        $css = plugins_url('assets/css/validator.css', __FILE__);

        wp_enqueue_script('wc-ro-validator-safe', $js, array('jquery'), '1.1.4', true);
        wp_enqueue_style('wc-ro-validator-safe', $css, array(), '1.1.4');

        wp_localize_script('wc-ro-validator-safe', 'WCRoSafe', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wc_ro_safe_nonce'),
            'messages' => array(
                'loading'     => 'Se încarcă...',
                'select_city' => 'Selectează mai întâi localitatea',
                'no_postal'   => 'Nu s-au găsit coduri - introdu manual',
                'api_error'   => 'Nu am putut verifica acum (poți continua comanda)',
                'cui_loading' => 'Se verifică CUI...',
                'cui_invalid' => 'Nu am găsit firma pe acest CUI (poți continua comanda)',
                'cui_valid'   => 'Date companie încărcate',
            ),
        ));
    }

    public function ajax_get_company() {
        check_ajax_referer('wc_ro_safe_nonce', 'nonce');

        $cui_raw = isset($_POST['cui']) ? sanitize_text_field(wp_unslash($_POST['cui'])) : '';
        $cui = preg_replace('/[^0-9]/', '', $cui_raw);

        if ($cui === '' || strlen($cui) < 4) {
            wp_send_json_error(array('message' => 'CUI invalid (prea scurt)'));
        }

        $raw = $this->infocui_data($cui);
        if ($raw === false) {
            $this->remember_api_error('Eroare conexiune către InfoCUI (timeout/host).');
            wp_send_json_error(array('api_offline' => true, 'message' => 'API offline'));
        }

        $data = array();
        if (is_array($raw) && isset($raw['data']) && is_array($raw['data'])) $data = $raw['data'];
        elseif (is_array($raw)) $data = $raw;

        $company = $this->pick($data, array('denumire','denumire_firma','company','name','firma','nume'));
        $address = $this->pick($data, array('adresa','address','address_1','sediu'));
        $regcom  = $this->pick($data, array('numar_reg_com','reg_com','nr_reg_com','registrul_comertului'));
        $city    = $this->pick($data, array('localitate','city','oras'));
        $county  = $this->pick($data, array('judet','county'));
        $postal  = $this->pick($data, array('cod_postal','postal_code','zipcode','zip'));

        if ($company !== '') {
            wp_send_json_success(array(
                'company'     => $company,
                'address'     => $address,
                'reg_com'     => $regcom,
                'city'        => $city,
                'county'      => $county,
                'postal_code' => $postal,
            ));
        }

        $msg = '';
        if (is_array($raw) && isset($raw['message']) && is_string($raw['message'])) {
            $msg = trim($raw['message']);
            if (strtolower($msg) === 'company data') $msg = '';
        }

        wp_send_json_error(array('message' => $msg ? $msg : 'CUI negăsit în InfoCUI'));
    }

    public function ajax_get_postcodes() {
        check_ajax_referer('wc_ro_safe_nonce', 'nonce');

        $city_in  = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        \1


// Map WooCommerce RO state codes (e.g., TM) to county names for InfoCUI
if ($state_in !== '' && strlen($state_in) <= 3) {
    $mapped = $this->map_ro_state_to_county($state_in);
    if ($mapped !== '') $state_in = $mapped;
}
        $addr1    = isset($_POST['addr1']) ? sanitize_text_field(wp_unslash($_POST['addr1'])) : '';
        $addr2    = isset($_POST['addr2']) ? sanitize_text_field(wp_unslash($_POST['addr2'])) : '';

        if ($city_in === '') wp_send_json_error(array('message' => 'Localitatea este necesară'));

        $city  = $this->normalize_ro($city_in);
        $state = $this->normalize_ro($state_in);

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

        wp_send_json_success(array('codes' => array_values($uniq), 'no_codes' => empty($uniq)));
    }

    public function save_order_meta($order_id) {
        if (isset($_POST['billing_invoice_type'])) update_post_meta($order_id, '_billing_invoice_type', sanitize_text_field(wp_unslash($_POST['billing_invoice_type'])));
        if (isset($_POST['billing_cui'])) update_post_meta($order_id, '_billing_cui', sanitize_text_field(wp_unslash($_POST['billing_cui'])));
        if (isset($_POST['billing_reg_com'])) update_post_meta($order_id, '_billing_reg_com', sanitize_text_field(wp_unslash($_POST['billing_reg_com'])));
    }

    public function log_validation_issues($order_id, $posted_data, $order) {
        if (!get_option(self::OPT_LOGGING)) return;

        $city   = isset($posted_data['billing_city']) ? (string) $posted_data['billing_city'] : '';
        $state  = isset($posted_data['billing_state']) ? (string) $posted_data['billing_state'] : '';
        $postal = isset($posted_data['billing_postcode']) ? (string) $posted_data['billing_postcode'] : '';

        if ($city === '') return;

        if ($postal === '') {
            update_post_meta($order_id, '_wc_ro_safe_postal_issue', 'Cod poștal lipsă pentru ' . $city . ' (' . $state . ')');
            return;
        }

        $ok = $this->infocui_codpostal($postal);
        if ($ok === false) return;

        $data = $this->unwrap($ok);
        if (empty($data)) {
            update_post_meta($order_id, '_wc_ro_safe_postal_issue', 'Cod poștal posibil invalid: ' . $postal . ' pentru ' . $city . ' (' . $state . ')');
        }
    }

    private function remember_api_error($msg) {
        update_option('_wc_ro_safe_last_api_error', (string) $msg, false);
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

    private function extract_postcodes($raw, $label_city) {
        $codes = array();
        $data = $this->unwrap($raw);
        if (!is_array($data)) return $codes;

        $rows = $data;
        $is_assoc = array_keys($data) !== range(0, count($data) - 1);
        if ($is_assoc) $rows = array($data);

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
        if (!is_array($data)) return $codes;

        $rows = $data;
        $is_assoc = array_keys($data) !== range(0, count($data) - 1);
        if ($is_assoc) $rows = array($data);

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $name = $this->pick($row, array('nume','localitate','city'));
            if ($name === '') continue;
            if ($this->normalize_ro($name) !== $city_norm) continue;
            $codes = array_merge($codes, $this->extract_postcodes($row, $label_city));
        }
        return $codes;
    }


private function map_ro_state_to_county($state_value) {
    $state_value = strtoupper(trim((string)$state_value));
    if ($state_value === '') return '';
    // If already looks like a name, return as-is
    if (strlen($state_value) > 3) return $state_value;

    try {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            $states = WC()->countries->get_states('RO');
            if (is_array($states) && isset($states[$state_value])) {
                // Example: 'TM' => 'Timiș'
                return (string) $states[$state_value];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $state_value; // fallback
}    private function infocui_request($params, $endpoint) {
        $key = (string) get_option(self::OPT_API_KEY, '');
        if ($key === '') return false;

        $url = add_query_arg(array_merge(array('key' => $key), $params), $this->api_base . ltrim($endpoint, '/'));
        $resp = wp_remote_get($url, array('timeout' => 7));
        if (is_wp_error($resp)) return false;

        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if ($json === null && trim($body) !== '' && strtolower(trim($body)) !== 'null') {
            $this->remember_api_error('Răspuns nevalid de la InfoCUI.');
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

// Boot safely (no fatal if WooCommerce missing)
add_action('plugins_loaded', function(){
    if (!class_exists('WooCommerce')) return;
    if (class_exists('WC_RO_Validator_Safe_114')) new WC_RO_Validator_Safe_114();
});
