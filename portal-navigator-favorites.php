<?php
/**
 * Plugin Name: Portal Navigator Favorites
 * Description: Page navigation with per-user favorites and a simple custom user system (separate from WP users).
 * Version:     1.0.0
 * Author:      Shimanto Rehman
 * License:     GPLv2 or later
 * Text Domain: portal-navigator-favorites
 */

if (!defined('ABSPATH')) exit;

/**
 * ----------------------------------------------------------------------------
 * 0) Bootstrap (sessions for custom auth)
 * ----------------------------------------------------------------------------
 */
add_action('init', function () {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}, 1);

/**
 * ----------------------------------------------------------------------------
 * 1) Activation: create tables for custom users and favorites
 * ----------------------------------------------------------------------------
 */
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $users_table = $wpdb->prefix . 'pn_custom_users';
    $favs_table  = $wpdb->prefix . 'pn_user_favorites';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Custom users (separate from WP users)
    $sql_users = "CREATE TABLE {$users_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY username_idx (username)
    ) {$charset};";
    dbDelta($sql_users);

    // Favorites pivot
    $sql_favs = "CREATE TABLE {$favs_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_page_unique (user_id, page_id),
        KEY user_idx (user_id),
        KEY page_idx (page_id)
    ) {$charset};";
    dbDelta($sql_favs);
});

/**
 * ----------------------------------------------------------------------------
 * 2) Minimal custom user system (helpers)
 * ----------------------------------------------------------------------------
 */
function pn_is_user_logged_in() : bool {
    return !empty($_SESSION['pn_user_id']);
}
function pn_get_current_user() : ?array {
    if (!pn_is_user_logged_in()) return null;
    static $cached = null;
    if ($cached) return $cached;

    global $wpdb;
    $table = $wpdb->prefix . 'pn_custom_users';
    $uid = (int) $_SESSION['pn_user_id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $uid), ARRAY_A);
    $cached = $row ?: null;
    return $cached;
}
function pn_login_user(string $username, string $password) : array {
    global $wpdb;
    $table = $wpdb->prefix . 'pn_custom_users';
    $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE username=%s", $username), ARRAY_A);
    if (!$u) return ['success'=>false,'message'=>__('Invalid credentials.','portal-navigator-favorites')];
    if (!password_verify($password, $u['password_hash'])) {
        return ['success'=>false,'message'=>__('Invalid credentials.','portal-navigator-favorites')];
    }
    $_SESSION['pn_user_id'] = (int) $u['id'];
    return ['success'=>true,'message'=>__('Logged in successfully.','portal-navigator-favorites')];
}
function pn_logout_user() {
    unset($_SESSION['pn_user_id']);
}

/**
 * ----------------------------------------------------------------------------
 * 3) Favorites storage API (DB)
 * ----------------------------------------------------------------------------
 */
function pn_get_user_favorites(int $user_id) : array {
    global $wpdb;
    $table = $wpdb->prefix . 'pn_user_favorites';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT page_id FROM {$table} WHERE user_id=%d ORDER BY id DESC", $user_id));
    return array_map('intval', $ids ?: []);
}
function pn_add_favorite(int $user_id, int $page_id) : bool {
    global $wpdb;
    $table = $wpdb->prefix . 'pn_user_favorites';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND page_id=%d", $user_id, $page_id));
    if ($exists) return true;
    return (bool) $wpdb->insert($table, ['user_id'=>$user_id,'page_id'=>$page_id], ['%d','%d']);
}
function pn_remove_favorite(int $user_id, int $page_id) : bool {
    global $wpdb;
    $table = $wpdb->prefix . 'pn_user_favorites';
    return (bool) $wpdb->delete($table, ['user_id'=>$user_id,'page_id'=>$page_id], ['%d','%d']);
}

/**
 * ----------------------------------------------------------------------------
 * 4) AJAX: Toggle favorite (❤️ / 🤍)
 * ----------------------------------------------------------------------------
 */
add_action('wp_ajax_pn_toggle_favorite', 'pn_toggle_favorite');
add_action('wp_ajax_nopriv_pn_toggle_favorite', 'pn_toggle_favorite');
function pn_toggle_favorite() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'pn_favorite_nonce')) {
        wp_send_json_error(['message'=>__('Invalid request.','portal-navigator-favorites')]);
    }
    if (!pn_is_user_logged_in()) {
        wp_send_json_error(['message'=>__('Please log in to save favorites.','portal-navigator-favorites')]);
    }
    $user = pn_get_current_user();
    if (!$user) wp_send_json_error(['message'=>__('User not found.','portal-navigator-favorites')]);

    $page_id = isset($_POST['page_id']) ? (int) $_POST['page_id'] : 0;
    if ($page_id <= 0) wp_send_json_error(['message'=>__('Invalid page.','portal-navigator-favorites')]);

    $favorites = pn_get_user_favorites((int) $user['id']);
    $is_fav = in_array($page_id, $favorites, true);

    $ok = $is_fav ? pn_remove_favorite((int) $user['id'], $page_id) : pn_add_favorite((int) $user['id'], $page_id);
    if (!$ok) wp_send_json_error(['message'=>__('Could not update favorites.','portal-navigator-favorites')]);

    wp_send_json_success([
        'is_favorite' => !$is_fav,
        'message'     => !$is_fav ? __('Added to favorites.','portal-navigator-favorites')
                                  : __('Removed from favorites.','portal-navigator-favorites')
    ]);
}

