<?php
/*
Plugin Name: Backend Pixel Pro
Description: Update test. Hoila jitsi
Version: 7.4
Author: Markeflav
Requires Plugins: woocommerce
*/
if (!defined('ABSPATH')) exit;

define('MARKEFLAV_BP_DIR_PATH', plugin_dir_path(__FILE__));

add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) { \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true); }
});

require_once MARKEFLAV_BP_DIR_PATH . 'modules/fraud-protection/class-bp-fraud.php';
require_once MARKEFLAV_BP_DIR_PATH . 'modules/data-extraction/class-bp-export.php';
require_once MARKEFLAV_BP_DIR_PATH . 'modules/tracking/class-bp-tracking.php';
require_once __DIR__ . '/modules/analytics/class-bp-analytics.php';
require_once MARKEFLAV_BP_DIR_PATH . 'modules/abandoned-cart/class-bp-abandoned-cart.php';
require_once MARKEFLAV_BP_DIR_PATH . 'modules/admin/class-bp-admin.php';

register_deactivation_hook(__FILE__, function() {
    global $markeflav_bp_keys;
    if(isset($markeflav_bp_keys['cron_check'])) wp_clear_scheduled_hook($markeflav_bp_keys['cron_check']);
    if(isset($markeflav_bp_keys['cron_send'])) wp_clear_scheduled_hook($markeflav_bp_keys['cron_send']);
});

// Custom 1-Click Update System
add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    global $markeflav_bp_version;
    $plugin_slug = plugin_basename(__FILE__);

    // Check configuration once a day via transient caching managed by markeflav_bp_get_remote_config
    $c = markeflav_bp_get_remote_config(false);

    if (isset($c['update_info']) && is_array($c['update_info'])) {
        $update_info = $c['update_info'];

        if (isset($update_info['latest_version']) && version_compare($markeflav_bp_version, $update_info['latest_version'], '<')) {
            $obj = new stdClass();
            $obj->slug = 'backend-pixel';
            $obj->plugin = $plugin_slug;
            $obj->new_version = $update_info['latest_version'];
            $obj->url = $update_info['plugin_url'] ?? '';
            $obj->package = $update_info['package_url'] ?? '';

            $transient->response[$plugin_slug] = $obj;
        }
    }

    return $transient;
});

add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'backend-pixel') {
        return $result;
    }

    global $markeflav_bp_version;
    $c = markeflav_bp_get_remote_config(false);

    if (isset($c['update_info']) && is_array($c['update_info'])) {
        $update_info = $c['update_info'];

        $obj = new stdClass();
        $obj->slug = 'backend-pixel';
        $obj->name = 'Backend Pixel Pro';
        $obj->plugin_name = 'Backend Pixel Pro';
        $obj->version = $update_info['latest_version'] ?? $markeflav_bp_version;
        $obj->author = 'Markeflav';
        $obj->homepage = $update_info['plugin_url'] ?? '';
        $obj->requires = '5.0';
        $obj->tested = '6.4';
        $obj->downloaded = 1000;
        $obj->last_updated = date('Y-m-d');
        $obj->sections = array(
            'description' => 'Server-side event optimization with Modular Architecture, Hardware Fingerprint, Dual-Tracking, GA4 Enhanced, MS Clarity, Load Balancing & Extreme Performance.',
        );
        $obj->download_link = $update_info['package_url'] ?? '';

        return $obj;
    }

    return $result;
}, 10, 3);
