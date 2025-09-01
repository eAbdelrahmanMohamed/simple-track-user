<?php
/**
 * Plugin Name: Simple User Tracker
 * Description: Track user visits with device/session/location + admin reports + CSV export + troubleshooting + pages stats (daily) + optimized.
 * Version: 1.5
 * Author: Abd El Rahman
 */

if (!defined('ABSPATH')) exit;

/* -------------------------
   Config
------------------------- */
define('SUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUT_DB_VERSION', '4');

/* -------------------------
   Autoload (PhpSpreadsheet optional)
------------------------- */
$SUT_VENDOR = __DIR__ . '/vendor/autoload.php';
$SUT_HAS_PHPSPREADSHEET = false;
if (file_exists($SUT_VENDOR)) {
    require_once $SUT_VENDOR;
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        $SUT_HAS_PHPSPREADSHEET = true;
    }
}


/* =======================
   Activation / Uninstall
   ======================= */
function sut_activate_plugin() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $visits = $wpdb->prefix . 'user_visits';
    $logs   = $wpdb->prefix . 'sut_logs';
    $daily  = $wpdb->prefix . 'sut_page_daily';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // visits table includes session_id and is_landing
    $sql1 = "CREATE TABLE {$visits} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NULL,
        device_id VARCHAR(64) NULL,
        session_id VARCHAR(64) NULL,
        ip VARCHAR(45) NULL,
        country VARCHAR(100) NULL,
        region VARCHAR(100) NULL,
        city VARCHAR(100) NULL,
        page_id BIGINT(20) NULL,
        page_url TEXT NULL,
        page_url_hash CHAR(40) NULL,
        page_type VARCHAR(50) NULL,
        referrer TEXT NULL,
        utm_source VARCHAR(100) NULL,
        utm_medium VARCHAR(100) NULL,
        utm_campaign VARCHAR(100) NULL,
        utm_term VARCHAR(100) NULL,
        utm_content VARCHAR(100) NULL,
        geo_lat DECIMAL(10,7) NULL,
        geo_lng DECIMAL(10,7) NULL,
        is_session_landing TINYINT(1) DEFAULT 0,
        is_bot TINYINT(1) DEFAULT 0,
        bot_name VARCHAR(100) NULL,
        is_landing TINYINT(1) DEFAULT 0,
        visited_at DATETIME(6) NOT NULL,
        PRIMARY KEY (id),
        INDEX (user_id),
        INDEX (device_id),
        INDEX (session_id),
        INDEX (page_id),
        INDEX (ip),
        INDEX (visited_at),
        INDEX page_url_hash_idx (page_url_hash),
        INDEX page_hash_visit_idx (page_url_hash, visited_at),
        INDEX device_visit_idx (device_id, visited_at),
        INDEX session_visit_idx (session_id, visited_at),
        INDEX geo_idx (geo_lat, geo_lng),
        INDEX is_bot_idx (is_bot)
    ) {$charset_collate};";

    $sql2 = "CREATE TABLE {$logs} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        message TEXT NOT NULL,
        created_at DATETIME(6) NOT NULL,
        PRIMARY KEY (id),
        INDEX (created_at)
    ) {$charset_collate};";

    $sql3 = "CREATE TABLE {$daily} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        day DATE NOT NULL,
        page_url_hash CHAR(40) NOT NULL,
        page_url TEXT NULL,
        visits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        landings BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_day_hash (day, page_url_hash),
        INDEX (day),
        INDEX (page_url_hash)
    ) {$charset_collate};";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    // Store DB version and schedule cron
    update_option('sut_db_version', SUT_DB_VERSION);
    if (!wp_next_scheduled('sut_cron_aggregate_daily')) {
        wp_schedule_event(time() + 300, 'daily', 'sut_cron_aggregate_daily');
    }

}
register_activation_hook(__FILE__, 'sut_activate_plugin');

function sut_uninstall_plugin() {
    global $wpdb;
    $visits = $wpdb->prefix . 'user_visits';
    $logs   = $wpdb->prefix . 'sut_logs';
    $daily  = $wpdb->prefix . 'sut_page_daily';
    $wpdb->query("DROP TABLE IF EXISTS {$visits}");
    $wpdb->query("DROP TABLE IF EXISTS {$logs}");
    $wpdb->query("DROP TABLE IF EXISTS {$daily}");
}
register_uninstall_hook(__FILE__, 'sut_uninstall_plugin');

/* =======================
   DB Upgrade on load
   ======================= */
add_action('plugins_loaded', 'sut_maybe_upgrade_db');
function sut_maybe_upgrade_db() {
    $current = get_option('sut_db_version');
    if ($current === SUT_DB_VERSION) return;
    // Re-run dbDelta with updated schemas via activation function pieces
    sut_activate_plugin();
}

/* =======================
   CRON: Aggregate daily
   ======================= */
