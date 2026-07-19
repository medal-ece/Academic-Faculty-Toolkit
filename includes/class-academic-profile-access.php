<?php

if (!defined('ABSPATH')) {
    exit;
}

class AcademicProfileAccess {

    const TOKEN_FILE = 'data/private/student-edit-tokens.csv';
    const EMAIL_FILE = 'data/private/email-settings.csv';
    const LOCK_FILE = 'data/private/profile-edit.lock';

    private static function default_email_message() {
        return "Hello {student_name},\n\nPlease use the private link below to review and update your research group profile:\n\n{edit_link}\n\nThis link expires on {expires_at}.\n\n- Please do not forward it.\n- Please do not reply it.\n\nThank you,\n{site_name}";
    }

    private static function previous_default_email_message() {
        return "Hello {student_name},\n\nPlease use the private link below to review and update your research group profile:\n\n{edit_link}\n\nThis link expires on {expires_at}. Please do not forward it.\n\nThank you,\n{site_name}";
    }

    public static function init() {
        self::ensure_storage();

        add_filter('the_posts', array(__CLASS__, 'inject_edit_page'), 9, 2);
        add_filter('template_include', array(__CLASS__, 'filter_edit_template'), 20);
        add_filter('document_title_parts', array(__CLASS__, 'filter_edit_title'));
        add_filter('pre_get_shortlink', array(__CLASS__, 'filter_edit_shortlink'), 5, 4);
        add_filter('wp_robots', array(__CLASS__, 'filter_robots'));
        add_action('send_headers', array(__CLASS__, 'send_private_headers'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_edit_assets'));

        add_action('admin_post_academic_profile_generate_link', array(__CLASS__, 'handle_generate_link'));
        add_action('admin_post_academic_profile_email_link', array(__CLASS__, 'handle_email_link'));
        add_action('admin_post_academic_profile_revoke_link', array(__CLASS__, 'handle_revoke_link'));
        add_action('admin_post_academic_profile_save_email_settings', array(__CLASS__, 'handle_save_email_settings'));
        add_action('admin_post_academic_profile_save_public', array(__CLASS__, 'handle_public_save'));
        add_action('admin_post_nopriv_academic_profile_save_public', array(__CLASS__, 'handle_public_save'));
    }

    private static function plugin_path($relative) {
        return dirname(__DIR__) . '/' . ltrim($relative, '/');
    }

    public static function ensure_storage() {
        $directory = self::plugin_path('data/private');

        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        self::ensure_private_security_files($directory);

        self::ensure_csv(self::plugin_path(self::TOKEN_FILE), array('Student ID', 'Token Hash', 'Created At', 'Expires At', 'Revoked At', 'Last Used At'));
        self::ensure_csv(self::plugin_path(self::EMAIL_FILE), array('Key', 'Value'));
    }

    private static function ensure_private_security_files($directory) {
        if (!is_dir($directory) || !is_writable($directory)) {
            return;
        }

        $index_path = $directory . DIRECTORY_SEPARATOR . 'index.php';
        if (!file_exists($index_path) || filesize($index_path) === 0) {
            file_put_contents($index_path, "<?php\n// Silence is golden.\n");
        }

        $htaccess_path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess_path)) {
            file_put_contents(
                $htaccess_path,
                "# Academic Faculty Toolkit private data protection.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
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

    private static function ensure_csv($path, $headers) {
        if (file_exists($path)) {
            return;
        }

        $handle = @fopen($path, 'x');
        if (!$handle) {
            return;
        }

        fputcsv($handle, $headers);
        fclose($handle);
    }

    private static function normalize_token($token) {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
    }

    private static function token_hash($token) {
        return hash('sha256', self::normalize_token($token));
    }

    private static function read_csv($path) {
        if (!file_exists($path)) {
            return array('headers' => array(), 'rows' => array());
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return array('headers' => array(), 'rows' => array());
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return array('headers' => array(), 'rows' => array());
        }

        $headers = fgetcsv($handle);
        $normalized = $headers ? AcademicDirectory::normalize_headers($headers) : array();
        $rows = array();

        while (($line = fgetcsv($handle)) !== false) {
            $line = array_pad($line, count($normalized), '');
            $row = array_combine($normalized, array_slice($line, 0, count($normalized)));
            if ($row) {
                $rows[] = array_map('trim', $row);
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return array('headers' => $headers ?: array(), 'rows' => $rows);
    }

    private static function write_csv_locked($path, $headers, $rows) {
        $handle = fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) {
                fclose($handle);
            }
            return false;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = array();
            foreach ($headers as $header) {
                $key = AcademicDirectory::normalize_headers(array($header))[0];
                $line[] = isset($row[$key]) ? $row[$key] : '';
            }
            fputcsv($handle, $line);
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    public static function generate_token($student_id, $expiry_days = 180) {
        $student_id = sanitize_title($student_id);
        if (!$student_id || !AcademicDirectory::get_student_by_id($student_id)) {
            return false;
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            $token = wp_generate_password(64, false, false);
        }

        $table = self::read_csv(self::plugin_path(self::TOKEN_FILE));
        $rows = $table['rows'];
        $now = gmdate('c');

        foreach ($rows as &$row) {
            if (isset($row['student_id']) && $row['student_id'] === $student_id && empty($row['revoked_at'])) {
                $row['revoked_at'] = $now;
            }
        }
        unset($row);

        $rows[] = array(
            'student_id' => $student_id,
            'token_hash' => self::token_hash($token),
            'created_at' => $now,
            'expires_at' => gmdate('c', time() + max(1, absint($expiry_days)) * DAY_IN_SECONDS),
            'revoked_at' => '',
            'last_used_at' => '',
        );

        $saved = self::write_csv_locked(
            self::plugin_path(self::TOKEN_FILE),
            array('Student ID', 'Token Hash', 'Created At', 'Expires At', 'Revoked At', 'Last Used At'),
            $rows
        );

        return $saved ? $token : false;
    }

    public static function validate_token($token) {
        $token = self::normalize_token($token);
        if (strlen($token) < 32) {
            return false;
        }

        $hash = self::token_hash($token);
        $table = self::read_csv(self::plugin_path(self::TOKEN_FILE));

        foreach ($table['rows'] as $row) {
            if (empty($row['token_hash']) || !hash_equals($row['token_hash'], $hash)) {
                continue;
            }

            if (!empty($row['revoked_at']) || empty($row['expires_at']) || strtotime($row['expires_at']) < time()) {
                return false;
            }

            return AcademicDirectory::get_student_by_id($row['student_id']) ? $row : false;
        }

        return false;
    }

    public static function revoke_student_tokens($student_id) {
        $student_id = sanitize_title($student_id);
        $table = self::read_csv(self::plugin_path(self::TOKEN_FILE));
        $rows = $table['rows'];
        $changed = false;

        foreach ($rows as &$row) {
            if (isset($row['student_id']) && $row['student_id'] === $student_id && empty($row['revoked_at'])) {
                $row['revoked_at'] = gmdate('c');
                $changed = true;
            }
        }
        unset($row);

        if (!$changed) {
            return true;
        }

        return self::write_csv_locked(
            self::plugin_path(self::TOKEN_FILE),
            array('Student ID', 'Token Hash', 'Created At', 'Expires At', 'Revoked At', 'Last Used At'),
            $rows
        );
    }

    private static function mark_token_used($token) {
        $hash = self::token_hash($token);
        $table = self::read_csv(self::plugin_path(self::TOKEN_FILE));
        $rows = $table['rows'];

        foreach ($rows as &$row) {
            if (!empty($row['token_hash']) && hash_equals($row['token_hash'], $hash)) {
                $row['last_used_at'] = gmdate('c');
            }
        }
        unset($row);

        self::write_csv_locked(
            self::plugin_path(self::TOKEN_FILE),
            array('Student ID', 'Token Hash', 'Created At', 'Expires At', 'Revoked At', 'Last Used At'),
            $rows
        );
    }

    public static function get_email_settings() {
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host);
        $defaults = array(
            'sender_name' => get_bloginfo('name'),
            'sender_email' => 'admin@' . ($host ?: 'localhost'),
            'subject' => 'Update your {site_name} profile',
            'message' => self::default_email_message(),
            'expiry_days' => '180',
        );
        $table = self::read_csv(self::plugin_path(self::EMAIL_FILE));
        $settings = array();

        foreach ($table['rows'] as $row) {
            if (!empty($row['key'])) {
                $settings[$row['key']] = isset($row['value']) ? $row['value'] : '';
            }
        }

        $settings = wp_parse_args($settings, $defaults);

        if (isset($settings['message']) && trim(str_replace("\r\n", "\n", $settings['message'])) === trim(self::previous_default_email_message())) {
            $settings['message'] = self::default_email_message();
        }

        return $settings;
    }

    public static function save_email_settings($data) {
        $settings = array(
            'sender_name' => isset($data['sender_name']) ? sanitize_text_field(wp_unslash($data['sender_name'])) : '',
            'sender_email' => isset($data['sender_email']) ? sanitize_email(wp_unslash($data['sender_email'])) : '',
            'subject' => isset($data['subject']) ? sanitize_text_field(wp_unslash($data['subject'])) : '',
            'message' => isset($data['message']) ? sanitize_textarea_field(wp_unslash($data['message'])) : '',
            'expiry_days' => isset($data['expiry_days']) ? (string) max(1, min(730, absint($data['expiry_days']))) : '180',
        );
        $rows = array();

        foreach ($settings as $key => $value) {
            $rows[] = array('key' => $key, 'value' => $value);
        }

        return self::write_csv_locked(self::plugin_path(self::EMAIL_FILE), array('Key', 'Value'), $rows);
    }

    private static function replace_placeholders($text, $student, $link, $expires_at) {
        return strtr($text, array(
            '{student_name}' => isset($student['name']) ? $student['name'] : '',
            '{edit_link}' => $link,
            '{site_name}' => get_bloginfo('name'),
            '{expires_at}' => wp_date(get_option('date_format'), strtotime($expires_at)),
        ));
    }

    private static function send_link_email($student, $token) {
        if (empty($student['email'])) {
            return false;
        }

        $settings = self::get_email_settings();
        $record = self::validate_token($token);
        if (!$record) {
            return false;
        }

        $link = add_query_arg('token', rawurlencode($token), home_url('/edit-profile/'));
        $subject = self::replace_placeholders($settings['subject'], $student, $link, $record['expires_at']);
        $message = self::replace_placeholders($settings['message'], $student, $link, $record['expires_at']);
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        if (!empty($settings['sender_email'])) {
            $headers[] = 'From: ' . $settings['sender_name'] . ' <' . $settings['sender_email'] . '>';
        }

        return wp_mail($student['email'], $subject, $message, $headers);
    }

    public static function get_student_token_statuses() {
        $table = self::read_csv(self::plugin_path(self::TOKEN_FILE));
        $statuses = array();

        foreach ($table['rows'] as $row) {
            if (empty($row['student_id'])) {
                continue;
            }

            $status = !empty($row['revoked_at']) ? 'Revoked' : ((empty($row['expires_at']) || strtotime($row['expires_at']) < time()) ? 'Expired' : 'Active');
            $statuses[$row['student_id']] = array(
                'status' => $status,
                'expires_at' => isset($row['expires_at']) ? $row['expires_at'] : '',
                'last_used_at' => isset($row['last_used_at']) ? $row['last_used_at'] : '',
            );
        }

        return $statuses;
    }

    public static function render_links_admin() {
        $students = AcademicDirectory::get_students();
        $statuses = self::get_student_token_statuses();
        ?>
        <h2>Private Student Edit Links</h2>
        <p>Generate a new private link to copy manually or generate and email a new link. Generating a link revokes every older link for that student.</p>
        <table class="widefat striped academic-profile-links-table">
            <thead><tr><th>Student</th><th>Email</th><th>Link Status</th><th>Expires</th><th>Last Used</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <?php
                $student_id = $student['student_id'];
                $status = isset($statuses[$student_id]) ? $statuses[$student_id] : array('status' => 'Not generated', 'expires_at' => '', 'last_used_at' => '');
                $generate_url = wp_nonce_url(admin_url('admin-post.php?action=academic_profile_generate_link&student_id=' . rawurlencode($student_id)), 'academic_profile_generate_' . $student_id);
                $email_url = wp_nonce_url(admin_url('admin-post.php?action=academic_profile_email_link&student_id=' . rawurlencode($student_id)), 'academic_profile_email_' . $student_id);
                $revoke_url = wp_nonce_url(admin_url('admin-post.php?action=academic_profile_revoke_link&student_id=' . rawurlencode($student_id)), 'academic_profile_revoke_' . $student_id);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($student['name']); ?></strong><br><code><?php echo esc_html($student_id); ?></code></td>
                    <td><?php echo esc_html($student['email']); ?></td>
                    <td><?php echo esc_html($status['status']); ?></td>
                    <td><?php echo esc_html(self::format_admin_date($status['expires_at'])); ?></td>
                    <td><?php echo esc_html(self::format_admin_date($status['last_used_at'])); ?></td>
                    <td class="academic-link-actions">
                        <a class="button" href="<?php echo esc_url($generate_url); ?>">Generate / Copy</a>
                        <?php if (!empty($student['email'])): ?><a class="button" href="<?php echo esc_url($email_url); ?>">Generate / Email</a><?php endif; ?>
                        <?php if ($status['status'] === 'Active'): ?><a class="button" href="<?php echo esc_url($revoke_url); ?>">Revoke</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function format_admin_date($date) {
        return $date ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($date)) : '';
    }

    public static function render_email_settings_admin() {
        $settings = self::get_email_settings();
        ?>
        <h2>Email Settings</h2>
        <p>These settings are stored in <code>data/private/email-settings.csv</code>. WordPress sends the message through <code>wp_mail()</code>.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_profile_save_email_settings'); ?>
            <input type="hidden" name="action" value="academic_profile_save_email_settings">
            <table class="form-table" role="presentation">
                <tr><th><label for="academic-sender-name">Sender name</label></th><td><input class="regular-text" id="academic-sender-name" name="sender_name" value="<?php echo esc_attr($settings['sender_name']); ?>"></td></tr>
                <tr><th><label for="academic-sender-email">Sender email</label></th><td><input class="regular-text" type="email" id="academic-sender-email" name="sender_email" value="<?php echo esc_attr($settings['sender_email']); ?>"><p class="description">Defaults to <code>admin@your-site-domain</code>. The host mail configuration may replace this address.</p></td></tr>
                <tr><th><label for="academic-email-subject">Subject</label></th><td><input class="large-text" id="academic-email-subject" name="subject" value="<?php echo esc_attr($settings['subject']); ?>"></td></tr>
                <tr><th><label for="academic-email-message">Message</label></th><td><textarea class="large-text code" rows="12" id="academic-email-message" name="message"><?php echo esc_textarea($settings['message']); ?></textarea><p class="description">Available placeholders: <code>{student_name}</code>, <code>{edit_link}</code>, <code>{site_name}</code>, <code>{expires_at}</code>.</p></td></tr>
                <tr><th><label for="academic-expiry-days">Link lifetime</label></th><td><input class="small-text" type="number" min="1" max="730" id="academic-expiry-days" name="expiry_days" value="<?php echo esc_attr($settings['expiry_days']); ?>"> days</td></tr>
            </table>
            <?php submit_button('Save Email Settings'); ?>
        </form>
        <?php
    }

    private static function require_admin($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to manage profile links.');
        }
        check_admin_referer($nonce_action);
    }

    public static function handle_generate_link() {
        $student_id = isset($_GET['student_id']) ? sanitize_title(wp_unslash($_GET['student_id'])) : '';
        self::require_admin('academic_profile_generate_' . $student_id);
        $settings = self::get_email_settings();
        $token = self::generate_token($student_id, $settings['expiry_days']);

        if (!$token) {
            wp_die('The profile link could not be generated.');
        }

        $link = add_query_arg('token', rawurlencode($token), home_url('/edit-profile/'));
        $back = admin_url('admin.php?page=academic-faculty-toolkit&tab=links');
        wp_die(
            '<p>This new link replaces any older link for this student.</p><p><input type="text" readonly value="' . esc_attr($link) . '" style="width:100%;max-width:760px" onclick="this.select()"></p><p><a class="button button-primary" href="' . esc_url($back) . '">Return to Profile Links</a></p>',
            'Private Profile Edit Link',
            array('response' => 200)
        );
    }

    public static function handle_email_link() {
        $student_id = isset($_GET['student_id']) ? sanitize_title(wp_unslash($_GET['student_id'])) : '';
        self::require_admin('academic_profile_email_' . $student_id);
        $student = AcademicDirectory::get_student_by_id($student_id);
        $settings = self::get_email_settings();
        $token = $student ? self::generate_token($student_id, $settings['expiry_days']) : false;
        $sent = $token ? self::send_link_email($student, $token) : false;

        if ($token && !$sent) {
            $link = add_query_arg('token', rawurlencode($token), home_url('/edit-profile/'));
            $back = admin_url('admin.php?page=academic-faculty-toolkit&tab=links');
            wp_die(
                '<p>WordPress could not send this email. You can copy the newly generated link manually:</p><p><input type="text" readonly value="' . esc_attr($link) . '" style="width:100%;max-width:760px" onclick="this.select()"></p><p><a class="button button-primary" href="' . esc_url($back) . '">Return to Profile Links</a></p>',
                'Email Could Not Be Sent',
                array('response' => 200)
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=academic-faculty-toolkit&tab=links&updated=' . ($sent ? 'emailed' : 'email_failed')));
        exit;
    }

    public static function handle_revoke_link() {
        $student_id = isset($_GET['student_id']) ? sanitize_title(wp_unslash($_GET['student_id'])) : '';
        self::require_admin('academic_profile_revoke_' . $student_id);
        $saved = self::revoke_student_tokens($student_id);
        wp_safe_redirect(admin_url('admin.php?page=academic-faculty-toolkit&tab=links&updated=' . ($saved ? 'revoked' : '0')));
        exit;
    }

    public static function handle_save_email_settings() {
        self::require_admin('academic_profile_save_email_settings');
        $saved = self::save_email_settings($_POST);
        wp_safe_redirect(admin_url('admin.php?page=academic-faculty-toolkit&tab=email&updated=' . ($saved ? '1' : '0')));
        exit;
    }

    public static function inject_edit_page($posts, $query) {
        if (is_admin() || !$query->is_main_query() || !get_query_var('academic_profile_edit')) {
            return $posts;
        }

        $token = isset($_GET['token']) ? self::normalize_token(wp_unslash($_GET['token'])) : '';
        $record = self::validate_token($token);
        $student = $record ? AcademicDirectory::get_student_by_id($record['student_id']) : false;
        $content = AcademicDirectory::render_template('student-edit-form.php', array(
            'token' => $token,
            'student' => $student,
            'updated' => isset($_GET['updated']) && $_GET['updated'] === '1',
            'error' => isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '',
            'pronoun_options' => AcademicDirectory::get_pronoun_options_for_admin(),
            'education_title_options' => AcademicDirectory::get_education_title_options_for_admin(),
        ));
        $virtual_post = new WP_Post((object) array(
            'ID' => -abs(crc32('academic-profile-edit')),
            'post_author' => 0,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
            'post_content' => $content,
            'post_title' => 'Edit Your Profile',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => 'edit-profile',
            'post_parent' => 0,
            'guid' => home_url('/edit-profile/'),
            'menu_order' => 0,
            'post_type' => 'page',
            'comment_count' => 0,
            'filter' => 'raw',
        ));

        $query->posts = array($virtual_post);
        $query->post = $virtual_post;
        $query->post_count = 1;
        $query->found_posts = 1;
        $query->max_num_pages = 1;
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

    public static function filter_edit_template($template) {
        if (!get_query_var('academic_profile_edit')) {
            return $template;
        }

        $edit_template = dirname(__DIR__) . '/templates/student-edit-page.php';
        return file_exists($edit_template) ? $edit_template : $template;
    }

    public static function filter_edit_title($parts) {
        if (get_query_var('academic_profile_edit')) {
            $parts['title'] = 'Edit Your Profile';
        }
        return $parts;
    }

    public static function filter_edit_shortlink($shortlink, $id, $context, $allow_slugs) {
        if (get_query_var('academic_profile_edit')) {
            return '';
        }

        return $shortlink;
    }

    public static function filter_robots($robots) {
        if (get_query_var('academic_profile_edit')) {
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            $robots['noarchive'] = true;
        }
        return $robots;
    }

    public static function send_private_headers() {
        if (!get_query_var('academic_profile_edit')) {
            return;
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    }

    public static function enqueue_edit_assets() {
        if (!get_query_var('academic_profile_edit')) {
            return;
        }

        wp_enqueue_script(
            'academic-profile-edit',
            plugins_url('assets/js/profile-edit.js', dirname(__DIR__) . '/academic-student-directory.php'),
            array(),
            '1.0',
            true
        );
    }

    private static function handle_profile_photo_upload($student) {
        if (empty($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])) {
            return '';
        }

        $file = $_FILES['profile_photo'];

        if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('academic_profile_upload_error', 'The uploaded image could not be received. Please try a smaller JPG, PNG, WEBP, or GIF image.');
        }

        if (!empty($file['size']) && (int) $file['size'] > 5 * MB_IN_BYTES) {
            return new WP_Error('academic_profile_upload_too_large', 'Please upload an image smaller than 5 MB.');
        }

        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        );
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);

        if (empty($checked['ext']) || empty($checked['type']) || strpos($checked['type'], 'image/') !== 0) {
            return new WP_Error('academic_profile_upload_type', 'Please upload a valid JPG, PNG, WEBP, or GIF image.');
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $student_name = !empty($student['name']) ? $student['name'] : 'Research group member';
        $attachment_id = media_handle_upload('profile_photo', 0, array(
            'post_title' => sanitize_text_field($student_name . ' profile photo'),
            'post_content' => '',
            'post_excerpt' => '',
        ));

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($student_name));

        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $filename = $attached_file ? wp_basename($attached_file) : wp_basename((string) wp_get_attachment_url($attachment_id));

        return sanitize_file_name($filename);
    }

    private static function with_profile_lock($callback) {
        $lock = fopen(self::plugin_path(self::LOCK_FILE), 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            return false;
        }

        foreach (array('students.csv', 'student-education.csv') as $filename) {
            $source = self::plugin_path('data/' . $filename);
            if (file_exists($source)) {
                @copy($source, self::plugin_path('data/private/' . $filename . '.bak'));
            }
        }

        $result = call_user_func($callback);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }

    public static function handle_public_save() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'academic_profile_save_public')) {
            wp_die('This profile edit session could not be verified. Please reload the edit link and try again.', 'Invalid Request', array('response' => 403));
        }

        $token = isset($_POST['profile_token']) ? self::normalize_token(wp_unslash($_POST['profile_token'])) : '';
        $record = self::validate_token($token);

        if (!$record || !empty($_POST['company'])) {
            wp_die('This profile link is invalid or expired.', 'Invalid Link', array('response' => 403));
        }

        $profile = isset($_POST['profile']) && is_array($_POST['profile']) ? $_POST['profile'] : array();
        $education = isset($_POST['education']) && is_array($_POST['education']) ? $_POST['education'] : array();
        $student = AcademicDirectory::get_student_by_id($record['student_id']);
        $uploaded_photo = $student ? self::handle_profile_photo_upload($student) : '';

        if (is_wp_error($uploaded_photo)) {
            $url = add_query_arg(array('token' => rawurlencode($token), 'error' => 'upload'), home_url('/edit-profile/'));
            wp_safe_redirect($url);
            exit;
        }

        if ($uploaded_photo !== '') {
            $profile['image'] = $uploaded_photo;
        }

        $saved = self::with_profile_lock(function() use ($record, $profile, $education) {
            return AcademicDirectory::save_student_profile_submission($record['student_id'], $profile, $education);
        });

        if ($saved) {
            self::mark_token_used($token);
        }

        $url = add_query_arg(array('token' => rawurlencode($token), $saved ? 'updated' : 'error' => '1'), home_url('/edit-profile/'));
        wp_safe_redirect($url);
        exit;
    }
}
