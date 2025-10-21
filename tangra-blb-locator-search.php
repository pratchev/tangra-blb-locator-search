<?php
/**
 * Plugin Name: Tangra - BLB Locator Search
 * Description: Front-end locator with brand/country filters, distance filtering/sorting, nightly pre-geocoding, manual geocode/status, cache clear, and BLB-styled UI.
 * Version: 1.2.5
 * Author: Tangra Inc.
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Tangra_BLB_Locator_Search {
    const OPT = 'tg_blbls_settings';
    const GEO_STATUS_OPT = 'tg_blbls_geo_status';
    const CRON_HOOK = 'tg_blbls_nightly_geocode';
    const WORKER_HOOK = 'tg_blbls_geocode_worker'; 

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('blb_locator', array($this, 'render_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_tg_blbls_search', array($this, 'ajax_search'));
        add_action('wp_ajax_nopriv_tg_blbls_search', array($this, 'ajax_search'));

        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action(self::CRON_HOOK, array($this, 'cron_pregeocode'));
        add_action('admin_post_tg_blbls_run_pregeocode', array($this, 'handle_run_pregeocode'));
        add_action('admin_post_tg_blbls_clear_cache', array($this, 'handle_clear_cache'));

        // Register the background worker tick to run on the scheduled worker hook
        add_action(self::WORKER_HOOK, array($this, 'geocode_worker_tick'));

        // Register AJAX endpoint for geo status polling (admin only)
        add_action('wp_ajax_tg_blbls_geo_status', array($this, 'ajax_geo_status'));
    }

    public function add_cron_schedules($schedules){
        // run a worker every minute
        if (empty($schedules['every_minute'])) {
            $schedules['every_minute'] = array('interval' => 60, 'display' => 'Every Minute');
        }
        return $schedules;
    }

    public function activate(){
        $defaults = array(
            'per_page' => 10,
            'colors' => array('#769bcc', '#9bcc76', '#808285'),
            'hover_outline' => true,
            'logo_url' => '',
            'google_api_key' => ''
        );
        if(!get_option(self::OPT)){
            update_option(self::OPT, $defaults);
        }
        if(!get_option(self::GEO_STATUS_OPT)){
            update_option(self::GEO_STATUS_OPT, array(
                'last_run' => null,
                'status'   => 'never',
                'count'    => 0,
                'message'  => ''
            ));
        }
        global $wpdb;
        $table = $wpdb->base_prefix . 'tg_blbls_geocode';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            address_hash CHAR(40) NOT NULL,
            address TEXT NOT NULL,
            lat DECIMAL(10,6) NULL,
            lng DECIMAL(10,6) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY address_hash_unique (address_hash)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if( ! wp_next_scheduled(self::CRON_HOOK) ){
            $ts = strtotime('tomorrow 2:15am');
            if(!$ts){ $ts = time() + 12*HOUR_IN_SECONDS; }
            wp_schedule_event($ts, 'daily', self::CRON_HOOK);
        }
        
        if ( ! wp_next_scheduled(self::WORKER_HOOK) ) {
            wp_schedule_event(time() + 60, 'every_minute', self::WORKER_HOOK);
        }           
    }

    public function deactivate(){
        $t = wp_next_scheduled(self::CRON_HOOK);
        if($t){ wp_unschedule_event($t, self::CRON_HOOK); }

        $w = wp_next_scheduled(self::WORKER_HOOK);
        if($w){ wp_unschedule_event($w, self::WORKER_HOOK); }
    }

    public function admin_menu(){
        add_options_page('Tangra – BLB Locator Search', 'BLB Locator Search', 'manage_options', 'tg-blbls', array($this, 'settings_page'));
    }

    public function register_settings(){
        register_setting('tg_blbls_group', self::OPT, array($this, 'sanitize'));
        add_settings_section('tg_blbls_main', 'Locator Settings', null, 'tg-blbls');
        add_settings_field('per_page', 'Results per page', array($this, 'field_per_page'), 'tg-blbls', 'tg_blbls_main');
        add_settings_field('colors', 'Brand colors (blue, green, gray)', array($this, 'field_colors'), 'tg-blbls', 'tg_blbls_main');
        add_settings_field('hover_outline', 'Button hover outline', array($this, 'field_hover'), 'tg-blbls', 'tg_blbls_main');
        add_settings_field('logo_url', 'Logo URL (optional)', array($this, 'field_logo'), 'tg-blbls', 'tg_blbls_main');
        add_settings_field('google_api_key', 'Google Maps Geocoding API Key', array($this, 'field_api'), 'tg-blbls', 'tg_blbls_main');
    }

    public function sanitize($input){
        $out = get_option(self::OPT, array());
        $out['per_page']       = max(1, intval($input['per_page'] ?? 10));
        $out['colors']         = array_map('sanitize_text_field', $input['colors'] ?? array('#769bcc','#9bcc76','#808285'));
        $out['hover_outline']  = !empty($input['hover_outline']);
        $out['logo_url']       = esc_url_raw($input['logo_url'] ?? '');
        $out['google_api_key'] = sanitize_text_field($input['google_api_key'] ?? '');
        return $out;
    }

    public function field_per_page(){ $o=get_option(self::OPT); echo '<input type="number" min="1" name="'.self::OPT.'[per_page]" value="'.esc_attr($o['per_page']).'"/>'; }
    public function field_colors(){ $o=get_option(self::OPT);
        printf('<input type="text" name="%s[colors][]" value="%s" /> Blue<br/>', self::OPT, esc_attr($o['colors'][0]??'#769bcc'));
        printf('<input type="text" name="%s[colors][]" value="%s" /> Green<br/>', self::OPT, esc_attr($o['colors'][1]??'#9bcc76'));
        printf('<input type="text" name="%s[colors][]" value="%s" /> Gray<br/>', self::OPT, esc_attr($o['colors'][2]??'#808285'));
    }
    public function field_hover(){ $o=get_option(self::OPT); printf('<label><input type="checkbox" name="%s[hover_outline]" %s/> Enable outline on hover</label>', self::OPT, checked(!empty($o['hover_outline']), true, false)); }
    public function field_logo(){ $o=get_option(self::OPT); printf('<input type="url" class="regular-text" name="%s[logo_url]" value="%s" placeholder="https://example.com/logo.png" />', self::OPT, esc_attr($o['logo_url'])); }
    public function field_api(){ $o=get_option(self::OPT); printf('<input type="text" class="regular-text" name="%s[google_api_key]" value="%s" />', self::OPT, esc_attr($o['google_api_key'])); }

    public function settings_page(){
        $status = get_option(self::GEO_STATUS_OPT, array('last_run'=>null,'status'=>'never','count'=>0,'message'=>''));
        echo '<div class="wrap"><h1>Tangra – BLB Locator Search</h1><form method="post" action="options.php">';
        settings_fields('tg_blbls_group'); do_settings_sections('tg-blbls'); submit_button(); echo '</form>';
        echo '<hr/><h2>Geocoding</h2>';
        echo '<p><strong>Last run:</strong> '.( $status['last_run'] ? esc_html($status['last_run']) : 'Never' ).'</p>';
        echo '<p><strong>Status:</strong> '.esc_html($status['status']).'</p>';
        echo '<p><strong>Records geocoded (last run):</strong> '.intval($status['count']).'</p>';
        if(!empty($status['message'])){ echo '<p><em>'.esc_html($status['message']).'</em></p>'; }
        echo '<div style="display:flex;gap:10px;align-items:center;">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('tg_blbls_run_pregeocode');
        echo '<input type="hidden" name="action" value="tg_blbls_run_pregeocode"/>';
        submit_button('Run Geocoding Now', 'primary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('tg_blbls_clear_cache');
        echo '<input type="hidden" name="action" value="tg_blbls_clear_cache"/>';
        submit_button('Clear Geocode Cache', 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
        echo '<p>Nightly pre-geocoding also runs automatically around 2:15am.</p>';
        echo '</div>';
        echo '<script>
        (function(){
        const row = document.querySelector(".wrap");
        function poll(){
            fetch(ajaxurl+"?action=tg_blbls_geo_status",{credentials:"same-origin"})
            .then(r=>r.json()).then(j=>{
                if(!j.success) return;
                // Optionally update a status area in the admin page if you add one
                // Example: console.log(j.data);
            }).catch(()=>{});
        }
        setInterval(poll, 5000);
        })();
        </script>';
    }

    public function enqueue(){
        $o = get_option(self::OPT);
        wp_enqueue_style('tg_blbls_lato','https://fonts.googleapis.com/css2?family=Lato:wght@300;400;500;600;700;900&display=swap',array(),null);
        wp_register_style('tg_blbls_css', plugins_url('assets/style.css', __FILE__), array('tg_blbls_lato'), '1.2.5');
        wp_enqueue_style('tg_blbls_css');
        wp_register_script('tg_blbls_js', plugins_url('assets/locator.js', __FILE__), array('jquery'), '1.2.5', true);
        wp_localize_script('tg_blbls_js', 'TG_BLBL', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce'=> wp_create_nonce('tg_blbls'),
            'perPage' => intval($o['per_page'] ?? 10),
            'assets' => plugins_url('assets/', __FILE__)
        ));
        wp_enqueue_script('tg_blbls_js');
    }

    public function render_shortcode($atts){
        $o = get_option(self::OPT);
        ob_start(); ?>
        <div class="tgfg-wrap">
          <div class="tgfg-center">
            <div class="tgfg-search-card tgfg-card">
              <?php if(!empty($o['logo_url'])): ?>
                <img class="tgfg-logo" src="<?php echo esc_url($o['logo_url']); ?>" alt="Best Life Brands"/>
              <?php endif; ?>
              <div class="tgfg-title">Find BLB Locations</div>

              <form id="tgfg-form">
                <div class="grid distance-row">
                  <label>Distance (miles)
                    <input type="number" step="1" min="0" name="distance" value="25" placeholder="e.g., 25"/>
                  </label>
                  <label>&nbsp;
                    <select name="from" id="tgfg-from">
                      <option value="current">Near Current Location</option>
                      <option value="city">Choose a City</option>
                    </select>
                  </label>
                  <label id="tgfg-nearby-city-wrap" style="display:none;">City
                    <input type="text" name="nearby_city" id="tgfg-nearby-city" placeholder="Enter city name"/>
                  </label>
                </div>
                <hr class="tgfg-separator"/>

                <details class="tgfg-search">
                  <summary>Filters</summary>
                  <div class="grid">
                    <label>Brand
                      <select name="brand" id="tgfg-brand"><option value="">All Brands</option></select>
                    </label>
                    <label>Country
                      <select name="country" id="tgfg-country"><option value="">All Countries</option></select>
                    </label>
                    <label>Franchisee Name
                      <input type="text" name="franchisee"/>
                    </label>
                    <label>Franchisee City
                      <input type="text" name="city"/>
                    </label>
                    <label>State
                      <select name="state" id="tgfg-state"><option value="">All States</option></select>
                    </label>
                    <label>ZIP
                      <input type="text" name="zip" maxlength="10" pattern="[0-9\-]{1,10}" placeholder="e.g., 12345 or 12345-6789"/>
                    </label>
                  </div>
                </details>

                <hr class="tgfg-separator"/>
                <div class="grid sort-row">
                  <label class="sort sort-wide">Sort by
                    <select name="sort" id="tgfg-sort">
                      <option value="distance">Distance (Nearest)</option>
                      <option value="brand,franchisee_name">Brand, Franchisee</option>
                      <option value="brand">Brand</option>
                      <option value="franchisee_name">Franchisee Name</option>
                      <option value="city">City</option>
                      <option value="state">State</option>
                      <option value="zip">ZIP</option>
                    </select>
                  </label>
                </div>

                <div class="actions">
                  <button class="tgfg-btn" type="submit">Search</button>
                  <button class="tgfg-btn ghost" type="button" id="tgfg-reset">Reset</button>
                </div>
              </form>
            </div>

            <div id="tgfg-results-meta" class="tgfg-meta"></div>
            <div id="tgfg-loading" class="tgfg-loading" role="status" aria-live="polite">
                <div class="spinner" aria-hidden="true"></div>
                <span class="sr">Searching…</span>
            </div>
            <div id="tgfg-results" class="tgfg-cards"></div>
            <div id="tgfg-pager" class="tgfg-pager"></div>

            <footer class="tgfg-footer">© <?php echo esc_html(date('Y')); ?> Best Life Brands</footer>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_db() { global $wpdb; return $wpdb; }

    private function normalize_address($row){
        $parts = array_filter(array($row['Address'] ?? '', $row['City'] ?? '', $row['State'] ?? '', $row['ZIP'] ?? '', $row['Country'] ?? ''));
        return trim(implode(', ', $parts));
    }

    /**
     * Clean and normalize an address before geocoding.
     * - Trim
     * - Newlines -> single space
     * - NBSP -> space
     * - Strip control characters
     * - Unicode normalize to NFC (if intl extension is available)
     * - Ensure UTF-8
     * - Collapse multiple spaces
     */
    private function sanitize_address_for_geocode( $address ) {
        $addr = (string) $address;

        // Replace newlines with a space
        $addr = preg_replace('/\r\n|\r|\n/u', ' ', $addr);

        // Replace NBSP with a space
        $addr = str_replace("\xC2\xA0", ' ', $addr); // NBSP

        // Strip ASCII control characters
        $addr = preg_replace('/[\x00-\x1F\x7F]/u', '', $addr);

        // Trim + collapse multiple spaces
        $addr = trim( preg_replace('/\s{2,}/u', ' ', $addr) );

        // Unicode normalize to NFC if available
        if (function_exists('normalizer_normalize')) {
            $addr = normalizer_normalize($addr, \Normalizer::FORM_C);
        }

        // Ensure UTF-8
        if (function_exists('seems_utf8')) {
            if (!seems_utf8($addr)) {
                $addr = mb_convert_encoding($addr, 'UTF-8', 'auto');
            }
        } else {
            // Fallback if WP helper isn't available in this scope
            if (function_exists('mb_detect_encoding') && mb_detect_encoding($addr, 'UTF-8', true) === false) {
                $addr = mb_convert_encoding($addr, 'UTF-8', 'auto');
            }
        }

        return $addr;
    }

    private function geocode_address($address){
        // Sanitize/normalize first
        $address = $this->sanitize_address_for_geocode($address);
        if ($address === '') { return null; }

        global $wpdb;
        $table = $wpdb->base_prefix . 'tg_blbls_geocode';

        // Hash the sanitized address
        $hash = sha1(mb_strtolower($address));

        // Check cache
        $cached = $wpdb->get_row($wpdb->prepare("SELECT lat,lng FROM $table WHERE address_hash=%s", $hash), ARRAY_A);
        if ($cached && !is_null($cached['lat']) && !is_null($cached['lng'])) {
            return array(floatval($cached['lat']), floatval($cached['lng']));
        }

        // API key
        $opts = get_option(self::OPT);
        $key  = $opts['google_api_key'] ?? '';
        if (empty($key)) { return null; }

        // Build URL with explicit rawurlencode to avoid double-encoding issues
        $qAddress = rawurlencode($address);
        $qKey     = rawurlencode($key);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$qAddress}&key={$qKey}";

        // Request (longer timeout, utf-8 header)
        $resp = wp_remote_get($url, array('timeout'=>20, 'headers'=>array('Accept-Charset'=>'utf-8')));
        if (is_wp_error($resp)) { return null; }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!empty($body['results'][0]['geometry']['location'])) {
            $lat = floatval($body['results'][0]['geometry']['location']['lat']);
            $lng = floatval($body['results'][0]['geometry']['location']['lng']);

            // Cache sanitized address
            $wpdb->replace($table, array('address_hash'=>$hash,'address'=>$address,'lat'=>$lat,'lng'=>$lng), array('%s','%s','%f','%f'));
            return array($lat,$lng);
        } else {
            // Cache miss with nulls to avoid re-trying endlessly
            $wpdb->replace($table, array('address_hash'=>$hash,'address'=>$address,'lat'=>null,'lng'=>null), array('%s','%s','%s','%s'));
        }

        return null;
    }

    private function haversine_miles($lat1,$lon1,$lat2,$lon2){
        $R = 3958.756; // miles
        $toRad = function($v){ return deg2rad($v); };
        $dLat = $toRad($lat2 - $lat1);
        $dLon = $toRad($lon2 - $lon1);
        $a = sin($dLat/2)**2 + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon/2)**2;
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }

    public function ajax_search(){
        check_ajax_referer('tg_blbls', 'nonce');
        $wpdb   = $this->get_db();
        $view   = $wpdb->base_prefix . 'blb_salesforce_territories_v';
        $brand  = isset($_POST['brand']) ? sanitize_text_field($_POST['brand']) : '';
        $country= isset($_POST['country']) ? sanitize_text_field($_POST['country']) : '';
        $fran   = isset($_POST['franchisee']) ? sanitize_text_field($_POST['franchisee']) : '';
        $city   = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
        $state  = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';
        $zip    = isset($_POST['zip']) ? sanitize_text_field($_POST['zip']) : '';
        $sort   = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'brand,franchisee_name';
        $page   = max(1, intval($_POST['page'] ?? 1));
        $per    = max(1, intval($_POST['perPage'] ?? (get_option(self::OPT)['per_page'] ?? 10)));
        $userLat= isset($_POST['userLat']) ? floatval($_POST['userLat']) : null;
        $userLng= isset($_POST['userLng']) ? floatval($_POST['userLng']) : null;
        $distanceMiles = isset($_POST['distance']) ? floatval($_POST['distance']) : 0;
        $from   = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : 'current';
        $nearbyCity = isset($_POST['nearby_city']) ? sanitize_text_field($_POST['nearby_city']) : '';

        $where = " WHERE 1=1 ";
        $params = array();
        if($brand!==''){   $where .= " AND `Brand` = %s ";             $params[] = $brand; }
        if($country!==''){ $where .= " AND `Country` = %s ";           $params[] = $country; }
        if($fran!==''){    $where .= " AND `Franchisee Name` LIKE %s ";$params[] = '%'.$wpdb->esc_like($fran).'%'; }
        if($city!==''){    $where .= " AND `City` LIKE %s ";           $params[] = '%'.$wpdb->esc_like($city).'%'; }
        if($state!==''){   $where .= " AND `State` LIKE %s ";          $params[] = '%'.$wpdb->esc_like($state).'%'; }
        if($zip!==''){     
            // Improved ZIP code search to handle various formats
            $zip_clean = preg_replace('/[^0-9]/', '', $zip); // Remove non-numeric chars
            if(strlen($zip_clean) >= 3) {
                // Try exact match first, then prefix match, then match ZIP+4 format
                $where .= " AND (`ZIP` = %s OR `ZIP` LIKE %s OR `ZIP` LIKE %s) ";
                $params[] = $zip_clean; // exact match (e.g., "12345")
                $params[] = $zip_clean.'%'; // prefix match (e.g., "12345*")
                $params[] = $zip_clean.'-%'; // ZIP+4 format (e.g., "12345-*")
            } else {
                // For very short input, just do prefix match
                $where .= " AND `ZIP` LIKE %s ";
                $params[] = $zip_clean.'%';
            }
        }

        $allowedSort = array(
            'brand' => '`Brand`',
            'franchisee_name' => '`Franchisee Name`',
            'city' => '`City`',
            'state' => '`State`',
            'zip' => '`ZIP`'
        );
        $order = '`Brand`, `Franchisee Name`';
        if($sort && isset($allowedSort[$sort])){ $order = $allowedSort[$sort]; }
        elseif($sort == 'brand,franchisee_name'){ $order = '`Brand`, `Franchisee Name`'; }

        $sql = "SELECT `Brand`,`Franchisee Name`,`Address`,`City`,`State`,`ZIP`,`Country`,`Phone`,`Email` 
                FROM `$view` $where ORDER BY $order";
        $rows_all = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        // Determine the reference location for distance calculations
        $refLat = null;
        $refLng = null;
        
        if($from === 'city' && !empty($nearbyCity)){
            // Geocode the nearby city
            $cityGeo = $this->geocode_address($nearbyCity);
            if($cityGeo){
                $refLat = $cityGeo[0];
                $refLng = $cityGeo[1];
            }
        } elseif($from === 'current' && !empty($userLat) && !empty($userLng)){
            // Use current location
            $refLat = $userLat;
            $refLng = $userLng;
        }

        $rows_filtered = array();
        foreach($rows_all as $r){
            $addr = $this->normalize_address($r);
            if(!empty($refLat) && !empty($refLng) && $addr){
                $geo = $this->geocode_address($addr);
                if($geo){
                    $r['Distance'] = round($this->haversine_miles($refLat, $refLng, $geo[0], $geo[1]), 2);
                }
            }
            if($distanceMiles > 0){
                if(isset($r['Distance']) && $r['Distance'] <= $distanceMiles){
                    $rows_filtered[] = $r;
                }
            } else {
                $rows_filtered[] = $r;
            }
        }

        if($sort === 'distance'){
            usort($rows_filtered, function($a,$b){
                $da = $a['Distance'] ?? INF;
                $db = $b['Distance'] ?? INF;
                if($da == $db) return 0;
                return ($da < $db) ? -1 : 1;
            });
        }

        foreach($rows_filtered as &$rr){
            if(isset($rr['Distance'])){
                $rr['Distance'] = number_format((float)$rr['Distance'], 1) . ' mi';
            }
        }

        $total  = count($rows_filtered);
        $offset = ($page-1) * $per;
        $rows   = array_slice($rows_filtered, $offset, $per);

        wp_send_json_success(array(
            'total'     => $total,
            'perPage'   => $per,
            'rows'      => $rows,
            'brands'    => $wpdb->get_col("SELECT DISTINCT `Brand` FROM `$view` ORDER BY `Brand` ASC"),
            'countries' => $wpdb->get_col("SELECT DISTINCT `Country` FROM `$view` ORDER BY `Country` ASC"),
            'states'    => $wpdb->get_col("SELECT DISTINCT `State` FROM `$view` WHERE `State` IS NOT NULL AND `State` != '' ORDER BY `State` ASC")
        ));
    }

    public function cron_pregeocode(){
        $status = array('last_run' => current_time('mysql'), 'status' => 'started', 'count' => 0, 'message' => '');
        update_option(self::GEO_STATUS_OPT, $status);

        $opts = get_option(self::OPT);
        if(empty($opts['google_api_key'])){
            $status['status'] = 'skipped';
            $status['message'] = 'No Google API key configured.';
            update_option(self::GEO_STATUS_OPT, $status);
            return;
        }
        global $wpdb;
        $view = $wpdb->base_prefix . 'blb_salesforce_territories_v';
        $rows = $wpdb->get_results("SELECT `Address`,`City`,`State`,`ZIP`,`Country` FROM `$view`", ARRAY_A);
        $count = 0;
        foreach((array)$rows as $r){
            $addr = $this->normalize_address($r);
            if($addr){
                $geo = $this->geocode_address($addr);
                if($geo){ $count++; }
            }
        }
        $status['status'] = 'done';
        $status['count']  = $count;
        $status['message']= 'Pre-geocoding completed.';
        update_option(self::GEO_STATUS_OPT, $status);
    }

    public function handle_run_pregeocode(){
        if( ! current_user_can('manage_options') || ! check_admin_referer('tg_blbls_run_pregeocode') ){
            wp_die('Not allowed.');
        }
        // $this->cron_pregeocode();
        $this->enqueue_geocode_job();
        wp_safe_redirect( wp_get_referer() ?: admin_url('options-general.php?page=tg-blbls') );
        exit;
    }

    private function enqueue_geocode_job(){
        global $wpdb;
        $view = $wpdb->base_prefix . 'blb_salesforce_territories_v';
        $table = $wpdb->base_prefix . 'tg_blbls_geocode';

        // mark status
        update_option(self::GEO_STATUS_OPT, array(
            'last_run' => current_time('mysql'),
            'status'   => 'queued',
            'count'    => 0,
            'message'  => 'Preparing addresses...'
        ));

        // pull all addresses that we might need to geocode
        $rows = $wpdb->get_results("SELECT `Address`,`City`,`State`,`ZIP`,`Country` FROM `$view`", ARRAY_A);

        // build queue as missing hashes in cache table
        if ($rows) {
            foreach ($rows as $r) {
                $addr = $this->sanitize_address_for_geocode( $this->normalize_address($r) );
                if (!$addr) { continue; }
                $hash = sha1(mb_strtolower($addr));
                // Insert stub rows if not present; don't geocode yet
                $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO $table (address_hash,address,lat,lng,created_at)
                        VALUES (%s,%s,NULL,NULL,NOW())", $hash, $addr
                    )
                );
            }
        }

        // flip status to "ready"
        update_option(self::GEO_STATUS_OPT, array(
            'last_run' => current_time('mysql'),
            'status'   => 'ready',
            'count'    => 0,
            'message'  => 'Queued for background processing.'
        ));
    }

    public function geocode_worker_tick(){
        $opts = get_option(self::OPT);
        $key  = $opts['google_api_key'] ?? '';
        if (empty($key)) { return; } // nothing to do

        global $wpdb;
        $table = $wpdb->base_prefix . 'tg_blbls_geocode';

        // take a small batch that still has NULL coords
        $batch_size = 50;                     // tune as needed
        $rows = $wpdb->get_results(
            "SELECT id, address, address_hash
            FROM $table
            WHERE lat IS NULL AND lng IS NULL
            ORDER BY id ASC
            LIMIT $batch_size", ARRAY_A
        );

        if (!$rows) {
            // finished
            $status = get_option(self::GEO_STATUS_OPT, array());
            $status['status']  = 'done';
            $status['message'] = 'Background geocoding completed.';
            update_option(self::GEO_STATUS_OPT, $status);
            return;
        }

        // Counters for status
        $done = 0;
        foreach($rows as $r){
            $geo = $this->geocode_address_resilient($r['address'], $key);
            if ($geo === false) {
                // hard failure -> write placeholders so we don't spin forever
                $wpdb->update($table, array('lat'=>0,'lng'=>0), array('id'=>$r['id']), array('%f','%f'), array('%d'));
            } elseif (is_array($geo)) {
                $wpdb->update($table, array('lat'=>$geo[0],'lng'=>$geo[1]), array('id'=>$r['id']), array('%f','%f'), array('%d'));
                $done++;
            }
            // tiny delay to be polite to the API; adjust as needed
            usleep(250000); // ~4 QPS overall
        }

        // update running status
        $status = get_option(self::GEO_STATUS_OPT, array('count'=>0));
        $status['status']  = 'running';
        $status['count']   = intval($status['count']) + $done;
        $status['message'] = 'Processing...';
        update_option(self::GEO_STATUS_OPT, $status);
    }

    private function geocode_address_resilient($address, $key){
        $address = $this->sanitize_address_for_geocode($address);
        if ($address === '') { return false; }

        $qAddress = rawurlencode($address);
        $qKey     = rawurlencode($key);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$qAddress}&key={$qKey}";

        $retries = 3; $delay = 1;
        while ($retries-- >= 0) {
            $resp = wp_remote_get($url, array('timeout'=>25, 'headers'=>array('Accept-Charset'=>'utf-8')));
            if (is_wp_error($resp)) { sleep($delay); $delay *= 2; continue; }

            $body = json_decode(wp_remote_retrieve_body($resp), true);
            $status = $body['status'] ?? '';

            if ($status === 'OK' && !empty($body['results'][0]['geometry']['location'])) {
                $lat = (float)$body['results'][0]['geometry']['location']['lat'];
                $lng = (float)$body['results'][0]['geometry']['location']['lng'];
                return array($lat,$lng);
            }
            if (in_array($status, array('OVER_QUERY_LIMIT','RESOURCE_EXHAUSTED','UNKNOWN_ERROR')) || (wp_remote_retrieve_response_code($resp) == 429)) {
                sleep($delay); $delay *= 2; continue;
            }
            return false;
        }
        return false;
    }

    public function ajax_geo_status(){
        if ( ! current_user_can('manage_options') ) wp_send_json_error('forbidden', 403);
        $s = get_option(self::GEO_STATUS_OPT, array('status'=>'unknown','count'=>0,'last_run'=>null,'message'=>''));
        wp_send_json_success($s);
    }

    public function handle_clear_cache(){
        if( ! current_user_can('manage_options') || ! check_admin_referer('tg_blbls_clear_cache') ){
            wp_die('Not allowed.');
        }
        global $wpdb;
        $table = $wpdb->base_prefix . 'tg_blbls_geocode';
        $wpdb->query("TRUNCATE TABLE $table");
        $status = array(
            'last_run' => current_time('mysql'),
            'status'   => 'cleared',
            'count'    => 0,
            'message'  => 'Geocode cache cleared.'
        );
        update_option(self::GEO_STATUS_OPT, $status);
        wp_safe_redirect( wp_get_referer() ?: admin_url('options-general.php?page=tg-blbls') );
        exit;
    }
}

new Tangra_BLB_Locator_Search();