add_action('sut_cron_aggregate_daily', 'sut_run_daily_aggregation');
function sut_run_daily_aggregation($for_day = null) {
    global $wpdb;
    $visits = $wpdb->prefix . 'user_visits';
    $daily  = $wpdb->prefix . 'sut_page_daily';

    $day = $for_day ?: gmdate('Y-m-d', time() - 86400); // default yesterday UTC
    $start = $day . ' 00:00:00';
    $end   = $day . ' 23:59:59';

    // Aggregate by hash within day
    $sql = $wpdb->prepare(
        "SELECT page_url_hash, MIN(page_url) as page_url, COUNT(*) as visits, SUM(is_landing=1) as landings
         FROM {$visits}
         WHERE visited_at BETWEEN %s AND %s
         GROUP BY page_url_hash",
        $start, $end
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if ($rows) {
        foreach ($rows as $r) {
            $wpdb->replace($daily, [
                'day' => $day,
                'page_url_hash' => $r['page_url_hash'] ?: sha1((string)$r['page_url']),
                'page_url' => $r['page_url'],
                'visits' => (int)$r['visits'],
                'landings' => (int)$r['landings'],
            ], ['%s','%s','%s','%d','%d']);
        }
    }
    update_option('sut_last_aggregate_day', $day);
}

/* =======================
   Logging helper
   ======================= */
function sut_log($msg) {
    global $wpdb;
    $table = $wpdb->prefix . 'sut_logs';
    $wpdb->insert($table, [
        'message' => is_scalar($msg) ? $msg : wp_json_encode($msg),
        'created_at' => current_time('mysql', 1)
    ], ['%s', '%s']);
}

/* =======================
   Enqueue tracker.js (creates device_id & session_id cookies)
   ======================= */
add_action('wp_enqueue_scripts', 'sut_enqueue_device_js');
function sut_enqueue_device_js() {
    wp_enqueue_script('sut-track', SUT_PLUGIN_URL . 'includes/tracker.js', [], '1.1', true);
    // Pass endpoint and nonce for geo updates
    wp_localize_script('sut-track', 'SUT_TRACK', [
        'geoEndpoint' => esc_url_raw(rest_url('sut/v1/geo')),
        'nonce' => wp_create_nonce('wp_rest'),
        'sessionKey' => 'sut_geo_sent',
    ]);
}

/* -------------------------
   includes/tracker.js (create this file in plugin includes/ folder)
   Content (JS) — put in includes/tracker.js:
--------------------------------------------------
(function () {
    function getCookie(n) {
        var v = document.cookie.match('(^|;) ?' + n + '=([^;]*)(;|$)');
        return v ? v[2] : null;
    }
    function gen() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0,
                v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    // device_id persistent (1 year)
    if (!getCookie('sut_device_id')) {
        document.cookie = 'sut_device_id=' + gen() + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    }
    // session_id (30 minutes)
    if (!getCookie('sut_session_id')) {
        document.cookie = 'sut_session_id=' + gen() + ';path=/;max-age=' + (30 * 60);
    }
})();
--------------------------------------------------
(End file)
------------------------- */

/* =======================
   Tracking (template_redirect)
   - records device_id, session_id, ip, location, page, is_landing
   - is_landing: first visit for device_id
   ======================= */
add_action('template_redirect', 'sut_track_visit');
function sut_track_visit() {
    if (is_admin()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (is_feed() || is_preview()) return;
    if (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1') return;

    global $wpdb;
    $table = $wpdb->prefix . 'user_visits';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $page_url = esc_url_raw(home_url($_SERVER['REQUEST_URI']));
    $user_id = get_current_user_id() ?: null;
    $page_id = get_queried_object_id() ?: null;
    $device_id = isset($_COOKIE['sut_device_id']) ? sanitize_text_field($_COOKIE['sut_device_id']) : null;
    $session_id = isset($_COOKIE['sut_session_id']) ? sanitize_text_field($_COOKIE['sut_session_id']) : null;
$page_type = null;

    $country = $region = $city = null;
    $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
    $is_bot = 0;
    $bot_name = null;

    // UTM params
    $utm_source = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : null;
    $utm_medium = isset($_GET['utm_medium']) ? sanitize_text_field(wp_unslash($_GET['utm_medium'])) : null;
    $utm_campaign = isset($_GET['utm_campaign']) ? sanitize_text_field(wp_unslash($_GET['utm_campaign'])) : null;
    $utm_term = isset($_GET['utm_term']) ? sanitize_text_field(wp_unslash($_GET['utm_term'])) : null;
    $utm_content = isset($_GET['utm_content']) ? sanitize_text_field(wp_unslash($_GET['utm_content'])) : null;



    // Determine canonical page context (type, id, and URL)
    if (is_singular()) {
        $page_type = get_post_type($page_id) ?: 'post';
        $page_url  = get_permalink($page_id);
    } elseif (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $page_id   = $term->term_id;
            $page_type = $term->taxonomy; // e.g. category, post_tag, custom taxonomy
            $link      = get_term_link($term);
            if (!is_wp_error($link)) {
                $page_url = $link;
            }
        }
    } elseif (is_author()) {
        $author = get_queried_object();
        if ($author && isset($author->ID)) {
            $page_id   = $author->ID;
            $page_type = 'author';
            $page_url  = get_author_posts_url($author->ID);
        }
    } elseif (is_search()) {
        $page_type = 'search';
        $page_url  = function_exists('get_search_link') ? get_search_link() : esc_url_raw(home_url($_SERVER['REQUEST_URI']));
    } elseif (is_post_type_archive()) {
        $page_type = 'post_type_archive';
        $ptype = get_query_var('post_type');
        if (is_array($ptype)) { $ptype = reset($ptype); }
        if ($ptype && function_exists('get_post_type_archive_link')) {
            $link = get_post_type_archive_link($ptype);
            if ($link) { $page_url = $link; }
        }
    } elseif (is_date()) {
        $page_type = 'date_archive';
        $year = get_query_var('year');
        $month = get_query_var('monthnum');
        $day = get_query_var('day');
        if ($year && $month && $day) {
            $page_url = get_day_link((int)$year, (int)$month, (int)$day);
        } elseif ($year && $month) {
            $page_url = get_month_link((int)$year, (int)$month);
        } elseif ($year) {
            $page_url = get_year_link((int)$year);
        }
    } elseif (is_front_page() || is_home()) {
        $page_type = 'home';
        $page_url  = home_url('/');
    } elseif (is_404()) {
        $page_type = '404';
        // keep request URL so we can see what was requested
        $page_url  = esc_url_raw(home_url($_SERVER['REQUEST_URI']));
    } else {
        // Fallback to request URL
        $page_url = esc_url_raw(home_url($_SERVER['REQUEST_URI']));
    }
    // Bot detection (basic UA patterns)
    $bot_patterns = [
        'googlebot','bingbot','yandexbot','duckduckbot','baiduspider','slurp','msnbot','facebookexternalhit',
        'twitterbot','linkedinbot','embedly','quora link preview','pinterestbot','slackbot','discordbot',
        'applebot','semrushbot','ahrefsbot','mj12bot','crawler','spider','bot'
    ];
    $ua_lc = strtolower($user_agent);
    foreach ($bot_patterns as $pat) {
        if (strpos($ua_lc, $pat) !== false) { $is_bot = 1; $bot_name = $pat; break; }
    }

    // Location via ip-api with 24h cache; skip localhost/private ranges
    $is_public_ip = filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, ['127.0.0.1', '::1', '0.0.0.0']);
    if ($is_public_ip) {
        $cache_key = 'sut_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached && is_array($cached)) {
            $country = $cached['country'];
            $region  = $cached['region'];
            $city    = $cached['city'];
        } else {
            $resp = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city", ['timeout' => 2]);
            if (is_wp_error($resp)) {
                sut_log('wp_remote_get error for ip-api: ' . $resp->get_error_message());
            } else {
                $body = wp_remote_retrieve_body($resp);
                $data = @json_decode($body);
                if ($data && isset($data->status) && $data->status === 'success') {
                    $country = isset($data->country) ? sanitize_text_field($data->country) : null;
                    $region  = isset($data->regionName) ? sanitize_text_field($data->regionName) : null;
                    $city    = isset($data->city) ? sanitize_text_field($data->city) : null;
                    set_transient($cache_key, [
                        'country' => $country,
                        'region'  => $region,
                        'city'    => $city,
                    ], DAY_IN_SECONDS);
                } else {
                    sut_log('ip-api non-success for IP ' . $ip . ' raw: ' . substr($body, 0, 200));
                }
            }
        }
    }

    // Determine is_landing: first ever visit for this device_id
    $is_landing = 0;
    $is_session_landing = 0;
    if ($device_id) {
        $cnt = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE device_id = %s", $device_id));
 
        if (intval($cnt) === 0) $is_landing = 1;
    }
    if ($session_id) {
        $sc = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id = %s", $session_id));
        if (intval($sc) === 0) $is_session_landing = 1;
    }
    

    $page_url_hash = sha1((string)$page_url);

   $inserted = $wpdb->insert($table, [
    'user_id'    => $user_id,
    'device_id'  => $device_id,
    'session_id' => $session_id,
    'ip'         => $ip,
    'country'    => $country,
    'region'     => $region,
    'city'       => $city,
    'page_id'    => $page_id,
    'page_type'  => $page_type,
    'page_url'   => $page_url,
    'page_url_hash' => $page_url_hash,
    'referrer'   => $referrer,
    'utm_source' => $utm_source,
    'utm_medium' => $utm_medium,
    'utm_campaign' => $utm_campaign,
    'utm_term'   => $utm_term,
    'utm_content'=> $utm_content,
    'geo_lat'    => null,
    'geo_lng'    => null,
    'is_landing' => $is_landing,
    'is_bot'     => $is_bot,
    'bot_name'   => $bot_name,
    'is_session_landing' => $is_session_landing,
    'visited_at' => current_time('mysql', 1),
], [
    '%d', // user_id
    '%s', // device_id
    '%s', // session_id
    '%s', // ip
    '%s', // country
    '%s', // region
    '%s', // city
    '%d', // page_id
    '%s', // page_type
    '%s', // page_url
    '%s', // page_url_hash
    '%s', // referrer
    '%s', // utm_source
    '%s', // utm_medium
    '%s', // utm_campaign
    '%s', // utm_term
    '%s', // utm_content
    '%f', // geo_lat
    '%f', // geo_lng
    '%d', // is_landing
    '%d', // is_bot
    '%s', // bot_name
    '%d', // is_session_landing
    '%s'  // visited_at
]);



    if ($inserted === false) {
        sut_log('DB insert failed: ' . $wpdb->last_error);
    }
}
function sut_get_page_label($row) {
    switch ($row->page_type) {
        case 'category':
        case 'post_tag':
        case 'taxonomy':
            $term = get_term($row->page_id, $row->page_type);
            return $term ? ucfirst($row->page_type) . ': ' . $term->name : $row->page_url;

        case 'author':
            $user = get_userdata($row->page_id);
            return $user ? 'Author: ' . $user->display_name : $row->page_url;

        case 'search':
            return 'Search Results';

        case 'post':
        default:
            return get_the_title($row->page_id) ?: $row->page_url;
    }
}