/**
 * ----------------------------------------------------------------------------
 * 5) Assets (JS/CSS) only when needed
 * ----------------------------------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) return;

    $post = get_post();
    if (!$post) return;

    $has_shortcode = ( has_shortcode($post->post_content, 'pn_sitemap')
                    || has_shortcode($post->post_content, 'pn_favorites') );

    if (!$has_shortcode) return;

    wp_enqueue_script('jquery');

    $handle = 'pn-favorites';
    wp_register_script($handle, false, ['jquery'], '1.0.0', true);
    wp_enqueue_script($handle);

    wp_localize_script($handle, 'pnFav', [
        'ajax'   => admin_url('admin-ajax.php'),
        'nonce'  => wp_create_nonce('pn_favorite_nonce'),
        'debug'  => defined('WP_DEBUG') && WP_DEBUG,
    ]);

    $js = <<<JS
    (function($){
      "use strict";
      $(document).on('click', '.pn-fav-btn', function(e){
        e.preventDefault();
        var \$btn = $(this);
        var pid   = parseInt(\$btn.data('page-id'),10);
        var nonce = pnFav.nonce;
        if(!pid) return;

        \$btn.prop('disabled', true).addClass('processing');
        $.ajax({
          url: pnFav.ajax,
          method: 'POST',
          dataType: 'json',
          data: { action: 'pn_toggle_favorite', page_id: pid, nonce: nonce }
        }).done(function(res){
          if(res && res.success){
            if(res.data.is_favorite){
              \$btn.addClass('active').text('❤️');
            } else {
              \$btn.removeClass('active').text('🤍');
            }
          } else {
            alert((res && res.data && res.data.message) ? res.data.message : 'Error');
          }
        }).fail(function(){
          alert('Request failed');
        }).always(function(){
          \$btn.prop('disabled', false).removeClass('processing');
        });
      });
    })(jQuery);
    JS;
    wp_add_inline_script($handle, $js);

    $css = <<<CSS
    .pn-fav-btn{
      background:none;border:none;cursor:pointer;font-size:16px;margin-left:10px;line-height:1;
      transition:transform .18s ease;
    }
    .pn-fav-btn:hover{ transform: scale(1.15); }
    .pn-fav-btn.active{ color:#e11d48; }
    .pn-login-card, .pn-auth-card{
      max-width:560px;margin:56px auto;padding:32px 28px;text-align:center;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;
      background:#fff;border-radius:18px;box-shadow:0 14px 36px rgba(18,38,63,.14);border:1px solid #eef1f7;position:relative;overflow:hidden;
    }
    .pn-btn{
      display:inline-flex;align-items:center;gap:10px;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700;font-size:15px;color:#fff;
      background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:0;transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
    }
    .pn-btn:hover{ transform:translateY(-2px); box-shadow:0 10px 24px rgba(102,126,234,.28); filter:brightness(1.03); }
    CSS;
    wp_register_style('pn-favorites-style', false, [], '1.0.0');
    wp_enqueue_style('pn-favorites-style');
    wp_add_inline_style('pn-favorites-style', $css);
});

/**
 * ----------------------------------------------------------------------------
 * 6) Shortcode: [pn_login] — custom user login form
 * ----------------------------------------------------------------------------
 */
