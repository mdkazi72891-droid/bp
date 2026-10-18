<?php
if (!defined('ABSPATH')) exit;

add_action('admin_init', function() {
    if (!get_option('markeflav_bp_db_installed_v1')) {
        global $wpdb; $collate = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}markeflav_bp_carts (id VARCHAR(50) PRIMARY KEY, name VARCHAR(100), email VARCHAR(100), phone VARCHAR(50), address TEXT, city VARCHAR(100), products TEXT, total FLOAT, fbc VARCHAR(255), fbp VARCHAR(255), fp VARCHAR(255), is_blocked TINYINT(1), time DATETIME) $collate;");
        update_option('markeflav_bp_db_installed_v1', 1);
    }
    if (get_option('markeflav_bp_db_installed_v1') && !get_option('markeflav_bp_db_idx_v2')) {
        global $wpdb; @$wpdb->query("ALTER TABLE {$wpdb->prefix}markeflav_bp_carts ADD INDEX (phone), ADD INDEX (email)");
        update_option('markeflav_bp_db_idx_v2', 1);
    }
});

add_action('wp_footer', function() {
    if (is_admin() || !function_exists('is_checkout') || !is_checkout() || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) || !markeflav_bp_is_active() || get_option('sys_ac_en', 1) == 0) return;
    $_ep = esc_url(rest_url('bp/v1/abandoned-cart'));
    echo '<script>(function() { var lastSent = ""; function getCartData() { var v = function(i) { var e = document.getElementById(i); return e ? e.value : ""; }; var c = function(n) { var m = document.cookie.match(new RegExp("(^|;)\\s*" + n + "\\s*=\\s*([^;]+)")); return m ? m.pop() : ""; }; var fp_el = document.getElementById("_markeflav_bp_fp"); var products = []; document.querySelectorAll(".woocommerce-checkout-review-order-table .cart_item").forEach(function(r) { var n = r.querySelector(".product-name"); if(n) products.push(n.textContent.trim()); }); var totalEl = document.querySelector(".order-total .woocommerce-Price-amount"); var total = totalEl ? parseFloat(totalEl.textContent.replace(/[^0-9.]/g, "")) : 0; return { name: (v("billing_first_name") + " " + v("billing_last_name")).trim(), email: v("billing_email"), phone: v("billing_phone"), address: v("billing_address_1"), city: v("billing_city"), products: products.join(", "), total: total, fbp: c("_fbp"), fbc: c("_fbc"), fp: (fp_el ? fp_el.value : "") }; } function sendAbandonedCart() { if (document.querySelector("form.checkout") && document.querySelector("form.checkout").classList.contains("processing")) return; var d = getCartData(); if (d.phone.length < 5 && d.email.length < 5) return; var pData = JSON.stringify(d); if (pData === lastSent) return; lastSent = pData; if (navigator.sendBeacon) { navigator.sendBeacon("'.$_ep.'", new Blob([pData], {type: "application/json"})); } else { fetch("'.$_ep.'", { method: "POST", headers: {"Content-Type": "application/json"}, body: pData, keepalive: true }).catch(function(){}); } } window.addEventListener("beforeunload", function() { sendAbandonedCart(); }); document.addEventListener("visibilitychange", function() { if (document.visibilityState === "hidden") { sendAbandonedCart(); } }); })();</script>';
    if (get_option('sys_nocopy')) { echo '<script>["contextmenu","copy","cut"].forEach(function(e){document.addEventListener(e,function(v){v.preventDefault();});});</script>'; }
});

add_action('rest_api_init', function() { register_rest_route('bp/v1', '/abandoned-cart', array('methods' => 'POST', 'callback' => 'markeflav_bp_abandoned_cart_handler', 'permission_callback' => '__return_true')); });
function markeflav_bp_abandoned_cart_handler(WP_REST_Request $rq) {
    if (get_option('sys_ac_en', 1) == 0) return new WP_REST_Response(array('status' => 'disabled'), 200);
    $d = $rq->get_json_params(); $e = array('name' => sanitize_text_field($d['name'] ?? ''), 'email' => sanitize_email($d['email'] ?? ''), 'phone' => sanitize_text_field($d['phone'] ?? ''), 'address' => sanitize_text_field($d['address'] ?? ''), 'city' => sanitize_text_field($d['city'] ?? ''), 'products' => sanitize_text_field($d['products'] ?? ''), 'total' => floatval($d['total'] ?? 0), 'fbc' => sanitize_text_field($d['fbc'] ?? ''), 'fbp' => sanitize_text_field($d['fbp'] ?? ''), 'fp' => sanitize_text_field($d['fp'] ?? ''));
    if (empty($e['phone']) && empty($e['email'])) return new WP_REST_Response(array('status' => 'skipped'), 200);
    
    $is_blocked = false; $bl = get_option('sys_blist'); if(!is_array($bl)) $bl = []; $ip = markeflav_bp_get_client_ip(); $ph = preg_replace('/[^0-9]/', '', $e['phone']); $did = explode('_', $e['fp'])[0] ?? '';
    foreach ($bl as $b) { $b_did = explode('_', $b['fp'] ?? '')[0] ?? ''; if ($b['ip'] === $ip || (!empty($ph) && $b['phone'] === $ph) || (!empty($did) && $did === $b_did)) { $is_blocked = true; break; } }
    $e['is_blocked'] = $is_blocked ? 1 : 0; 
    
    // 🚀 WooCommerce Action Scheduler Queue Magic
    if ( function_exists( 'as_enqueue_async_action' ) ) {
        as_enqueue_async_action( 'markeflav_bp_background_process_cart', array( $e ) );
    } else {
        markeflav_bp_execute_cart_db_operation( $e );
    }
    
    // Return response instantly to free up the frontend
    return new WP_REST_Response(array('status' => 'queued'), 200);
}