/* =======================
   Admin pages (menu)
   ======================= */
add_action('admin_menu', 'sut_admin_menu');
function sut_admin_menu() {
    add_menu_page('User Tracker', 'User Tracker', 'manage_options', 'sut-main', 'sut_page_visits', 'dashicons-chart-area', 60);
    add_submenu_page('sut-main', 'Visits', 'Visits', 'manage_options', 'sut-visits', 'sut_page_visits');
    add_submenu_page('sut-main', 'Pages Stats', 'Pages Stats', 'manage_options', 'sut-pages-stats', 'sut_page_pages_stats');
    add_submenu_page('sut-main', 'Troubleshoot', 'Troubleshoot', 'manage_options', 'sut-troubleshoot', 'sut_page_troubleshoot');
    add_submenu_page('sut-main', 'Retention', 'Retention', 'manage_options', 'sut-retention', 'sut_page_retention');
}

/* =======================
   Helper: build where & params from GET for visits page
   Returns array(where_sql, params_array)
   ======================= */
function sut_build_where_params_from_get() {
    global $wpdb;
    $where = "WHERE 1=1";
    $params = [];

    if (!empty($_GET['search'])) {
        $s = sanitize_text_field(wp_unslash($_GET['search']));
        $like = '%' . $wpdb->esc_like($s) . '%';
        $where .= " AND (ip LIKE %s OR device_id LIKE %s OR page_url LIKE %s)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (!empty($_GET['from'])) {
        $from = sanitize_text_field($_GET['from']) . ' 00:00:00';
        $where .= " AND visited_at >= %s";
        $params[] = $from;
    }
    if (!empty($_GET['to'])) {
        $to = sanitize_text_field($_GET['to']) . ' 23:59:59';
        $where .= " AND visited_at <= %s";
        $params[] = $to;
    }
    if (!empty($_GET['page_url'])) {
        $p = sanitize_text_field(wp_unslash($_GET['page_url']));
        $likep = '%' . $wpdb->esc_like($p) . '%';
        $where .= " AND page_url LIKE %s";
        $params[] = $likep;
    }

    return [$where, $params];
}