add_shortcode('pn_login', function ($atts = []) {
    if (pn_is_user_logged_in()) {
        $u = pn_get_current_user();
        ob_start(); ?>
        <div class="pn-auth-card">
            <h3><?php echo esc_html(sprintf(__('Hello, %s','portal-navigator-favorites'), $u ? ($u['display_name'] ?: $u['username']) : '')); ?></h3>
            <form method="post">
                <?php wp_nonce_field('pn_logout','pn_logout_nonce'); ?>
                <button class="pn-btn" type="submit" name="pn_do_logout" value="1"><?php _e('Log out','portal-navigator-favorites'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // Handle login submission
    if (!empty($_POST['pn_do_login']) && check_admin_referer('pn_login','pn_login_nonce')) {
        $username = isset($_POST['pn_username']) ? sanitize_text_field($_POST['pn_username']) : '';
        $password = isset($_POST['pn_password']) ? (string) $_POST['pn_password'] : '';
        $res = pn_login_user($username, $password);
        if ($res['success']) {
            wp_safe_redirect(remove_query_arg(['login_error']));
            exit;
        } else {
            $_GET['login_error'] = $res['message'];
        }
    }

    // Handle logout
    if (!empty($_POST['pn_do_logout']) && check_admin_referer('pn_logout','pn_logout_nonce')) {
        pn_logout_user();
        wp_safe_redirect(remove_query_arg(['login_error']));
        exit;
    }

    $error = isset($_GET['login_error']) ? sanitize_text_field($_GET['login_error']) : '';
    ob_start(); ?>
    <div class="pn-login-card">
        <h3><?php _e('Custom User Login','portal-navigator-favorites'); ?></h3>
        <?php if ($error): ?>
            <p style="color:#b91c1c;margin:6px 0 12px;"><?php echo esc_html($error); ?></p>
        <?php endif; ?>
        <form method="post" style="display:grid;gap:10px;max-width:360px;margin:0 auto;">
            <?php wp_nonce_field('pn_login','pn_login_nonce'); ?>
            <input type="text" name="pn_username" placeholder="<?php esc_attr_e('Username','portal-navigator-favorites'); ?>" required style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">
            <input type="password" name="pn_password" placeholder="<?php esc_attr_e('Password','portal-navigator-favorites'); ?>" required style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">
            <button class="pn-btn" type="submit" name="pn_do_login" value="1"><?php _e('Login','portal-navigator-favorites'); ?></button>
        </form>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * ----------------------------------------------------------------------------
 * 7) Shortcode: [pn_sitemap] — page navigation with hearts
 * ----------------------------------------------------------------------------
 */
add_shortcode('pn_sitemap', function ($atts = []) {
    // Build list with wp_list_pages then inject heart buttons
    $args = [
        'title_li' => '',
        'echo'     => false,
        'sort_column' => 'menu_order,post_title'
    ];
    $html = wp_list_pages($args);

    if (pn_is_user_logged_in()) {
        $user = pn_get_current_user();
        $user_id = (int) $user['id'];
        $favorites = pn_get_user_favorites($user_id);
        $nonce = wp_create_nonce('pn_favorite_nonce');

        $html = preg_replace_callback('/<li[^>]*><a href="([^"]+)">(.+?)<\/a><\/li>/i', function ($m) use ($favorites, $nonce) {
            $url   = esc_url_raw($m[1]);
            $title = wp_kses_post($m[2]);
            $slug  = str_replace(trailingslashit(home_url()), '', $url);
            $page  = get_page_by_path($slug);
            if (!$page) {
                // fallback: try by URL to ID
                $pid = url_to_postid($url);
                if ($pid) { $page = get_post($pid); }
            }
            if (!$page) return $m[0];

            $pid = (int) $page->ID;
            $is_fav = in_array($pid, $favorites, true);
            $icon = $is_fav ? '❤️' : '🤍';

            $btn = sprintf(
                '<button class="pn-fav-btn %s" data-page-id="%d" data-nonce="%s" type="button">%s</button>',
                $is_fav ? 'active' : '',
                $pid,
                esc_attr($nonce),
                $icon
            );
            return '<li><a href="'.$url.'">'.$title.'</a> '.$btn.'</li>';
        }, $html);
    }

    ob_start(); ?>
    <div class="pn-sitemap">
        <ul class="pn-sitemap-list">
            <?php echo $html; ?>
        </ul>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * ----------------------------------------------------------------------------
 * 8) Shortcode: [pn_favorites] — current user favorites list (cards)
 * ----------------------------------------------------------------------------
 */
add_shortcode('pn_favorites', function ($atts = []) {
    if (!pn_is_user_logged_in()) {
        ob_start(); ?>
        <div class="pn-auth-card" role="region" aria-label="<?php esc_attr_e('Login required','portal-navigator-favorites'); ?>">
            <h3 style="margin:0 0 8px;"><?php _e('Please log in to view your favorites','portal-navigator-favorites'); ?></h3>
            <p style="margin:0 0 18px;color:#64748b;"><?php _e('Your saved pages will appear here after you sign in.','portal-navigator-favorites'); ?></p>
            <a class="pn-btn" href="<?php echo esc_url( home_url('/login/') ); ?>">
                <?php _e('Login','portal-navigator-favorites'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    $u = pn_get_current_user();
    $favs = pn_get_user_favorites((int) $u['id']);

    ob_start(); ?>
    <div class="pn-favorites-wrap" style="max-width:1040px;margin:40px auto 64px;background:#f8f9fb;border-radius:22px;box-shadow:0 16px 48px rgba(18,38,63,.12);overflow:hidden;">
        <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:32px 24px;color:#fff;text-align:center;">
            <h2 style="margin:0 0 6px;font-size:26px;"><?php _e('❤️ Your Favorite Pages','portal-navigator-favorites'); ?></h2>
            <p style="margin:0;opacity:.92;"><?php _e('Quick access to content you’ve saved.','portal-navigator-favorites'); ?></p>
        </div>

        <?php if (empty($favs)): ?>
            <div style="text-align:center;padding:46px 28px 56px;">
                <p style="margin:0 0 12px;color:#334155;font-size:16px;"><?php _e('You haven’t added any pages yet.','portal-navigator-favorites'); ?></p>
                <p style="margin:0;color:#64748b;font-size:14px;"><?php _e('Browse the portal and tap the heart ♥ to save pages.','portal-navigator-favorites'); ?></p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:14px;padding:22px;">
                <?php foreach ($favs as $pid): ?>
                    <?php $p = get_post($pid); if (!$p || $p->post_status !== 'publish') continue; ?>
                    <article style="background:#fff;border:1px solid #e9ecf1;border-radius:16px;padding:18px;transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;">
                        <a href="<?php echo esc_url(get_permalink($pid)); ?>" style="color:#1f2937;text-decoration:none;font-weight:700;font-size:17px;line-height:1.35;">
                            <?php echo esc_html(get_the_title($pid)); ?>
                        </a>
                        <div style="color:#64748b;font-size:13px;margin-top:6px;">
                            <?php echo esc_html(get_the_date('', $pid)); ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});