// Background Worker Function
add_action( 'markeflav_bp_background_process_cart', 'markeflav_bp_execute_cart_db_operation' );
function markeflav_bp_execute_cart_db_operation( $e ) {
    $ph = preg_replace('/[^0-9]/', '', $e['phone'] ?? ''); $em = $e['email'] ?? '';
    if ((!empty($ph) && get_transient('markeflav_bp_ord_'.$ph)) || (!empty($em) && get_transient('markeflav_bp_ord_'.md5($em)))) return; // 🚀 সফল অর্ডার ড্রপ করা হলো
    global $wpdb; $tb = $wpdb->prefix . 'markeflav_bp_carts';
    $exist = $wpdb->get_row($wpdb->prepare("SELECT id FROM $tb WHERE phone = %s OR email = %s LIMIT 1", $e['phone'], $e['email']));
    if ($exist) { 
        $wpdb->update($tb, ['name'=>$e['name'], 'email'=>$e['email'], 'phone'=>$e['phone'], 'address'=>$e['address'], 'city'=>$e['city'], 'products'=>$e['products'], 'total'=>$e['total'], 'fbc'=>$e['fbc'], 'fbp'=>$e['fbp'], 'fp'=>$e['fp'], 'is_blocked'=>$e['is_blocked'], 'time'=>current_time('mysql')], ['id'=>$exist->id]); 
    } else { 
        $e['id'] = uniqid('ac_'); $e['time'] = current_time('mysql'); 
        $wpdb->insert($tb, $e); 
    }
}

add_action('woocommerce_checkout_order_processed', function($id) { $o = wc_get_order($id); if (!$o) return; $ph = preg_replace('/[^0-9]/', '', $o->get_billing_phone()); $em = $o->get_billing_email(); if(!empty($ph)) set_transient('markeflav_bp_ord_'.$ph, 1, 300); if(!empty($em)) set_transient('markeflav_bp_ord_'.md5($em), 1, 300); global $wpdb; $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}markeflav_bp_carts WHERE phone = %s OR email = %s", $ph, $em)); });

add_action('wp_ajax__markeflav_bp_ac2o', function() { check_ajax_referer('markeflav_bp_ac_nonce'); if (!current_user_can('manage_options')) wp_send_json_error('Forbidden'); global $wpdb; $tb = $wpdb->prefix . 'markeflav_bp_carts'; $ai = sanitize_text_field($_POST['ac_id'] ?? ''); $ct = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tb WHERE id = %s", $ai), ARRAY_A); if (!$ct) wp_send_json_error('Not found'); $or = wc_create_order(); $np = explode(' ', $ct['name'], 2); $or->set_billing_first_name($np[0] ?? ''); $or->set_billing_last_name($np[1] ?? ''); $or->set_billing_email($ct['email']); $or->set_billing_phone($ct['phone']); $or->set_billing_address_1($ct['address']); $or->set_billing_city($ct['city']); if (!empty($ct['fbp'])) $or->update_meta_data('_s_fbp', $ct['fbp']); if (!empty($ct['fbc'])) $or->update_meta_data('_s_fbc', $ct['fbc']); $fe = new WC_Order_Item_Fee(); $fe->set_name('Abandoned Cart: ' . ($ct['products'] ?: 'N/A')); $fe->set_total($ct['total']); $or->add_item($fe); $or->set_total($ct['total']); $or->set_status('offline-purchase'); $rv = get_option('markeflav_bp_recovered_value', 0); update_option('markeflav_bp_recovered_value', $rv + $ct['total']); $or->add_order_note('Created from abandoned cart (' . $ai . ')'); $or->save(); $wpdb->delete($tb, ['id' => $ai]); wp_send_json_success(array('order_id' => $or->get_id(), 'edit_url' => $or->get_edit_order_url())); });
add_action('wp_ajax__markeflav_bp_acd', function() { check_ajax_referer('markeflav_bp_ac_nonce'); if (!current_user_can('manage_options')) wp_send_json_error('Forbidden'); global $wpdb; $wpdb->delete($wpdb->prefix . 'markeflav_bp_carts', ['id' => sanitize_text_field($_POST['ac_id'] ?? '')]); wp_send_json_success(); });