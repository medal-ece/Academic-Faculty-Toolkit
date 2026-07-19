<?php
/*
Plugin Name: Academic Faculty Toolkit
Description: Faculty website tools for academic WordPress sites, including a database-backed people directory shortcode [student_list].
Version: 4.0.4
Author: Soroosh Noorzad
*/

if (!defined('ACADEMIC_DIRECTORY_VERSION')) {
    define('ACADEMIC_DIRECTORY_VERSION', '4.0.4');
}

if (!defined('ACADEMIC_DIRECTORY_DEFAULT_UPDATE_JSON')) {
    define('ACADEMIC_DIRECTORY_DEFAULT_UPDATE_JSON', 'https://raw.githubusercontent.com/medal-ece/Academic-Faculty-Toolkit/main/update-manifest.json');
}

add_action('wp_enqueue_scripts', function() {
    if (get_query_var('academic_profile_edit')) {
        wp_enqueue_editor();
    }

    $needs_directory_assets = (
        get_query_var('academic_profile_edit') ||
        AcademicDirectory::is_virtual_profile_request() ||
        AcademicDirectory::is_directory_shortcode_page()
    );

    if ($needs_directory_assets) {
        wp_enqueue_style(
            'academic-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css',
            array(),
            '6.5.2'
        );
    }

    wp_enqueue_style(
            'student-dir-styles',
            plugins_url('assets/css/student-directory.css', __FILE__),
            $needs_directory_assets ? array('academic-fontawesome') : array(),
            ACADEMIC_DIRECTORY_VERSION
        );

    wp_register_script('student-dir-email-protection', '', array(), '1.0', true);
    wp_enqueue_script('student-dir-email-protection');
    wp_add_inline_script(
        'student-dir-email-protection',
        "document.addEventListener('click',function(event){var link=event.target.closest('a.academic-email-link[data-academic-email]');if(!link){return;}var encoded=link.getAttribute('data-academic-email');if(!encoded){return;}event.preventDefault();var email=encoded.replace(/[a-zA-Z]/g,function(c){return String.fromCharCode((c<='Z'?90:122)>=(c=c.charCodeAt(0)+13)?c:c-26);});window.location.href='mailto:'+email;});"
    );
    wp_add_inline_script(
        'student-dir-email-protection',
        "document.addEventListener('DOMContentLoaded',function(){var filters=document.querySelector('[data-member-filters]');if(!filters){return;}var search=filters.querySelector('[data-member-search-input]');var status=filters.querySelector('[data-member-status-filter]');var empty=document.querySelector('[data-member-filter-empty]');function norm(value){return (value||'').toString().toLowerCase().trim();}function apply(){var query=norm(search?search.value:'');var group=status?status.value:'';var cards=Array.prototype.slice.call(document.querySelectorAll('[data-member-card]'));var visibleCount=0;cards.forEach(function(card){var matchesQuery=!query||norm(card.getAttribute('data-member-search')).indexOf(query)!==-1;var matchesGroup=!group||card.getAttribute('data-member-status')===group;var visible=matchesQuery&&matchesGroup;card.hidden=!visible;if(visible){visibleCount++;}});Array.prototype.slice.call(document.querySelectorAll('[data-member-category-section]')).forEach(function(section){section.hidden=!section.querySelector('[data-member-card]:not([hidden])');});Array.prototype.slice.call(document.querySelectorAll('[data-member-section]')).forEach(function(section){section.hidden=!section.querySelector('[data-member-card]:not([hidden])');});if(empty){empty.hidden=visibleCount>0;}}if(search){search.addEventListener('input',apply);}if(status){status.addEventListener('change',apply);}apply();});"
    );
});

register_activation_hook(__FILE__, array('AcademicDirectory', 'activate'));
register_deactivation_hook(__FILE__, array('AcademicDirectory', 'deactivate'));

add_action('init', array('AcademicDirectory', 'register_routes'));
add_action('init', array('AcademicDirectory', 'maybe_upgrade_database'), 5);
add_action('init', array('AcademicDirectory', 'maybe_flush_routes'), 20);
add_action('pre_get_posts', array('AcademicDirectory', 'prepare_student_profile_query'));
add_filter('query_vars', array('AcademicDirectory', 'register_query_vars'));
add_filter('the_posts', array('AcademicDirectory', 'inject_student_profile_post'), 10, 2);
add_filter('document_title_parts', array('AcademicDirectory', 'filter_student_profile_title'));
add_filter('pre_get_shortlink', array('AcademicDirectory', 'filter_student_profile_shortlink'), 10, 4);
add_filter('template_include', array('AcademicDirectory', 'filter_virtual_profile_template'));
add_action('wp_head', array('AcademicDirectory', 'render_virtual_profile_meta'), 2);

function academic_directory_get_update_info() {
    $manifest_url = defined('ACADEMIC_DIRECTORY_UPDATE_JSON') && ACADEMIC_DIRECTORY_UPDATE_JSON ? ACADEMIC_DIRECTORY_UPDATE_JSON : ACADEMIC_DIRECTORY_DEFAULT_UPDATE_JSON;

    if (!$manifest_url) {
        return false;
    }

    $cache_key = 'academic_directory_update_info_' . md5(ACADEMIC_DIRECTORY_VERSION . '|' . $manifest_url);
    $cached = get_site_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    $response = wp_remote_get($manifest_url, array(
        'timeout' => 5,
        'headers' => array('Accept' => 'application/json'),
    ));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_site_transient($cache_key, array(), HOUR_IN_SECONDS);
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
        set_site_transient($cache_key, array(), HOUR_IN_SECONDS);
        return false;
    }

    set_site_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);

    return $data;
}

function academic_directory_check_for_updates($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(__FILE__);
    $info = academic_directory_get_update_info();

    if (!$info || empty($info['version']) || version_compare($info['version'], ACADEMIC_DIRECTORY_VERSION, '<=')) {
        return $transient;
    }

    $transient->response[$plugin_file] = (object) array(
        'slug' => dirname($plugin_file),
        'plugin' => $plugin_file,
        'new_version' => sanitize_text_field($info['version']),
        'url' => !empty($info['details_url']) ? esc_url_raw($info['details_url']) : '',
        'package' => esc_url_raw($info['download_url']),
        'tested' => !empty($info['tested']) ? sanitize_text_field($info['tested']) : '',
        'requires' => !empty($info['requires']) ? sanitize_text_field($info['requires']) : '',
        'requires_php' => !empty($info['requires_php']) ? sanitize_text_field($info['requires_php']) : '',
    );

    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'academic_directory_check_for_updates');

function academic_directory_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'academic-student-directory') {
        return $result;
    }

    $info = academic_directory_get_update_info();
    if (!$info) {
        return $result;
    }

    return (object) array(
        'name' => 'Academic Faculty Toolkit',
        'slug' => 'academic-student-directory',
        'version' => !empty($info['version']) ? sanitize_text_field($info['version']) : ACADEMIC_DIRECTORY_VERSION,
        'author' => '<a href="https://www.utah.edu/">Soroosh Noorzad</a>',
        'homepage' => !empty($info['details_url']) ? esc_url_raw($info['details_url']) : '',
        'requires' => !empty($info['requires']) ? sanitize_text_field($info['requires']) : '',
        'requires_php' => !empty($info['requires_php']) ? sanitize_text_field($info['requires_php']) : '',
        'sections' => array(
            'description' => !empty($info['description']) ? wp_kses_post($info['description']) : 'Faculty website tools and database-backed people directory.',
            'changelog' => !empty($info['changelog']) ? wp_kses_post($info['changelog']) : '',
        ),
        'download_link' => !empty($info['download_url']) ? esc_url_raw($info['download_url']) : '',
    );
}
add_filter('plugins_api', 'academic_directory_plugin_info', 10, 3);

class AcademicDirectory {

    private static $profile_settings_option = 'academic_directory_profile_page_settings';
    private static $db_version_option = 'academic_directory_db_version';
    private static $db_migrated_option = 'academic_directory_csv_migrated_to_db';
    private static $db_version = '1.0';

    public static function activate() {
        self::ensure_data_security_files();
        self::ensure_database_tables();
        self::migrate_csv_data_to_database();
        self::register_routes();
        flush_rewrite_rules();
    }

    public static function maybe_upgrade_database() {
        if (get_option(self::$db_version_option) !== self::$db_version) {
            self::ensure_database_tables();
        }

        if (get_option(self::$db_migrated_option) !== '1') {
            self::migrate_csv_data_to_database();
        }
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function maybe_flush_routes() {
        if (get_option('academic_directory_routes_version') === '2.6') {
            return;
        }

        self::register_routes();
        flush_rewrite_rules();
        update_option('academic_directory_routes_version', '2.6');
    }

    public static function register_routes() {
        add_rewrite_rule('^edit-profile/?$', 'index.php?academic_profile_edit=1', 'top');
        add_rewrite_rule('^research-group/?$', 'index.php?academic_directory_home=1', 'top');
        add_rewrite_rule('^research-group/PI/?$', 'index.php?academic_pi_profile=1', 'top');
        add_rewrite_rule('^research-group/pi/?$', 'index.php?academic_pi_profile=1', 'top');
        add_rewrite_rule('^research-group/([^/]+)/?$', 'index.php?academic_student_id=$matches[1]', 'top');
    }

    public static function register_query_vars($vars) {
        $vars[] = 'academic_profile_edit';
        $vars[] = 'academic_directory_home';
        $vars[] = 'academic_student_id';
        $vars[] = 'academic_pi_profile';
        return $vars;
    }

    private static function get_records_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'academic_directory_records';
    }

    public static function ensure_database_tables() {
        global $wpdb;

        $table = self::get_records_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dataset varchar(64) NOT NULL,
            row_key varchar(191) NOT NULL DEFAULT '',
            row_order int(11) NOT NULL DEFAULT 0,
            row_data longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY dataset_order (dataset, row_order),
            KEY dataset_key (dataset, row_key)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option(self::$db_version_option, self::$db_version, false);
    }

    private static function database_table_exists() {
        global $wpdb;

        $table = self::get_records_table_name();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function get_dataset_for_filename($filename) {
        $basename = basename((string) $filename);
        $map = array(
            'students.csv' => 'students',
            'student-education.csv' => 'education',
            'principal-investigator.csv' => 'pi',
            'student-category-order.csv' => 'categories',
            'pronouns.csv' => 'pronouns',
            'education-title-order.csv' => 'education_titles',
        );

        return isset($map[$basename]) ? $map[$basename] : '';
    }

    private static function get_default_headers_for_dataset($dataset) {
        $headers = array(
            'students' => self::get_student_csv_headers(),
            'education' => array('Student ID', 'Education Title', 'Institution', 'University Link', 'Start Date', 'End Date'),
            'pi' => array('name', 'title', 'department', 'institution', 'email', 'phone', 'office', 'website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url', 'link_order', 'vacancies_enabled', 'vacancies_label', 'vacancies_title', 'vacancies_text', 'vacancies_button_text', 'vacancies_button_url', 'image', 'short_bio', 'full_bio', 'education', 'professional_experience', 'honors_awards', 'useful_title', 'useful_text', 'research_interests'),
            'categories' => array('Category', 'Rank'),
            'pronouns' => array('Pronoun', 'Label'),
            'education_titles' => array('Title', 'Rank'),
        );

        return isset($headers[$dataset]) ? $headers[$dataset] : array();
    }

    private static function get_dataset_headers($dataset) {
        $headers = get_option('academic_directory_db_headers_' . $dataset, array());

        return is_array($headers) && $headers ? $headers : self::get_default_headers_for_dataset($dataset);
    }

    private static function get_row_key_for_dataset($dataset, $row) {
        if ($dataset === 'students') {
            return self::get_student_identifier($row);
        }

        if ($dataset === 'education') {
            return isset($row['student_id']) ? sanitize_title($row['student_id']) : '';
        }

        if ($dataset === 'pi') {
            return 'principal-investigator';
        }

        if ($dataset === 'categories') {
            return isset($row['category']) ? self::normalize_key($row['category']) : '';
        }

        if ($dataset === 'pronouns') {
            return isset($row['pronoun']) ? self::normalize_key($row['pronoun']) : '';
        }

        if ($dataset === 'education_titles') {
            return isset($row['title']) ? self::normalize_key($row['title']) : '';
        }

        return '';
    }

    private static function read_database_table($filename) {
        global $wpdb;

        $dataset = self::get_dataset_for_filename($filename);
        if (!$dataset || !self::database_table_exists()) {
            return false;
        }

        $table = self::get_records_table_name();
        $records = $wpdb->get_results(
            $wpdb->prepare("SELECT row_data FROM {$table} WHERE dataset = %s ORDER BY row_order ASC, id ASC", $dataset),
            ARRAY_A
        );

        $headers = self::get_dataset_headers($dataset);

        if (!$records) {
            if (get_option(self::$db_migrated_option) === '1' || get_option('academic_directory_db_headers_' . $dataset, false)) {
                return array(
                    'headers' => $headers,
                    'normalized_headers' => self::normalize_headers($headers),
                    'rows' => array(),
                );
            }

            return false;
        }

        $rows = array();
        foreach ($records as $record) {
            $row = maybe_unserialize($record['row_data']);
            if (is_array($row)) {
                $rows[] = array_map('trim', $row);
            }
        }

        return array(
            'headers' => $headers,
            'normalized_headers' => self::normalize_headers($headers),
            'rows' => $rows,
        );
    }

    private static function backup_database_dataset($filename) {
        $dataset = self::get_dataset_for_filename($filename);
        if (!$dataset || !self::database_table_exists()) {
            return true;
        }

        $table = self::read_database_table($filename);
        if (!$table || empty($table['rows'])) {
            return true;
        }

        self::ensure_data_security_files();
        $backup_dir = self::get_backup_directory();
        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            return false;
        }

        $backup_path = $backup_dir . DIRECTORY_SEPARATOR . $dataset . '-' . gmdate('Ymd-His') . '.csv';
        $handle = fopen($backup_path, 'w');
        if (!$handle) {
            return false;
        }

        $headers = !empty($table['headers']) ? $table['headers'] : self::get_default_headers_for_dataset($dataset);
        $normalized = self::normalize_headers($headers);
        fputcsv($handle, $headers);

        foreach ($table['rows'] as $row) {
            $line = array();
            foreach ($normalized as $key) {
                $line[] = isset($row[$key]) ? $row[$key] : '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);

        return true;
    }

    private static function write_database_rows($filename, $headers, $rows) {
        global $wpdb;

        $dataset = self::get_dataset_for_filename($filename);
        if (!$dataset) {
            return false;
        }

        self::ensure_database_tables();

        if (!self::backup_database_dataset($filename)) {
            return false;
        }

        $table = self::get_records_table_name();
        $normalized_headers = self::normalize_headers($headers);
        $prepared_rows = array();

        foreach (array_values($rows) as $order => $row) {
            $normalized_row = array();
            foreach ($headers as $index => $header) {
                $key = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
                if (isset($row[$header])) {
                    $normalized_row[$key] = (string) $row[$header];
                } elseif (isset($row[$key])) {
                    $normalized_row[$key] = (string) $row[$key];
                } else {
                    $normalized_row[$key] = '';
                }
            }

            $prepared_rows[] = array(
                'dataset' => $dataset,
                'row_key' => self::get_row_key_for_dataset($dataset, $normalized_row),
                'row_order' => $order,
                'row_data' => maybe_serialize($normalized_row),
                'updated_at' => current_time('mysql'),
            );
        }

        $wpdb->query('START TRANSACTION');
        $deleted = $wpdb->delete($table, array('dataset' => $dataset), array('%s'));

        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        foreach ($prepared_rows as $prepared_row) {
            $inserted = $wpdb->insert(
                $table,
                $prepared_row,
                array('%s', '%s', '%d', '%s', '%s')
            );

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        $wpdb->query('COMMIT');
        update_option('academic_directory_db_headers_' . $dataset, array_values($headers), false);

        return true;
    }

    private static function migrate_csv_data_to_database() {
        if (!self::database_table_exists()) {
            return false;
        }

        $datasets = array(
            'data/students.csv',
            'data/student-education.csv',
            'data/principal-investigator.csv',
            'data/student-category-order.csv',
            'data/pronouns.csv',
            'data/education-title-order.csv',
        );

        foreach ($datasets as $filename) {
            $dataset = self::get_dataset_for_filename($filename);
            if (!$dataset || self::database_dataset_has_rows($dataset)) {
                continue;
            }

            $table = self::read_csv_table_from_file($filename);
            if (!$table || empty($table['headers'])) {
                continue;
            }

            self::write_database_rows($filename, $table['headers'], $table['rows']);
        }

        update_option(self::$db_migrated_option, '1', false);

        return true;
    }

    private static function database_dataset_has_rows($dataset) {
        global $wpdb;

        if (!self::database_table_exists()) {
            return false;
        }

        $table = self::get_records_table_name();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE dataset = %s", $dataset)) > 0;
    }

    private static function get_csv_path($filename = 'students.csv') {
        global $wpdb;

        $plugin_path = self::get_plugin_data_csv_path($filename);

        if ($plugin_path) {
            return $plugin_path;
        }

        $filename = basename(sanitize_file_name($filename));

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            return false;
        }

        $file_path = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value FROM $wpdb->postmeta pm
             INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_attached_file'
             AND pm.meta_value LIKE %s
             AND p.post_type = 'attachment'
             LIMIT 1",
            '%' . $wpdb->esc_like($filename)
        ));

        if (!$file_path) return false;

        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/' . $file_path;
    }

