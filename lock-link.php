<?php
/*
 * Plugin Name: Lock Link
 * Description: Create secure, expiring preview links for pages or your entire WordPress site. Share access with clients, without accounts.
 * Version: 1.0.0
 * Author: acpSiam
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */


add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'toplevel_page_preview-link-manager') {
        wp_enqueue_style('preview-link-admin', plugins_url('admin-style.css', __FILE__));
    }
});

add_action('admin_menu', function () {
    add_menu_page(
        'Preview Link Manager',
        'Preview Links',
        'manage_options',
        'preview-link-manager',
        'render_preview_link_admin_page',
        'dashicons-link',
        80
    );
});

add_action('admin_post_export_preview_links', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $tokens_data = get_option('custom_preview_tokens', []);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=preview_links.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Client', 'Page Title', 'Created At', 'Expires At', 'Token', 'Show Info Bar', 'Link']);

    foreach ($tokens_data as $token => $data) {
        $page_title = $data['site_wide'] ? 'Entire Website' : get_the_title($data['page_id']);
        $link = ($data['site_wide'] ? home_url() : get_permalink($data['page_id'])) . '?token=' . urlencode($token);
        fputcsv($output, [
            $data['client_name'],
            $page_title,
            $data['created_at'],
            $data['expires_at'],
            $token,
            $data['show_bar'] ? 'Yes' : 'No',
            $link
        ]);
    }

    fclose($output);
    exit;
});