/* =======================
   Visits Page: search/filter/pagination/export CSV
   ======================= */
function sut_page_visits() {
    global $wpdb;
    $table = $wpdb->prefix . 'user_visits';

    list($where, $params) = sut_build_where_params_from_get();

    // Export handling
    if (isset($_GET['sut_export'])) {
        if ($_GET['sut_export'] === 'csv') {
            sut_export_csv_filtered($where, $params);
            exit;
        } elseif ($_GET['sut_export'] === 'xlsx') {
            sut_export_xlsx_filtered($where, $params);
            exit;
        }
    }

    // pagination
    $per_page = isset($_GET['per_page']) ? max(5, min(200, intval($_GET['per_page']))) : 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    // count total (prepare)
    if (!empty($params)) {
        $count_sql = call_user_func_array([$wpdb, 'prepare'], array_merge(["SELECT COUNT(*) FROM {$table} {$where}"], $params));
        $total = intval($wpdb->get_var($count_sql));
    } else {
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}"));
    }

    // fetch page
    $select_sql = "SELECT * FROM {$table} {$where} ORDER BY visited_at DESC LIMIT %d OFFSET %d";
    if (!empty($params)) {
        $prepared_select = $wpdb->prepare($select_sql, array_merge($params, [$per_page, $offset]));
    } else {
        $prepared_select = $wpdb->prepare($select_sql, $per_page, $offset);
    }
    $rows = $wpdb->get_results($prepared_select);

    // Top pages summary (limit 20)
    $stats_sql = "SELECT page_url, COUNT(*) AS total_visits, SUM(is_landing = 1) AS landing_visits
                  FROM {$table} {$where} GROUP BY page_url ORDER BY total_visits DESC LIMIT 20";
    $prepared_stats = !empty($params) ? $wpdb->prepare($stats_sql, $params) : $stats_sql;
    $stats = $wpdb->get_results($prepared_stats);

    // Render UI
    echo '<div class="wrap"><h1>User Visits</h1>';

    // filter form
    $search_val = isset($_GET['search']) ? esc_attr(sanitize_text_field(wp_unslash($_GET['search']))) : '';
    $from_val = isset($_GET['from']) ? esc_attr(sanitize_text_field($_GET['from'])) : '';
    $to_val = isset($_GET['to']) ? esc_attr(sanitize_text_field($_GET['to'])) : '';
    $pagefilter = isset($_GET['page_url']) ? esc_attr(sanitize_text_field(wp_unslash($_GET['page_url']))) : '';

    echo '<form method="get" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="sut-visits" />';
    echo '<input style="width:260px;margin-right:6px;" type="text" name="search" placeholder="Search IP / Device / Page" value="' . $search_val . '" /> ';
    echo 'From: <input type="date" name="from" value="' . $from_val . '" /> ';
    echo 'To: <input type="date" name="to" value="' . $to_val . '" /> ';
    echo 'Page: <input style="width:300px;margin-left:6px;" type="text" name="page_url" value="' . $pagefilter . '" /> ';
    echo 'Per page: <input style="width:70px;margin-left:6px;" type="number" name="per_page" value="' . esc_attr($per_page) . '" min="5" max="200" /> ';
    echo '<input type="submit" class="button" value="Filter" /> ';
    echo '<a class="button" href="?page=sut-visits">Reset</a>';
    echo '</form>';

    // export buttons
    $qs = [];
    if ($search_val) $qs['search'] = $search_val;
    if ($from_val) $qs['from'] = $from_val;
    if ($to_val) $qs['to'] = $to_val;
    if ($pagefilter) $qs['page_url'] = $pagefilter;
    if ($per_page) $qs['per_page'] = $per_page;
    $base_qs = http_build_query($qs);

    global $SUT_HAS_PHPSPREADSHEET;
    echo '<p>';
    echo '<a class="button" href="?page=sut-visits&' . $base_qs . '&sut_export=csv">Export CSV</a> ';
    if (!empty($SUT_HAS_PHPSPREADSHEET)) {
        echo '<a class="button" href="?page=sut-visits&' . $base_qs . '&sut_export=xlsx">Export XLSX</a>';
    }
    echo '</p>';

    // Top pages
    echo '<h2>Top Pages (by visits)</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Page</th><th>Total</th><th>Landings</th><th></th></tr></thead><tbody>';
    if ($stats) {
        foreach ($stats as $st) {
            $link_to_visits = '?page=sut-visits&' . http_build_query([
                'page_url' => $st->page_url,
                'per_page' => $per_page,
            ]);
            echo '<tr><td><a href="' . esc_url($st->page_url) . '" target="_blank">' . esc_html($st->page_url) . '</a></td><td>' . esc_html($st->total_visits) . '</td><td>' . esc_html($st->landing_visits) . '</td><td><a class="button" href="' . esc_url($link_to_visits) . '">View Visits</a></td></tr>';
        }
    } else {
        echo '<tr><td colspan="4">No data</td></tr>';
    }
    echo '</tbody></table><br/>';

    // Raw visits table
    echo '<h2>Raw Visits</h2>';
    echo '<table class="widefat striped"><thead><tr>
        <th>ID</th><th>User</th><th>Device</th><th>Session</th><th>IP</th><th>Country</th><th>Region</th><th>City</th><th>Page</th><th>Landing</th><th>Visited At (GMT)</th>
    </tr></thead><tbody>';
    if ($rows) {
        foreach ($rows as $row) {
            $user = $row->user_id ? esc_html(get_userdata($row->user_id)->user_login) : 'Guest';
            // $page_title = $row->page_id ? get_the_title($row->page_id) : $row->page_id get_term_by('id', $value, 'category')->name ?: $row->page_url;
            $page_title = sut_get_page_label($row);

            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . $user . '</td>';
            echo '<td>' . esc_html($row->device_id) . '</td>';
            echo '<td>' . esc_html($row->session_id) . '</td>';
            echo '<td>' . esc_html($row->ip) . '</td>';
            echo '<td>' . esc_html($row->country) . '</td>';
            echo '<td>' . esc_html($row->region) . '</td>';
            echo '<td>' . esc_html($row->city) . '</td>';
            // echo '<td><a href="' . esc_url($row->page_url) . '" target="_blank">' . esc_html($page_title) . '</a><br/><small>' . esc_html($row->page_url) . '</small></td>';
            echo '<td><a href="' . esc_url($row->page_url) . '" target="_blank">' . esc_html($page_title) . '</a><br/><small>' . esc_html($row->page_url) . '</small></td>';

            echo '<td>' . ($row->is_landing ? 'Yes' : 'No') . '</td>';
            echo '<td>' . esc_html($row->visited_at) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="11">No visits found for this filter.</td></tr>';
    }
    echo '</tbody></table>';

    // Pagination UI (compact)
    $total_pages = max(1, ceil($total / $per_page));
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        $base_link = '?page=sut-visits';
        if ($search_val) $base_link .= '&search=' . urlencode($search_val);
        if ($from_val) $base_link .= '&from=' . urlencode($from_val);
        if ($to_val) $base_link .= '&to=' . urlencode($to_val);
        if ($pagefilter) $base_link .= '&page_url=' . urlencode($pagefilter);
        if ($per_page) $base_link .= '&per_page=' . urlencode($per_page);

        if ($paged > 1) echo '<a class="button" href="' . esc_url($base_link . '&paged=' . ($paged - 1)) . '">&laquo; Prev</a> ';
        $start = max(1, $paged - 3);
        $end = min($total_pages, $paged + 3);
        for ($i = $start; $i <= $end; $i++) {
            $style = $i === $paged ? 'style="font-weight:bold;background:#f1f1f1;padding:4px 8px;border-radius:3px;"' : '';
            echo '<a ' . $style . ' href="' . esc_url($base_link . '&paged=' . $i) . '">' . $i . '</a> ';
        }
        if ($paged < $total_pages) echo '<a class="button" href="' . esc_url($base_link . '&paged=' . ($paged + 1)) . '">Next &raquo;</a>';
        echo '</div></div>';
    }

    echo '</div>'; // wrap
}

