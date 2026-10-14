<?php

/**
 * Tourfecto - Edge Rules
 * القواعد اللي كود Tourfecto Edge (على موقع WordPress بتاع العميل) بيسحبها وينفّذها.
 *
 * مبدأ الأمان الأساسي: السيرفر بيبعت **بيانات** مش كود. كل حقل له مدقّق صارم هنا، والـ snippet بيراجع تاني قبل ما ينفّذ.
 * يعني حتى لو حد اخترق Tourfecto ما يقدرش يشغّل PHP أو JavaScript على مواقع العملاء، أقصى حاجة يغيّر عنوان أو وصف.
 *
 * التوكن: "{websiteId}.{توقيع HMAC}" مش متخزّن في الداتابيزاو وبيتحقق منه توقيع.
 *
 * @version 1.0.0
 */
class EdgeRules
{
    private const FIELDS = ['title', 'description', 'canonical', 'robots', 'viewport', 'lang', 'og', 'jsonld', 'hsts', 'llms_txt'];

    // ------------------------------------------------------------------ التوكن

    private static function secret(): string
    {
        $k = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-edge-secret';
        return hash('sha256', 'tourfecto-edge-v1|' . $k, true);
    }

    public static function token(int $websiteId): string
    {
        return $websiteId . '.' . substr(hash_hmac('sha256', 'edge|' . $websiteId, self::secret()), 0, 32);
    }

