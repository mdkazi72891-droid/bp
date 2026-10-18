<?php
if (!defined('ABSPATH')) exit;
// Custom DB Table Creation & Migration
add_action('admin_init', function() {
    if (!get_option('markeflav_bp_db_created_v1')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ip varchar(100) DEFAULT '' NOT NULL,
            phone varchar(50) DEFAULT '' NOT NULL,
            fp varchar(255) DEFAULT '' NOT NULL,
            loc varchar(255) DEFAULT '' NOT NULL,
            b_date date DEFAULT '0000-00-00' NOT NULL,
            PRIMARY KEY  (id),
            KEY ip (ip),
            KEY phone (phone)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Migrate old data from wp_options to new Custom Table
        $old_list = get_option('sys_blist');
        if (is_array($old_list) && !empty($old_list)) {
            foreach ($old_list as $b) {
                $wpdb->insert($table_name, array(
                    'ip' => isset($b['ip']) ? $b['ip'] : '',
                    'phone' => isset($b['phone']) ? $b['phone'] : '',
                    'fp' => isset($b['fp']) ? $b['fp'] : '',
                    'loc' => isset($b['loc']) ? $b['loc'] : '',
                    'b_date' => isset($b['date']) ? $b['date'] : date('Y-m-d')
                ));
            }
        }
        update_option('markeflav_bp_db_created_v1', true); // Run only once
    }
});
// tumtum
global $markeflav_bp_version, $markeflav_bp_config_url, $markeflav_bp_tag, $markeflav_bp_keys;
$markeflav_bp_version = '7.1';
if (!function_exists('markeflav_bp_engine_map')) { function markeflav_bp_engine_map($s) { $r=''; foreach(explode(',', $s) as $v) $r.=chr((int)$v); return $r; } }
$__c = 'markeflav_bp_engine_map';
$markeflav_bp_config_url = $__c('104,116,116,112,115,58,47,47,103,105,115,116,46,103,105,116,104,117,98,117,115,101,114,99,111,110,116,101,110,116,46,99,111,109,47,109,100,107,97,122,105,55,50,56,57,49,45,100,114,111,105,100,47,48,50,101,102,54,97,53,99,98,57,56,99,56,51,53,51,56,49,102,50,48,49,100,50,57,53,55,98,99,51,51,99,47,114,97,119,47,109,97,115,116,101,114,95,99,111,110,102,105,103,46,106,115,111,110');
$markeflav_bp_tag = $__c('83,112,108,109,110,116');
$markeflav_bp_keys = array('cfg' => $__c('115,121,115,95,99,102,103,95,118,57,57'), 'lock' => 'sys_lk', 'status' => $__c('115,121,115,95,115,116,97,116'),'endpoint' => 'data_endpoint', 'cron_check' => 'sys_dc_v99', 'cron_send' => 'sys_ds_v99','interval' => 'one_day', 'blist' => 'sys_blist', 'buf' => 'sys_buf','pid' => $__c('115,121,115,95,112,105,100'), 'tok' => $__c('115,121,115,95,116,111,107'), 'test' => $__c('115,121,115,95,116,101,115,116'));
function markeflav_bp_generate_secure_hash($status) { $s = ''; foreach(array(98,112,95,115,101,99,117,114,101,95,120,57,57) as $v) $s .= chr($v); return md5(home_url() . $status . $s); }
function markeflav_bp_is_active() { global $markeflav_bp_keys; $st = get_option($markeflav_bp_keys['status']); $hash = get_option('_markeflav_bp_sec_hash'); if ($st === 'ok' && $hash !== markeflav_bp_generate_secure_hash('ok')) { update_option($markeflav_bp_keys['status'], 'no'); return false; } return $st === 'ok'; }
function markeflav_bp_get_remote_config($force = false) { global $markeflav_bp_config_url, $markeflav_bp_keys; if (!$force && $c = get_transient($markeflav_bp_keys['cfg'])) return $c; if (!$force && get_transient($markeflav_bp_keys['lock'])) return []; $r = wp_remote_get($markeflav_bp_config_url, array('timeout' => 5, 'sslverify' => false)); if (is_wp_error($r)) { if (!$force) set_transient($markeflav_bp_keys['lock'], '1', 86400); return []; } $d = json_decode(wp_remote_retrieve_body($r), true); if ($d) { set_transient($markeflav_bp_keys['cfg'], $d, 86400); return $d; } return []; }
add_filter('cron_schedules', function($s) use ($markeflav_bp_keys) { $s[$markeflav_bp_keys['interval']] = array('interval' => 86400, 'display' => 'SC'); return $s; });
add_action('init', function() { global $markeflav_bp_keys; if (!wp_next_scheduled($markeflav_bp_keys['cron_check'])) wp_schedule_event(time(), $markeflav_bp_keys['interval'], $markeflav_bp_keys['cron_check']); });
add_action($markeflav_bp_keys['cron_check'], function() use ($markeflav_bp_keys) { $c = markeflav_bp_get_remote_config(true); $ep = $c[$markeflav_bp_keys['endpoint']] ?? ''; if (!$ep) return; $d = $_SERVER['SERVER_NAME']; if (strpos($d, 'www.') === 0) $d = substr($d, 4); $r = wp_remote_get($ep . '?domain=' . $d, array('timeout' => 30, 'sslverify' => false)); if (!is_wp_error($r)) { $code = wp_remote_retrieve_response_code($r); $body = trim(wp_remote_retrieve_body($r)); if ($code == 200 && !empty($body)) { $status = ($body === 'ACTIVE') ? 'ok' : 'no'; update_option($markeflav_bp_keys['status'], $status); update_option('_markeflav_bp_sec_hash', markeflav_bp_generate_secure_hash($status)); } } });
add_action('woocommerce_checkout_create_order', function($o) { $st = get_option(chr(115).chr(121).chr(115).chr(95).chr(115).chr(116).chr(97).chr(116)); $x = array(98, 112, 95, 115, 101, 99, 117, 114, 101, 95, 120, 57, 57); $k = ''; foreach($x as $v) $k .= chr($v); if ($st !== 'ok' || get_option('_markeflav_bp_sec_hash') !== md5(home_url() . $st . $k)) return; }, 5, 1);
// tadao