add_action('template_redirect', function () {
    global $pagenow;
    
    // Bypass validation for admin pages and login
    if (is_admin() || $pagenow === 'wp-login.php' || defined('XMLRPC_REQUEST') || defined('REST_REQUEST')) {
        return;
    }

    if (is_user_logged_in() && current_user_can('administrator')) {
        return;
    }

    global $post;
    $current_page_id = isset($post->ID) ? $post->ID : 0;
    $provided_token = $_GET['token'] ?? $_COOKIE['preview_token'] ?? '';
    $tokens_data = get_option('custom_preview_tokens', []);

    // Clear invalid cookies
    if (empty($provided_token)) {
        setcookie('preview_token', '', time() - 3600, '/');
    }

    $valid_token = null;
    $is_site_wide = false;

    if (!empty($provided_token)) {
        $token_data = $tokens_data[$provided_token] ?? null;

        if ($token_data) {
            $expiry = strtotime($token_data['expires_at'] . ' UTC');
            $valid_token = time() <= $expiry;

            if ($valid_token) {
                // Set cookie if token came from URL
                if (isset($_GET['token'])) {
                    setcookie('preview_token', $provided_token, [
                        'expires' => $expiry,
                        'path' => '/',
                        'secure' => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }

                $is_site_wide = $token_data['site_wide'];
                $current_page_valid = $is_site_wide || $token_data['page_id'] == $current_page_id;

                if (!$current_page_valid) {
                    wp_die('⛔ This token is not valid for this page.');
                }
            } else {
                setcookie('preview_token', '', time() - 3600, '/');
                unset($tokens_data[$provided_token]);
                update_option('custom_preview_tokens', $tokens_data);
            }
        }
    }

    if (!$valid_token) {
        // Check if page is protected
        $is_protected = false;
        $has_active_site_wide = false;

        foreach ($tokens_data as $data) {
            if ($data['site_wide'] && time() <= strtotime($data['expires_at'] . ' UTC')) {
                $has_active_site_wide = true;
            }
            if (!$data['site_wide'] && $data['page_id'] == $current_page_id && time() <= strtotime($data['expires_at'] . ' UTC')) {
                $is_protected = true;
            }
        }

        if ($has_active_site_wide || $is_protected) {
            wp_die('⛔ This content requires a valid preview token. Please use your provided link.');
        }
        return;
    }

    // Show info bar if enabled
    if (!empty($token_data['show_bar'])) {
        add_action('wp_footer', function () use ($token_data, $expiry) {
            $client_name = esc_html($token_data['client_name']);
            echo "<div style='position:fixed;top:0;left:0;right:0;background:linear-gradient(145deg, #2c3e50, #3498db);color:#fff;
                  padding:15px 20px;font-size:15px;z-index:9999;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,0.2);
                  display:flex;justify-content:center;align-items:center;gap:15px;'>
                  <span>🔍 Preview for <strong>$client_name</strong></span>
                  <span class='sep'>|</span>
                  <span>⏳ Expires in <span id='countdown' style='font-weight:bold'></span></span>
                  </div>";
            echo "<script>
                function updateCountdown() {
                    var expiryTime = $expiry * 1000;
                    var now = new Date().getTime();
                    var distance = expiryTime - now;

                    if (distance <= 0) {
                        document.getElementById('countdown').innerText = 'Expired';
                        return;
                    }

                    var d = Math.floor(distance / (1000 * 60 * 60 * 24));
                    var h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    var m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                    var s = Math.floor((distance % (1000 * 60)) / 1000);

                    document.getElementById('countdown').innerText = `${d}d ${h}h ${m}m ${s}s`;
                    setTimeout(updateCountdown, 1000);
                }
                updateCountdown();
            </script>";
        });
    }
});

function render_preview_link_admin_page() {
    $tokens_data = get_option('custom_preview_tokens', []);
    $pages = get_pages();

    if (isset($_POST['generate_preview_link'])) {
        $client_name = sanitize_text_field($_POST['client_name']);
        $target_type = sanitize_text_field($_POST['target_type'] ?? 'specific_page');
        $site_wide = ($target_type === 'site_wide');
        $page_id = $site_wide ? 0 : intval($_POST['page_id']);
        $show_bar = isset($_POST['show_bar']) ? true : false;

        $timezone = wp_timezone();
        
        if (!empty($_POST['custom_date'])) {
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['custom_date'], $timezone);
            if ($date) {
                $date->setTimezone(new DateTimeZone('UTC'));
                $expires_at = $date->format('Y-m-d\TH:i');
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ Invalid date format</p></div>';
                $expires_at = null;
            }
        } else {
            $duration_days = intval($_POST['duration_days']);
            $duration_hours = intval($_POST['duration_hours']);
            $duration_minutes = intval($_POST['duration_minutes']);
            
            $now = new DateTime('now', $timezone);
            $now->modify("+$duration_days days +$duration_hours hours +$duration_minutes minutes");
            $now->setTimezone(new DateTimeZone('UTC'));
            $expires_at = $now->format('Y-m-d\TH:i');
        }

        if ($expires_at) {
            $token = bin2hex(random_bytes(8));
            $tokens_data[$token] = [
                'client_name' => $client_name,
                'expires_at' => $expires_at,
                'page_id' => $page_id,
                'site_wide' => $site_wide,
                'show_bar' => $show_bar,
                'created_at' => current_time('mysql', true),
            ];
            update_option('custom_preview_tokens', $tokens_data);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Link generated successfully for ' . esc_html($client_name) . '</p></div>';
        }
    }

    if (isset($_GET['delete_token'])) {
        $delete_token = sanitize_text_field($_GET['delete_token']);
        if (isset($tokens_data[$delete_token])) {
            unset($tokens_data[$delete_token]);
            update_option('custom_preview_tokens', $tokens_data);
            echo '<div class="notice notice-success is-dismissible"><p>🗑️ Token deleted.</p></div>';
        }
    }

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">🔑 Client Preview Link Manager</h1>';
    echo '<a href="' . admin_url('admin-post.php?action=export_preview_links') . '" class="page-title-action">Export CSV</a>';
    echo '<hr class="wp-header-end">';

    echo '<div class="preview-link-box">';
    echo '<h2>Generate New Preview Link</h2>';
    echo '<form method="POST">';
    echo '<table class="form-table">';
    echo '<tr><th scope="row"><label for="client_name">Client Name</label></th>';
    echo '<td><input type="text" id="client_name" name="client_name" required class="regular-text"></td></tr>';
    
    echo '<tr><th scope="row"><label for="target_type">Target</label></th>';
    echo '<td><div class="target-type-select">';
    echo '<label><input type="radio" name="target_type" value="specific_page" checked> Specific Page</label>';
    echo '<label><input type="radio" name="target_type" value="site_wide"> Entire Website</label>';
    echo '</div><div id="page_id_container">';
    echo '<select id="page_id" name="page_id" required>';
    foreach ($pages as $page) {
        echo '<option value="' . $page->ID . '">' . esc_html($page->post_title) . '</option>';
    }
    echo '</select></div></td></tr>';
    
    echo '<tr><th scope="row"><label>Expiration Settings</label></th>';
    echo '<td><div class="expiration-settings">';
    echo '<div><label>Duration:</label>';
    echo '<input type="number" name="duration_days" value="0" min="0"> days ';
    echo '<input type="number" name="duration_hours" value="1" min="0"> hours ';
    echo '<input type="number" name="duration_minutes" value="0" min="0"> minutes</div>';
    echo '<div style="margin-top:10px"><label>Or set exact expiration (Site Time: ' . date_i18n('M j, Y H:i') . '):</label>';
    echo '<input type="datetime-local" name="custom_date"></div>';
    echo '</div></td></tr>';
    
    echo '<tr><th scope="row"><label for="show_bar">Show Info Bar</label></th>';
    echo '<td><input type="checkbox" id="show_bar" name="show_bar" checked></td></tr>';
    echo '</table>';
    echo '<p><input type="submit" name="generate_preview_link" class="button button-primary" value="Generate Link"></p>';
    echo '</form>';
    echo '</div>';

    echo '<div class="preview-link-box" id="preview-link-table">';
    echo '<h2>Existing Preview Links</h2>';
    echo '<table class="wp-list-table widefat fixed striped table-view-list">';
    echo '<thead><tr>
            <th>Client</th>
            <th>Target</th>
            <th>Created At</th>
            <th>Expires At</th>
            <th>Info Bar</th>
            <th>Token</th>
            <th>Link</th>
            <th>Actions</th>
          </tr></thead><tbody>';

    if (empty($tokens_data)) {
        echo '<tr><td colspan="8">No preview links found.</td></tr>';
    } else {
        foreach ($tokens_data as $token => $data) {
            $link = ($data['site_wide'] ? home_url() : get_permalink($data['page_id'])) . '?token=' . urlencode($token);
            $created_at = date_i18n('M j, Y g:i a', strtotime($data['created_at'] . ' UTC'));
            $expires_at = date_i18n('M j, Y g:i a', strtotime($data['expires_at'] . ' UTC'));
            $is_expired = time() > strtotime($data['expires_at'] . ' UTC');

            echo '<tr' . ($is_expired ? ' style="background:#ffeeee"' : '') . '>';
            echo '<td>' . esc_html($data['client_name']) . '</td>';
            echo '<td>' . ($data['site_wide'] ? '🌐 Entire Website' : '📄 ' . esc_html(get_the_title($data['page_id']))) . '</td>';
            echo '<td>' . $created_at . '</td>';
            echo '<td>' . ($is_expired ? '<span class="expired">' . $expires_at . '</span>' : $expires_at) . '</td>';
            echo '<td>' . ($data['show_bar'] ? '✅ Enabled' : '❌ Disabled') . '</td>';
            echo '<td><code class="token-code">' . esc_html($token) . '</code></td>';
            echo '<td><div class="link-actions">';
            echo '<a href="' . esc_url($link) . '" target="_blank" class="button">View</a>';
            echo '<button type="button" class="button copy-link" data-clipboard-text="' . esc_attr($link) . '">Copy</button>';
            echo '</div></td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=preview-link-manager&delete_token=' . $token)) . '" class="button delete-link">Delete</a></td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody></table></div></div>';
    
    echo '<script>
    jQuery(document).ready(function($) {
        $("input[name=\"target_type\"]").change(function() {
            if ($(this).val() === "site_wide") {
                $("#page_id_container").hide();
                $("#page_id").removeAttr("required");
            } else {
                $("#page_id_container").show();
                $("#page_id").attr("required", "required");
            }
        });

        $(".copy-link").click(function() {
            var text = $(this).data("clipboard-text");
            navigator.clipboard.writeText(text).then(() => {
                $(this).text("Copied!").delay(2000).fadeOut(300, function() {
                    $(this).text("Copy").fadeIn();
                });
            });
        });
    });
    </script>';
}