    public static function parseToken(string $token): ?int
    {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{32})$/', $token, $m)) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', 'edge|' . $m[1], self::secret()), 0, 32);
        return hash_equals($expected, $m[2]) ? (int) $m[1] : null;
    }

    // ------------------------------------------------------------------ المدققات

    /**
     * @param string $field   واحد من FIELDS
     * @param string $raw     القيمة كما اتخزّنت
     * @param string $siteHost دومين الموقع (للـ canonical والروابط)
     * @return string|array|null  القيمة النظيفة أو null لو مرفوضة
     */
    public static function sanitize(string $field, string $raw, string $siteHost = '')
    {
        $raw = trim($raw);
        switch ($field) {
            case 'title':
                $v = self::line($raw);
                $n = mb_strlen($v);
                return ($n >= 10 && $n <= 120) ? $v : null;
            case 'description':
                $v = self::line($raw);
                $n = mb_strlen($v);
                return ($n >= 20 && $n <= 320) ? $v : null;
            case 'canonical':
                if (!preg_match('#^https://[^\s<>"\']+$#i', $raw)) {
                    return null;
                }
                $h = strtolower((string) parse_url($raw, PHP_URL_HOST));
                return ($siteHost !== '' && preg_replace('/^www\./', '', $h) === preg_replace('/^www\./', '', strtolower($siteHost))) ? $raw : null;
            case 'robots':
                return preg_match('/^(index|noindex)(, ?(follow|nofollow))?$/i', $raw) ? strtolower(str_replace(' ', '', $raw)) : null;
            case 'viewport':
                return preg_match('/^width=device-width, ?initial-scale=1(\.0)?$/i', $raw) ? 'width=device-width, initial-scale=1' : null;
            case 'lang':
                return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $raw) ? $raw : null;
            case 'hsts':
                return preg_match('/^max-age=\d{1,10}(; ?includeSubDomains)?(; ?preload)?$/', $raw) ? $raw : null;
            case 'og':
                $j = json_decode($raw, true);
                if (!is_array($j)) {
                    return null;
                }
                $out = [];
                foreach ($j as $k => $v) {
                    if (!is_string($k) || !is_string($v) || !preg_match('/^(og|twitter):[a-z_:]{2,30}$/', $k)) {
                        continue;
                    }
                    $v = self::line($v);
                    if ($v === '' || mb_strlen($v) > 500) {
                        continue;
                    }
                    if (in_array($k, ['og:image', 'og:url'], true) && !preg_match('#^https://[^\s<>"\']+$#i', $v)) {
                        continue;
                    }
                    $out[$k] = $v;
                }
                return count($out) >= 2 ? $out : null;
            case 'jsonld':
                $j = json_decode($raw, true);
                if (!is_array($j) || stripos((string) ($j['@context'] ?? ''), 'schema.org') === false
                    || !in_array($j['@type'] ?? '', ['Organization', 'LocalBusiness', 'TravelAgency', 'WebSite', 'Person'], true)) {
                    return null;
                }
                return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            case 'llms_txt':
                return (strlen($raw) >= 20 && strlen($raw) <= 20000 && strpos($raw, '<') === false) ? $raw : null;
        }
        return null;
    }

    private static function line(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    }

    // ------------------------------------------------------------------ payload

    /**
     * أحدث قاعدة فعّالة لكل حقل. @return array<string, string|array>
     */
    public static function rulesFor($db, int $websiteId, string $siteHost): array
    {
        $rows = $db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes WHERE website_id = ? AND is_active = 1 ORDER BY id ASC",
            [$websiteId]
        );
        $reverse = array_flip(FixCapability::DB_FIELD);
        $rules = [];
        foreach ($rows as $r) {
            $field = $reverse[$r['field_name']] ?? null;
            if ($field === null) {
                continue;
            }
            $clean = self::sanitize($field, (string) $r['injected_code'], $siteHost);
            if ($clean !== null) {
                $rules[$field] = $clean;      // الأحدث بيكسب (ORDER BY id ASC)
            } else {
                unset($rules[$field]);
            }
        }
        $exists=$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_expert_tasks'");
        if($exists){
            $pageRows=$db->query("SELECT page_path,field_name,expected_value FROM seo_expert_tasks WHERE website_id=? AND kind='metadata' AND is_active=1 AND status IN ('active','verified','needs_client') ORDER BY id",[$websiteId]);
            $pageRules=[];
            foreach($pageRows as $r){$path=(string)$r['page_path'];$field=(string)$r['field_name'];if(!in_array($field,['title','description','canonical','viewport','lang','jsonld'],true)||!str_starts_with($path,'/')||str_contains($path,'?')||str_contains($path,chr(92))||preg_match('/[\x00-\x20]/',$path))continue;$clean=self::sanitize($field,(string)$r['expected_value'],$siteHost);if($clean!==null)$pageRules[$path][$field]=$clean;}
            if($pageRules)$rules['page_rules']=$pageRules;
        }
        ksort($rules);
        return $rules;
    }

    public static function version(array $rules): string
    {
        return substr(md5(json_encode($rules, JSON_UNESCAPED_UNICODE)), 0, 12);
    }

    // ------------------------------------------------------------------ كود WordPress

    /**
     * كود PHP جاهز يتلصق في Code Snippets / WPCode (من غير <?php).
     * بيسحب القواعد كل 10 دقايق (cache) بمهلة 3 ثواني، وبيفشل بصمت (لو Tourfecto وقع الموقع بيشتغل عادي).
     */
    public static function snippet(int $websiteId, string $endpoint): string
    {
        $token = self::token($websiteId);
        $endpoint = rtrim($endpoint, '/') . '/api/edge/rules';
        return <<<PHP
/* Tourfecto Edge v4 - Page-scoped approved SEO updates. Data only; no remote code execution. */
if ( defined( 'TF_EDGE_RUNTIME_V4' ) ) { return; }
if ( function_exists( 'tf_edge_rules' ) ) { add_action( 'admin_notices', function () { if(current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>Tourfecto: disable the old Tourfecto snippet before using connector v4.</p></div>'; } ); return; }
define( 'TF_EDGE_RUNTIME_V4', true );
if ( ! defined( 'TF_EDGE_RUNTIME_V3' ) ) { define( 'TF_EDGE_RUNTIME_V3', true ); }
if ( ! function_exists( 'tf_edge_rules' ) ) {
    function tf_edge_rules() {
        \$c = get_transient( 'tf_edge_rules_v4' );
        if ( is_array( \$c ) ) { return \$c; }
        \$last = get_option( 'tf_edge_last_rules_v4', array() );
        if ( get_transient( 'tf_edge_retry_v4' ) ) { return is_array(\$last) ? \$last : array(); }
        if ( is_array(\$last) && count(\$last)>0 && !is_admin() && !(defined('DOING_CRON') && DOING_CRON) && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ) {
            if(!wp_next_scheduled('tf_edge_refresh_v4'))wp_schedule_single_event(time()+1,'tf_edge_refresh_v4');
            set_transient('tf_edge_rules_v4',\$last,30);return \$last;
        }
        \$rules = array(); \$valid = false;
        \$r = wp_remote_get( '{$endpoint}?token={$token}&cv=4&site=' . rawurlencode( home_url() ), array( 'timeout' => 2, 'redirection' => 0, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
        if ( ! is_wp_error( \$r ) && 200 === wp_remote_retrieve_response_code( \$r ) ) {
            \$d = json_decode( wp_remote_retrieve_body( \$r ), true );
            if ( is_array( \$d ) && isset( \$d['data']['rules'] ) && is_array( \$d['data']['rules'] ) ) {
                \$valid = true; delete_option( 'tf_edge_last_error' ); delete_transient( 'tf_edge_retry_v4' );
                \$rules = \$d['data']['rules'];
                \$v = isset( \$d['data']['version'] ) ? (string) \$d['data']['version'] : '';
                if ( \$v !== get_option( 'tf_edge_version' ) ) {
                    update_option( 'tf_edge_version', \$v, false );
                    do_action( 'litespeed_purge_all' );
                    if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
                }
            }
        }
        if ( ! \$valid ) { set_transient( 'tf_edge_retry_v4', true, 15 ); update_option( 'tf_edge_last_error', is_wp_error( \$r ) ? 'Network request failed. Check outbound HTTPS access.' : 'Connection failed. HTTP ' . wp_remote_retrieve_response_code( \$r ) . '. Check the site connection in Tourfecto.', false ); }
        if ( \$valid ) { set_transient( 'tf_edge_rules_v4', \$rules, 120 ); update_option('tf_edge_last_rules_v4',\$rules,false); }
        return \$valid ? \$rules : (is_array(\$last) ? \$last : array());
    }
    /* Homepage SEO values apply only to the audited homepage, never every page. */
    function tf_edge_is_home() {
        return function_exists( 'is_front_page' ) && is_front_page() && ! is_paged();
    }
    function tf_edge_current_path() {
        \$path = isset( \$_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( \$_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/';
        return rtrim( (string) \$path, '/' ) ?: '/';
    }
    function tf_edge_page_rules() {
        \$all = tf_edge_rules(); \$page = array();
        if ( tf_edge_is_home() ) { foreach (array('title','description','canonical','robots','viewport','lang','og','jsonld') as \$key) { if(isset(\$all[\$key]))\$page[\$key]=\$all[\$key]; } }
        \$path = tf_edge_current_path();
        if ( isset(\$all['page_rules'][\$path]) && is_array(\$all['page_rules'][\$path]) ) {
            foreach(array('title','description','canonical','viewport','lang','jsonld') as \$key)if(isset(\$all['page_rules'][\$path][\$key]))\$page[\$key]=\$all['page_rules'][\$path][\$key];
        }
        return \$page;
    }
    function tf_edge_has_page_rules() { return tf_edge_is_home() || count(tf_edge_page_rules())>0; }
    function tf_edge_seo_plugin() {
        return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
    }
}
add_action('tf_edge_refresh_v4',function(){delete_transient('tf_edge_rules_v4');tf_edge_rules();});
add_action( 'admin_init', function () { tf_edge_rules(); } );
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    \$error = get_option( 'tf_edge_last_error', '' );
    if ( \$error ) { echo '<div class="notice notice-warning"><p>Tourfecto: ' . esc_html( \$error ) . '</p></div>'; }
} );
add_action( 'send_headers', function () {
    \$r = tf_edge_rules();
    if ( ! empty( \$r['hsts'] ) && preg_match( '/^max-age=\\d{1,10}(; ?includeSubDomains)?(; ?preload)?\$/', \$r['hsts'] ) && is_ssl() ) {
        header( 'Strict-Transport-Security: ' . \$r['hsts'] );
    }
} );
add_filter( 'pre_get_document_title', function ( \$t ) {
    \$r = tf_edge_page_rules();
    return ( tf_edge_has_page_rules() && ! empty( \$r['title'] ) ) ? \$r['title'] : \$t;
}, 99 );
add_filter( 'language_attributes', function ( \$out ) {
    \$r = tf_edge_page_rules();
    if ( ! empty( \$r['lang'] ) && preg_match( '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\$/', \$r['lang'] ) ) {
        \$out = preg_replace( '/\\blang=["\\x27][^"\\x27]*["\\x27]/i', '', \$out );
        \$out .= ' lang="' . esc_attr( \$r['lang'] ) . '"';
    }
    return \$out;
} );

/* Replace only explicitly approved homepage values and retain all other head markup. */
if ( ! function_exists( 'tf_edge_normalize_head' ) ) {
function tf_edge_normalize_head( \$head, \$rules ) {
    if ( ! tf_edge_has_page_rules() || empty( \$rules ) ) { return \$head; }

    \$protected = array();
    if ( ! empty( \$rules['jsonld'] ) ) { \$head = preg_replace( '/<script\\b[^>]*\\bid=["\\x27]tourfecto-schema["\\x27][^>]*>.*?<\\/script>/is', '', \$head ); }
    \$head = preg_replace_callback( '/<(script|style)\\b[^>]*>.*?<\\/\\1>/is', function ( \$match ) use ( &\$protected ) {
        \$key = '<!--tf-protected-' . count( \$protected ) . '-->'; \$protected[\$key] = \$match[0]; return \$key;
    }, \$head );
    \$extra = '';
    if ( ! empty( \$rules['title'] ) ) {
        \$head = preg_replace( '/<title\\b[^>]*>.*?<\\/title>/is', '', \$head );
        \$extra .= '<title>' . esc_html( \$rules['title'] ) . '</title>' . "\\n";
    }
    foreach ( array( 'description', 'robots', 'viewport' ) as \$field ) {
        if ( empty( \$rules[\$field] ) ) { continue; }
        \$head = preg_replace_callback( '/<meta\\b[^>]*>/i', function ( \$match ) use ( \$field ) {
            return preg_match( '/\\bname\\s*=\\s*(["\\x27])' . preg_quote( \$field, '/' ) . '\\1/i', \$match[0] ) ? '' : \$match[0];
        }, \$head );
        \$extra .= '<meta name="' . esc_attr( \$field ) . '" content="' . esc_attr( \$rules[\$field] ) . '">' . "\\n";
    }
    if ( ! empty( \$rules['canonical'] ) ) {
        \$head = preg_replace_callback( '/<link\\b[^>]*>/i', function ( \$match ) {
            return preg_match( '/\\brel\\s*=\\s*(["\\x27])canonical\\1/i', \$match[0] ) ? '' : \$match[0];
        }, \$head );
        \$extra .= '<link rel="canonical" href="' . esc_url( \$rules['canonical'] ) . '">' . "\\n";
    }
    if ( ! empty( \$rules['og'] ) && is_array( \$rules['og'] ) ) {
        foreach ( \$rules['og'] as \$key => \$value ) {
            \$head = preg_replace_callback( '/<meta\\b[^>]*>/i', function ( \$match ) use ( \$key ) {
                return preg_match( '/\\b(?:name|property)\\s*=\\s*(["\\x27])' . preg_quote( \$key, '/' ) . '\\1/i', \$match[0] ) ? '' : \$match[0];
            }, \$head );
            \$extra .= '<meta ' . ( strpos( \$key, 'twitter:' ) === 0 ? 'name' : 'property' ) . '="' . esc_attr( \$key ) . '" content="' . esc_attr( \$value ) . '">' . "\\n";
        }
    }
    if ( ! empty( \$rules['jsonld'] ) ) {
        // Keep other schemas. Replace only this connector's own schema block.
        \$head = preg_replace( '/<script\\b[^>]*\\bid=["\\x27]tourfecto-schema["\\x27][^>]*>.*?<\\/script>/is', '', \$head );
        \$extra .= '<script id="tourfecto-schema" type="application/ld+json">' . \$rules['jsonld'] . '</script>' . "\\n";
    }
    return strtr( \$head, \$protected ) . \$extra;
}
}
add_action( 'wp_head', function () { echo '<meta name="tourfecto-connector" content="4">'; }, -100000 );
add_action( 'wp_head', function () {
    if ( tf_edge_has_page_rules() ) {
        \$rules = tf_edge_page_rules();
        ob_start( function ( \$head ) use ( \$rules ) { return tf_edge_normalize_head( \$head, \$rules ); } );
        \$GLOBALS['tf_edge_head_level'] = ob_get_level();
    }
}, -99999 );
add_action( 'wp_head', function () {
    if ( isset( \$GLOBALS['tf_edge_head_level'] ) && ob_get_level() === \$GLOBALS['tf_edge_head_level'] ) { ob_end_flush(); unset( \$GLOBALS['tf_edge_head_level'] ); }
}, PHP_INT_MAX );
add_action( 'init', function () {
    \$path = isset( \$_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( \$_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    if ( '/llms.txt' !== \$path ) { return; }
    \$r = tf_edge_rules();
    if ( ! empty( \$r['llms_txt'] ) ) {
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo \$r['llms_txt'];
        exit;
    }
} );
PHP;
    }

    /**
     * نفس الكود بس كإضافة WordPress كاملة (تترفع من Plugins ← Add New ← Upload)
     * بدل ما العميل يحتاج يسطّب Code Snippets ويلصق كود.
     * @return array{php:string, readme:string}
     */
    public static function pluginFiles(int $websiteId, string $endpoint, string $siteLabel = ''): array
    {
        $label = preg_replace('/[^A-Za-z0-9 .\-]/', '', $siteLabel);
        $php = "<?php\n"
            . "/**\n"
            . " * Plugin Name: Tourfecto SEO\n"
            . " * Plugin URI: https://tourfecto.pro\n"
            . " * Description: Applies the SEO fixes you approve in Tourfecto (title, description, schema, Open Graph, llms.txt...) server-side. Data only - no remote code is executed." . ($label !== '' ? ' Site: ' . $label . '.' : '') . "\n"
            . " * Version: 2.0.0\n"
            . " * Requires at least: 5.6\n"
            . " * Requires PHP: 7.2\n"
            . " * Author: Tourfecto\n"
            . " * License: GPLv2 or later\n"
            . " */\n"
            . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
            . self::snippet($websiteId, $endpoint) . "\n\n"
            . "register_deactivation_hook( __FILE__, function () { delete_transient( 'tf_edge_rules_v4' ); delete_option( 'tf_edge_last_rules_v4' ); delete_option( 'tf_edge_version' ); wp_clear_scheduled_hook('tf_edge_refresh_v4'); } );\n"
            . "add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( \$links ) {\n"
            . "    \$links[] = '<a href=\"' . esc_url( admin_url( 'index.php?tf_edge_refresh=1' ) ) . '\">Refresh now</a>';\n"
            . "    return \$links;\n"
            . "} );\n"
            . "add_action( 'admin_init', function () {\n"
            . "    if ( isset( \$_GET['tf_edge_refresh'] ) && current_user_can( 'manage_options' ) ) { delete_transient( 'tf_edge_rules_v4' ); tf_edge_rules(); }\n"
            . "} );\n";
        $readme = "=== Tourfecto SEO ===\nContributors: tourfecto\nRequires at least: 5.6\nStable tag: 2.0.0\nLicense: GPLv2 or later\n\n"
            . "Applies the SEO fixes you approve in your Tourfecto dashboard, server-side.\n\n"
            . "== Description ==\nThe plugin fetches validated data (never code) from Tourfecto every two minutes and applies it. If Tourfecto is unreachable the site keeps its last approved rule data; the platform still verifies the current public response. Refreshes use WP-Cron when available, with a two-second bounded fallback. "
            . "Homepage legacy rules and exact-path SEO Expert rules replace only explicitly approved metadata. Other pages and unrelated markup are preserved. Disable old Tourfecto snippets before activation. Check the public-site verification report after applying changes.\n\n"
            . "== Installation ==\n1. Plugins > Add New > Upload Plugin, choose this zip.\n2. Activate.\n3. Back in Tourfecto press \"Check connection\".\n";
        return ['php' => $php, 'readme' => $readme];
    }
}