/* =======================
   CSV Export for filtered visits (streamed, chunked)
   ======================= */
function sut_export_csv_filtered($where, $params) {
    global $wpdb;
    $table = $wpdb->prefix . 'user_visits';

    // build ids list to chunk
    $ids_sql = "SELECT id FROM {$table} {$where} ORDER BY visited_at DESC";
    $ids_query = !empty($params) ? $wpdb->prepare($ids_sql, $params) : $ids_sql;
    $all_ids = $wpdb->get_col($ids_query);

    @set_time_limit(0);
    if (function_exists('ob_get_level')) {
        while (ob_get_level()) ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_visits.csv"');

    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF"; // BOM
    fputcsv($out, ['ID','User','Device','Session','IP','Country','Region','City','Page','IsLanding','Visited At']);

    if (!empty($all_ids)) {
        $chunk_size = 5000; // larger chunks, but still memory-safe
        $chunks = array_chunk($all_ids, $chunk_size);
        foreach ($chunks as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE id IN ({$in}) ORDER BY visited_at DESC", ARRAY_A);
            foreach ($rows as $r) {
                // Sanitize cells that could be interpreted as formulas by Excel
                $sanitize = function($v) {
                    $v = (string)$v;
                    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'])) {
                        return "'" . $v;
                    }
                    return $v;
                };
                fputcsv($out, [
                    (int)$r['id'],
                    $sanitize($r['user_id'] ?: 'Guest'),
                    $sanitize($r['device_id']),
                    $sanitize($r['session_id']),
                    $sanitize($r['ip']),
                    $sanitize($r['country']),
                    $sanitize($r['region']),
                    $sanitize($r['city']),
                    $sanitize($r['page_url']),
                    (int)$r['is_landing'],
                    $sanitize($r['visited_at']),
                ]);
            }
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            flush();
        }
    }
    fclose($out);
    exit;
}