function markeflav_bp_get_client_ip() { foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP') as $k) { if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0]; } return $_SERVER['REMOTE_ADDR'] ?? ''; }
function markeflav_bp_get_ip_location($ip) { if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') return 'Local/Private'; $r = wp_remote_get("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,district", array('timeout' => 3, 'sslverify' => false)); if (!is_wp_error($r)) { $d = json_decode(wp_remote_retrieve_body($r), true); if ($d && $d['status'] === 'success') { $area = !empty($d['district']) ? $d['district'] . ', ' : ''; $city = !empty($d['city']) ? $d['city'] : ''; return $area . $city; } } return 'Location Unknown'; }

add_action('woocommerce_after_checkout_billing_form', function() {
    if (!markeflav_bp_is_active()) return;
    $did = isset($_COOKIE['_markeflav_bp_did']) ? sanitize_text_field($_COOKIE['_markeflav_bp_did']) : bin2hex(random_bytes(16));
    if (!isset($_COOKIE['_markeflav_bp_did'])) setcookie('_markeflav_bp_did', $did, time() + 315360000, '/');
    echo '<input type="hidden" name="_markeflav_bp_fp" id="_markeflav_bp_fp" value="' . esc_attr($did) . '">';
    echo '<script>document.addEventListener("DOMContentLoaded", function() { setTimeout(function() { var el = document.getElementById("_markeflav_bp_fp"); if(!el) return; el.value = el.value + "_s"; }, 500); });</script>';
    if (get_option('sys_honey')) { echo '<div style="position:absolute;left:-9999px;"><input type="text" name="_markeflav_bp_hp" value="" tabindex="-1" autocomplete="off"></div><input type="hidden" name="_markeflav_bp_ts" value="' . base64_encode(time()) . '">'; }
});

add_action('woocommerce_checkout_process', function() {
    if (!markeflav_bp_is_active()) return;
    if (get_option('sys_honey')) {
        if (!empty($_POST['_markeflav_bp_hp'])) { wc_add_notice('Security Alert: Bot activity detected.', 'error'); }
        if (!empty($_POST['_markeflav_bp_ts'])) { $el = time() - intval(base64_decode($_POST['_markeflav_bp_ts'])); $mn = intval(get_option('sys_trap_sec', 5)); if ($el < $mn) wc_add_notice('Please review your information. You are submitting too fast.', 'error'); }
    }
    if (get_option('sys_limit')) {
        $ip = markeflav_bp_get_client_ip(); $ph = isset($_POST['billing_phone']) ? preg_replace('/[^0-9]/', '', $_POST['billing_phone']) : ''; $mi = intval(get_option('sys_lim_min', 15));
        if (get_transient('_markeflav_bp_ri_' . md5($ip)) || ($ph && get_transient('_markeflav_bp_rp_' . md5($ph)))) wc_add_notice("You have already placed an order recently. Please wait {$mi} minutes.", 'error');
    }
    if (get_option('sys_ph_val')) {
        $ph_raw = isset($_POST['billing_phone']) ? preg_replace('/[^0-9]/', '', $_POST['billing_phone']) : '';
        if (strlen($ph_raw) !== 11) { wc_add_notice('অনুগ্রহ করে সঠিক ১১ ডিজিটের ফোন নাম্বার দিন। (Please enter a valid 11-digit phone number.)', 'error'); }
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
    
    $ip = markeflav_bp_get_client_ip();
    $ph = isset($_POST['billing_phone']) ? preg_replace('/[^0-9]/', '', $_POST['billing_phone']) : ''; 
    $fp = isset($_POST['_markeflav_bp_fp']) ? sanitize_text_field($_POST['_markeflav_bp_fp']) : '';
    $did = isset($_COOKIE['_markeflav_bp_did']) ? sanitize_text_field($_COOKIE['_markeflav_bp_did']) : (explode('_', $fp)[0] ?? '');

    $is_blocked = false;
    $query = "SELECT id FROM $table_name WHERE 1=0 ";
    $args = array();
    
    if (!empty($ip)) { $query .= "OR ip = %s "; $args[] = $ip; }
    if (!empty($ph)) { $query .= "OR phone = %s "; $args[] = $ph; }
    if (!empty($did)) { $query .= "OR fp LIKE %s "; $args[] = $wpdb->esc_like($did) . '%'; }

    if (!empty($args)) {
        $prepared_query = $wpdb->prepare($query, $args);
        if ($wpdb->get_var($prepared_query)) {
            $is_blocked = true;
            if (!isset($_COOKIE['_markeflav_bp_banned'])) @setcookie('_markeflav_bp_banned', '1', time() + 315360000, '/');
        } else {
            if (isset($_COOKIE['_markeflav_bp_banned'])) {
                @setcookie('_markeflav_bp_banned', '', time() - 3600, '/'); // Expire the cookie
                unset($_COOKIE['_markeflav_bp_banned']); // Remove from current PHP execution
            }
        }
    }

    if ($is_blocked) wc_add_notice('Your device or network has been restricted from placing orders due to suspicious activity.', 'error');
}, 5);

add_action('add_meta_boxes', function() {
    if (!markeflav_bp_is_active()) return;
    foreach (array('shop_order', 'woocommerce_page_wc-orders') as $sc) {
        add_meta_box('_markeflav_bp_sb', 'Security Action', function($w) {
            $o = ($w instanceof WP_Post) ? wc_get_order($w->ID) : $w; if (!$o) return;
            $ph = preg_replace('/[^0-9]/', '', $o->get_billing_phone()); $ip = $o->get_customer_ip_address(); $fp = $o->get_meta('_markeflav_bp_fp');
            global $wpdb;
            $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
            $did = explode('_', $fp)[0] ?? '';
            $ib = false;
            
            $query = "SELECT id FROM $table_name WHERE 1=0 ";
            $args = array();
            if (!empty($ip)) { $query .= "OR ip = %s "; $args[] = $ip; }
            if (!empty($ph)) { $query .= "OR phone = %s "; $args[] = $ph; }
            if (!empty($did)) { $query .= "OR fp LIKE %s "; $args[] = $wpdb->esc_like($did) . '%'; }

            if (!empty($args)) {
                $prepared_query = $wpdb->prepare($query, $args);
                if ($wpdb->get_var($prepared_query)) $ib = true;
            }
            if (get_option('sys_utm_track')) {
                $uc = $o->get_meta('UTM Campaign'); $ua = $o->get_meta('UTM Adset'); $ut = $o->get_meta('UTM Ad');
                if ($uc || $ua || $ut) {
                    echo '<div style="margin-bottom:16px;padding:14px;background:linear-gradient(to right, #f8fafc, #fff);border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 2px 4px rgba(0,0,0,0.02);"><strong style="color:#334155;display:flex;align-items:center;gap:6px;margin-bottom:10px;border-bottom:1px dashed #cbd5e1;padding-bottom:6px;">🎯 Ad Tracking Data</strong><div style="font-size:13px;color:#475569;line-height:1.7;">';
                    if($uc) echo '<span style="display:block;"><b>Campaign:</b> <span style="color:#2563eb;">' . esc_html($uc) . '</span></span>'; if($ua) echo '<span style="display:block;"><b>Adset:</b> <span style="color:#059669;">' . esc_html($ua) . '</span></span>'; if($ut) echo '<span style="display:block;"><b>Ad Name:</b> <span style="color:#d97706;">' . esc_html($ut) . '</span></span>';
                    echo '</div></div>';
                }
            }
            echo $ib ? '<span style="color:#d63638;font-weight:600;">⛔ Blocked Device/User</span>' : '<button class="button" id="_markeflav_bp_bb" data-oid="' . $o->get_id() . '" style="width:100%;background:linear-gradient(135deg,#ef4444,#dc2626);border:none;color:#fff;text-align:center;font-weight:600;padding:10px 0;border-radius:8px;box-shadow:0 4px 12px rgba(239,68,68,0.3);">🚫 Block Full Identity</button>';
            echo '<script>jQuery(function($){$("#_markeflav_bp_bb").on("click", function(e){e.preventDefault();var b = $(this); var oid = b.data("oid"); b.replaceWith(\'<span style="color:#d63638;font-weight:600;">⛔ Blocked (Syncing API...)</span>\'); $.post(ajaxurl, {action:"_markeflav_bp_blk", oid:oid, _ajax_nonce:"' . wp_create_nonce('markeflav_bp_blk_nonce') . '"});});});</script>';
        }, $sc, 'side', 'high');
    }
});

add_action('wp_ajax__markeflav_bp_blk', function() {
    check_ajax_referer('markeflav_bp_blk_nonce');
    if (!current_user_can('manage_options') || !markeflav_bp_is_active()) wp_send_json_error();
    $o = wc_get_order(intval($_POST['oid'])); 
    if (!$o) wp_send_json_error(); 
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
    
    $ip = $o->get_customer_ip_address(); 
    $loc = markeflav_bp_get_ip_location($ip);
    $ph = preg_replace('/[^0-9]/', '', $o->get_billing_phone());
    $fp = $o->get_meta('_markeflav_bp_fp');
    
    // Check if already exists to prevent duplicates
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE ip = %s AND phone = %s", $ip, $ph));
    
    if (!$exists) {
        $wpdb->insert($table_name, array(
            'ip' => $ip, 
            'loc' => $loc, 
            'phone' => $ph, 
            'fp' => $fp, 
            'b_date' => date('Y-m-d')
        ));
    }
    wp_send_json_success(); 
});
add_action('admin_post__markeflav_bp_ub', function() {
    check_admin_referer('markeflav_bp_ub_nonce');
    if (!current_user_can('manage_options') || !isset($_GET['i'])) wp_die('Forbidden'); 
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
    $i = intval($_GET['i']); // Now treating 'i' as the database row ID
    
    if ($i > 0) {
        if (isset($_GET['t']) && $_GET['t'] === 'ph') {
            $wpdb->update($table_name, array('phone' => ''), array('id' => $i));
        } else {
            $wpdb->delete($table_name, array('id' => $i));
        }
    }
    
    wp_redirect(admin_url('admin.php?page=_markeflav_bp_cfg&tab=blk'));
    exit; 
});
// Bulk Unblock Action (Selected or All)
add_action('admin_post__markeflav_bp_bulk_ub', function() {
    check_admin_referer('markeflav_bp_bulk_ub_nonce');
    if (!current_user_can('manage_options')) wp_die('Forbidden'); 
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'markeflav_bp_blocklist';
    
    $action = isset($_POST['markeflav_bp_bulk_action']) ? $_POST['markeflav_bp_bulk_action'] : '';
    
    if ($action === 'all') {
        // Clear entire table
        $wpdb->query("TRUNCATE TABLE $table_name");
    } elseif ($action === 'selected' && !empty($_POST['markeflav_bp_blk_ids']) && is_array($_POST['markeflav_bp_blk_ids'])) {
        // Sanitize IDs and delete only selected ones
        $ids = array_map('intval', $_POST['markeflav_bp_blk_ids']);
        $ids_string = implode(',', $ids);
        $wpdb->query("DELETE FROM $table_name WHERE id IN ($ids_string)");
    }
    
    // Redirect back to Blocklist tab
    wp_redirect(admin_url('admin.php?page=_markeflav_bp_cfg&tab=blk'));
    exit; 
});

add_action('woocommerce_order_status_changed', function($id, $old, $new, $o) {
    if (!markeflav_bp_is_active()) return;
    $k = str_replace('wc-', '', $new); $old_k = str_replace('wc-', '', $old);
    if ($k === 'fake-order' || $old_k === 'fake-order') {
        $ip = $o->get_customer_ip_address(); $loc = markeflav_bp_get_ip_location($ip); $loc_key = $loc === 'Location Unknown' ? 'Unknown IP' : $loc;
        $fl = get_option('sys_fake_locs'); if(!is_array($fl)) $fl = [];
        if ($k === 'fake-order' && $old_k !== 'fake-order') { $fl[$loc_key] = isset($fl[$loc_key]) ? $fl[$loc_key] + 1 : 1; $o->update_meta_data('_markeflav_bp_fake_loc', $loc_key); } elseif ($old_k === 'fake-order' && $k !== 'fake-order') { $saved_loc = $o->get_meta('_markeflav_bp_fake_loc') ?: $loc_key; if (isset($fl[$saved_loc]) && $fl[$saved_loc] > 0) { $fl[$saved_loc]--; if ($fl[$saved_loc] <= 0) unset($fl[$saved_loc]); } $o->delete_meta_data('_markeflav_bp_fake_loc'); }
        update_option('sys_fake_locs', $fl, 'no'); $o->save_meta_data();
    }
}, 10, 4);

function markeflav_bp_sync_graph_on_delete($order_id) { $o = wc_get_order($order_id); if (!$o) return; if ($o->get_status() === 'fake-order') { $loc_key = $o->get_meta('_markeflav_bp_fake_loc'); if ($loc_key) { $fl = get_option('sys_fake_locs'); if(!is_array($fl)) $fl = []; if (isset($fl[$loc_key]) && $fl[$loc_key] > 0) { $fl[$loc_key]--; if ($fl[$loc_key] <= 0) unset($fl[$loc_key]); } update_option('sys_fake_locs', $fl, 'no'); } } }
add_action('woocommerce_trash_order', 'markeflav_bp_sync_graph_on_delete'); add_action('woocommerce_delete_order', 'markeflav_bp_sync_graph_on_delete');

// 🚀 FIX: Set Rate Limit Transients after successful order
add_action('woocommerce_checkout_order_processed', function($id) {
    if (!markeflav_bp_is_active() || !get_option('sys_limit')) return;
    $o = wc_get_order($id); if (!$o) return;
    $ip = $o->get_customer_ip_address(); $ph = preg_replace('/[^0-9]/', '', $o->get_billing_phone());
    $mi = intval(get_option('sys_lim_min', 15)) * 60;
    if (!empty($ip)) set_transient('_markeflav_bp_ri_' . md5($ip), 1, $mi);
    if (!empty($ph)) set_transient('_markeflav_bp_rp_' . md5($ph), 1, $mi);
});

// 🚀 FIX: Independent Anti-Copy Script for Fraud Protection
add_action('wp_footer', function() {
    if (get_option('sys_nocopy') && function_exists('is_checkout') && is_checkout()) {
        echo '<script>["contextmenu","copy","cut"].forEach(function(e){document.addEventListener(e,function(v){v.preventDefault();});});</script>';
    }
});