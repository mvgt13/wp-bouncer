<?php
/*
Plugin Name: Bouncer
Version: 2.5.4
Plugin URI: https://voget.co
Update URI: false
Author: VOGET.CO
Author URI: https://voget.co
Description: Maintenance mode and coming soon plugin with IP/token bypass, proper HTTP status codes, and optional branding features.
Requires at least: 6.6
Requires PHP: 8.1
Text Domain: bouncer
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

register_activation_hook(__FILE__, function () {
    if (false === get_option('bouncer_preview_expiry')) {
        update_option('bouncer_preview_expiry', DAY_IN_SECONDS);
    }
    if (false === get_option('bouncer_preview_tokens')) {
        update_option('bouncer_preview_tokens', []);
    }
});

if (!class_exists('BOUNCER')) {

    class BOUNCER
    {
        private string $plugin_version;
        private string $plugin_url  = '';
        private string $plugin_path = '';

        function __construct()
        {
            $data = get_file_data(__FILE__, ['Version' => 'Version']);
            $this->plugin_version = $data['Version'];
            define('BOUNCER_VERSION', $this->plugin_version);
            define('BOUNCER_SITE_URL', site_url());
            define('BOUNCER_URL', $this->plugin_url());
            define('BOUNCER_PATH', $this->plugin_path());
            $this->plugin_includes();
        }

        function plugin_includes()
        {
            add_action('plugins_loaded', array($this, 'plugins_loaded_handler'));
            add_action('template_redirect', array($this, 'bouncer_template_redirect'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
            add_action('wp_ajax_bouncer_toggle', array($this, 'ajax_toggle_bouncer'));
            add_action('wp_ajax_bouncer_create_token', array($this, 'ajax_create_token'));
            add_action('wp_ajax_bouncer_deactivate_token', array($this, 'ajax_deactivate_token'));
            add_action('wp_ajax_bouncer_delete_token', array($this, 'ajax_delete_token'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_adminbar_scripts'));
            add_action('wp_enqueue_scripts',    array($this, 'enqueue_adminbar_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_page_scripts'));
            add_action('rest_api_init', array($this, 'register_rest_routes'));
        }

        function plugins_loaded_handler()
        {
            load_plugin_textdomain('bouncer', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            $this->maybe_migrate_single_token();
        }

        // Migrate from the old single-token options to the new token list (runs once).
        function maybe_migrate_single_token()
        {
            if (false !== get_option('bouncer_preview_tokens')) return;

            $old_token = get_option('bouncer_preview_token', '');
            $tokens    = [];

            if ($old_token) {
                $id          = wp_generate_password(8, false);
                $tokens[$id] = [
                    'name'      => 'Preview Link',
                    'token'     => $old_token,
                    'created'   => time(),
                    'expires'   => (int) get_option('bouncer_preview_token_expires', 0),
                    'active'    => true,
                    'use_count' => (int) get_option('bouncer_preview_use_count', 0),
                    'last_used' => (int) get_option('bouncer_preview_last_used', 0),
                ];
            }

            update_option('bouncer_preview_tokens', $tokens);
        }

        function plugin_url()
        {
            if ($this->plugin_url) return $this->plugin_url;
            return $this->plugin_url = plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__));
        }

        function plugin_path()
        {
            if ($this->plugin_path) return $this->plugin_path;
            return $this->plugin_path = untrailingslashit(plugin_dir_path(__FILE__));
        }

        function is_login_page()
        {
            return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
        }

        // ----- Token helpers -----

        function get_tokens()
        {
            return (array) get_option('bouncer_preview_tokens', []);
        }

        function save_tokens($tokens)
        {
            update_option('bouncer_preview_tokens', $tokens);
        }

        function new_token_entry($name, $expiry_seconds = null)
        {
            if ($expiry_seconds === null) {
                $expiry_seconds = DAY_IN_SECONDS;
            }
            return [
                'name'      => sanitize_text_field($name),
                'token'     => wp_generate_password(32, false),
                'created'   => time(),
                'expires'   => $expiry_seconds > 0 ? time() + $expiry_seconds : 0,
                'active'    => true,
                'use_count' => 0,
                'last_used' => 0,
            ];
        }

        function token_is_accessible($entry)
        {
            return $entry['active'] && ($entry['expires'] === 0 || time() < $entry['expires']);
        }

        function get_preview_url($token)
        {
            return add_query_arg('bouncer_preview', $token, home_url('/'));
        }

        // ----- Core bypass logic -----

        function is_bypass_allowed()
        {
            $tokens  = $this->get_tokens();
            $changed = false;

            foreach ($tokens as $id => &$entry) {
                if (!$this->token_is_accessible($entry)) continue;
                $t = $entry['token'];

                if (isset($_GET['bouncer_preview']) && hash_equals($t, sanitize_text_field($_GET['bouncer_preview']))) {
                    $has_cookie = isset($_COOKIE['bouncer_preview']) && hash_equals($t, $_COOKIE['bouncer_preview']);
                    if (!$has_cookie) {
                        $entry['use_count']++;
                        $entry['last_used'] = time();
                        $changed = true;
                    }
                    // Unlimited tokens: cap cookie at 14 days to avoid a near-permanent backdoor.
                    $ttl = $entry['expires'] > 0 ? $entry['expires'] - time() : 14 * DAY_IN_SECONDS;
                    setcookie('bouncer_preview', $t, time() + $ttl, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
                    if ($changed) $this->save_tokens($tokens);
                    return true;
                }

                if (isset($_COOKIE['bouncer_preview']) && hash_equals($t, $_COOKIE['bouncer_preview'])) {
                    return true;
                }
            }
            unset($entry);

            // Check IP allowlist.
            $allowed_ips = get_option('bouncer_allowed_ips', '');
            if ($allowed_ips) {
                $ip_list   = array_filter(array_map('trim', explode("\n", $allowed_ips)));
                $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
                if (in_array($client_ip, $ip_list, true)) {
                    return true;
                }
            }

            return false;
        }

        function bouncer_template_redirect()
        {
            if (!get_option('bouncer_enabled', false)) return;
            if (is_user_logged_in()) return;
            if ($this->is_login_page()) return;
            if ($this->is_bypass_allowed()) return;
            if (!is_admin()) {
                $this->load_bouncer_page();
            }
        }

        function load_bouncer_page()
        {
            $mode = get_option('bouncer_mode', 'maintenance');

            if ($mode === 'coming_soon') {
                status_header(200);
            } else {
                status_header(503);
                header('Retry-After: ' . get_option('bouncer_retry_after', 3600));
            }

            include_once('bouncer-template.php');
            exit();
        }

        function add_admin_menu()
        {
            add_options_page(
                __('Bouncer', 'bouncer'),
                __('Bouncer', 'bouncer'),
                'manage_options',
                'bouncer',
                array($this, 'admin_page')
            );
        }

        function register_settings()
        {
            register_setting('bouncer_settings', 'bouncer_enabled',        ['sanitize_callback' => 'absint']);
            register_setting('bouncer_settings', 'bouncer_mode',           ['sanitize_callback' => function ($v) {
                return in_array($v, ['maintenance', 'coming_soon'], true) ? $v : 'maintenance';
            }]);
            register_setting('bouncer_settings', 'bouncer_retry_after',    ['sanitize_callback' => function ($v) {
                return max(60, absint($v));
            }]);
            register_setting('bouncer_settings', 'bouncer_heading',        ['sanitize_callback' => 'sanitize_text_field']);
            register_setting('bouncer_settings', 'bouncer_text_en',        ['sanitize_callback' => 'wp_kses_post']);
            register_setting('bouncer_settings', 'bouncer_text_de',        ['sanitize_callback' => 'wp_kses_post']);
            register_setting('bouncer_settings', 'bouncer_email',          ['sanitize_callback' => 'sanitize_email']);
            register_setting('bouncer_settings', 'bouncer_website_url',    ['sanitize_callback' => 'esc_url_raw']);
            register_setting('bouncer_settings', 'bouncer_allowed_ips',    ['sanitize_callback' => 'sanitize_textarea_field']);
            register_setting('bouncer_settings', 'bouncer_show_dark_mode', ['sanitize_callback' => 'absint']);
            register_setting('bouncer_settings', 'bouncer_show_website_btn', ['sanitize_callback' => 'absint']);
            register_setting('bouncer_settings', 'bouncer_bilingual',      ['sanitize_callback' => 'absint']);
        }

        function admin_page()
        {
            $tokens         = $this->get_tokens();
            $current_expiry = (int) get_option('bouncer_preview_expiry', DAY_IN_SECONDS);
            $expiry_options = array(
                3600                => __('1 hour',   'bouncer'),
                21600               => __('6 hours',  'bouncer'),
                DAY_IN_SECONDS      => __('24 hours', 'bouncer'),
                3 * DAY_IN_SECONDS  => __('3 days',   'bouncer'),
                7 * DAY_IN_SECONDS  => __('7 days',   'bouncer'),
                30 * DAY_IN_SECONDS => __('30 days',  'bouncer'),
                0                   => __('No expiry', 'bouncer'),
            );
            $date_format = get_option('date_format') . ' ' . get_option('time_format');
            ?>
            <div class="wrap">
                <h1><?php _e('Bouncer Settings', 'bouncer'); ?></h1>
                <form method="post" action="options.php">
                    <?php settings_fields('bouncer_settings'); ?>

                    <h2><?php _e('Status', 'bouncer'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bouncer_enabled"><?php _e('Enable Bouncer', 'bouncer'); ?></label></th>
                            <td>
                                <input type="checkbox" name="bouncer_enabled" id="bouncer_enabled" value="1" <?php checked(1, get_option('bouncer_enabled')); ?> />
                                <p class="description"><?php _e('Redirect non-logged-in visitors to the bouncer page.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_mode"><?php _e('Mode', 'bouncer'); ?></label></th>
                            <td>
                                <select name="bouncer_mode" id="bouncer_mode">
                                    <option value="maintenance" <?php selected(get_option('bouncer_mode', 'maintenance'), 'maintenance'); ?>><?php _e('Maintenance Mode (503)', 'bouncer'); ?></option>
                                    <option value="coming_soon" <?php selected(get_option('bouncer_mode', 'maintenance'), 'coming_soon'); ?>><?php _e('Coming Soon (200)', 'bouncer'); ?></option>
                                </select>
                                <p class="description">
                                    <strong><?php _e('503 Maintenance:', 'bouncer'); ?></strong> <?php _e('Temporary downtime. Search engines retry after the Retry-After interval.', 'bouncer'); ?><br>
                                    <strong><?php _e('200 Coming Soon:', 'bouncer'); ?></strong> <?php _e('New site under construction. Search engines index normally.', 'bouncer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_retry_after"><?php _e('Retry After', 'bouncer'); ?></label></th>
                            <td>
                                <input type="number" name="bouncer_retry_after" id="bouncer_retry_after" value="<?php echo esc_attr(get_option('bouncer_retry_after', 3600)); ?>" class="small-text" min="60" />
                                <span class="description"> <?php _e('seconds', 'bouncer'); ?> &nbsp;—&nbsp; </span>
                                <button type="button" class="button-link bouncer-retry-preset" data-val="1800"><?php _e('30 min', 'bouncer'); ?></button> &middot;
                                <button type="button" class="button-link bouncer-retry-preset" data-val="3600"><?php _e('1 hour', 'bouncer'); ?></button> &middot;
                                <button type="button" class="button-link bouncer-retry-preset" data-val="21600"><?php _e('6 hours', 'bouncer'); ?></button> &middot;
                                <button type="button" class="button-link bouncer-retry-preset" data-val="86400"><?php _e('24 hours', 'bouncer'); ?></button>
                                <p class="description"><?php _e('Maintenance mode only — tells search engines how long to wait before checking back.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php _e('Access Control', 'bouncer'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php _e('Preview Links', 'bouncer'); ?></th>
                            <td>
                                <p style="margin-top:0"><?php _e('A preview link lets someone view the site without a WordPress account. Give each person or team their own link — you\'ll see who opened it and can deactivate individual links without affecting others.', 'bouncer'); ?></p>

                                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;width:100%;box-sizing:border-box">
                                    <input type="text" id="bouncer-new-name" placeholder="<?php esc_attr_e('Name — e.g. Acme Corp, Client B…', 'bouncer'); ?>" class="regular-text" style="flex:1;min-width:180px;width:auto" />
                                    <select id="bouncer-new-expiry">
                                        <?php foreach ($expiry_options as $seconds => $label) : ?>
                                            <option value="<?php echo esc_attr($seconds); ?>" <?php selected($current_expiry, $seconds); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="bouncer-create-token" class="button button-primary"><?php _e('Create link', 'bouncer'); ?></button>
                                </div>

                                <?php if (!empty($tokens)) : ?>
                                <ul style="margin:0 0 8px;padding:0;list-style:none">
                                    <?php foreach ($tokens as $id => $entry) :
                                        $accessible = $this->token_is_accessible($entry);

                                        if (!$entry['active']) {
                                            $dot   = '#787c82';
                                            $label = __('Deactivated', 'bouncer');
                                        } elseif ($entry['expires'] > 0 && time() > $entry['expires']) {
                                            $dot   = '#dba617';
                                            $label = __('Expired', 'bouncer');
                                        } elseif ($entry['use_count'] === 0) {
                                            $dot   = '#787c82';
                                            $label = __('Active — not opened yet', 'bouncer');
                                        } else {
                                            $dot   = '#00a32a';
                                            $label = __('Active', 'bouncer');
                                        }

                                        $expires_text = $entry['expires'] > 0
                                            ? wp_date($date_format, $entry['expires'])
                                            : __('No expiry', 'bouncer');

                                    ?>
                                    <li style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:12px 16px;margin-bottom:8px">
                                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-bottom:8px">
                                            <strong style="font-size:14px;flex:1;min-width:120px"><?php echo esc_html($entry['name']); ?></strong>
                                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:<?php echo esc_attr($dot); ?>;white-space:nowrap">
                                                <svg width="8" height="8" viewBox="0 0 8 8" aria-hidden="true"><circle cx="4" cy="4" r="4" fill="<?php echo esc_attr($dot); ?>"/></svg>
                                                <?php echo esc_html($label); ?>
                                            </span>
                                            <span style="display:flex;gap:6px;flex-shrink:0">
                                                <?php if ($accessible) : ?>
                                                    <button type="button"
                                                        class="button button-small bouncer-copy-link"
                                                        data-url="<?php echo esc_url($this->get_preview_url($entry['token'])); ?>"
                                                    ><?php _e('Copy link', 'bouncer'); ?></button>
                                                    <button type="button"
                                                        class="button button-small bouncer-deactivate"
                                                        data-id="<?php echo esc_attr($id); ?>"
                                                        data-name="<?php echo esc_attr($entry['name']); ?>"
                                                    ><?php _e('Deactivate', 'bouncer'); ?></button>
                                                <?php endif; ?>
                                                <button type="button"
                                                    class="button button-small bouncer-delete"
                                                    data-id="<?php echo esc_attr($id); ?>"
                                                    data-name="<?php echo esc_attr($entry['name']); ?>"
                                                    style="color:#d63638;border-color:#d63638"
                                                ><?php _e('Delete', 'bouncer'); ?></button>
                                            </span>
                                        </div>
                                        <div style="font-size:12px;color:#646970">
                                            <span><?php _e('Expires:', 'bouncer'); ?> <?php echo esc_html($expires_text); ?></span>
                                            <span style="margin:0 6px">&middot;</span>
                                            <span><?php if ($entry['use_count'] > 0) {
                                                printf(
                                                    esc_html__('Opened %d×', 'bouncer') . ' &middot; ' . esc_html__('Last opened: %s', 'bouncer'),
                                                    (int) $entry['use_count'],
                                                    esc_html(wp_date($date_format, $entry['last_used']))
                                                );
                                            } else {
                                                esc_html_e('Never opened', 'bouncer');
                                            } ?></span>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php else : ?>
                                <p style="color:#787c82;font-style:italic;margin-bottom:16px"><?php _e('No preview links yet. Enter a name above and click "Create link" to get started.', 'bouncer'); ?></p>
                                <?php endif; ?>

                                <p class="description">
                                    <?php _e('<strong>Deactivate</strong> blocks access immediately — the link stops working but stays in the list so you have a record. <strong>Delete</strong> removes it permanently.', 'bouncer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_allowed_ips"><?php _e('Allowed IPs', 'bouncer'); ?></label></th>
                            <td>
                                <textarea name="bouncer_allowed_ips" id="bouncer_allowed_ips" rows="5" class="large-text" placeholder="192.168.1.1&#10;10.0.0.1"><?php echo esc_textarea(get_option('bouncer_allowed_ips', '')); ?></textarea>
                                <p class="description"><?php _e('One IP address per line. Visitors from these IPs always see the live site, regardless of whether Bouncer is on.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php _e('Content', 'bouncer'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="bouncer_heading"><?php _e('Heading', 'bouncer'); ?></label></th>
                            <td><input type="text" name="bouncer_heading" id="bouncer_heading" value="<?php echo esc_attr(get_option('bouncer_heading', 'COMING SOON')); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_bilingual"><?php _e('Secondary Text', 'bouncer'); ?></label></th>
                            <td>
                                <input type="checkbox" name="bouncer_bilingual" id="bouncer_bilingual" value="1" <?php checked(1, get_option('bouncer_bilingual')); ?> />
                                <label for="bouncer_bilingual"><?php _e('Show a second text block below the main text', 'bouncer'); ?></label>
                                <p class="description"><?php _e('Useful for bilingual sites, simplified summaries, or any additional copy.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_text_en"><?php _e('Main Text', 'bouncer'); ?></label></th>
                            <td><textarea name="bouncer_text_en" id="bouncer_text_en" rows="4" class="large-text"><?php echo esc_textarea(get_option('bouncer_text_en', 'This site is still in the works and will be live soon.')); ?></textarea></td>
                        </tr>
                        <tr id="bouncer-secondary-text-row">
                            <th><label for="bouncer_text_de"><?php _e('Secondary Text', 'bouncer'); ?></label></th>
                            <td>
                                <textarea name="bouncer_text_de" id="bouncer_text_de" rows="4" class="large-text"><?php echo esc_textarea(get_option('bouncer_text_de', 'Diese Seite befindet sich noch im Aufbau und geht bald online.')); ?></textarea>
                                <p class="description"><?php _e('Shown below the main text when secondary text is enabled.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bouncer_email"><?php _e('Contact Email', 'bouncer'); ?></label></th>
                            <td>
                                <input type="email" name="bouncer_email" id="bouncer_email" value="<?php echo esc_attr(get_option('bouncer_email', '')); ?>" class="regular-text" />
                                <p class="description"><?php _e('Leave blank to omit the contact link.', 'bouncer'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php _e('Display', 'bouncer'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Dark Mode Toggle', 'bouncer'); ?></th>
                            <td>
                                <input type="checkbox" name="bouncer_show_dark_mode" id="bouncer_show_dark_mode" value="1" <?php checked(1, get_option('bouncer_show_dark_mode')); ?> />
                                <label for="bouncer_show_dark_mode"><?php _e('Show dark/light mode toggle button', 'bouncer'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Website Button', 'bouncer'); ?></th>
                            <td>
                                <input type="checkbox" name="bouncer_show_website_btn" id="bouncer_show_website_btn" value="1" <?php checked(1, get_option('bouncer_show_website_btn')); ?> />
                                <label for="bouncer_show_website_btn"><?php _e('Show website link button', 'bouncer'); ?></label>
                            </td>
                        </tr>
                        <tr id="bouncer-website-url-row">
                            <th><label for="bouncer_website_url"><?php _e('Website URL', 'bouncer'); ?></label></th>
                            <td>
                                <input type="url" name="bouncer_website_url" id="bouncer_website_url" value="<?php echo esc_attr(get_option('bouncer_website_url', '')); ?>" class="regular-text" placeholder="https://" />
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }

        function add_admin_bar_menu($wp_admin_bar)
        {
            if (!current_user_can('manage_options')) return;

            $enabled   = get_option('bouncer_enabled', false);
            $mode      = get_option('bouncer_mode', 'maintenance');
            $dot_color = $enabled ? '#f5c400' : '#888888';
            $dot_svg   = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" style="display:inline-block;vertical-align:middle;margin-right:5px;flex-shrink:0"><circle cx="8" cy="8" r="6" fill="' . $dot_color . '"/></svg>';
            $label     = $enabled ? 'Bouncer: ACTIVE' : 'Bouncer: OFF';
            $mode_text = $enabled ? ($mode === 'coming_soon' ? 'Coming Soon (200)' : 'Maintenance (503)') : 'Inactive';

            $wp_admin_bar->add_node(array(
                'id'    => 'bouncer-status',
                'title' => $dot_svg . '<span style="font-family:monospace">' . $label . '</span>',
                'href'  => '#',
                'meta'  => array('class' => 'bouncer-admin-bar'),
            ));
            $wp_admin_bar->add_node(array(
                'parent' => 'bouncer-status',
                'id'     => 'bouncer-toggle',
                'title'  => $enabled ? __('Deactivate Bouncer', 'bouncer') : __('Activate Bouncer', 'bouncer'),
                'href'   => '#',
                'meta'   => array('class' => 'bouncer-toggle-link'),
            ));
            $wp_admin_bar->add_node(array(
                'parent' => 'bouncer-status',
                'id'     => 'bouncer-settings',
                'title'  => __('Settings', 'bouncer'),
                'href'   => admin_url('options-general.php?page=bouncer'),
            ));
            $wp_admin_bar->add_node(array(
                'parent' => 'bouncer-status',
                'id'     => 'bouncer-mode-info',
                'title'  => '<em style="opacity:.7">Mode: ' . esc_html($mode_text) . '</em>',
                'href'   => '#',
                'meta'   => array('class' => 'bouncer-mode-info'),
            ));
        }

        // Runs on both frontend and admin — admin bar toggle + CSS only (1 nonce).
        function enqueue_adminbar_scripts()
        {
            if (!is_admin_bar_showing() || !current_user_can('manage_options')) return;
            $ajax_url     = admin_url('admin-ajax.php');
            $nonce_toggle = wp_create_nonce('bouncer_toggle_nonce');
            ?>
            <style>
                #wp-admin-bar-bouncer-mode-info { pointer-events: none; }
                #wp-admin-bar-bouncer-mode-info .ab-item { cursor: default !important; }
            </style>
            <script>
            document.addEventListener('DOMContentLoaded', function () {

                function bouncerAjax(data, onSuccess) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_js($ajax_url); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function () {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success && onSuccess) onSuccess(res);
                        } catch (e) {}
                    };
                    var parts = [];
                    for (var k in data) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
                    xhr.send(parts.join('&'));
                }
                window.bouncerAjax = bouncerAjax;

                var toggle = document.querySelector('.bouncer-toggle-link');
                if (toggle) {
                    toggle.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (!confirm('<?php echo esc_js(__('Toggle Bouncer status?', 'bouncer')); ?>')) return;
                        bouncerAjax({ action: 'bouncer_toggle', nonce: '<?php echo esc_js($nonce_toggle); ?>' }, function () {
                            location.reload();
                        });
                    });
                }

            });
            </script>
            <?php
        }

        // Runs on admin pages only — full settings-page JS (3 extra nonces, only on Bouncer settings page).
        function enqueue_admin_page_scripts($hook)
        {
            if ($hook !== 'settings_page_bouncer') return;
            if (!current_user_can('manage_options')) return;
            $nonce_create = wp_create_nonce('bouncer_create_nonce');
            $nonce_deact  = wp_create_nonce('bouncer_deactivate_nonce');
            $nonce_delete = wp_create_nonce('bouncer_delete_nonce');
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {

                // Copy link buttons (per row)
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.bouncer-copy-link');
                    if (!btn) return;
                    var url = btn.dataset.url;
                    navigator.clipboard.writeText(url).then(function () {
                        var orig = btn.textContent;
                        btn.textContent = '<?php echo esc_js(__('Copied!', 'bouncer')); ?>';
                        setTimeout(function () { btn.textContent = orig; }, 2000);
                    });
                });

                // Create link
                var createBtn = document.getElementById('bouncer-create-token');
                if (createBtn) {
                    createBtn.addEventListener('click', function () {
                        var nameInput = document.getElementById('bouncer-new-name');
                        var name = nameInput ? nameInput.value.trim() : '';
                        if (!name) {
                            nameInput && nameInput.focus();
                            return;
                        }
                        createBtn.disabled = true;
                        var expirySelect = document.getElementById('bouncer-new-expiry');
                        var expiry = expirySelect ? expirySelect.value : '86400';
                        window.bouncerAjax({ action: 'bouncer_create_token', nonce: '<?php echo esc_js($nonce_create); ?>', name: name, expiry: expiry }, function () {
                            location.reload();
                        });
                    });
                }

                // Deactivate link (per row)
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.bouncer-deactivate');
                    if (!btn) return;
                    var name = btn.dataset.name;
                    if (!confirm('<?php echo esc_js(__('Deactivate the link for', 'bouncer')); ?> "' + name + '"? <?php echo esc_js(__('They will lose access immediately. You can delete it afterwards if you no longer need the record.', 'bouncer')); ?>')) return;
                    window.bouncerAjax({ action: 'bouncer_deactivate_token', nonce: '<?php echo esc_js($nonce_deact); ?>', id: btn.dataset.id }, function () {
                        location.reload();
                    });
                });

                // Delete link (per row)
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.bouncer-delete');
                    if (!btn) return;
                    var name = btn.dataset.name;
                    if (!confirm('<?php echo esc_js(__('Permanently delete the link for', 'bouncer')); ?> "' + name + '"? <?php echo esc_js(__('This cannot be undone.', 'bouncer')); ?>')) return;
                    window.bouncerAjax({ action: 'bouncer_delete_token', nonce: '<?php echo esc_js($nonce_delete); ?>', id: btn.dataset.id }, function () {
                        location.reload();
                    });
                });

                // Retry After quick-set preset buttons
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.bouncer-retry-preset');
                    if (!btn) return;
                    var field = document.getElementById('bouncer_retry_after');
                    if (field) field.value = btn.dataset.val;
                });

                // Show/hide Website URL row based on Website Button checkbox
                var websiteCheck = document.getElementById('bouncer_show_website_btn');
                var websiteRow   = document.getElementById('bouncer-website-url-row');
                function syncWebsiteRow() {
                    if (websiteRow) websiteRow.style.display = websiteCheck.checked ? '' : 'none';
                }
                if (websiteCheck && websiteRow) {
                    syncWebsiteRow();
                    websiteCheck.addEventListener('change', syncWebsiteRow);
                }

                // Show/hide Secondary Text row based on secondary text checkbox
                var secondaryCheck = document.getElementById('bouncer_bilingual');
                var secondaryRow   = document.getElementById('bouncer-secondary-text-row');
                function syncSecondaryRow() {
                    if (secondaryRow) secondaryRow.style.display = secondaryCheck.checked ? '' : 'none';
                }
                if (secondaryCheck && secondaryRow) {
                    syncSecondaryRow();
                    secondaryCheck.addEventListener('change', syncSecondaryRow);
                }

            });
            </script>
            <?php
        }

        // ----- AJAX handlers -----

        function ajax_toggle_bouncer()
        {
            check_ajax_referer('bouncer_toggle_nonce', 'nonce');
            if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'bouncer'));
            $new = !get_option('bouncer_enabled', false);
            update_option('bouncer_enabled', $new);
            wp_send_json_success(array('enabled' => $new));
        }

        function ajax_create_token()
        {
            check_ajax_referer('bouncer_create_nonce', 'nonce');
            if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'bouncer'));
            $name   = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
            if (empty($name)) $name = __('Unnamed', 'bouncer');
            $expiry = isset($_POST['expiry']) ? (int) $_POST['expiry'] : DAY_IN_SECONDS;
            $tokens = $this->get_tokens();
            $id     = wp_generate_password(8, false);
            $tokens[$id] = $this->new_token_entry($name, $expiry);
            $this->save_tokens($tokens);
            wp_send_json_success();
        }

        function ajax_deactivate_token()
        {
            check_ajax_referer('bouncer_deactivate_nonce', 'nonce');
            if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'bouncer'));
            $id     = sanitize_text_field($_POST['id'] ?? '');
            $tokens = $this->get_tokens();
            if (isset($tokens[$id])) {
                $tokens[$id]['active'] = false;
                $this->save_tokens($tokens);
            }
            wp_send_json_success();
        }

        function ajax_delete_token()
        {
            check_ajax_referer('bouncer_delete_nonce', 'nonce');
            if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'bouncer'));
            $id     = sanitize_text_field($_POST['id'] ?? '');
            $tokens = $this->get_tokens();
            unset($tokens[$id]);
            $this->save_tokens($tokens);
            wp_send_json_success();
        }
        // ----- REST API: revision cleanup -----

        function register_rest_routes()
        {
            register_rest_route('bouncer/v1', '/revisions/(?P<parent_id>[\d]+)', array(
                'methods'             => 'DELETE',
                'callback'            => array($this, 'rest_delete_revisions'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
                'args' => array(
                    'parent_id' => array(
                        'validate_callback' => function ($v) { return is_numeric($v) && (int)$v > 0; },
                    ),
                ),
            ));
        }

        function rest_delete_revisions($request)
        {
            $parent_id = (int) $request['parent_id'];
            $revisions = wp_get_post_revisions($parent_id, array('numberposts' => -1, 'post_status' => 'inherit'));
            $deleted   = 0;
            foreach ($revisions as $revision) {
                if (wp_delete_post_revision($revision->ID)) {
                    $deleted++;
                }
            }
            return rest_ensure_response(array('parent_id' => $parent_id, 'deleted' => $deleted));
        }
    }

    $GLOBALS['bouncer'] = new BOUNCER();
}