/* =======================
   Pages Stats Page
   - shows pages with total visits in selected date range
   - for a selected page_url shows daily breakdown
   ======================= */
function sut_page_pages_stats() {
    global $wpdb;
    $table = $wpdb->prefix . 'user_visits';
    $daily  = $wpdb->prefix . 'sut_page_daily';

    // date range inputs
    $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $to = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';

    // selected page (page_url)
    $selected_page = isset($_GET['page_url']) ? sanitize_text_field(wp_unslash($_GET['page_url'])) : '';

    // build where for date range
    $where_date = "WHERE 1=1";
    $params = [];
    if ($from) { $where_date .= " AND visited_at >= %s"; $params[] = $from . ' 00:00:00'; }
    if ($to)   { $where_date .= " AND visited_at <= %s"; $params[] = $to . ' 23:59:59'; }

    // Prefer pre-aggregated daily table when full days selected
    $use_daily = $from && $to;
    if ($use_daily) {
        $pages_sql = $wpdb->prepare(
            "SELECT page_url, SUM(visits) as total_visits, SUM(landings) as landing_visits
             FROM {$daily}
             WHERE day BETWEEN %s AND %s
             GROUP BY page_url
             ORDER BY total_visits DESC LIMIT 200",
            $from, $to
        );
        $pages = $wpdb->get_results($pages_sql);
    } else {
        if (!empty($params)) {
            $pages_sql = $wpdb->prepare("SELECT page_url, COUNT(*) as total_visits, SUM(is_landing=1) as landing_visits FROM {$table} {$where_date} GROUP BY page_url ORDER BY total_visits DESC LIMIT 200", $params);
            $pages = $wpdb->get_results($pages_sql);
        } else {
            $pages = $wpdb->get_results("SELECT page_url, COUNT(*) as total_visits, SUM(is_landing=1) as landing_visits FROM {$table} {$where_date} GROUP BY page_url ORDER BY total_visits DESC LIMIT 200");
        }
    }

    echo '<div class="wrap"><h1>Pages Stats</h1>';
    echo '<form method="get" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="sut-pages-stats" />';
    echo 'From: <input type="date" name="from" value="' . esc_attr($from) . '" /> ';
    echo 'To: <input type="date" name="to" value="' . esc_attr($to) . '" /> ';
    echo 'Page filter (URL contains): <input style="width:300px;" type="text" name="page_url" value="' . esc_attr($selected_page) . '" /> ';
    echo '<input class="button" type="submit" value="Apply" /> ';
    echo '<a class="button" href="?page=sut-pages-stats">Reset</a>';
    echo '</form>';

    // Export buttons (CSV/XLSX) for pages stats
    $qs = [];
    if ($from) $qs['from'] = $from;
    if ($to) $qs['to'] = $to;
    if ($selected_page) $qs['page_url'] = $selected_page;
    $base_qs = http_build_query($qs);
    global $SUT_HAS_PHPSPREADSHEET;
    echo '<p>';
    echo '<a class="button" href="?page=sut-pages-stats&' . $base_qs . '&sut_export=csv">Export CSV</a> ';
    if (!empty($SUT_HAS_PHPSPREADSHEET)) {
        echo '<a class="button" href="?page=sut-pages-stats&' . $base_qs . '&sut_export=xlsx">Export XLSX</a>';
    }
    echo '</p>';

    // show top pages table
    echo '<h2>Pages (Top 200 by visits in range)</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Page URL</th><th>Total Visits</th><th>Landing Visits</th><th></th></tr></thead><tbody>';
    if ($pages) {
        foreach ($pages as $p) {
            // if page filter present, skip others (we already applied range only; for contains filter, apply below)
            if ($selected_page && stripos($p->page_url, $selected_page) === false) continue;
            $link_to_visits = '?page=sut-visits&' . http_build_query([
                'page_url' => $p->page_url,
            ]);
            echo '<tr><td><a href="' . esc_url($p->page_url) . '" target="_blank">' . esc_html($p->page_url) . '</a></td><td>' . esc_html($p->total_visits) . '</td><td>' . esc_html($p->landing_visits) . '</td><td><a class="button" href="' . esc_url($link_to_visits) . '">View Visits</a></td></tr>';
        }
    } else {
        echo '<tr><td colspan="4">No pages in selected range.</td></tr>';
    }
    echo '</tbody></table><br/>';

    // If a specific page selected, show daily breakdown (use visits table for finer filtering)
    if (!empty($selected_page)) {
        // decide params for daily aggregation
        $daily_where = "WHERE page_url LIKE %s";
        $daily_params = ['%' . $wpdb->esc_like($selected_page) . '%'];
        if ($from) { $daily_where .= " AND visited_at >= %s"; $daily_params[] = $from . ' 00:00:00'; }
        if ($to)   { $daily_where .= " AND visited_at <= %s"; $daily_params[] = $to . ' 23:59:59'; }

        $daily_sql = "SELECT DATE(visited_at) as day, COUNT(*) as visits, SUM(is_landing=1) as landings
                      FROM {$table} {$daily_where}
                      GROUP BY DATE(visited_at)
                      ORDER BY DATE(visited_at) DESC";

        $daily_query = call_user_func_array([$wpdb, 'prepare'], array_merge([$daily_sql], $daily_params));
        $daily = $wpdb->get_results($daily_query);

        echo '<h2>Daily Breakdown for: <code>' . esc_html($selected_page) . '</code></h2>';
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Visits</th><th>Landings</th></tr></thead><tbody>';
        if ($daily) {
            foreach ($daily as $d) {
                echo '<tr><td>' . esc_html($d->day) . '</td><td>' . esc_html($d->visits) . '</td><td>' . esc_html($d->landings) . '</td></tr>';
            }
        } else {
            echo '<tr><td colspan="3">No data for this page in the selected range.</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

/* =======================
   Troubleshoot page: logs + test insertion
   ======================= */
function sut_page_troubleshoot() {
    global $wpdb;
    $table = $wpdb->prefix . 'sut_logs';

    echo '<div class="wrap"><h1>Troubleshoot</h1>';
    echo '<p><strong>Last daily aggregate:</strong> ' . esc_html(get_option('sut_last_aggregate_day', 'N/A')) . '</p>';

    if (isset($_POST['sut_clear_logs']) && current_user_can('manage_options') && check_admin_referer('sut_clear_logs')) {
        $wpdb->query("TRUNCATE TABLE {$table}");
        echo '<div class="updated"><p>Logs cleared.</p></div>';
    }

    // Test insert button - insert a fake visit row to verify tracking DB write (this is optional)
    if (isset($_POST['sut_test_visit']) && current_user_can('manage_options') && check_admin_referer('sut_test_visit')) {
        $visits = $wpdb->prefix . 'user_visits';
        $wpdb->insert($visits, [
            'user_id' => null,
            'device_id' => 'TEST-DEVICE',
            'session_id' => 'TEST-SESSION',
            'ip' => '127.0.0.1',
            'country' => 'Testland',
            'region' => 'Test Region',
            'city' => 'Test City',
            'page_id' => null,
            'page_url' => home_url('/?test=1'),
            'is_landing' => 1,
            'visited_at' => current_time('mysql', 1),
        ]);
        if ($wpdb->last_error) {
            sut_log('Test visit insert failed: ' . $wpdb->last_error);
            echo '<div class="error"><p>Test visit insert failed. Check logs.</p></div>';
        } else {
            echo '<div class="updated"><p>Test visit inserted.</p></div>';
        }
    }

    echo '<form method="post" style="margin-bottom:12px;">';
    wp_nonce_field('sut_test_visit');
    echo '<button class="button" name="sut_test_visit" type="submit">Insert Test Visit</button> ';
    echo '</form>';

    echo '<form method="post" style="margin-bottom:12px;">';
    wp_nonce_field('sut_clear_logs');
    echo '<button class="button" name="sut_clear_logs" type="submit">Clear Logs</button>';
    echo '</form>';

    $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200");

    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Message</th><th>Time (GMT)</th></tr></thead><tbody>';
    if ($logs) {
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->id) . '</td><td>' . esc_html($log->message) . '</td><td>' . esc_html($log->created_at) . '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="3">No logs.</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

/* =======================
   Retention Page: purge old data
   ======================= */
function sut_page_retention() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $visits = $wpdb->prefix . 'user_visits';
    $logs   = $wpdb->prefix . 'sut_logs';
    $daily  = $wpdb->prefix . 'sut_page_daily';

    echo '<div class="wrap"><h1>Data Retention</h1>';
    if (isset($_POST['sut_purge']) && check_admin_referer('sut_purge')) {
        $days = isset($_POST['days']) ? max(1, intval($_POST['days'])) : 90;
        $cut = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $deleted1 = $wpdb->query($wpdb->prepare("DELETE FROM {$visits} WHERE visited_at < %s", $cut));
        $deleted2 = $wpdb->query($wpdb->prepare("DELETE FROM {$logs} WHERE created_at < %s", $cut));
        $deleted3 = $wpdb->query($wpdb->prepare("DELETE FROM {$daily} WHERE day < %s", gmdate('Y-m-d', strtotime($cut))));
        echo '<div class="updated"><p>Purged visits: ' . intval($deleted1) . ', logs: ' . intval($deleted2) . ', daily: ' . intval($deleted3) . '.</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('sut_purge');
    echo 'Delete records older than <input type="number" name="days" value="90" min="1" max="3650" style="width:80px;" /> days ';
    echo '<button class="button button-primary" name="sut_purge" type="submit">Purge</button>';
    echo '</form>';
    echo '</div>';
}

/* =======================
   Excel Export (XLSX) with memory optimizations
   ======================= */
if (!function_exists('sut_export_xlsx_filtered')) {
    function sut_export_xlsx_filtered($where, $params) {
        global $wpdb, $SUT_HAS_PHPSPREADSHEET;
        if (empty($SUT_HAS_PHPSPREADSHEET)) {
            wp_die('XLSX export requires PhpSpreadsheet.');
        }

        // Lazy-load classes to avoid autoload overhead when not used
        $Spreadsheet = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
        $WriterXlsx  = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
        $Settings    = '\\PhpOffice\\PhpSpreadsheet\\Settings';
        $MemoryCache = '\\PhpOffice\\PhpSpreadsheet\\Collection\\Memory\\SimpleCache3';

        // Prefer memory-efficient cache
        if (class_exists($Settings) && class_exists($MemoryCache)) {
            $Settings::setCache(new $MemoryCache());
        }

        $table = $wpdb->prefix . 'user_visits';

        // Collect IDs first (so we can stream in chunks)
        $ids_sql = "SELECT id FROM {$table} {$where} ORDER BY visited_at DESC";
        $ids_query = !empty($params) ? $wpdb->prepare($ids_sql, $params) : $ids_sql;
        $all_ids = $wpdb->get_col($ids_query);

        // Prepare spreadsheet
        $spreadsheet = new $Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('User Visits');
        $headers = ['ID','User','Device','Session','IP','Country','Region','City','Page','IsLanding','Visited At'];
        $colIndex = 1; // 1-based
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($colIndex++, 1, $h);
        }

        $rowIndex = 2;
        if (!empty($all_ids)) {
            $chunk_size = 5000;
            $chunks = array_chunk($all_ids, $chunk_size);
            foreach ($chunks as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE id IN ({$in}) ORDER BY visited_at DESC", ARRAY_A);
                foreach ($rows as $r) {
                    $colIndex = 1;
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, (int)$r['id']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['user_id'] ?: 'Guest');
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['device_id']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['session_id']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['ip']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['country']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['region']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['city']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['page_url']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, (int)$r['is_landing']);
                    $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r['visited_at']);
                    $rowIndex++;
                }
                if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            }
        }

        // Stream to browser
        @set_time_limit(0);
        if (function_exists('ob_get_level')) {
            while (ob_get_level()) ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="user_visits.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new $WriterXlsx($spreadsheet);
        // Write directly to output stream to avoid buffering
        $writer->save('php://output');
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        exit;
    }
}

/* =======================
   REST: Receive precise geo (lat,lng) once per session
   ======================= */
add_action('rest_api_init', function() {
    register_rest_route('sut/v1', '/geo', [
        'methods'  => 'POST',
        'permission_callback' => function($req){ return wp_verify_nonce($req->get_header('X-WP-Nonce'), 'wp_rest'); },
        'callback' => function(WP_REST_Request $req) {
            global $wpdb;
            $table = $wpdb->prefix . 'user_visits';
            $lat = floatval($req->get_param('lat'));
            $lng = floatval($req->get_param('lng'));
            $sid = isset($_COOKIE['sut_session_id']) ? sanitize_text_field($_COOKIE['sut_session_id']) : '';
            if (!$sid) return new WP_REST_Response(['ok' => false], 200);
            // Update the most recent visit for this session with geo
            $row_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE session_id=%s ORDER BY visited_at DESC LIMIT 1", $sid));
            if ($row_id) {
                $wpdb->update($table, [
                    'geo_lat' => $lat,
                    'geo_lng' => $lng,
                ], ['id' => $row_id], ['%f','%f'], ['%d']);
            }
            return new WP_REST_Response(['ok' => true], 200);
        }
    ]);
});

/* End of plugin file */