    private static function get_optional_csv_path($filename) {
        if (empty($filename)) return false;
        $path = self::get_csv_path($filename);
        return ($path && file_exists($path)) ? $path : false;
    }

    private static function get_plugin_data_csv_path($filename) {
        $filename = ltrim((string) $filename, '/\\');
        $filename = str_replace(array('../', '..\\'), '', $filename);

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            return false;
        }

        $path = plugin_dir_path(__FILE__) . $filename;

        return file_exists($path) ? $path : false;
    }

    private static function get_plugin_data_csv_write_path($filename) {
        $filename = ltrim((string) $filename, '/\\');
        $filename = str_replace(array('../', '..\\'), '', $filename);

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') {
            return false;
        }

        $path = plugin_dir_path(__FILE__) . $filename;
        $base = wp_normalize_path(plugin_dir_path(__FILE__));
        $target = wp_normalize_path($path);

        if (strpos($target, $base) !== 0) {
            return false;
        }

        return $path;
    }

    public static function get_data_directory() {
        return plugin_dir_path(__FILE__) . 'data';
    }

    public static function get_backup_directory() {
        return self::get_data_directory() . DIRECTORY_SEPARATOR . 'backups';
    }

    public static function ensure_data_security_files() {
        $directories = array(self::get_data_directory(), self::get_backup_directory());

        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                wp_mkdir_p($directory);
            }

            if (!is_dir($directory) || !is_writable($directory)) {
                continue;
            }

            $index_path = $directory . DIRECTORY_SEPARATOR . 'index.php';
            if (!file_exists($index_path)) {
                file_put_contents($index_path, "<?php\n// Silence is golden.\n");
            }

            $htaccess_path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess_path)) {
                file_put_contents(
                    $htaccess_path,
                    "# Academic Faculty Toolkit data protection.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
                );
            }

            $web_config_path = $directory . DIRECTORY_SEPARATOR . 'web.config';
            if (!file_exists($web_config_path)) {
                file_put_contents(
                    $web_config_path,
                    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n"
                );
            }
        }

        return true;
    }

    private static function backup_csv_file($path) {
        if (!$path || !file_exists($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return true;
        }

        self::ensure_data_security_files();
        $backup_dir = self::get_backup_directory();

        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            return false;
        }

        $backup_name = pathinfo($path, PATHINFO_FILENAME) . '-' . gmdate('Ymd-His') . '.csv';
        $backup_path = $backup_dir . DIRECTORY_SEPARATOR . $backup_name;

        if (!copy($path, $backup_path)) {
            return false;
        }

        $backups = glob($backup_dir . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '-*.csv');
        if (is_array($backups) && count($backups) > 25) {
            usort($backups, function($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });
            foreach (array_slice($backups, 0, count($backups) - 25) as $old_backup) {
                @unlink($old_backup);
            }
        }

        return true;
    }

    public static function get_backup_status() {
        self::ensure_data_security_files();

        $data_dir = self::get_data_directory();
        $backup_dir = self::get_backup_directory();
        $private_dir = self::get_data_directory() . DIRECTORY_SEPARATOR . 'private';
        $checks = array(
            'data_dir' => is_dir($data_dir) && is_writable($data_dir),
            'backup_dir' => is_dir($backup_dir) && is_writable($backup_dir),
            'private_dir' => is_dir($private_dir) && is_writable($private_dir),
            'data_htaccess' => file_exists($data_dir . DIRECTORY_SEPARATOR . '.htaccess'),
            'backup_htaccess' => file_exists($backup_dir . DIRECTORY_SEPARATOR . '.htaccess'),
            'private_htaccess' => file_exists($private_dir . DIRECTORY_SEPARATOR . '.htaccess'),
            'data_index' => file_exists($data_dir . DIRECTORY_SEPARATOR . 'index.php'),
            'backup_index' => file_exists($backup_dir . DIRECTORY_SEPARATOR . 'index.php'),
            'private_index' => file_exists($private_dir . DIRECTORY_SEPARATOR . 'index.php'),
        );

        return $checks;
    }

    public static function get_database_status() {
        return array(
            'table_exists' => self::database_table_exists(),
            'version' => get_option(self::$db_version_option) === self::$db_version,
            'migrated' => get_option(self::$db_migrated_option) === '1',
        );
    }

    public static function get_csv_backup_files_for_admin() {
        self::ensure_data_security_files();

        $backup_dir = self::get_backup_directory();
        $files = is_dir($backup_dir) ? glob($backup_dir . DIRECTORY_SEPARATOR . '*.csv') : array();
        $items = array();

        if (!is_array($files)) {
            return $items;
        }

        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match('/^([a-z0-9_-]+)-\d{8}-\d{6}\.csv$/i', $basename, $matches)) {
                continue;
            }

            $items[] = array(
                'file' => $basename,
                'dataset' => $matches[1],
                'path' => $file,
                'modified' => filemtime($file),
                'size' => filesize($file),
            );
        }

        usort($items, function($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });

        return $items;
    }

    public static function restore_backup_from_admin($backup_file) {
        self::ensure_data_security_files();

        $backup_file = sanitize_file_name((string) $backup_file);
        if (!preg_match('/^([a-z0-9_-]+)-\d{8}-\d{6}\.csv$/i', $backup_file, $matches)) {
            return false;
        }

        $backup_dir = wp_normalize_path(self::get_backup_directory());
        $backup_path = wp_normalize_path(self::get_backup_directory() . DIRECTORY_SEPARATOR . $backup_file);

        if (strpos($backup_path, $backup_dir) !== 0 || !file_exists($backup_path)) {
            return false;
        }

        $dataset_map = array(
            'students' => 'data/students.csv',
            'education' => 'data/student-education.csv',
            'pi' => 'data/principal-investigator.csv',
            'categories' => 'data/student-category-order.csv',
            'education_titles' => 'data/education-title-order.csv',
            'student-education' => 'data/student-education.csv',
            'principal-investigator' => 'data/principal-investigator.csv',
            'student-category-order' => 'data/student-category-order.csv',
            'pronouns' => 'data/pronouns.csv',
            'education-title-order' => 'data/education-title-order.csv',
        );

        $dataset = $matches[1];
        if (empty($dataset_map[$dataset])) {
            return false;
        }

        $handle = fopen($backup_path, 'r');
        if (!$handle) {
            return false;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return false;
        }

        $normalized_headers = self::normalize_headers($headers);
        $rows = array();

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($normalized_headers)) {
                $row = array_pad($row, count($normalized_headers), '');
            }

            $data = array_combine($normalized_headers, array_slice($row, 0, count($normalized_headers)));
            if ($data) {
                $rows[] = array_map('trim', $data);
            }
        }

        fclose($handle);

        $restored = self::write_database_rows($dataset_map[$dataset], $headers, $rows);
        if ($restored) {
            self::flush_directory_cache();
        }

        return $restored;
    }

    public static function delete_backup_from_admin($backup_file) {
        self::ensure_data_security_files();

        $backup_file = sanitize_file_name((string) $backup_file);
        if (!preg_match('/^([a-z0-9_-]+)-\d{8}-\d{6}\.csv$/i', $backup_file)) {
            return false;
        }

        $backup_dir = wp_normalize_path(self::get_backup_directory());
        $backup_path = wp_normalize_path(self::get_backup_directory() . DIRECTORY_SEPARATOR . $backup_file);

        if (strpos($backup_path, $backup_dir) !== 0 || !is_file($backup_path)) {
            return false;
        }

        return unlink($backup_path);
    }

    public static function get_image_id($filename) {
        if (empty($filename)) return false;

        if (filter_var($filename, FILTER_VALIDATE_URL)) {
            return attachment_url_to_postid($filename);
        }

        global $wpdb;

        $basename = pathinfo($filename, PATHINFO_FILENAME);

        return $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM $wpdb->postmeta pm
             INNER JOIN $wpdb->posts p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_attached_file'
             AND pm.meta_value LIKE %s
             AND p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%%'
             LIMIT 1",
            '%' . $wpdb->esc_like($basename) . '%'
        ));
    }

    public static function get_protected_email_data($email) {
        $email = sanitize_email($email);

        if ($email === '') {
            return '';
        }

        return str_rot13($email);
    }

    private static function is_active($value) {
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'active', 'true', 'y', 'yes'), true);
    }

    private static function get_member_section($value) {
        return self::is_active($value) ? 'current' : 'past';
    }

    public static function normalize_headers($headers) {
        $normalized = array();

        foreach ($headers as $index => $header) {
            $key = strtolower(trim($header));
            $key = preg_replace('/[^a-z0-9]+/', '_', $key);
            $normalized[$index] = trim($key, '_');
        }

        return $normalized;
    }

    public static function get_student_csv_headers($headers = array()) {
        $defaults = array('Student ID', 'Category', 'Active', 'Name', 'Email', 'Secondary Email', 'Website', 'LinkedIn', 'GitHub', 'Google Scholar', 'ORCID', 'Research Gate', 'CV URL', 'Bio', 'Date of Entry', 'Pronoun', 'Research Interests', 'Hobbies', 'Current Position', 'Position Updated', 'Image');
        $headers = !empty($headers) ? $headers : $defaults;
        $normalized_headers = self::normalize_headers($headers);

        foreach ($defaults as $default_header) {
            if (!in_array(self::normalize_key($default_header), $normalized_headers, true)) {
                $headers[] = $default_header;
                $normalized_headers[] = self::normalize_key($default_header);
            }
        }

        return $headers;
    }

    public static function get_student_link_fields() {
        return array(
            'website' => array(
                'label' => 'Website',
                'icon' => 'Website',
                'icon_class' => 'fa-solid fa-globe',
            ),
            'linkedin' => array(
                'label' => 'LinkedIn',
                'icon' => 'LinkedIn',
                'icon_class' => 'fa-brands fa-linkedin-in',
            ),
            'github' => array(
                'label' => 'GitHub',
                'icon' => 'GitHub',
                'icon_class' => 'fa-brands fa-github',
            ),
            'google_scholar' => array(
                'label' => 'Google Scholar',
                'icon' => 'Google Scholar',
                'icon_class' => 'fa-brands fa-google-scholar',
            ),
            'orcid' => array(
                'label' => 'ORCID',
                'icon' => 'ORCID',
                'icon_class' => 'fa-brands fa-orcid',
            ),
            'research_gate' => array(
                'label' => 'ResearchGate',
                'icon' => 'ResearchGate',
                'icon_class' => 'fa-brands fa-researchgate',
                'aliases' => array('researchgate'),
            ),
            'cv_url' => array(
                'label' => 'CV',
                'icon' => 'CV',
                'icon_class' => 'fa-regular fa-file-lines',
                'aliases' => array('cv'),
            ),
        );
    }

    private static function get_student_link_value($data, $field) {
        $fields = self::get_student_link_fields();
        $keys = array($field);

        if (isset($fields[$field]['aliases']) && is_array($fields[$field]['aliases'])) {
            $keys = array_merge($keys, $fields[$field]['aliases']);
        }

        foreach ($keys as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return '';
    }

    private static function normalize_external_url($url) {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) && strpos($url, 'mailto:') !== 0) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    public static function get_student_external_links($student) {
        $links = array();

        foreach (self::get_student_link_fields() as $field => $settings) {
            $url = self::normalize_external_url(self::get_student_link_value($student, $field));

            if ($url === '') {
                continue;
            }

            $links[] = array(
                'field' => $field,
                'label' => $settings['label'],
                'icon' => $settings['icon'],
                'icon_class' => isset($settings['icon_class']) ? $settings['icon_class'] : '',
                'url' => $url,
            );
        }

        return $links;
    }

    public static function get_pi_link_definitions() {
        return array(
            'profile' => array(
                'label' => 'Profile',
                'icon' => 'Profile',
                'icon_class' => 'fa-solid fa-id-card',
            ),
            'email' => array(
                'label' => 'Email',
                'icon' => '@',
                'icon_class' => 'fa-solid fa-envelope',
            ),
            'website' => array(
                'label' => 'Website',
                'icon' => 'Website',
                'icon_class' => 'fa-solid fa-globe',
            ),
            'google_scholar' => array(
                'label' => 'Google Scholar',
                'icon' => 'Google Scholar',
                'icon_class' => 'fa-brands fa-google-scholar',
            ),
            'github' => array(
                'label' => 'GitHub',
                'icon' => 'GitHub',
                'icon_class' => 'fa-brands fa-github',
            ),
            'orcid' => array(
                'label' => 'ORCID',
                'icon' => 'ORCID',
                'icon_class' => 'fa-brands fa-orcid',
            ),
            'research_gate' => array(
                'label' => 'ResearchGate',
                'icon' => 'ResearchGate',
                'icon_class' => 'fa-brands fa-researchgate',
                'aliases' => array('researchgate'),
            ),
            'cv_url' => array(
                'label' => 'CV',
                'icon' => 'CV',
                'icon_class' => 'fa-regular fa-file-lines',
                'aliases' => array('cv'),
            ),
            'linkedin' => array(
                'label' => 'LinkedIn',
                'icon' => 'LinkedIn',
                'icon_class' => 'fa-brands fa-linkedin-in',
            ),
        );
    }

    public static function get_pi_link_order($pi, $include_profile = false, $include_email = true) {
        $defaults = array('profile', 'email', 'website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url');
        $order_value = isset($pi['link_order']) ? trim((string) $pi['link_order']) : '';
        $order = $order_value !== '' ? array_map('trim', explode(',', $order_value)) : $defaults;
        $order = array_values(array_filter(array_map(array(__CLASS__, 'normalize_key'), $order)));

        if (!$include_profile) {
            $order = array_values(array_diff($order, array('profile')));
        }

        if (!$include_email) {
            $order = array_values(array_diff($order, array('email')));
        }

        foreach ($defaults as $default) {
            if (($default === 'profile' && !$include_profile) || ($default === 'email' && !$include_email)) {
                continue;
            }

            if (!in_array($default, $order, true)) {
                $order[] = $default;
            }
        }

        return $order;
    }

    public static function get_pi_external_links($pi, $include_profile = false, $include_email = true) {
        $fields = self::get_pi_link_definitions();
        $links = array();

        foreach (self::get_pi_link_order($pi, $include_profile, $include_email) as $field) {
            if (!isset($fields[$field])) {
                continue;
            }

            $settings = $fields[$field];
            $url = '';
            $data_email = '';

            if ($field === 'profile') {
                $url = self::get_pi_profile_url();
            } elseif ($field === 'email') {
                if (!empty($pi['email'])) {
                    $url = '#';
                    $data_email = self::get_protected_email_data($pi['email']);
                }
            } else {
                $raw_url = isset($pi[$field]) ? $pi[$field] : '';
                if ($raw_url === '' && !empty($settings['aliases']) && is_array($settings['aliases'])) {
                    foreach ($settings['aliases'] as $alias) {
                        if (!empty($pi[$alias])) {
                            $raw_url = $pi[$alias];
                            break;
                        }
                    }
                }
                $url = self::normalize_external_url($raw_url);
            }

            if ($url === '') {
                continue;
            }

            $links[] = array(
                'field' => $field,
                'label' => $settings['label'],
                'icon' => $settings['icon'],
                'icon_class' => $settings['icon_class'],
                'url' => $url,
                'is_email' => $field === 'email',
                'data_email' => $data_email,
            );
        }

        return $links;
    }

    private static function normalize_key($value) {
        $value = strtolower(trim((string) $value));
        return preg_replace('/[^a-z0-9]+/', '_', trim($value));
    }

    private static function compare_ranked_names($a_rank, $b_rank, $a_name, $b_name) {
        if ($a_rank === $b_rank) {
            return strnatcasecmp($a_name, $b_name);
        }

        return ($a_rank < $b_rank) ? -1 : 1;
    }

    private static function get_order_rank($name, $rank_map) {
        $key = self::normalize_key($name);

        if (isset($rank_map[$key])) {
            return $rank_map[$key];
        }

        foreach ($rank_map as $rank_key => $rank) {
            if ($rank_key !== '' && strpos($key, $rank_key) !== false) {
                return $rank;
            }
        }

        return PHP_INT_MAX;
    }

    private static function read_order_csv($filename) {
        $order = array(
            'sections' => array(
                self::normalize_key('current') => 1,
                self::normalize_key('past') => 2,
            ),
            'categories' => array(),
        );

        $table = self::read_csv_table($filename);
        if (empty($table['rows'])) return $order;

        foreach ($table['rows'] as $data) {
            $category = isset($data['category']) ? trim($data['category']) : '';
            $rank = isset($data['rank']) ? (int) $data['rank'] : 0;

            if (!$category || !$rank) continue;

            $order['categories'][self::normalize_key($category)] = $rank;
        }

        return $order;
    }

    private static function read_option_csv($filename, $value_key, $label_key = '') {
        $table = self::read_csv_table($filename);
        $options = array();

        if (empty($table['rows'])) return $options;

        foreach ($table['rows'] as $row) {
            $value = isset($row[$value_key]) ? trim($row[$value_key]) : '';
            if ($value === '') continue;

            $label = $label_key && isset($row[$label_key]) && trim($row[$label_key]) !== '' ? trim($row[$label_key]) : $value;
            $options[$value] = $label;
        }

        return $options;
    }

    private static function get_student_identifier($data) {
        if (!empty($data['student_id'])) {
            return trim($data['student_id']);
        }

        if (!empty($data['email'])) {
            return self::normalize_key($data['email']);
        }

        return !empty($data['name']) ? self::normalize_key($data['name']) : '';
    }

    private static function read_student_education_csv($filename) {
        $table = self::read_csv_table($filename);
        $education = array();

        if (empty($table['rows'])) return $education;

        foreach ($table['rows'] as $row) {
            $student_id = isset($row['student_id']) ? trim($row['student_id']) : '';
            if ($student_id === '') continue;

            $education[$student_id][] = array(
                'education_title' => isset($row['education_title']) ? trim($row['education_title']) : '',
                'institution' => isset($row['institution']) ? trim($row['institution']) : '',
                'university_link' => isset($row['university_link']) ? trim($row['university_link']) : '',
                'start_date' => isset($row['start_date']) ? trim($row['start_date']) : '',
                'end_date' => isset($row['end_date']) ? trim($row['end_date']) : '',
            );
        }

        foreach ($education as &$items) {
            usort($items, function($a, $b) {
                $rank_a = self::get_education_sort_rank($a['education_title']);
                $rank_b = self::get_education_sort_rank($b['education_title']);

                if ($rank_a !== $rank_b) {
                    return $rank_a - $rank_b;
                }

                $year_a = self::get_education_sort_year($a['end_date'], $a['start_date']);
                $year_b = self::get_education_sort_year($b['end_date'], $b['start_date']);

                if ($year_a !== $year_b) {
                    return $year_b - $year_a;
                }

                return strnatcasecmp($b['start_date'], $a['start_date']);
            });
        }
        unset($items);

        return $education;
    }

    private static function get_education_sort_rank($title) {
        $title = strtolower((string) $title);

        if (strpos($title, 'ph') !== false) {
            return 10;
        }

        if (strpos($title, 'm.sc') !== false || strpos($title, 'msc') !== false || strpos($title, 'master') !== false || strpos($title, 'm.s') !== false) {
            return 20;
        }

        if (strpos($title, 'b.sc') !== false || strpos($title, 'bsc') !== false || strpos($title, 'bachelor') !== false || strpos($title, 'b.s') !== false) {
            return 30;
        }

        return 90;
    }

    private static function get_education_sort_year($end_date, $start_date) {
        $date = trim((string) $end_date);

        if ($date === '' || strtolower($date) === 'present') {
            return 9999;
        }

        if (preg_match('/\d{4}/', $date, $match)) {
            return (int) $match[0];
        }

        if (preg_match('/\d{4}/', (string) $start_date, $match)) {
            return (int) $match[0];
        }

        return 0;
    }

    private static function read_pi_csv($filename) {
        $table = self::read_csv_table($filename);
        $data = !empty($table['rows'][0]) ? $table['rows'][0] : array();
        if (!$data) return array();

        $interests = array();
        if (!empty($data['research_interests'])) {
            $interests = array_filter(array_map('trim', explode(';', $data['research_interests'])));
        }

        return array(
            'name' => isset($data['name']) ? trim($data['name']) : '',
            'title' => isset($data['title']) ? trim($data['title']) : '',
            'department' => isset($data['department']) ? trim($data['department']) : '',
            'institution' => isset($data['institution']) ? trim($data['institution']) : '',
            'email' => isset($data['email']) ? trim($data['email']) : '',
            'phone' => isset($data['phone']) ? trim($data['phone']) : '',
            'office' => isset($data['office']) ? trim($data['office']) : '',
            'website' => isset($data['website']) ? trim($data['website']) : '',
            'linkedin' => isset($data['linkedin']) ? trim($data['linkedin']) : '',
            'github' => isset($data['github']) ? trim($data['github']) : '',
            'google_scholar' => isset($data['google_scholar']) ? trim($data['google_scholar']) : '',
            'orcid' => isset($data['orcid']) ? trim($data['orcid']) : '',
            'research_gate' => isset($data['research_gate']) ? trim($data['research_gate']) : (isset($data['researchgate']) ? trim($data['researchgate']) : ''),
            'cv_url' => isset($data['cv_url']) ? trim($data['cv_url']) : '',
            'link_order' => isset($data['link_order']) ? trim($data['link_order']) : '',
            'vacancies_enabled' => isset($data['vacancies_enabled']) ? trim($data['vacancies_enabled']) : '',
            'vacancies_label' => isset($data['vacancies_label']) ? trim($data['vacancies_label']) : '',
            'vacancies_title' => isset($data['vacancies_title']) ? trim($data['vacancies_title']) : '',
            'vacancies_text' => isset($data['vacancies_text']) ? trim($data['vacancies_text']) : '',
            'vacancies_button_text' => isset($data['vacancies_button_text']) ? trim($data['vacancies_button_text']) : '',
            'vacancies_button_url' => isset($data['vacancies_button_url']) ? trim($data['vacancies_button_url']) : '',
            'image' => isset($data['image']) ? trim($data['image']) : '',
            'short_bio' => isset($data['short_bio']) ? trim($data['short_bio']) : (isset($data['bio']) ? trim($data['bio']) : ''),
            'bio' => isset($data['short_bio']) ? trim($data['short_bio']) : (isset($data['bio']) ? trim($data['bio']) : ''),
            'full_bio' => isset($data['full_bio']) ? trim($data['full_bio']) : '',
            'education' => isset($data['education']) ? trim($data['education']) : '',
            'professional_experience' => isset($data['professional_experience']) ? trim($data['professional_experience']) : '',
            'honors_awards' => isset($data['honors_awards']) ? trim($data['honors_awards']) : '',
            'useful_title' => isset($data['useful_title']) ? trim($data['useful_title']) : '',
            'useful_text' => isset($data['useful_text']) ? trim($data['useful_text']) : '',
            'research_interests' => $interests,
        );
    }

    private static function read_csv_rows($filename, $headers) {
        $path = self::get_csv_path($filename);

        if (!$path || !file_exists($path)) return array();

        $handle = fopen($path, 'r');
        if (!$handle) return array();

        $raw_headers = fgetcsv($handle);
        if (!$raw_headers) {
            fclose($handle);
            return array();
        }

        $normalized_headers = self::normalize_headers($raw_headers);
        $rows = array();

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($normalized_headers)) {
                $row = array_pad($row, count($normalized_headers), '');
            }

            $data = array_combine($normalized_headers, array_slice($row, 0, count($normalized_headers)));
            if (!$data) continue;

            $clean_row = array();
            foreach ($headers as $header) {
                $clean_row[$header] = isset($data[$header]) ? trim($data[$header]) : '';
            }

            $rows[] = $clean_row;
        }

        fclose($handle);

        return $rows;
    }

    private static function read_csv_table($filename) {
        $database_table = self::read_database_table($filename);
        if ($database_table !== false) {
            return $database_table;
        }

        return self::read_csv_table_from_file($filename);
    }

    private static function read_csv_table_from_file($filename) {
        $path = self::get_csv_path($filename);

        if (!$path || !file_exists($path)) {
            return array('headers' => array(), 'rows' => array());
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return array('headers' => array(), 'rows' => array());
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return array('headers' => array(), 'rows' => array());
        }

        $normalized_headers = self::normalize_headers($headers);
        $rows = array();

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($normalized_headers)) {
                $row = array_pad($row, count($normalized_headers), '');
            }

            $data = array_combine($normalized_headers, array_slice($row, 0, count($normalized_headers)));
            if (!$data) continue;

            $rows[] = array_map('trim', $data);
        }

        fclose($handle);

        return array(
            'headers' => $headers,
            'normalized_headers' => $normalized_headers,
            'rows' => $rows,
        );
    }

    private static function write_csv_rows($filename, $headers, $rows) {
        $dataset = self::get_dataset_for_filename($filename);
        if ($dataset) {
            return self::write_database_rows($filename, $headers, $rows);
        }

        $path = self::get_plugin_data_csv_write_path($filename);

        if (!$path) return false;

        self::ensure_data_security_files();

        $directory = dirname($path);
        if (!file_exists($directory)) {
            wp_mkdir_p($directory);
        }

        if (!self::backup_csv_file($path)) {
            return false;
        }

        $handle = fopen($path, 'c+');
        if (!$handle) return false;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }

        rewind($handle);
        ftruncate($handle, 0);

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $line[] = isset($row[$header]) ? $row[$header] : '';
            }
            fputcsv($handle, $line);
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    public static function get_export_headers($dataset) {
        if ($dataset === 'education') {
            return array('Profile Slug', 'Education Title', 'Institution', 'University Link', 'Start Date', 'End Date');
        }

        return array('Profile Slug', 'Category', 'Active', 'Name', 'Email', 'Secondary Email', 'Website', 'LinkedIn', 'GitHub', 'Google Scholar', 'ORCID', 'Research Gate', 'CV URL', 'Bio', 'Date of Entry', 'Pronoun', 'Research Interests', 'Hobbies', 'Current Position', 'Position Updated');
    }

    private static function normalize_dataset($dataset) {
        $dataset = sanitize_key((string) $dataset);
        return in_array($dataset, array('students', 'education'), true) ? $dataset : 'students';
    }

    private static function get_export_filename($dataset) {
        $dataset = self::normalize_dataset($dataset);
        $filenames = array(
            'students' => 'research-group-students.csv',
            'education' => 'research-group-education.csv',
        );

        return $filenames[$dataset];
    }

    private static function get_dataset_label($dataset) {
        $labels = array(
            'students' => 'Students',
            'education' => 'Education',
        );

        return isset($labels[$dataset]) ? $labels[$dataset] : 'Students';
    }

    public static function output_export_csv($dataset) {
        $dataset = self::normalize_dataset($dataset);
        $headers = self::get_export_headers($dataset);
        $rows = self::get_export_rows($dataset);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::get_export_filename($dataset) . '"');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'w');
        if (!$output) {
            return;
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $line[] = isset($row[$header]) ? $row[$header] : '';
            }
            fputcsv($output, $line);
        }

        fclose($output);
    }

    private static function get_export_rows($dataset) {
        if ($dataset === 'education') {
            return self::get_education_export_rows();
        }

        return self::get_student_export_rows();
    }

    private static function get_student_export_rows() {
        $table = self::read_csv_table('data/students.csv');
        $rows = array();

        foreach ($table['rows'] as $student) {
            $rows[] = array(
                'Profile Slug' => self::get_student_identifier($student),
                'Category' => isset($student['category']) ? $student['category'] : '',
                'Active' => isset($student['active']) ? $student['active'] : '',
                'Name' => isset($student['name']) ? $student['name'] : '',
                'Email' => isset($student['email']) ? $student['email'] : '',
                'Secondary Email' => isset($student['secondary_email']) ? $student['secondary_email'] : '',
                'Website' => isset($student['website']) ? $student['website'] : '',
                'LinkedIn' => isset($student['linkedin']) ? $student['linkedin'] : '',
                'GitHub' => isset($student['github']) ? $student['github'] : '',
                'Google Scholar' => isset($student['google_scholar']) ? $student['google_scholar'] : '',
                'ORCID' => isset($student['orcid']) ? $student['orcid'] : '',
                'Research Gate' => isset($student['research_gate']) ? $student['research_gate'] : '',
                'CV URL' => isset($student['cv_url']) ? $student['cv_url'] : '',
                'Bio' => isset($student['bio']) ? $student['bio'] : '',
                'Date of Entry' => isset($student['date_of_entry']) ? $student['date_of_entry'] : '',
                'Pronoun' => isset($student['pronoun']) ? $student['pronoun'] : '',
                'Research Interests' => isset($student['research_interests']) ? $student['research_interests'] : '',
                'Hobbies' => isset($student['hobbies']) ? $student['hobbies'] : '',
                'Current Position' => isset($student['current_position']) ? $student['current_position'] : '',
                'Position Updated' => isset($student['position_updated']) ? $student['position_updated'] : '',
            );
        }

        return $rows;
    }

    private static function get_education_export_rows() {
        $table = self::read_csv_table('data/student-education.csv');
        $rows = array();

        foreach ($table['rows'] as $education) {
            $rows[] = array(
                'Profile Slug' => isset($education['student_id']) ? $education['student_id'] : '',
                'Education Title' => isset($education['education_title']) ? $education['education_title'] : '',
                'Institution' => isset($education['institution']) ? $education['institution'] : '',
                'University Link' => isset($education['university_link']) ? $education['university_link'] : '',
                'Start Date' => isset($education['start_date']) ? $education['start_date'] : '',
                'End Date' => isset($education['end_date']) ? $education['end_date'] : '',
            );
        }

        return $rows;
    }

    private static function flush_directory_cache() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_acad_dir_csv_%'
             OR option_name LIKE '_transient_timeout_acad_dir_csv_%'"
        );
    }

    public static function get_pi_for_admin($filename = 'data/principal-investigator.csv') {
        return self::read_pi_csv($filename);
    }

    public static function get_students_for_admin($filename = 'data/students.csv') {
        return self::read_csv_table($filename);
    }

    public static function get_category_options_for_admin($filename = 'data/student-category-order.csv') {
        return self::read_option_csv($filename, 'category');
    }

    public static function get_pronoun_options_for_admin($filename = 'data/pronouns.csv') {
        return self::read_option_csv($filename, 'pronoun', 'label');
    }

    public static function get_education_title_options_for_admin($filename = 'data/education-title-order.csv') {
        $table = self::read_csv_table($filename);
        $options = array();

        foreach ($table['rows'] as $row) {
            $title = '';
            if (isset($row['title'])) {
                $title = trim($row['title']);
            } elseif (isset($row['category'])) {
                $title = trim($row['category']);
            }

            if ($title !== '') {
                $options[$title] = $title;
            }
        }

        return $options;
    }

    public static function get_student_education_for_admin($filename = 'data/student-education.csv') {
        return self::read_student_education_csv($filename);
    }

    public static function get_generic_settings_for_admin() {
        return array(
            'categories' => self::read_csv_table('data/student-category-order.csv'),
            'pronouns' => self::read_csv_table('data/pronouns.csv'),
            'education_titles' => self::read_education_title_table_for_admin('data/education-title-order.csv'),
        );
    }

    private static function read_education_title_table_for_admin($filename) {
        $table = self::read_csv_table($filename);
        $rows = array();

        foreach ($table['rows'] as $row) {
            $title = '';
            if (isset($row['title'])) {
                $title = trim($row['title']);
            } elseif (isset($row['category'])) {
                $title = trim($row['category']);
            }

            if ($title === '') {
                continue;
            }

            $rows[] = array(
                'title' => $title,
                'rank' => isset($row['rank']) ? $row['rank'] : '',
            );
        }

        return array(
            'headers' => array('Title', 'Rank'),
            'normalized_headers' => array('title', 'rank'),
            'rows' => $rows,
        );
    }

    public static function save_generic_settings_from_admin($data) {
        $categories = isset($data['categories']) && is_array($data['categories']) ? $data['categories'] : array();
        $pronouns = isset($data['pronouns']) && is_array($data['pronouns']) ? $data['pronouns'] : array();
        $education_titles = isset($data['education_titles']) && is_array($data['education_titles']) ? $data['education_titles'] : array();

        $saved_categories = self::write_csv_rows(
            'data/student-category-order.csv',
            array('Category', 'Rank'),
            self::sanitize_ranked_option_rows($categories, 'Category')
        );

        $saved_pronouns = self::write_csv_rows(
            'data/pronouns.csv',
            array('Pronoun', 'Label'),
            self::sanitize_label_option_rows($pronouns, 'Pronoun', 'Label')
        );

        $saved_education_titles = self::write_csv_rows(
            'data/education-title-order.csv',
            array('Title', 'Rank'),
            self::sanitize_ranked_option_rows($education_titles, 'Title')
        );

        self::flush_directory_cache();

        return $saved_categories && $saved_pronouns && $saved_education_titles;
    }

    private static function sanitize_ranked_option_rows($rows, $value_header) {
        $clean_rows = array();

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $value = isset($row['value']) ? sanitize_text_field(wp_unslash($row['value'])) : '';
            $rank = isset($row['rank']) ? absint($row['rank']) : 0;

            if ($value === '') continue;

            $clean_rows[] = array(
                $value_header => $value,
                'Rank' => $rank > 0 ? $rank : count($clean_rows) + 1,
            );
        }

        usort($clean_rows, function($a, $b) {
            return ((int) $a['Rank']) <=> ((int) $b['Rank']);
        });

        return $clean_rows;
    }

    private static function sanitize_label_option_rows($rows, $value_header, $label_header) {
        $clean_rows = array();

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $value = isset($row['value']) ? sanitize_text_field(wp_unslash($row['value'])) : '';
            $label = isset($row['label']) ? sanitize_text_field(wp_unslash($row['label'])) : '';

            if ($value === '') continue;

            $clean_rows[] = array(
                $value_header => $value,
                $label_header => $label !== '' ? $label : $value,
            );
        }

        return $clean_rows;
    }

    public static function get_student_profile_url($student_id) {
        return home_url('/research-group/' . rawurlencode($student_id) . '/');
    }

    public static function get_pi_profile_url() {
        return home_url('/research-group/PI/');
    }

    public static function get_students($students_csv = 'data/students.csv', $education_csv = 'data/student-education.csv') {
        $table = self::read_csv_table($students_csv);
        $education_by_student = self::read_student_education_csv($education_csv);
        $students = array();

        if (empty($table['rows'])) return $students;

        foreach ($table['rows'] as $data) {
            $category = isset($data['category']) ? trim($data['category']) : '';
            $student_id = self::get_student_identifier($data);

            if ($student_id === '' || $category === '') continue;

            $students[] = array(
                'student_id' => $student_id,
                'profile_url' => self::get_student_profile_url($student_id),
                'category' => $category,
                'active' => isset($data['active']) ? trim($data['active']) : 'y',
                'name' => isset($data['name']) ? trim($data['name']) : '',
                'email' => isset($data['email']) ? trim($data['email']) : '',
                'secondary_email' => isset($data['secondary_email']) ? trim($data['secondary_email']) : '',
                'website' => isset($data['website']) ? trim($data['website']) : '',
                'linkedin' => self::get_student_link_value($data, 'linkedin'),
                'github' => self::get_student_link_value($data, 'github'),
                'google_scholar' => self::get_student_link_value($data, 'google_scholar'),
                'orcid' => self::get_student_link_value($data, 'orcid'),
                'research_gate' => self::get_student_link_value($data, 'research_gate'),
                'cv_url' => self::get_student_link_value($data, 'cv_url'),
                'bio' => isset($data['bio']) ? trim($data['bio']) : '',
                'date_of_entry' => isset($data['date_of_entry']) ? trim($data['date_of_entry']) : '',
                'pronoun' => isset($data['pronoun']) ? trim($data['pronoun']) : '',
                'research_interests' => isset($data['research_interests']) ? trim($data['research_interests']) : '',
                'hobbies' => isset($data['hobbies']) ? trim($data['hobbies']) : '',
                'current_position' => isset($data['current_position']) ? trim($data['current_position']) : '',
                'position_updated' => isset($data['position_updated']) ? trim($data['position_updated']) : '',
                'image' => isset($data['image']) ? trim($data['image']) : '',
                'rank' => isset($data['rank']) ? (int) $data['rank'] : 0,
                'education' => isset($education_by_student[$student_id]) ? $education_by_student[$student_id] : array(),
            );
        }

        return $students;
    }

    public static function get_student_by_id($student_id) {
        $student_id = sanitize_title($student_id);

        foreach (self::get_students() as $student) {
            if ($student['student_id'] === $student_id) {
                return $student;
            }
        }

        return false;
    }

    public static function prepare_student_profile_query($query) {
        if (is_admin() || !$query->is_main_query() || (!$query->get('academic_profile_edit') && !$query->get('academic_directory_home') && !$query->get('academic_student_id') && !$query->get('academic_pi_profile'))) {
            return;
        }

        $query->is_home = false;
        $query->is_page = true;
        $query->is_singular = true;
        $query->is_404 = false;

        remove_filter('the_content', 'wpautop');
        remove_filter('the_content', 'shortcode_unautop');
    }

    private static function create_virtual_page_post($id_key, $title, $content, $slug, $guid) {
        return new WP_Post((object) array(
            'ID' => -abs(crc32($id_key)),
            'post_author' => 0,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
            'post_content' => $content,
            'post_title' => $title,
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $slug,
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => $guid,
            'menu_order' => 0,
            'post_type' => 'page',
            'post_mime_type' => '',
            'comment_count' => 0,
            'filter' => 'raw',
        ));
    }

    private static function set_virtual_page_query($query, $virtual_post) {
        $query->found_posts = 1;
        $query->post_count = 1;
        $query->max_num_pages = 1;
        $query->posts = array($virtual_post);
        $query->post = $virtual_post;
        $query->queried_object = $virtual_post;
        $query->queried_object_id = $virtual_post->ID;
        $query->is_page = true;
        $query->is_singular = true;
        $query->is_404 = false;

        global $post;
        $post = $virtual_post;
        setup_postdata($post);

        return array($virtual_post);
    }

    public static function inject_student_profile_post($posts, $query) {
        if (is_admin() || !$query->is_main_query()) {
            return $posts;
        }

        $is_directory_home = (bool) get_query_var('academic_directory_home');
        $student_id = get_query_var('academic_student_id');
        $is_pi_profile = (bool) get_query_var('academic_pi_profile');

        if (!$is_directory_home && !$student_id && !$is_pi_profile) {
            return $posts;
        }

        if ($is_directory_home) {
            $directory_post = self::create_virtual_page_post(
                'academic-directory-home',
                'Research Group',
                self::render(),
                'research-group',
                home_url('/research-group/')
            );

            return self::set_virtual_page_query($query, $directory_post);
        }

        if ($is_pi_profile) {
            $pi = self::get_pi_for_admin();

            if (empty($pi) || empty($pi['name'])) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return array();
            }

            $pi_post = self::create_virtual_page_post(
                'academic-pi-profile',
                $pi['name'],
                self::render_pi_profile_content($pi),
                'PI',
                self::get_pi_profile_url()
            );

            return self::set_virtual_page_query($query, $pi_post);
        }

        $student = self::get_student_by_id($student_id);

        if (!$student) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return array();
        }

        $student_post = self::create_virtual_page_post(
            $student['student_id'],
            $student['name'],
            self::render_student_profile_content($student),
            $student['student_id'],
            self::get_student_profile_url($student['student_id'])
        );

        return self::set_virtual_page_query($query, $student_post);
    }

    public static function filter_student_profile_title($parts) {
        if (get_query_var('academic_directory_home')) {
            $parts['title'] = 'Research Group';
            return $parts;
        }

        if (get_query_var('academic_pi_profile')) {
            $pi = self::get_pi_for_admin();

            if (!empty($pi['name'])) {
                $parts['title'] = $pi['name'];
            }

            return $parts;
        }

        $student_id = get_query_var('academic_student_id');

        if (!$student_id) {
            return $parts;
        }

        $student = self::get_student_by_id($student_id);

        if ($student) {
            $parts['title'] = $student['name'];
        }

        return $parts;
    }

    public static function filter_student_profile_shortlink($shortlink, $id, $context, $allow_slugs) {
        if (get_query_var('academic_directory_home')) {
            return home_url('/research-group/');
        }

        if (get_query_var('academic_pi_profile')) {
            return self::get_pi_profile_url();
        }

        $student_id = get_query_var('academic_student_id');

        if (!$student_id) {
            return $shortlink;
        }

        $student = self::get_student_by_id($student_id);

        if (!$student) {
            return $shortlink;
        }

        return self::get_student_profile_url($student['student_id']);
    }

    public static function get_virtual_profile_meta_data() {
        if (get_query_var('academic_directory_home')) {
            return array(
                'title' => 'Research Group',
                'description' => wp_strip_all_tags(get_bloginfo('description')),
                'url' => home_url('/research-group/'),
                'image' => '',
                'schema_type' => 'Organization',
            );
        }

        if (get_query_var('academic_pi_profile')) {
            $pi = self::get_pi_for_admin();
            if (empty($pi) || empty($pi['name'])) {
                return false;
            }

            $image_url = '';
            if (!empty($pi['image'])) {
                $image_id = self::get_image_id($pi['image']);
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
            }

            return array(
                'title' => $pi['name'],
                'description' => !empty($pi['short_bio']) ? wp_trim_words(wp_strip_all_tags($pi['short_bio']), 32, '') : wp_strip_all_tags(get_bloginfo('description')),
                'url' => self::get_pi_profile_url(),
                'image' => $image_url,
                'schema_type' => 'Person',
            );
        }

        $student_id = get_query_var('academic_student_id');
        if (!$student_id) {
            return false;
        }

        $student = self::get_student_by_id($student_id);
        if (!$student) {
            return false;
        }

        $image_url = '';
        if (!empty($student['image'])) {
            $image_id = self::get_image_id($student['image']);
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        }

        return array(
            'title' => $student['name'],
            'description' => !empty($student['bio']) ? wp_trim_words(wp_strip_all_tags($student['bio']), 32, '') : wp_strip_all_tags(get_bloginfo('description')),
            'url' => self::get_student_profile_url($student['student_id']),
            'image' => $image_url,
            'schema_type' => 'Person',
        );
    }

    public static function render_virtual_profile_meta() {
        if (is_admin() || !self::is_virtual_profile_request() || is_404()) {
            return;
        }

        $meta = self::get_virtual_profile_meta_data();
        if (!$meta || empty($meta['url'])) {
            return;
        }

        $title = !empty($meta['title']) ? $meta['title'] : get_bloginfo('name');
        $description = !empty($meta['description']) ? $meta['description'] : get_bloginfo('description');
        $description = wp_trim_words(wp_strip_all_tags($description), 34, '');
        ?>
        <link rel="canonical" href="<?php echo esc_url($meta['url']); ?>">
        <meta name="description" content="<?php echo esc_attr($description); ?>">
        <meta property="og:type" content="profile">
        <meta property="og:title" content="<?php echo esc_attr($title); ?>">
        <meta property="og:description" content="<?php echo esc_attr($description); ?>">
        <meta property="og:url" content="<?php echo esc_url($meta['url']); ?>">
        <meta name="twitter:card" content="<?php echo !empty($meta['image']) ? 'summary_large_image' : 'summary'; ?>">
        <meta name="twitter:title" content="<?php echo esc_attr($title); ?>">
        <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">
        <?php if (!empty($meta['image'])) : ?>
            <meta property="og:image" content="<?php echo esc_url($meta['image']); ?>">
            <meta name="twitter:image" content="<?php echo esc_url($meta['image']); ?>">
        <?php endif; ?>
        <?php
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => !empty($meta['schema_type']) ? $meta['schema_type'] : 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $meta['url'],
        );
        if (!empty($meta['image'])) {
            $schema['image'] = $meta['image'];
        }
        if ($schema['@type'] === 'Person') {
            $schema['affiliation'] = array(
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
            );
        }
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    public static function is_virtual_profile_request() {
        return (bool) (get_query_var('academic_directory_home') || get_query_var('academic_pi_profile') || get_query_var('academic_student_id'));
    }

    public static function is_directory_shortcode_page() {
        if (is_admin() || !is_singular()) {
            return false;
        }

        $post = get_post();

        return $post && has_shortcode($post->post_content, 'student_list');
    }

    public static function filter_virtual_profile_template($template) {
        if ((!self::is_virtual_profile_request() && !self::is_directory_shortcode_page()) || is_404()) {
            return $template;
        }

        $profile_template = self::get_template_path('virtual-profile-page.php');

        return $profile_template ? $profile_template : $template;
    }

    public static function get_profile_page_settings() {
        $defaults = array(
            'hero_enabled' => '0',
            'hero_image' => '',
            'hero_title_size' => '4rem',
        );
        $settings = get_option(self::$profile_settings_option, array());

        return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
    }

    public static function save_profile_page_settings_from_admin($data) {
        $title_size = isset($data['profile_hero_title_size']) ? sanitize_text_field(wp_unslash($data['profile_hero_title_size'])) : '4rem';
        $title_size = trim($title_size);

        if ($title_size !== '' && preg_match('/^\d+(\.\d+)?$/', $title_size)) {
            $title_size .= 'rem';
        }

        if ($title_size === '' || !preg_match('/^\d+(\.\d+)?(px|rem|em)$/', $title_size)) {
            $title_size = '4rem';
        }

        $settings = array(
            'hero_enabled' => !empty($data['profile_hero_enabled']) ? '1' : '0',
            'hero_image' => isset($data['profile_hero_image']) ? sanitize_text_field(wp_unslash($data['profile_hero_image'])) : '',
            'hero_title_size' => $title_size,
        );

        update_option(self::$profile_settings_option, $settings, false);

        return true;
    }

    public static function get_route_hero_title() {
        if (get_query_var('academic_directory_home')) {
            return 'Research Group';
        }

        if (get_query_var('academic_pi_profile')) {
            return 'Principal Investigator';
        }

        $student_id = get_query_var('academic_student_id');

        if ($student_id) {
            $student = self::get_student_by_id($student_id);

            if (!$student || empty($student['category'])) {
                return 'Researcher';
            }

            $category = strtolower($student['category']);

            if (strpos($category, 'post') !== false && strpos($category, 'doc') !== false) {
                return 'Postdoctoral Researcher';
            }

            if (
                strpos($category, 'ph') !== false ||
                strpos($category, 'm.sc') !== false ||
                strpos($category, 'msc') !== false ||
                strpos($category, 'master') !== false
            ) {
                return 'Graduate Researcher';
            }

            if (
                strpos($category, 'b.sc') !== false ||
                strpos($category, 'bsc') !== false ||
                strpos($category, 'bachelor') !== false ||
                strpos($category, 'undergraduate') !== false ||
                strpos($category, 'visiting') !== false ||
                strpos($category, 'high school') !== false ||
                $category === 'student'
            ) {
                return 'Undergraduate Researcher';
            }

            return 'Researcher';
        }

        $page_title = get_the_title();

        return $page_title !== '' ? $page_title : 'Research Group';
    }

    public static function render_virtual_profile_hero() {
        $settings = self::get_profile_page_settings();
        $theme_options = get_option('faculty_theme_options', array());

        if (is_array($theme_options)) {
            if (!empty($theme_options['page_hero_image'])) {
                $settings['hero_enabled'] = '1';
                $settings['hero_image'] = $theme_options['page_hero_image'];
            }

            if (!empty($theme_options['page_hero_title_size'])) {
                $settings['hero_title_size'] = $theme_options['page_hero_title_size'];
            }
        }

        if (empty($settings['hero_enabled']) || empty($settings['hero_image'])) {
            return '';
        }

        $image = trim($settings['hero_image']);
        $image_url = filter_var($image, FILTER_VALIDATE_URL) ? esc_url_raw($image) : '';

        if ($image_url === '') {
            $image_id = self::get_image_id($image);
            if ($image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'full');
            }
        }

        if (!$image_url) {
            return '';
        }

        $title = self::get_route_hero_title();
        $title_size = !empty($settings['hero_title_size']) ? $settings['hero_title_size'] : '4rem';

        ob_start();
        ?>
        <section class="academic-route-hero" style="background-image: url('<?php echo esc_url($image_url); ?>'); --academic-route-title-size: <?php echo esc_attr($title_size); ?>;">
            <div class="academic-route-hero-overlay"></div>
            <div class="academic-route-hero-inner">
                <h1><?php echo esc_html($title); ?></h1>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function render_student_profile_content($student) {
        return self::render_template('student-profile-content.php', array(
            'student' => $student,
        ));
    }

    public static function render_pi_profile_content($pi) {
        return self::render_template('pi-profile-content.php', array(
            'pi' => $pi,
        ));
    }

    public static function save_pi_from_admin($data, $filename = 'data/principal-investigator.csv') {
        $headers = array('name', 'title', 'department', 'institution', 'email', 'phone', 'office', 'website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url', 'link_order', 'vacancies_enabled', 'vacancies_label', 'vacancies_title', 'vacancies_text', 'vacancies_button_text', 'vacancies_button_url', 'image', 'short_bio', 'full_bio', 'education', 'professional_experience', 'honors_awards', 'useful_title', 'useful_text', 'research_interests');

        $row = array();
        foreach ($headers as $header) {
            $value = isset($data[$header]) ? wp_unslash($data[$header]) : '';

            if ($header === 'email') {
                $row[$header] = sanitize_email($value);
            } elseif (in_array($header, array('website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url', 'vacancies_button_url'), true)) {
                $row[$header] = esc_url_raw($value);
            } elseif (in_array($header, array('full_bio', 'education', 'professional_experience', 'honors_awards', 'vacancies_text', 'useful_text'), true)) {
                $row[$header] = wp_kses_post($value);
            } elseif (in_array($header, array('short_bio', 'research_interests'), true)) {
                $row[$header] = sanitize_textarea_field($value);
            } else {
                $row[$header] = sanitize_text_field($value);
            }
        }

        $saved = self::write_csv_rows($filename, $headers, array($row));
        self::flush_directory_cache();

        return $saved;
    }

    public static function save_pi_partial_from_admin($data, $fields, $filename = 'data/principal-investigator.csv') {
        $pi = self::get_pi_for_admin($filename);
        if (isset($pi['research_interests']) && is_array($pi['research_interests'])) {
            $pi['research_interests'] = implode('; ', $pi['research_interests']);
        }

        foreach ((array) $fields as $field) {
            if (array_key_exists($field, $data)) {
                $pi[$field] = wp_unslash($data[$field]);
            } elseif ($field === 'vacancies_enabled') {
                $pi[$field] = '';
            }
        }

        return self::save_pi_from_admin($pi, $filename);
    }

    public static function save_students_from_admin($data, $filename = 'data/students.csv') {
        $table = self::read_csv_table($filename);
        $headers = self::get_student_csv_headers(!empty($table['headers']) ? $table['headers'] : array());
        $normalized_headers = self::normalize_headers($headers);
        $rows = array();

        if (empty($data['students']) || !is_array($data['students'])) {
            $saved = self::write_csv_rows($filename, $headers, array());
            self::flush_directory_cache();
            return $saved;
        }

        foreach ($data['students'] as $student) {
            if (!is_array($student)) continue;

            $row = array();
            foreach ($headers as $index => $header) {
                $normalized_header = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
                $value = isset($student[$normalized_header]) ? wp_unslash($student[$normalized_header]) : '';

                if (in_array($normalized_header, array('email', 'secondary_email'), true)) {
                    $row[$header] = sanitize_email($value);
                } elseif (in_array($normalized_header, array('website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'researchgate', 'cv_url', 'cv'), true)) {
                    $row[$header] = esc_url_raw($value);
                } elseif ($normalized_header === 'bio') {
                    $row[$header] = wp_kses_post($value);
                } elseif (in_array($normalized_header, array('research_interests', 'hobbies'), true)) {
                    $row[$header] = sanitize_textarea_field($value);
                } else {
                    $row[$header] = sanitize_text_field($value);
                }
            }

            $name_key = array_search('name', $normalized_headers, true);
            $email_key = array_search('email', $normalized_headers, true);
            $category_key = array_search('category', $normalized_headers, true);
            $name_value = $name_key !== false && isset($headers[$name_key], $row[$headers[$name_key]]) ? $row[$headers[$name_key]] : '';
            $email_value = $email_key !== false && isset($headers[$email_key], $row[$headers[$email_key]]) ? $row[$headers[$email_key]] : '';
            $category_value = $category_key !== false && isset($headers[$category_key], $row[$headers[$category_key]]) ? $row[$headers[$category_key]] : '';

            if ($name_value === '' && $email_value === '' && $category_value === '') {
                continue;
            }

            $rows[] = $row;
        }

        $saved = self::write_csv_rows($filename, $headers, $rows);
        self::flush_directory_cache();

        return $saved;
    }

    public static function save_student_from_admin($data, $filename = 'data/students.csv') {
        $table = self::read_csv_table($filename);
        $headers = self::get_student_csv_headers(!empty($table['headers']) ? $table['headers'] : array());
        $normalized_headers = self::normalize_headers($headers);
        $existing_rows = !empty($table['rows']) ? $table['rows'] : array();
        $rows = array();

        foreach ($existing_rows as $existing_row) {
            $row = array();
            foreach ($headers as $index => $header) {
                $normalized_header = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
                $row[$header] = isset($existing_row[$normalized_header]) ? $existing_row[$normalized_header] : '';
            }
            $rows[] = $row;
        }

        $student_index = isset($data['student_index']) && $data['student_index'] !== 'new' ? absint($data['student_index']) : count($rows);
        $student_data = isset($data['student']) && is_array($data['student']) ? $data['student'] : array();
        $row = array();

        foreach ($headers as $index => $header) {
            $normalized_header = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
            $value = isset($student_data[$normalized_header]) ? wp_unslash($student_data[$normalized_header]) : '';

            if ($normalized_header === 'student_id' && trim($value) === '') {
                $name_value = isset($student_data['name']) ? wp_unslash($student_data['name']) : '';
                $email_value = isset($student_data['email']) ? wp_unslash($student_data['email']) : '';
                $value = $email_value !== '' ? $email_value : $name_value;
            }

            if (in_array($normalized_header, array('email', 'secondary_email'), true)) {
                $row[$header] = sanitize_email($value);
            } elseif ($normalized_header === 'student_id') {
                $row[$header] = sanitize_title($value);
            } elseif (in_array($normalized_header, array('website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'researchgate', 'cv_url', 'cv'), true)) {
                $row[$header] = esc_url_raw($value);
            } elseif ($normalized_header === 'bio') {
                $row[$header] = wp_kses_post($value);
            } elseif (in_array($normalized_header, array('research_interests', 'hobbies'), true)) {
                $row[$header] = sanitize_textarea_field($value);
            } else {
                $row[$header] = sanitize_text_field($value);
            }
        }

        $rows[$student_index] = $row;
        ksort($rows);

        $saved = self::write_csv_rows($filename, $headers, $rows);
        $education_saved = self::save_student_education_from_admin(
            $data,
            self::get_student_identifier(self::read_csv_table_row_as_normalized($headers, $row)),
            'data/student-education.csv',
            isset($data['previous_student_id']) ? sanitize_title(wp_unslash($data['previous_student_id'])) : ''
        );
        self::flush_directory_cache();

        return array(
            'saved' => $saved && $education_saved,
            'student_index' => $student_index,
        );
    }

    public static function save_student_profile_submission($student_id, $profile, $education) {
        $student_id = sanitize_title($student_id);
        $table = self::read_csv_table('data/students.csv');
        $headers = self::get_student_csv_headers(!empty($table['headers']) ? $table['headers'] : array());
        $normalized_headers = self::normalize_headers($headers);
        $rows = array();
        $found = false;
        $allowed_fields = array(
            'name',
            'secondary_email',
            'website',
            'linkedin',
            'github',
            'google_scholar',
            'orcid',
            'research_gate',
            'researchgate',
            'cv_url',
            'cv',
            'bio',
            'pronoun',
            'research_interests',
            'hobbies',
            'current_position',
            'position_updated',
            'image',
        );

        foreach ($table['rows'] as $existing) {
            $row = array();
            $existing_id = self::get_student_identifier($existing);

            foreach ($headers as $index => $header) {
                $key = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
                $value = isset($existing[$key]) ? $existing[$key] : '';

                if ($existing_id === $student_id && in_array($key, $allowed_fields, true) && array_key_exists($key, $profile)) {
                    $submitted = isset($profile[$key]) ? wp_unslash($profile[$key]) : '';

                    if ($key === 'secondary_email') {
                        $value = sanitize_email($submitted);
                    } elseif (in_array($key, array('website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'researchgate', 'cv_url', 'cv'), true)) {
                        $value = esc_url_raw($submitted);
                    } elseif ($key === 'bio') {
                        $value = wp_kses_post($submitted);
                    } elseif ($key === 'image') {
                        $value = sanitize_file_name($submitted);
                    } elseif (in_array($key, array('research_interests', 'hobbies'), true)) {
                        $value = sanitize_textarea_field($submitted);
                    } else {
                        $value = sanitize_text_field($submitted);
                    }
                }

                $row[$header] = $value;
            }

            if ($existing_id === $student_id) {
                $found = true;
            }

            $rows[] = $row;
        }

        if (!$found) {
            return false;
        }

        $student_saved = self::write_csv_rows('data/students.csv', $headers, $rows);
        $education_saved = self::save_student_education_from_admin(
            array('education' => is_array($education) ? $education : array()),
            $student_id,
            'data/student-education.csv',
            $student_id
        );

        self::flush_directory_cache();

        return $student_saved && $education_saved;
    }

    private static function read_csv_table_row_as_normalized($headers, $row) {
        $normalized_headers = self::normalize_headers($headers);
        $data = array();

        foreach ($headers as $index => $header) {
            $normalized_header = isset($normalized_headers[$index]) ? $normalized_headers[$index] : self::normalize_key($header);
            $data[$normalized_header] = isset($row[$header]) ? $row[$header] : '';
        }

        return $data;
    }

    private static function save_student_education_from_admin($data, $student_id, $filename = 'data/student-education.csv', $previous_student_id = '') {
        $headers = array('Student ID', 'Education Title', 'Institution', 'University Link', 'Start Date', 'End Date');
        $table = self::read_csv_table($filename);
        $rows = array();

        if (!empty($table['rows'])) {
            foreach ($table['rows'] as $existing_row) {
                if (isset($existing_row['student_id']) && in_array($existing_row['student_id'], array($student_id, $previous_student_id), true)) {
                    continue;
                }

                $rows[] = array(
                    'Student ID' => isset($existing_row['student_id']) ? $existing_row['student_id'] : '',
                    'Education Title' => isset($existing_row['education_title']) ? $existing_row['education_title'] : '',
                    'Institution' => isset($existing_row['institution']) ? $existing_row['institution'] : '',
                    'University Link' => isset($existing_row['university_link']) ? $existing_row['university_link'] : '',
                    'Start Date' => isset($existing_row['start_date']) ? $existing_row['start_date'] : '',
                    'End Date' => isset($existing_row['end_date']) ? $existing_row['end_date'] : '',
                );
            }
        }

        $education_items = isset($data['education']) && is_array($data['education']) ? $data['education'] : array();

        foreach ($education_items as $item) {
            if (!is_array($item)) continue;

            if (!empty($item['remove'])) {
                continue;
            }

            $title = isset($item['education_title']) ? sanitize_text_field(wp_unslash($item['education_title'])) : '';
            $institution = isset($item['institution']) ? sanitize_text_field(wp_unslash($item['institution'])) : '';
            $university_link = isset($item['university_link']) ? esc_url_raw(wp_unslash($item['university_link'])) : '';
            $start_date = isset($item['start_date']) ? sanitize_text_field(wp_unslash($item['start_date'])) : '';
            $end_date = isset($item['end_date']) ? sanitize_text_field(wp_unslash($item['end_date'])) : '';

            if ($title === '' && $institution === '' && $start_date === '') {
                continue;
            }

            $rows[] = array(
                'Student ID' => $student_id,
                'Education Title' => $title,
                'Institution' => $institution,
                'University Link' => $university_link,
                'Start Date' => $start_date,
                'End Date' => $end_date,
            );
        }

        usort($rows, function($a, $b) {
            if ($a['Student ID'] === $b['Student ID']) {
                return strnatcasecmp($a['Start Date'], $b['Start Date']);
            }

            return strnatcasecmp($a['Student ID'], $b['Student ID']);
        });

        return self::write_csv_rows($filename, $headers, $rows);
    }

    private static function sort_groups(&$groups, $order) {
        uksort($groups, function($a, $b) use ($order) {
            $a_key = AcademicDirectory::normalize_key($a);
            $b_key = AcademicDirectory::normalize_key($b);
            $a_rank = isset($order['sections'][$a_key]) ? $order['sections'][$a_key] : PHP_INT_MAX;
            $b_rank = isset($order['sections'][$b_key]) ? $order['sections'][$b_key] : PHP_INT_MAX;

            return AcademicDirectory::compare_ranked_names($a_rank, $b_rank, $a, $b);
        });

        foreach ($groups as &$categories) {
            uksort($categories, function($a, $b) use ($order) {
                $a_key = AcademicDirectory::normalize_key($a);
                $b_key = AcademicDirectory::normalize_key($b);
                $a_rank = AcademicDirectory::get_order_rank($a_key, $order['categories']);
                $b_rank = AcademicDirectory::get_order_rank($b_key, $order['categories']);

                return AcademicDirectory::compare_ranked_names($a_rank, $b_rank, $a, $b);
            });

            foreach ($categories as &$students) {
                usort($students, function($a, $b) {
                    $a_rank = !empty($a['rank']) ? $a['rank'] : PHP_INT_MAX;
                    $b_rank = !empty($b['rank']) ? $b['rank'] : PHP_INT_MAX;

                    return AcademicDirectory::compare_ranked_names($a_rank, $b_rank, $a['name'], $b['name']);
                });
            }
            unset($students);
        }
        unset($categories);
    }

    private static function get_template_path($template, $allow_theme_override = true) {
        $template = ltrim((string) $template, '/\\');
        $template = str_replace('\\', '/', $template);
        $template = preg_replace('#(^|/)\.\.(/|$)#', '', $template);

        if ($allow_theme_override) {
            $theme_template = locate_template('academic-directory/' . $template);

            if ($theme_template && file_exists($theme_template)) {
                return apply_filters('academic_directory_template_path', $theme_template, $template, 'theme');
            }
        }

        $path = plugin_dir_path(__FILE__) . 'templates/' . $template;

        if (!file_exists($path)) {
            return false;
        }

        return apply_filters('academic_directory_template_path', $path, $template, 'plugin');
    }

    public static function render_template($template, $vars = array()) {
        $path = self::get_template_path($template);

        if (!$path) {
            return '';
        }

        if (!empty($vars)) {
            extract($vars, EXTR_SKIP);
        }

        ob_start();
        include $path;
        return ob_get_clean();
    }

    public static function render($atts = array()) {
        $atts = shortcode_atts(array(
            'csv' => 'data/students.csv',
            'order_csv' => 'data/student-category-order.csv',
            'pi_csv' => 'data/principal-investigator.csv',
            'education_csv' => 'data/student-education.csv',
            'template' => 'student-directory-page.php',
        ), $atts, 'student_list');

        $template_path = self::get_template_path($atts['template']);
        $groups = array();
        $students = self::get_students($atts['csv'], $atts['education_csv']);

        foreach ($students as $student) {
            if (empty($student['category'])) {
                continue;
            }

            $member_section = self::get_member_section(isset($student['active']) ? $student['active'] : 'y');
            $groups[$member_section][$student['category']][] = $student;
        }

        $order = self::read_order_csv($atts['order_csv']);
        self::sort_groups($groups, $order);
        $principal_investigator = self::read_pi_csv($atts['pi_csv']);

        $output = self::render_template($atts['template'], array(
            'groups' => $groups,
            'principal_investigator' => $principal_investigator,
            'atts' => $atts,
        ));

        if ($output === '') {
            $output = '<!-- Error: Student directory template not found. -->';
        }

        return $output;
    }
}

require_once __DIR__ . '/includes/class-academic-profile-access.php';
AcademicProfileAccess::init();

add_shortcode('student_list', array('AcademicDirectory', 'render'));

class AcademicDirectoryAdmin {

    private static $page_slug = 'academic-faculty-toolkit';
    private static $last_saved_option = 'academic_directory_last_saved';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_post_academic_directory_save_pi', array(__CLASS__, 'save_pi'));
        add_action('admin_post_academic_directory_save_positions', array(__CLASS__, 'save_positions'));
        add_action('admin_post_academic_directory_save_students', array(__CLASS__, 'save_students'));
        add_action('admin_post_academic_directory_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_academic_directory_export_data', array(__CLASS__, 'export_data'));
        add_action('admin_post_academic_directory_restore_backup', array(__CLASS__, 'restore_backup'));
        add_action('admin_post_academic_directory_delete_backup', array(__CLASS__, 'delete_backup'));
    }

    public static function register_menu() {
        add_menu_page(
            'Academic Faculty Toolkit',
            'Faculty Toolkit',
            'manage_options',
            self::$page_slug,
            array(__CLASS__, 'render_page'),
            'dashicons-groups',
            30
        );

        add_submenu_page(
            self::$page_slug,
            'Faculty Toolkit Dashboard',
            'Dashboard',
            'manage_options',
            self::$page_slug,
            array(__CLASS__, 'render_page')
        );

        add_submenu_page(
            self::$page_slug,
            'Faculty Toolkit Setup Guide',
            'Setup Guide',
            'manage_options',
            self::$page_slug . '-setup-guide',
            array(__CLASS__, 'render_setup_guide_page')
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_' . self::$page_slug) {
            return;
        }

        wp_enqueue_editor();
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style(
            'academic-directory-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            array(),
            ACADEMIC_DIRECTORY_VERSION
        );
        wp_enqueue_script(
            'academic-directory-admin',
            plugins_url('assets/js/admin.js', __FILE__),
            array('jquery', 'jquery-ui-sortable'),
            ACADEMIC_DIRECTORY_VERSION,
            true
        );
    }

    private static function get_active_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'pi';
        return in_array($tab, array('pi', 'students', 'positions', 'links', 'settings', 'health', 'email'), true) ? $tab : 'pi';
    }

    private static function render_tabs($active_tab) {
        $tabs = array(
            'pi' => 'PI Profile',
            'students' => 'People',
            'positions' => 'Open Positions',
            'links' => 'Private Links',
            'settings' => 'Ordering & Export',
            'health' => 'Site Health',
            'email' => 'Email',
        );

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            $url = admin_url('admin.php?page=' . self::$page_slug . '&tab=' . $tab);
            $class = $active_tab === $tab ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    private static function render_notice() {
        if (isset($_GET['restored'])) {
            $restored = sanitize_key(wp_unslash($_GET['restored']));
            if ($restored === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>CSV backup restored successfully. The previous current CSV was backed up first.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Could not restore that CSV backup. Please check file permissions and try again.</p></div>';
            }
            return;
        }

        if (isset($_GET['backup_deleted'])) {
            $deleted = sanitize_key(wp_unslash($_GET['backup_deleted']));
            if ($deleted === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>CSV backup deleted successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Could not delete that CSV backup. Please check file permissions and try again.</p></div>';
            }
            return;
        }

        if (empty($_GET['updated'])) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['updated']));

        if ($status === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Saved successfully.</p></div>';
            return;
        }

        if ($status === 'emailed') {
            echo '<div class="notice notice-success is-dismissible"><p>A new private profile link was generated and passed to the WordPress mail system.</p></div>';
            return;
        }

        if ($status === 'revoked') {
            echo '<div class="notice notice-success is-dismissible"><p>The private profile link was revoked.</p></div>';
            return;
        }

        if ($status === 'email_failed') {
            echo '<div class="notice notice-error is-dismissible"><p>WordPress could not send the email. The generated link was not displayed; generate a new copyable link instead.</p></div>';
            return;
        }

        echo '<div class="notice notice-error is-dismissible"><p>Could not save changes. Please check file permissions for the plugin data directory.</p></div>';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = self::get_active_tab();
        ?>
        <div class="wrap academic-directory-admin">
            <h1>Academic Faculty Toolkit</h1>
            <?php $last_saved = get_option(self::$last_saved_option, ''); ?>
            <?php if ($last_saved): ?>
                <p class="description">Last saved: <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_saved))); ?></p>
            <?php endif; ?>
            <?php self::render_notice(); ?>
            <?php self::render_tabs($active_tab); ?>

            <?php if ($active_tab === 'pi'): ?>
                <?php self::render_pi_tab(); ?>
            <?php elseif ($active_tab === 'positions'): ?>
                <?php self::render_positions_tab(); ?>
            <?php elseif ($active_tab === 'students'): ?>
                <?php self::render_students_tab(); ?>
            <?php elseif ($active_tab === 'links'): ?>
                <?php AcademicProfileAccess::render_links_admin(); ?>
            <?php elseif ($active_tab === 'email'): ?>
                <?php AcademicProfileAccess::render_email_settings_admin(); ?>
            <?php elseif ($active_tab === 'health'): ?>
                <?php self::render_site_health_tab(); ?>
            <?php else: ?>
                <?php self::render_settings_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_health_row($label, $ok, $message) {
        ?>
        <tr>
            <td><strong><?php echo esc_html($label); ?></strong></td>
            <td><?php echo $ok ? '<span style="color:#008a20;font-weight:700;">Pass</span>' : '<span style="color:#b32d2e;font-weight:700;">Check</span>'; ?></td>
            <td><?php echo wp_kses_post($message); ?></td>
        </tr>
        <?php
    }

    private static function render_site_health_tab() {
        $theme = wp_get_theme();
        $required_pages = array('Home', 'NEWS', 'Research', 'Courses', 'Gallery', 'Contact');
        $permalink_structure = get_option('permalink_structure');
        $front_page_id = (int) get_option('page_on_front');
        $posts_page_id = (int) get_option('page_for_posts');
        $backup_status = AcademicDirectory::get_backup_status();
        $backup_status_ok = !in_array(false, $backup_status, true);
        $database_status = AcademicDirectory::get_database_status();
        $database_status_ok = !in_array(false, $database_status, true);
        $routes = array(
            '/research-group/' => home_url('/research-group/'),
            '/research-group/PI/' => home_url('/research-group/PI/'),
            '/edit-profile/' => home_url('/edit-profile/'),
        );
        ?>
        <h2>Site Health</h2>
        <p class="description">Quick operational checks for the Faculty Theme + Faculty Toolkit website setup.</p>
        <table class="widefat striped" style="max-width: 960px;">
            <thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead>
            <tbody>
                <?php self::render_health_row('Active theme', $theme->get_stylesheet() === 'faculty-theme', 'Current theme: <code>' . esc_html($theme->get('Name')) . '</code> ' . esc_html($theme->get('Version'))); ?>
                <?php self::render_health_row('Toolkit plugin', true, 'Academic Faculty Toolkit is active. Version <code>' . esc_html(ACADEMIC_DIRECTORY_VERSION) . '</code>.'); ?>
                <?php self::render_health_row('Permalinks', !empty($permalink_structure), !empty($permalink_structure) ? 'Pretty permalinks are enabled.' : 'Go to <a href="' . esc_url(admin_url('options-permalink.php')) . '">Settings &gt; Permalinks</a> and click Save Changes.'); ?>
                <?php self::render_health_row('Database storage', $database_status_ok, $database_status_ok ? 'People data table exists, DB version is current, and CSV seed data has been migrated.' : 'The toolkit will try to repair/create the database table automatically. If this persists, check database permissions.'); ?>
                <?php self::render_health_row('Backup protection', $backup_status_ok, $backup_status_ok ? 'Export/restore backup folders have protection files and are writable.' : 'One or more data-folder protection files could not be created. Check filesystem permissions on <code>plugins/academic-student-directory/data</code>.'); ?>
                <?php self::render_health_row('Static front page', $front_page_id > 0, $front_page_id > 0 ? 'Homepage: <code>' . esc_html(get_the_title($front_page_id)) . '</code>' : 'Go to Settings &gt; Reading and select a homepage.'); ?>
                <?php self::render_health_row('Posts page', $posts_page_id > 0, $posts_page_id > 0 ? 'Posts page: <code>' . esc_html(get_the_title($posts_page_id)) . '</code>' : 'Recommended: set NEWS as the posts page under Settings &gt; Reading.'); ?>
                <?php foreach ($required_pages as $page_title): ?>
                    <?php $page = get_page_by_title($page_title); ?>
                    <?php self::render_health_row('Page: ' . $page_title, (bool) $page, $page ? 'Found page at <a href="' . esc_url(get_permalink($page)) . '">' . esc_html(get_permalink($page)) . '</a>' : 'Create a page named <code>' . esc_html($page_title) . '</code>.'); ?>
                <?php endforeach; ?>
                <?php foreach ($routes as $label => $url): ?>
                    <?php self::render_health_row('Route: ' . $label, !empty($permalink_structure), '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>'); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function render_setup_guide_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap academic-directory-admin academic-directory-help">
            <h1>Faculty Toolkit Setup Guide</h1>
            <p class="description">A practical in-admin guide for initializing and maintaining PI/student profile data. The main dashboard is under Faculty Toolkit &gt; Dashboard.</p>

            <style>
                .academic-directory-help-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-top: 1.25rem; }
                .academic-directory-help-card { padding: 1rem 1.15rem; border: 1px solid #dcdcde; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
                .academic-directory-help-card h2 { margin-top: 0; }
                .academic-directory-help-card ol, .academic-directory-help-card ul { padding-left: 1.3rem; }
                .academic-directory-help-callout { margin-top: 1rem; padding: 1rem 1.15rem; border-left: 4px solid #BE0000; background: #fff; }
                .academic-directory-help-code { display: inline-block; padding: .1rem .35rem; background: #f0f0f1; font-family: Consolas, Monaco, monospace; }
            </style>

            <div class="academic-directory-help-callout">
                <h2>What this plugin controls</h2>
                <p>Use Faculty Toolkit for people data: PI information, student/member records, education records, private profile-edit links, CSV exports, and the automatic Research Group directory route.</p>
                <p>Use Faculty Theme for appearance: homepage, page headers, colors, logos, contact page, research page, gallery, footer, and slideshow.</p>
            </div>

            <div class="academic-directory-help-grid">
                <section class="academic-directory-help-card">
                    <h2>1. Required site setup</h2>
                    <ol>
                        <li>Activate the Faculty Theme.</li>
                        <li>Activate Academic Faculty Toolkit.</li>
                        <li>Go to Settings &gt; Permalinks and click Save Changes once.</li>
                        <li>Create normal pages such as Home, NEWS, Research, Gallery, and Contact.</li>
                        <li>Do not create a normal WordPress page at <span class="academic-directory-help-code">/research-group/</span>.</li>
                    </ol>
                </section>

                <section class="academic-directory-help-card">
                    <h2>2. Initialize the PI</h2>
                    <ol>
                        <li>Open Faculty Toolkit &gt; Dashboard &gt; PI Profile.</li>
                        <li>Enter name, title, department, institution, contact details, and links.</li>
                        <li>Select a profile image from the Media Library.</li>
                        <li>Add short bio for the Research Group page.</li>
                        <li>Add useful PI-page resources if desired.</li>
                        <li>Add full PI page content: biography, education, professional experience, honors, and research interests.</li>
                        <li>Save changes.</li>
                    </ol>
                    <p>The PI profile is generated automatically at <span class="academic-directory-help-code">/research-group/PI/</span>.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>3. Configure open positions</h2>
                    <ol>
                        <li>Open Faculty Toolkit &gt; Dashboard &gt; Open Positions.</li>
                        <li>Turn the section on or off.</li>
                        <li>Add the title and rich text announcement.</li>
                        <li>Use the button URL to link to a FAQ post or contact page.</li>
                    </ol>
                    <p>The callout appears below the PI card on <span class="academic-directory-help-code">/research-group/</span>.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>4. Add students and members</h2>
                    <ol>
                        <li>Open Faculty Toolkit &gt; Dashboard &gt; People.</li>
                        <li>Add each person with a stable profile slug.</li>
                        <li>Choose category and active/past status.</li>
                        <li>Add profile image, bio, research interests, education, and current position where relevant.</li>
                        <li>Save changes.</li>
                    </ol>
                    <p>Each student profile is generated automatically from the profile slug, for example <span class="academic-directory-help-code">/research-group/jane-doe/</span>.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>5. Private profile links</h2>
                    <ol>
                        <li>Open Faculty Toolkit &gt; Dashboard &gt; Private Links.</li>
                        <li>Generate a private link for a student.</li>
                        <li>Copy it manually or send it by email if WordPress mail is configured.</li>
                        <li>Students can edit approved public fields without having WordPress accounts.</li>
                    </ol>
                    <p>Primary administrative fields remain controlled by the administrator.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>6. Email and settings</h2>
                    <ul>
                        <li>Email Settings: configure sender, subject, message, and link expiry.</li>
                        <li>Settings: download CSV exports/backups and restore from automatic safety backups when needed.</li>
                        <li>Settings: edit category order, pronoun options, and education-title options.</li>
                    </ul>
                    <p>Useful email placeholders: <span class="academic-directory-help-code">{student_name}</span>, <span class="academic-directory-help-code">{edit_link}</span>, <span class="academic-directory-help-code">{site_name}</span>, <span class="academic-directory-help-code">{expires_at}</span>.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>7. Quick tests</h2>
                    <ul>
                        <li><span class="academic-directory-help-code">/research-group/</span> shows the directory.</li>
                        <li><span class="academic-directory-help-code">/research-group/PI/</span> shows the PI profile.</li>
                        <li>A student card opens its profile page.</li>
                        <li>A generated private edit link opens the edit form.</li>
                        <li>Site Health reports the active theme, permalink status, expected routes, and required pages.</li>
                    </ul>
                    <p>If routes show 404, go to Settings &gt; Permalinks and click Save Changes.</p>
                </section>

                <section class="academic-directory-help-card">
                    <h2>Producer / maintenance info</h2>
                    <p><strong>Plugin:</strong> Academic Faculty Toolkit 3.7</p>
                    <p><strong>Author:</strong> Soroosh Noorzad</p>
                    <p><strong>Designed for:</strong> MEDAL Research Group academic people-directory workflows.</p>
                    <p>Keep people/profile data here. Keep visual and page-layout settings in Faculty Theme.</p>
                </section>
            </div>
        </div>
        <?php
    }

    private static function render_pi_tab() {
        $pi = AcademicDirectory::get_pi_for_admin();
        $fields = array(
            'Identity' => array(
                'name' => 'Name',
                'title' => 'Title',
                'department' => 'Department',
                'institution' => 'Institution',
                'image' => 'Image',
            ),
            'Contact and Links' => array(
                'email' => 'Email',
                'phone' => 'Phone',
                'office' => 'Office',
                'website' => 'Website',
                'linkedin' => 'LinkedIn URL',
                'github' => 'GitHub URL',
                'google_scholar' => 'Google Scholar URL',
                'orcid' => 'ORCID URL',
                'research_gate' => 'ResearchGate URL',
                'cv_url' => 'CV URL',
                'link_order' => 'Link display order',
            ),
            'Biography' => array(
                'short_bio' => 'Short Bio',
                'full_bio' => 'Full Bio',
                'research_interests' => 'Research Interests',
                'education' => 'Education',
                'professional_experience' => 'Professional Experience',
                'honors_awards' => 'Honors and Awards',
                'useful_title' => 'Useful section title',
                'useful_text' => 'Useful section content',
            ),
        );

        $textarea_fields = array('short_bio', 'research_interests', 'vacancies_text');
        $rich_editor_fields = array('full_bio', 'education', 'professional_experience', 'honors_awards', 'useful_text');
        $url_fields = array('website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url', 'vacancies_button_url');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_directory_save_pi'); ?>
            <input type="hidden" name="action" value="academic_directory_save_pi">

            <?php foreach ($fields as $section_title => $section_fields): ?>
                <h2><?php echo esc_html($section_title); ?></h2>
                <table class="form-table" role="presentation">
                    <?php foreach ($section_fields as $key => $label): ?>
                        <?php
                        $value = isset($pi[$key]) ? $pi[$key] : '';
                        if ($key === 'research_interests' && is_array($value)) {
                            $value = implode('; ', $value);
                        }
                        ?>
                        <tr>
                            <th scope="row"><label for="academic-pi-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <?php if ($key === 'link_order'): ?>
                                    <?php $link_order = AcademicDirectory::get_pi_link_order($pi, true, true); ?>
                                    <?php $definitions = AcademicDirectory::get_pi_link_definitions(); ?>
                                    <input type="hidden" id="academic-pi-link-order" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(implode(',', $link_order)); ?>">
                                    <ul class="academic-sortable-list academic-pi-link-order" data-order-target="#academic-pi-link-order">
                                        <?php foreach ($link_order as $link_key): ?>
                                            <?php if (!isset($definitions[$link_key])) continue; ?>
                                            <li data-link-key="<?php echo esc_attr($link_key); ?>"><span class="academic-sort-handle" aria-hidden="true">↕</span><?php echo esc_html($definitions[$link_key]['label']); ?> <code><?php echo esc_html($link_key); ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                        <p class="description">Drag to set the display order. The PI page ignores Profile; the Research Group PI card uses it. Empty links are skipped automatically.</p>
                                <?php elseif ($key === 'image'): ?>
                                    <div class="media-field">
                                        <input
                                            class="regular-text"
                                            id="academic-pi-<?php echo esc_attr($key); ?>"
                                            name="<?php echo esc_attr($key); ?>"
                                            type="text"
                                            value="<?php echo esc_attr($value); ?>"
                                        >
                                        <button type="button" class="button academic-media-select" data-target="#academic-pi-<?php echo esc_attr($key); ?>">Select</button>
                                    </div>
                                    <p class="description">Select or upload a PI image from the WordPress Media Library.</p>
                                <?php elseif (in_array($key, $rich_editor_fields, true)): ?>
                                    <?php
                                    wp_editor(
                                        $value,
                                        'academic_pi_' . $key,
                                        array(
                                            'textarea_name' => $key,
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => false,
                                            'quicktags' => true,
                                        )
                                    );
                                    ?>
                                    <?php if ($key === 'full_bio'): ?>
                                        <p class="description">Used on the full PI biography page. Supports paragraphs, links, bold text, and lists.</p>
                                    <?php elseif ($key === 'useful_text'): ?>
                                        <p class="description">Shown as a Useful Links / Resources section on the PI page. Add links, bullets, and short guidance for students or visitors.</p>
                                    <?php else: ?>
                                        <p class="description">Supports headings, links, bullets, and nested lists.</p>
                                    <?php endif; ?>
                                <?php elseif (in_array($key, $textarea_fields, true)): ?>
                                    <textarea
                                        class="large-text"
                                        id="academic-pi-<?php echo esc_attr($key); ?>"
                                        name="<?php echo esc_attr($key); ?>"
                                        rows="<?php echo esc_attr($key === 'short_bio' || $key === 'research_interests' ? 4 : 7); ?>"
                                    ><?php echo esc_textarea($value); ?></textarea>
                                    <?php if ($key === 'short_bio'): ?>
                                        <p class="description">Used on the research group page PI section.</p>
                                    <?php elseif ($key === 'research_interests'): ?>
                                        <p class="description">Separate interests with semicolons.</p>
                                    <?php else: ?>
                                        <p class="description">Use one item per line for easier future display.</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <input
                                        class="regular-text"
                                        id="academic-pi-<?php echo esc_attr($key); ?>"
                                        name="<?php echo esc_attr($key); ?>"
                                        type="<?php echo esc_attr(in_array($key, $url_fields, true) ? 'url' : ($key === 'email' ? 'email' : 'text')); ?>"
                                        value="<?php echo esc_attr($value); ?>"
                                    >
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>

            <?php submit_button('Save PI Information'); ?>
        </form>
        <?php
    }

    private static function render_positions_tab() {
        $pi = AcademicDirectory::get_pi_for_admin();
        ?>
        <h2>Open Positions</h2>
        <p class="description">Manage the recruiting / vacancies callout shown below the PI card on the Research Group page. Use the button URL to link to a FAQ post if you want more detailed guidance.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_directory_save_positions'); ?>
            <input type="hidden" name="action" value="academic_directory_save_positions">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Display</th>
                    <td><label><input type="checkbox" name="vacancies_enabled" value="1" <?php checked(!empty($pi['vacancies_enabled']), true); ?>> Show open positions on the Research Group page</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="academic-vacancies-label">Small label</label></th>
                    <td><input class="regular-text" id="academic-vacancies-label" name="vacancies_label" value="<?php echo esc_attr(!empty($pi['vacancies_label']) ? $pi['vacancies_label'] : 'Open positions'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="academic-vacancies-title">Title</label></th>
                    <td><input class="large-text" id="academic-vacancies-title" name="vacancies_title" value="<?php echo esc_attr(!empty($pi['vacancies_title']) ? $pi['vacancies_title'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="academic-vacancies-text">Main text</label></th>
                    <td>
                        <?php
                        wp_editor(
                            !empty($pi['vacancies_text']) ? $pi['vacancies_text'] : '',
                            'academic_vacancies_text',
                            array(
                                'textarea_name' => 'vacancies_text',
                                'textarea_rows' => 8,
                                'media_buttons' => false,
                                'teeny' => false,
                                'quicktags' => true,
                            )
                        );
                        ?>
                        <p class="description">Supports paragraphs, links, bold text, and bullet lists.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Button</th>
                    <td>
                        <label>Label <input name="vacancies_button_text" value="<?php echo esc_attr(!empty($pi['vacancies_button_text']) ? $pi['vacancies_button_text'] : 'Contact us'); ?>"></label>
                        <label>URL <input class="regular-text" type="url" name="vacancies_button_url" value="<?php echo esc_url(!empty($pi['vacancies_button_url']) ? $pi['vacancies_button_url'] : ''); ?>"></label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Open Positions'); ?>
        </form>
        <?php
    }

    private static function render_students_tab() {
        $student_table = AcademicDirectory::get_students_for_admin();
        $headers = AcademicDirectory::get_student_csv_headers(!empty($student_table['headers']) ? $student_table['headers'] : array());
        $normalized_headers = AcademicDirectory::normalize_headers($headers);
        $students = !empty($student_table['rows']) ? $student_table['rows'] : array();

        if (isset($_GET['student_index'])) {
            self::render_student_edit_form($headers, $normalized_headers, $students);
            return;
        }
        ?>
        <div class="student-edit-actions">
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::$page_slug . '&tab=students&student_index=new')); ?>">Add Student</a>
        </div>

        <table class="widefat striped student-list-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Email</th>
                    <th>Image</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6">No students found.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($students as $index => $student): ?>
                    <?php
                    $name = isset($student['name']) && $student['name'] !== '' ? $student['name'] : 'Untitled student';
                    $edit_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=students&student_index=' . $index);
                    $active = isset($student['active']) ? strtolower($student['active']) : '';
                    ?>
                    <tr>
                        <td class="student-name"><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($name); ?></a></td>
                        <td><?php echo esc_html(isset($student['category']) ? $student['category'] : ''); ?></td>
                        <td><?php echo esc_html(in_array($active, array('y', 'yes', 'active', '1', 'true'), true) ? 'Current' : 'Past'); ?></td>
                        <td><?php echo esc_html(isset($student['email']) ? $student['email'] : ''); ?></td>
                        <td><?php echo esc_html(isset($student['image']) ? $student['image'] : ''); ?></td>
                        <td><a class="button" href="<?php echo esc_url($edit_url); ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_year_options($selected, $include_present = false) {
        $selected = (string) $selected;

        if ($include_present) {
            echo '<option value="" ' . selected($selected, '', false) . '>Present</option>';
        } else {
            echo '<option value="" ' . selected($selected, '', false) . '>Select year</option>';
        }

        $current_year = (int) wp_date('Y') + 6;
        for ($year = $current_year; $year >= 1950; $year--) {
            echo '<option value="' . esc_attr((string) $year) . '" ' . selected($selected, (string) $year, false) . '>' . esc_html((string) $year) . '</option>';
        }
    }

    private static function render_student_edit_form($headers, $normalized_headers, $students) {
        $raw_index = isset($_GET['student_index']) ? sanitize_text_field(wp_unslash($_GET['student_index'])) : 'new';
        $is_new = $raw_index === 'new';
        $student_index = $is_new ? 'new' : absint($raw_index);
        $student = (!$is_new && isset($students[$student_index])) ? $students[$student_index] : array();
        $student_id = isset($student['student_id']) ? $student['student_id'] : '';
        $back_url = admin_url('admin.php?page=' . self::$page_slug . '&tab=students');
        $category_options = AcademicDirectory::get_category_options_for_admin();
        $pronoun_options = AcademicDirectory::get_pronoun_options_for_admin();
        $education_title_options = AcademicDirectory::get_education_title_options_for_admin();
        $education_by_student = AcademicDirectory::get_student_education_for_admin();
        $education_items = !$is_new && $student_id !== '' && isset($education_by_student[$student_id]) ? $education_by_student[$student_id] : array();
        $student_link_fields = array_keys(AcademicDirectory::get_student_link_fields());
        $groups = array(
            'Profile' => array('name', 'category', 'active', 'image', 'pronoun'),
            'Contact' => array('email', 'secondary_email', 'website', 'linkedin', 'github', 'google_scholar', 'orcid', 'research_gate', 'cv_url'),
            'Academic' => array('date_of_entry', 'research_interests'),
            'Past Member Placement' => array('current_position', 'position_updated'),
            'Personal' => array('bio', 'hobbies'),
        );
        ?>
        <div class="student-edit-actions">
            <a class="button" href="<?php echo esc_url($back_url); ?>">Back to Students</a>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_directory_save_students'); ?>
            <input type="hidden" name="action" value="academic_directory_save_students">
            <input type="hidden" name="student_index" value="<?php echo esc_attr($student_index); ?>">
            <input type="hidden" name="previous_student_id" value="<?php echo esc_attr($student_id); ?>">
            <input type="hidden" name="student[student_id]" value="<?php echo esc_attr($student_id); ?>">

            <div class="student-edit-grid">
                <?php foreach ($groups as $group_title => $group_fields): ?>
                    <div class="student-edit-panel">
                        <h3><?php echo esc_html($group_title); ?></h3>

                        <?php foreach ($group_fields as $field): ?>
                            <?php
                            $header_index = array_search($field, $normalized_headers, true);
                            if ($header_index === false) continue;

                            $label = isset($headers[$header_index]) ? $headers[$header_index] : $field;
                            $value = isset($student[$field]) ? $student[$field] : '';
                            ?>
                            <label for="student-<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label>

                            <?php if ($field === 'active'): ?>
                                <select id="student-<?php echo esc_attr($field); ?>" name="student[<?php echo esc_attr($field); ?>]">
                                    <option value="y" <?php selected(strtolower($value), 'y'); ?>>Current</option>
                                    <option value="n" <?php selected(strtolower($value), 'n'); ?>>Past</option>
                                </select>
                            <?php elseif ($field === 'category'): ?>
                                <select id="student-<?php echo esc_attr($field); ?>" name="student[<?php echo esc_attr($field); ?>]">
                                    <option value="">Select category</option>
                                    <?php foreach ($category_options as $category_value => $category_label): ?>
                                        <option value="<?php echo esc_attr($category_value); ?>" <?php selected($value, $category_value); ?>><?php echo esc_html($category_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field === 'pronoun'): ?>
                                <select id="student-<?php echo esc_attr($field); ?>" name="student[<?php echo esc_attr($field); ?>]">
                                    <option value="">Select pronouns</option>
                                    <?php foreach ($pronoun_options as $pronoun_value => $pronoun_label): ?>
                                        <option value="<?php echo esc_attr($pronoun_value); ?>" <?php selected($value, $pronoun_value); ?>><?php echo esc_html($pronoun_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field === 'image'): ?>
                                <div class="media-field">
                                    <input id="student-<?php echo esc_attr($field); ?>" type="text" name="student[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($value); ?>">
                                    <button type="button" class="button academic-media-select" data-target="#student-<?php echo esc_attr($field); ?>">Select</button>
                                </div>
                            <?php elseif ($field === 'bio'): ?>
                                <div class="student-bio-editor-wrap">
                                    <?php
                                    wp_editor(
                                        $value,
                                        'student_bio_editor',
                                        array(
                                            'textarea_name' => 'student[bio]',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => false,
                                            'quicktags' => true,
                                        )
                                    );
                                    ?>
                                </div>
                            <?php elseif (in_array($field, array('research_interests', 'hobbies'), true)): ?>
                                <textarea id="student-<?php echo esc_attr($field); ?>" name="student[<?php echo esc_attr($field); ?>]" rows="5"><?php echo esc_textarea($value); ?></textarea>
                            <?php elseif (in_array($field, $student_link_fields, true)): ?>
                                <input id="student-<?php echo esc_attr($field); ?>" type="url" name="student[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($value); ?>" placeholder="https://">
                            <?php else: ?>
                                <input id="student-<?php echo esc_attr($field); ?>" type="text" name="student[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($value); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="student-edit-panel student-education-panel">
                    <h3>Education</h3>

                    <div class="education-edit-form">
                        <input type="hidden" id="education-edit-index" value="">

                        <h4 class="education-form-title wide">Add Education</h4>

                        <div>
                            <label for="education-form-title">Title</label>
                            <select id="education-form-title">
                                <option value="">Select title</option>
                                <?php foreach ($education_title_options as $title_value => $title_label): ?>
                                    <option value="<?php echo esc_attr($title_value); ?>"><?php echo esc_html($title_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="education-form-institution">Institution</label>
                            <input id="education-form-institution" type="text" value="">
                        </div>

                        <div>
                            <label for="education-form-university-link">University Link</label>
                            <input id="education-form-university-link" type="url" value="">
                        </div>

                        <div>
                            <label for="education-form-start">Start Year</label>
                            <select id="education-form-start"><?php self::render_year_options(''); ?></select>
                        </div>

                        <div>
                            <label for="education-form-end">End Year</label>
                            <select id="education-form-end"><?php self::render_year_options('', true); ?></select>
                        </div>

                        <div class="wide">
                            <button type="button" class="button button-secondary academic-save-education">Save Education</button>
                        </div>
                    </div>

                    <table class="widefat striped student-education-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Institution</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody data-next-index="<?php echo esc_attr(count($education_items)); ?>">
                            <?php foreach ($education_items as $education_index => $education): ?>
                                <?php $education = wp_parse_args($education, array('education_title' => '', 'institution' => '', 'university_link' => '', 'start_date' => '', 'end_date' => '')); ?>
                                <tr data-education-index="<?php echo esc_attr($education_index); ?>">
                                    <td><?php echo esc_html($education['education_title']); ?></td>
                                    <td><?php echo esc_html($education['institution']); ?></td>
                                    <td><?php echo esc_html($education['start_date']); ?></td>
                                    <td><?php echo esc_html($education['end_date'] !== '' ? $education['end_date'] : 'Present'); ?></td>
                                    <td>
                                        <button type="button" class="button academic-edit-education">Edit</button>
                                        <button type="button" class="button academic-remove-education">Remove</button>
                                        <input type="hidden" name="education[<?php echo esc_attr($education_index); ?>][education_title]" value="<?php echo esc_attr($education['education_title']); ?>">
                                        <input type="hidden" name="education[<?php echo esc_attr($education_index); ?>][institution]" value="<?php echo esc_attr($education['institution']); ?>">
                                        <input type="hidden" name="education[<?php echo esc_attr($education_index); ?>][university_link]" value="<?php echo esc_attr($education['university_link']); ?>">
                                        <input type="hidden" name="education[<?php echo esc_attr($education_index); ?>][start_date]" value="<?php echo esc_attr($education['start_date']); ?>">
                                        <input type="hidden" name="education[<?php echo esc_attr($education_index); ?>][end_date]" value="<?php echo esc_attr($education['end_date']); ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php submit_button($is_new ? 'Add Student' : 'Save Student'); ?>
        </form>
        <?php
    }

    private static function render_ranked_settings_table($table_id, $group, $rows, $value_key, $title_label, $rank_key = 'rank') {
        ?>
        <table id="<?php echo esc_attr($table_id); ?>" class="widefat striped settings-options-table" data-group="<?php echo esc_attr($group); ?>" data-type="ranked">
            <thead>
                <tr>
                    <th><?php echo esc_html($title_label); ?></th>
                    <th>Rank</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody data-next-index="<?php echo esc_attr(count($rows)); ?>">
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><input type="text" name="<?php echo esc_attr($group); ?>[<?php echo esc_attr($index); ?>][value]" value="<?php echo esc_attr(isset($row[$value_key]) ? $row[$value_key] : ''); ?>"></td>
                        <td><input class="rank-input" type="number" min="1" step="1" name="<?php echo esc_attr($group); ?>[<?php echo esc_attr($index); ?>][rank]" value="<?php echo esc_attr(isset($row[$rank_key]) ? $row[$rank_key] : $index + 1); ?>"></td>
                        <td><button type="button" class="button academic-remove-option-row">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button academic-add-option-row" data-target="#<?php echo esc_attr($table_id); ?>">Add Row</button></p>
        <?php
    }

    private static function render_label_settings_table($table_id, $group, $rows, $value_key, $label_key, $value_label, $label_label) {
        ?>
        <table id="<?php echo esc_attr($table_id); ?>" class="widefat striped settings-options-table" data-group="<?php echo esc_attr($group); ?>" data-type="label">
            <thead>
                <tr>
                    <th><?php echo esc_html($value_label); ?></th>
                    <th><?php echo esc_html($label_label); ?></th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody data-next-index="<?php echo esc_attr(count($rows)); ?>">
                <?php foreach ($rows as $index => $row): ?>
                    <tr>
                        <td><input type="text" name="<?php echo esc_attr($group); ?>[<?php echo esc_attr($index); ?>][value]" value="<?php echo esc_attr(isset($row[$value_key]) ? $row[$value_key] : ''); ?>"></td>
                        <td><input type="text" name="<?php echo esc_attr($group); ?>[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr(isset($row[$label_key]) ? $row[$label_key] : ''); ?>"></td>
                        <td><button type="button" class="button academic-remove-option-row">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button academic-add-option-row" data-target="#<?php echo esc_attr($table_id); ?>">Add Row</button></p>
        <?php
    }

    private static function render_settings_tab() {
        $generic_settings = AcademicDirectory::get_generic_settings_for_admin();
        $categories = !empty($generic_settings['categories']['rows']) ? $generic_settings['categories']['rows'] : array();
        $pronouns = !empty($generic_settings['pronouns']['rows']) ? $generic_settings['pronouns']['rows'] : array();
        $education_titles = !empty($generic_settings['education_titles']['rows']) ? $generic_settings['education_titles']['rows'] : array();
        $csv_datasets = array(
            'students' => 'Students',
            'education' => 'Education',
        );
        $backup_files = AcademicDirectory::get_csv_backup_files_for_admin();
        ?>
        <h2>Virtual Profile Pages</h2>
        <p>Plugin-generated routes such as <code>/research-group/</code>, <code>/research-group/PI/</code>, and <code>/research-group/{profile-slug}/</code> keep their people data here. Shared hero images, hero title sizing, and visual page-header styling are managed in <strong>Faculty Theme → General</strong>.</p>

        <hr>

        <h2>CSV Export</h2>
        <p>Download separated student and education CSV files for backup, reporting, or offline reference. Student images are not included.</p>

        <table class="form-table" role="presentation">
            <?php foreach ($csv_datasets as $dataset => $label): ?>
                <tr>
                    <th scope="row"><?php echo esc_html('Export ' . strtolower($label)); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('academic_directory_export_data'); ?>
                            <input type="hidden" name="action" value="academic_directory_export_data">
                            <input type="hidden" name="dataset" value="<?php echo esc_attr($dataset); ?>">
                            <?php submit_button('Download ' . $label . ' CSV', 'secondary', 'submit', false); ?>
                        </form>
                        <p class="description">Columns: <code><?php echo esc_html(implode(', ', AcademicDirectory::get_export_headers($dataset))); ?></code></p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <hr>

        <h2>Automatic CSV Backups</h2>
        <p>Before the toolkit overwrites a CSV file, it keeps a timestamped backup in the protected plugin data folder. Use restore only when you intentionally want to roll one CSV back.</p>

        <?php if ($backup_files): ?>
            <table class="widefat striped" style="max-width: 960px;">
                <thead>
                    <tr>
                        <th>Dataset</th>
                        <th>Backup file</th>
                        <th>Created</th>
                        <th>Size</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($backup_files, 0, 20) as $backup): ?>
                        <tr>
                            <td><code><?php echo esc_html($backup['dataset']); ?></code></td>
                            <td><code><?php echo esc_html($backup['file']); ?></code></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $backup['modified'])); ?></td>
                            <td><?php echo esc_html(size_format($backup['size'])); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:.35rem;" onsubmit="return confirm('Restore this CSV backup? The current CSV will be backed up first.');">
                                    <?php wp_nonce_field('academic_directory_restore_backup'); ?>
                                    <input type="hidden" name="action" value="academic_directory_restore_backup">
                                    <input type="hidden" name="backup_file" value="<?php echo esc_attr($backup['file']); ?>">
                                    <?php submit_button('Restore backup', 'secondary', 'submit', false); ?>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;" onsubmit="return confirm('Delete this backup permanently? This cannot be undone.');">
                                    <?php wp_nonce_field('academic_directory_delete_backup'); ?>
                                    <input type="hidden" name="action" value="academic_directory_delete_backup">
                                    <input type="hidden" name="backup_file" value="<?php echo esc_attr($backup['file']); ?>">
                                    <?php submit_button('Delete backup', 'delete', 'submit', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Showing the newest 20 backup files. The toolkit automatically keeps the newest 25 backups per CSV.</p>
        <?php else: ?>
            <p>No automatic backups exist yet. A backup is created the first time a CSV is saved after data already exists.</p>
        <?php endif; ?>

        <hr>

        <h2>Generic Options</h2>
        <p>Edit the shared dropdowns and ordering rules used by the student editor and public directory.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_directory_save_settings'); ?>
            <input type="hidden" name="action" value="academic_directory_save_settings">

            <div class="settings-grid">
                <div class="settings-panel">
                    <h3>Student Categories</h3>
                    <p class="description">Controls category dropdown options and public group order.</p>
                    <?php self::render_ranked_settings_table('academic-category-options', 'categories', $categories, 'category', 'Category'); ?>
                </div>

                <div class="settings-panel">
                    <h3>Pronouns</h3>
                    <p class="description">Controls pronoun dropdown options in the student editor.</p>
                    <?php self::render_label_settings_table('academic-pronoun-options', 'pronouns', $pronouns, 'pronoun', 'label', 'Pronoun', 'Label'); ?>
                </div>

                <div class="settings-panel">
                    <h3>Education Titles</h3>
                    <p class="description">Controls education title options and their preferred order.</p>
                    <?php self::render_ranked_settings_table('academic-education-title-options', 'education_titles', $education_titles, 'title', 'Title'); ?>
                </div>
            </div>

            <?php submit_button('Save Generic Options'); ?>
        </form>

        <hr>

        <h2>Plugin Paths</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Shortcode</th>
                <td><code>[student_list]</code></td>
            </tr>
            <tr>
                <th scope="row">Student profile URLs</th>
                <td><code>/research-group/{student-id}/</code></td>
            </tr>
            <tr>
                <th scope="row">PI CSV</th>
                <td><code>data/principal-investigator.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Students CSV</th>
                <td><code>data/students.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Category order CSV</th>
                <td><code>data/student-category-order.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Pronouns CSV</th>
                <td><code>data/pronouns.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Student education CSV</th>
                <td><code>data/student-education.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Education titles CSV</th>
                <td><code>data/education-title-order.csv</code></td>
            </tr>
            <tr>
                <th scope="row">Image fields</th>
                <td>Select existing Media Library images or upload new ones from the student edit form.</td>
            </tr>
        </table>
        <?php
    }

    public static function save_pi() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to edit this plugin.');
        }

        check_admin_referer('academic_directory_save_pi');

        $saved = AcademicDirectory::save_pi_from_admin($_POST);
        if ($saved) {
            update_option(self::$last_saved_option, current_time('mysql'), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=pi&updated=' . ($saved ? '1' : '0')));
        exit;
    }

    public static function save_positions() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to edit this plugin.');
        }

        check_admin_referer('academic_directory_save_positions');

        $fields = array('vacancies_enabled', 'vacancies_label', 'vacancies_title', 'vacancies_text', 'vacancies_button_text', 'vacancies_button_url');
        $saved = AcademicDirectory::save_pi_partial_from_admin($_POST, $fields);
        if ($saved) {
            update_option(self::$last_saved_option, current_time('mysql'), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=positions&updated=' . ($saved ? '1' : '0')));
        exit;
    }

    public static function save_students() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to edit this plugin.');
        }

        check_admin_referer('academic_directory_save_students');

        if (isset($_POST['student'])) {
            $result = AcademicDirectory::save_student_from_admin($_POST);
            $saved = !empty($result['saved']);
            if ($saved) {
                update_option(self::$last_saved_option, current_time('mysql'), false);
            }
            $student_index = isset($result['student_index']) ? $result['student_index'] : 0;
            wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=students&student_index=' . absint($student_index) . '&updated=' . ($saved ? '1' : '0')));
            exit;
        }

        $saved = AcademicDirectory::save_students_from_admin($_POST);
        if ($saved) {
            update_option(self::$last_saved_option, current_time('mysql'), false);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=students&updated=' . ($saved ? '1' : '0')));
        exit;
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to edit this plugin.');
        }

        check_admin_referer('academic_directory_save_settings');

        $settings_section = isset($_POST['settings_section']) ? sanitize_key(wp_unslash($_POST['settings_section'])) : 'generic';
        if ($settings_section === 'profile_pages') {
            $saved = AcademicDirectory::save_profile_page_settings_from_admin($_POST);
        } else {
        $saved = AcademicDirectory::save_generic_settings_from_admin($_POST);
        if ($saved) {
            update_option(self::$last_saved_option, current_time('mysql'), false);
        }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=settings&updated=' . ($saved ? '1' : '0')));
        exit;
    }

    public static function export_data() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to export this plugin data.');
        }

        check_admin_referer('academic_directory_export_data');

        $dataset = isset($_POST['dataset']) ? sanitize_key(wp_unslash($_POST['dataset'])) : 'students';
        AcademicDirectory::output_export_csv($dataset);
        exit;
    }

    public static function restore_backup() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to restore plugin data.');
        }

        check_admin_referer('academic_directory_restore_backup');

        $backup_file = isset($_POST['backup_file']) ? sanitize_file_name(wp_unslash($_POST['backup_file'])) : '';
        $restored = AcademicDirectory::restore_backup_from_admin($backup_file);

        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=settings&restored=' . ($restored ? '1' : '0')));
        exit;
    }

    public static function delete_backup() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to delete plugin backups.');
        }

        check_admin_referer('academic_directory_delete_backup');

        $backup_file = isset($_POST['backup_file']) ? sanitize_file_name(wp_unslash($_POST['backup_file'])) : '';
        $deleted = AcademicDirectory::delete_backup_from_admin($backup_file);

        wp_safe_redirect(admin_url('admin.php?page=' . self::$page_slug . '&tab=settings&backup_deleted=' . ($deleted ? '1' : '0')));
        exit;
    }

}

AcademicDirectoryAdmin::init();
