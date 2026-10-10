<?php
if (!defined('ABSPATH')) exit;

// 🚀 SUPER FAST LTV CALCULATOR
if (!function_exists('markeflav_bp_fast_ltv')) { function markeflav_bp_fast_ltv($user_id, $email = '') {
    if ($user_id) {
        $amt = wc_get_customer_order_count($user_id);
        $val = wc_get_customer_total_spent($user_id);
        return ['amt' => $amt, 'val' => $val];
    } elseif ($email) {
        global $wpdb;
        // 🚀 HIGH PERFORMANCE DIRECT SQL: Highly optimized for millions of rows (Supports both Traditional & HPOS)
        $hpos_enabled = false;
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        
        if ($hpos_enabled) {
            $results = $wpdb->get_row($wpdb->prepare("
                SELECT COUNT(id) as amt, SUM(total_amount) as val 
                FROM {$wpdb->prefix}wc_orders 
                WHERE billing_email = %s 
                AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            ", $email), ARRAY_A);
        } else {
            $results = $wpdb->get_row($wpdb->prepare("
                SELECT COUNT(p.ID) as amt, SUM(CAST(m.meta_value AS DECIMAL(10,2))) as val 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_order_total'
                INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_billing_email'
                WHERE p.post_type = 'shop_order' 
                AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                AND m2.meta_value = %s
            ", $email), ARRAY_A);
        }
        
        $amt = !empty($results['amt']) ? (int)$results['amt'] : 0;
        $val = !empty($results['val']) ? (float)$results['val'] : 0;
        return ['amt' => $amt, 'val' => $val];
    }
    return ['amt' => 0, 'val' => 0];
} }

// 🚀 FIXED: Caching LTV on Order Status Change (Prevents DB Overload & Header Errors)
add_action('woocommerce_order_status_changed', 'markeflav_bp_update_ltv_cache', 10, 4);
function markeflav_bp_update_ltv_cache($id, $old, $new, $order) {
    $user_id = $order->get_customer_id();
    if ($user_id) {
        $ltv = markeflav_bp_fast_ltv($user_id, $order->get_billing_email());
        update_user_meta($user_id, '_markeflav_bp_ltv_amt', $ltv['amt']);
        update_user_meta($user_id, '_markeflav_bp_ltv_val', $ltv['val']);
    }
}

if (!function_exists('markeflav_bp_get_raw_guest_data')) { function markeflav_bp_get_raw_guest_data() {
    $d = ['fn'=>'', 'ln'=>'', 'em'=>'', 'ph'=>'', 'ct'=>'', 'st'=>'', 'cn'=>'', 'zp'=>''];
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
        global $wp; $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0; $o = wc_get_order($order_id);
        if ($o) { $d['fn'] = $o->get_billing_first_name(); $d['ln'] = $o->get_billing_last_name(); $d['em'] = $o->get_billing_email(); $d['ph'] = $o->get_billing_phone(); $d['ct'] = $o->get_billing_city(); $d['st'] = $o->get_billing_state(); $d['cn'] = $o->get_billing_country(); $d['zp'] = $o->get_billing_postcode(); }
    } elseif (is_user_logged_in()) {
        $u = wp_get_current_user(); $d['fn'] = $u->billing_first_name ?: $u->first_name; $d['ln'] = $u->billing_last_name ?: $u->last_name; $d['em'] = $u->billing_email ?: $u->user_email; $d['ph'] = $u->billing_phone; $d['ct'] = $u->billing_city; $d['st'] = $u->billing_state; $d['cn'] = $u->billing_country; $d['zp'] = $u->billing_postcode;
    } return $d;
} }

add_action('wp_head', function() {
    if (is_admin() || !markeflav_bp_is_active()) return;
    $pid = get_option('sys_pid'); if (!$pid) return;
    $web_events = get_option('sys_ev_web'); if(!is_array($web_events)) $web_events = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase'];
    
    // Minimal DB interaction on frontend
    $raw_php_data = markeflav_bp_get_raw_guest_data();
    $vc_cookie = isset($_COOKIE['lifetime_visit_count']) ? intval($_COOKIE['lifetime_visit_count']) : 1;
    $c = ['user_role'=>'guest', 'lifetime_orders_amount'=>0, 'lifetime_order_value'=>0, 'new_customer'=> ($vc_cookie === 1)];
    
    if (is_user_logged_in()) { 
        $u = wp_get_current_user(); $roles = (array)$u->roles; $c['user_role'] = implode(',', $roles); 
        
        // 🚀 Read from Cache instead of running heavy queries
        $ltv_amt = get_user_meta($u->ID, '_markeflav_bp_ltv_amt', true);
        $ltv_val = get_user_meta($u->ID, '_markeflav_bp_ltv_val', true);
        
        if ($ltv_amt === '') {
            $ltv = markeflav_bp_fast_ltv($u->ID, '');
            update_user_meta($u->ID, '_markeflav_bp_ltv_amt', $ltv['amt']); update_user_meta($u->ID, '_markeflav_bp_ltv_val', $ltv['val']);
            $ltv_amt = $ltv['amt']; $ltv_val = $ltv['val'];
        }
        if($ltv_amt > 0) { $c['lifetime_orders_amount'] = $ltv_amt; $c['lifetime_order_value'] = $ltv_val; } 
    } else { 
        if (isset($_COOKIE['_markeflav_bp_guest_ltv'])) {
            $ltv = json_decode(stripslashes($_COOKIE['_markeflav_bp_guest_ltv']), true);
            if (is_array($ltv)) { $c['lifetime_orders_amount'] = $ltv['amt'] ?? 0; $c['lifetime_order_value'] = $ltv['val'] ?? 0; } 
        } 
    }
    
    $tmr_sec = intval(get_option('sys_tmr_sec', 30)); $scr_pct = floatval(get_option('sys_scr_pct', 50)) / 100; 
    global $post; $page_title = esc_js(wp_title('', false)); $post_id = isset($post->ID) ? $post->ID : 0;
    
    $domain_verify = get_option('sys_domain_verify');
    if($domain_verify): echo '<meta name="facebook-domain-verification" content="'.esc_attr(trim($domain_verify)).'" />' . "\n"; endif;
    ?>
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    (function(){
        function setCookie(n,v,days){var d=new Date();d.setTime(d.getTime()+((days||730)*24*60*60*1000));var dom=location.hostname.replace(/^www\./i,'');document.cookie=n+"="+v+";path=/;domain=."+dom+";expires="+d.toUTCString();} 
        function getCookie(n){var m=document.cookie.match(new RegExp('(^| )'+n+'=([^;]+)'));return m?m[2]:null;} 
        function uuid(){return'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0,v=c=='x'?r:(r&0x3|0x8);return v.toString(16);});}
        window.getLS = function(k){try{return window.localStorage.getItem(k);}catch(e){return null;}};
        window.setLS = function(k,v){try{window.localStorage.setItem(k,v);}catch(e){}};
        window.getSS = function(k){try{return window.sessionStorage.getItem(k);}catch(e){return null;}};
        window.setSS = function(k,v){try{window.sessionStorage.setItem(k,v);}catch(e){}};
        
        window.bpExtId = getCookie('guest_ext_id_backup'); if(!window.bpExtId) { window.bpExtId = uuid(); setCookie('guest_ext_id_backup', window.bpExtId); }
        var uParams = new URLSearchParams(window.location.search); ['utm_campaign', 'utm_content', 'utm_term'].forEach(function(k) { if(uParams.get(k)) setCookie('_markeflav_bp_'+k, uParams.get(k)); });
        
        window.sys_fbc = getCookie('_fbc'); if (!window.sys_fbc && uParams.has('fbclid')) { window.sys_fbc = 'fb.1.' + Date.now() + '.' + uParams.get('fbclid'); setCookie('_fbc', window.sys_fbc); }
        window.sys_fbp = getCookie('_fbp'); if (!window.sys_fbp) { window.sys_fbp = 'fb.1.' + Date.now() + '.' + Math.floor(Math.random() * 10000000000); setCookie('_fbp', window.sys_fbp); }
        
        var isNewSession = !window.getSS('markeflav_bp_sess_actv');
        if(isNewSession) window.setSS('markeflav_bp_sess_actv', '1');
        
        var rawCookieVal = getCookie('lifetime_visit_count');
        var parsedCookieVc = parseInt(rawCookieVal);

        if (!rawCookieVal || rawCookieVal === 'NaN' || isNaN(parsedCookieVc) || parsedCookieVc < 1) {
            window.bpVisitCount = 1;
        } else {
            var currentSessVc = parseInt(window.getSS('markeflav_bp_sess_vc'));
            if (!isNaN(currentSessVc) && currentSessVc > 0) {
                window.bpVisitCount = currentSessVc;
            } else {
                window.bpVisitCount = parsedCookieVc + 1;
            }
        }

        setCookie('lifetime_visit_count', window.bpVisitCount, 5475);
        window.setLS('lifetime_visit_count', window.bpVisitCount);
        window.setSS('markeflav_bp_sess_vc', window.bpVisitCount);
        
        window.bpGetDynUser = function() {
            var pData = <?php echo json_encode($raw_php_data); ?>; var lData = JSON.parse(window.getLS('_markeflav_bp_guest_form') || '{}');
            var dEm = (document.getElementById('billing_email') ? document.getElementById('billing_email').value : '') || pData.em || lData.em || '';
            var dPh = (document.getElementById('billing_phone') ? document.getElementById('billing_phone').value : '') || pData.ph || lData.ph || '';
            var dFn = (document.getElementById('billing_first_name') ? document.getElementById('billing_first_name').value : '') || pData.fn || lData.fn || '';
            var dLn = (document.getElementById('billing_last_name') ? document.getElementById('billing_last_name').value : '') || pData.ln || lData.ln || '';
            var dCt = (document.getElementById('billing_city') ? document.getElementById('billing_city').value : '') || pData.ct || lData.ct || '';
            var dZp = (document.getElementById('billing_postcode') ? document.getElementById('billing_postcode').value : '') || pData.zp || lData.zp || '';
            
            var nData = {em: dEm, ph: dPh, fn: dFn, ln: dLn, ct: dCt, st: lData.st||'', cn: lData.cn||'', zp: dZp};
            if(dEm || dPh || dFn) window.setLS('_markeflav_bp_guest_form', JSON.stringify(nData));
            
            return { 
                em: dEm, ph: dPh, fn: dFn, ln: dLn, ct: dCt, st: nData.st, cn: nData.cn, zp: dZp, 
                external_id: window.bpExtId, fbp: getCookie('_fbp') || window.sys_fbp, 
                fbc: getCookie('_fbc') || window.sys_fbc || '', 
                client_ip_address: '<?php echo esc_js(markeflav_bp_get_client_ip()); ?>', client_user_agent: navigator.userAgent
            };
        };
        
        window.bpAllCustomData = { user_role: '<?php echo esc_js($c['user_role']); ?>', lifetime_order_value: <?php echo $c['lifetime_order_value']; ?>, lifetime_orders_amount: <?php echo $c['lifetime_orders_amount']; ?>, new_customer: (window.bpVisitCount === 1), visit_count: window.bpVisitCount, device_type: /Mobi|Android/i.test(navigator.userAgent) ? 'Mobile' : 'Desktop', business_type: 'retail', page_title: '<?php echo $page_title; ?>', post_id: '<?php echo $post_id; ?>', event_source_url: window.location.href, first_party_collection: true };
        
        var ltvAmt = parseInt(window.getLS('_markeflav_bp_ltv_amt') || getCookie('_markeflav_bp_ltv_amt')) || 0;
        var ltvVal = parseFloat(window.getLS('_markeflav_bp_ltv_val') || getCookie('_markeflav_bp_ltv_val')) || 0;
        if(ltvAmt > 0) { window.bpAllCustomData.lifetime_orders_amount = ltvAmt; window.bpAllCustomData.lifetime_order_value = ltvVal; }
        
        var initUser = window.bpGetDynUser(); var cleanFbqData = {}; 
        ['em','ph','fn','ln','ct','st','cn','zp','external_id'].forEach(function(k){ if(initUser[k]) cleanFbqData[k] = initUser[k].toLowerCase().trim(); });
        
        fbq('set', 'autoConfig', false, '<?php echo esc_js(get_option('sys_pid')); ?>');
        fbq('init', '<?php echo esc_js(get_option('sys_pid')); ?>', cleanFbqData);
        
        var capiEvents = <?php echo json_encode(get_option('sys_ev_capi') ?: ['AddToCart','InitiateCheckout','Purchase']); ?>; 
        var webEvents = <?php echo json_encode(get_option('sys_ev_web') ?: ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase']); ?>;
        
        <?php 
        $db_prm = get_option('sys_ev_prm');
        $strict_params_php = array(
            'PageView' => ['event_source_url','external_id','visit_count','client_ip_address','client_user_agent','fbp','fbc','business_type','page_title','post_id','user_role','new_customer','lifetime_order_value','lifetime_orders_amount','device_type','event_id'],
            'ViewContent' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','product_average_rating','product_review_count','product_sku','event_id'],
            'AddToCart' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','product_sku','first_party_collection','product_average_rating','product_review_count','event_id'],
            'InitiateCheckout' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','coupon','product_sku','tax','event_id'],
            'Purchase' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','shipping_charge','tax','coupon','discount_total','order_id','transaction_id','new_customer','fbc','fbp','visit_count','order_date','event_source_url','payment_method','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','first_party_collection','total_order_value','product_sku','customer_location','event_id'],
            'view_cart' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','product_sku','first_party_collection','event_id'],
            'remove_from_cart' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','product_sku','first_party_collection','event_id'],
            'check_review' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','new_customer','fbc','fbp','visit_count','event_source_url','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','product_average_rating','product_review_count','product_sku','event_id'],
            'Ordered_Customers_Visit' => ['client_ip_address','client_user_agent','content_type','content_name','content_ids','content_category','num_items','currency','value','shipping_charge','tax','coupon','discount_total','order_id','transaction_id','new_customer','fbc','fbp','visit_count','order_date','event_source_url','payment_method','business_type','page_title','post_id','stock_level','user_role','lifetime_order_value','lifetime_orders_amount','device_type','first_party_collection','total_order_value','product_sku','customer_location','event_id'],
            'New_Visitor' => ['event_source_url','external_id','visit_count','client_ip_address','client_user_agent','fbp','fbc','business_type','page_title','post_id','user_role','new_customer','lifetime_order_value','lifetime_orders_amount','device_type','event_id'],
            'Visit_Second_Time' => ['event_source_url','external_id','visit_count','client_ip_address','client_user_agent','fbp','fbc','business_type','page_title','post_id','user_role','new_customer','lifetime_order_value','lifetime_orders_amount','device_type','event_id'],
            'Visit_Third_Time' => ['event_source_url','external_id','visit_count','client_ip_address','client_user_agent','fbp','fbc','business_type','page_title','post_id','user_role','new_customer','lifetime_order_value','lifetime_orders_amount','device_type','event_id'],
            'Visit_Multiple_Times' => ['event_source_url','external_id','visit_count','client_ip_address','client_user_agent','fbp','fbc','business_type','page_title','post_id','user_role','new_customer','lifetime_order_value','lifetime_orders_amount','device_type','event_id']
        );
        if (is_array($db_prm) && isset($db_prm['markeflav_bp_dummy'])) {
            foreach ($strict_params_php as $ev => $params) {
                $strict_params_php[$ev] = isset($db_prm[$ev]) ? array_values((array)$db_prm[$ev]) : [];
            }
        }
        ?>
        var strictParams = <?php echo json_encode($strict_params_php); ?>;
        
        var sysWeb = {}; for(var i=0; i<webEvents.length; i++) sysWeb[webEvents[i]] = 1; sysWeb['PageView'] = 1;
        var sysCapi = {}; for(var i=0; i<capiEvents.length; i++) sysCapi[capiEvents[i]] = 1; sysCapi['PageView'] = 1;

        window.bpTrackEvent = function(eventName, customData, forcedEventId) {
            var evId = forcedEventId ? forcedEventId : 'evt_' + eventName.toLowerCase() + '_' + Date.now() + '_' + Math.floor(Math.random()*1000); 
            var finalData = {}; var mapKey = strictParams[eventName] ? eventName : 'ViewContent'; 
            var allowed = strictParams[mapKey] || [];
            
            var liveUser = window.bpGetDynUser(); 
            var mergeData = Object.assign({}, window.bpAllCustomData, liveUser, customData || {}); 
            mergeData.event_id = evId;
            
            for(var i=0; i<allowed.length; i++) {
                var key = allowed[i];
                if (mergeData[key] !== undefined && mergeData[key] !== null && mergeData[key] !== "") {
                    finalData[key] = mergeData[key]; 
                }
            }
            
            var isDynamic = (eventName.indexOf('Time_') === 0 || eventName.indexOf('Scroll_') === 0);
            var fireWeb = isDynamic || (sysWeb[eventName] !== undefined);
            var fireCapi = isDynamic || (sysCapi[eventName] !== undefined);

            if (fireWeb) {
                if(['PageView','ViewContent','AddToCart','InitiateCheckout','Purchase', 'view_cart', 'remove_from_cart'].indexOf(eventName) !== -1){ 
                    fbq('track', eventName, finalData, {eventID: evId}); 
                } else { 
                    fbq('trackCustom', eventName, finalData, {eventID: evId}); 
                } 
            }
            
            document.dispatchEvent(new CustomEvent('markeflav_bp_analytics_trigger', { detail: { event: eventName, data: finalData } }));
            
            // 🚀 ULTRA FAST GA4 SYNC: Syncs all plugin events and parameters to GA4 instantly
            if (typeof gtag === 'function' && '<?php echo esc_js(get_option("sys_ga4_id")); ?>' !== '') {
                gtag('event', eventName, finalData);
            }
            
            if (fireCapi) { 
                var capiPayload = { event_name: eventName, event_id: evId, custom_data: finalData, user_data: liveUser, event_source_url: window.location.href, url: window.location.href }; 
                fetch('<?php echo esc_url(rest_url('bp/v1/capi')); ?>', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(capiPayload), keepalive: true }).catch(function(){}); 
            }
        };
        
        window.addEventListener('pageshow', function(e) {
            if(e.persisted) { fbq('init', '<?php echo esc_js(get_option('sys_pid')); ?>', cleanFbqData); }
        });
        
        if(isNewSession) {
            var vCount = parseInt(window.bpVisitCount);
            if(isNaN(vCount)) vCount = 1; // Bulletproof Fallback for cleared data
            
            var ltvAmtCheck = parseInt(window.getLS('_markeflav_bp_ltv_amt') || getCookie('_markeflav_bp_ltv_amt')) || 0;
            var isCustomer = (window.bpAllCustomData.lifetime_orders_amount > 0 || ltvAmtCheck > 0);
            
            setTimeout(function(){
                if(isCustomer) { window.bpTrackEvent('Ordered_Customers_Visit'); } 
                else {
                    if(vCount === 1) window.bpTrackEvent('New_Visitor');
                    else if(vCount === 2) window.bpTrackEvent('Visit_Second_Time');
                    else if(vCount === 3) window.bpTrackEvent('Visit_Third_Time');
                    else window.bpTrackEvent('Visit_Multiple_Times');
                }
            }, 1500);
        }
    })();
    window.bpTrackEvent('PageView'); // Force PageView everywhere
    </script>
    <?php
}, 5);

add_action('wp_footer', function() {
    if (is_admin() || !markeflav_bp_is_active()) return;
    $web_events = get_option('sys_ev_web'); if(!is_array($web_events)) $web_events = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase'];
    $tmr_sec = intval(get_option('sys_tmr_sec', 30)); $scr_pct = floatval(get_option('sys_scr_pct', 50)) / 100; 
    
        echo '<script>jQuery(document).on("blur change", ".woocommerce-checkout input.input-text", function() {
        var hashStr = function(s) { return s ? s.trim().toLowerCase() : ""; }; // FB expects lowercase for hashing on client side if passed directly, or fbq will hash it.
        var d = JSON.parse(window.getLS("_markeflav_bp_guest_form") || "{}");
        d.em = jQuery("#billing_email").val() || d.em;
        d.ph = jQuery("#billing_phone").val() || d.ph;
        d.fn = jQuery("#billing_first_name").val() || d.fn;
        d.ln = jQuery("#billing_last_name").val() || d.ln;
        d.ct = jQuery("#billing_city").val() || d.ct;
        d.st = jQuery("#billing_state").val() || d.st;
        d.cn = jQuery("#billing_country").val() || d.cn;
        d.zp = jQuery("#billing_postcode").val() || d.zp;
        window.setLS("_markeflav_bp_guest_form", JSON.stringify(d));

        // Push advanced matching to pixel
        if (typeof fbq === "function") {
            fbq("init", window.bpPid, {
                em: hashStr(d.em),
                ph: d.ph ? d.ph.replace(/[^0-9]/g, "") : "",
                fn: hashStr(d.fn),
                ln: hashStr(d.ln),
                ct: hashStr(d.ct),
                st: hashStr(d.st),
                zp: d.zp ? d.zp.replace(/[^a-zA-Z0-9]/g, "").toLowerCase() : "",
                country: hashStr(d.cn)
            });
        }
    });</script>';
    
    echo '<script>document.addEventListener("DOMContentLoaded", function() {';
    if(in_array('timer_on_page', $web_events) && $tmr_sec > 0) { echo 'setTimeout(function(){ if(typeof window.bpTrackEvent === "function") window.bpTrackEvent("Time_'.$tmr_sec.'s"); }, '.$tmr_sec.' * 1000);'; }
    if(in_array('scroll_on_page', $web_events) && $scr_pct > 0) { echo 'var bpScrolled = false; window.addEventListener("scroll", function() { if(!bpScrolled && (window.scrollY + window.innerHeight) >= (document.documentElement.scrollHeight * '.$scr_pct.')) { if(typeof window.bpTrackEvent === "function") window.bpTrackEvent("Scroll_'.($scr_pct*100).'_Percent"); bpScrolled = true; } });'; }
    echo '});</script>';

    if (function_exists('is_product') && is_product()) { 
        global $product; $cat_ids = $product->get_category_ids(); $cat_names = []; foreach($cat_ids as $cid){ $term = get_term_by('id', $cid, 'product_cat'); if($term) $cat_names[] = $term->name; } 
        $p_data = "{content_name: '".esc_js($product->get_name())."', content_ids: ['".$product->get_id()."'], contents: [{id: '".$product->get_id()."', quantity: 1, item_price: ".$product->get_price()."}], content_type: 'product', value: ".($product->get_price()?:0).", currency: '".get_woocommerce_currency()."', product_average_rating: '".$product->get_average_rating()."', product_review_count: '".$product->get_review_count()."', content_category: '".esc_js(implode(', ', $cat_names))."', product_sku: '".esc_js($product->get_sku())."', stock_level: '".($product->get_stock_quantity()?:'in_stock')."'}";
        
        echo "<script>window.bpProductData = {$p_data};</script>";
        if (in_array('ViewContent', $web_events)) { echo "<script>window.bpTrackEvent('ViewContent', {$p_data});</script>"; }
        
        echo "<script>jQuery(document).on('click', '.woocommerce-review-link, .star-rating, #reviews, #tab-title-reviews', function(){ if(!window.bpReviewTracked) { window.bpTrackEvent('check_review', {$p_data}); window.bpReviewTracked = true; } });</script>";
    }
    
    if (function_exists('is_cart') && is_cart() && in_array('view_cart', $web_events)) { 
        $val = WC()->cart->get_cart_contents_total(); $num_items = WC()->cart->get_cart_contents_count(); $ids = []; $names = []; $cats = []; $contents = []; 
        foreach (WC()->cart->get_cart() as $cart_item) { 
            $product = $cart_item['data']; $pid = (string)($cart_item['variation_id'] ?: $cart_item['product_id']); $qty = max(1, $cart_item['quantity']); 
            $ids[] = $pid; $names[] = $product->get_name(); $contents[] = array('id' => $pid, 'quantity' => $qty, 'item_price' => (float)($cart_item['line_total'] / $qty)); 
            $term_ids = $product->get_category_ids(); foreach($term_ids as $cid){ $term = get_term_by('id', $cid, 'product_cat'); if($term) $cats[] = $term->name; }
        } 
        $cat_str = esc_js(implode(', ', array_unique($cats))); $name_str = esc_js(implode(', ', $names));
        echo "<script>window.bpTrackEvent('view_cart', {value: ".$val.", currency: '".get_woocommerce_currency()."', content_ids: ".json_encode($ids).", contents: ".json_encode($contents).", content_name: '".$name_str."', content_category: '".$cat_str."', content_type: 'product', num_items: ".$num_items."});</script>"; 
    }
    
    if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) && in_array('InitiateCheckout', $web_events)) { 
        $val = WC()->cart->get_cart_contents_total(); $num_items = WC()->cart->get_cart_contents_count(); $ids = []; $names = []; $cats = []; $contents = []; 
        foreach (WC()->cart->get_cart() as $cart_item) { 
            $product = $cart_item['data']; $pid = (string)($cart_item['variation_id'] ?: $cart_item['product_id']); $qty = max(1, $cart_item['quantity']); 
            $ids[] = $pid; $names[] = $product->get_name(); $contents[] = array('id' => $pid, 'quantity' => $qty, 'item_price' => (float)($cart_item['line_total'] / $qty)); 
            $term_ids = $product->get_category_ids(); foreach($term_ids as $cid){ $term = get_term_by('id', $cid, 'product_cat'); if($term) $cats[] = $term->name; }
        } 
        $tax = WC()->cart->get_total_tax(); $cpn = implode(',', WC()->cart->get_applied_coupons());
        $cat_str = esc_js(implode(', ', array_unique($cats))); $name_str = esc_js(implode(', ', $names));
        echo "<script>window.bpTrackEvent('InitiateCheckout', {value: ".$val.", currency: '".get_woocommerce_currency()."', content_ids: ".json_encode($ids).", contents: ".json_encode($contents).", content_name: '".$name_str."', content_category: '".$cat_str."', content_type: 'product', num_items: ".$num_items.", tax: ".$tax.", coupon: '".esc_js($cpn)."'});</script>"; 
    }
    
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) { 
        global $wp; $order_id = isset($wp->query_vars['order-received']) ? absint($wp->query_vars['order-received']) : 0; $o = wc_get_order($order_id); 
        if ($o) { $tracked = $o->get_meta('_markeflav_bp_fe_tracked'); if (!$tracked) {
            $ids=[]; $names=[]; $cats=[]; $contents=[]; 
            foreach($o->get_items() as $i) { 
                $pid = (string)$i->get_product_id(); $qty = max(1, $i->get_quantity()); $ids[] = $pid; $names[] = $i->get_name();
                $contents[] = array('id' => $pid, 'quantity' => $qty, 'item_price' => (float)($i->get_total() / $qty)); 
                $product = $i->get_product(); if($product) { $term_ids = $product->get_category_ids(); foreach($term_ids as $cid){ $term = get_term_by('id', $cid, 'product_cat'); if($term) $cats[] = $term->name; } }
            } 
            $cpn = implode(',', (array)$o->get_coupon_codes()); 
            $dedup_id = 'purchase_' . $order_id;
            $cat_str = esc_js(implode(', ', array_unique($cats))); $name_str = esc_js(implode(', ', $names));
            
            $final_ltv = markeflav_bp_fast_ltv($o->get_customer_id(), $o->get_billing_email());
            $safe_amt = max(1, (int)$final_ltv['amt']);
            $safe_val = max((float)$o->get_total(), (float)$final_ltv['val']);
            
            echo "<script>
            var expireDate = new Date(); expireDate.setTime(expireDate.getTime() + (730*24*60*60*1000)); var expires = 'expires='+ expireDate.toUTCString();
            document.cookie = '_markeflav_bp_ltv_amt=".$safe_amt.";' + expires + ';path=/'; document.cookie = '_markeflav_bp_ltv_val=".$safe_val.";' + expires + ';path=/';
            window.setLS('_markeflav_bp_ltv_amt', '".$safe_amt."'); window.setLS('_markeflav_bp_ltv_val', '".$safe_val."');
            
            var svd = JSON.parse(window.getLS('_markeflav_bp_guest_form') || '{}');
            svd.em = '".esc_js($o->get_billing_email())."'; svd.ph = '".esc_js($o->get_billing_phone())."'; svd.fn = '".esc_js($o->get_billing_first_name())."'; svd.ln = '".esc_js($o->get_billing_last_name())."'; svd.ct = '".esc_js($o->get_billing_city())."'; svd.zp = '".esc_js($o->get_billing_postcode())."';
            window.setLS('_markeflav_bp_guest_form', JSON.stringify(svd));

            if(typeof window.bpTrackEvent === 'function') { window.bpTrackEvent('Purchase', {value: ".$o->get_total().", currency: '".$o->get_currency()."', content_ids: ".json_encode($ids).", contents: ".json_encode($contents).", content_name: '".$name_str."', content_category: '".$cat_str."', content_type: 'product', num_items: ".$o->get_item_count().", order_id: '".esc_js($order_id)."', transaction_id: '".esc_js($order_id)."', shipping_charge: ".$o->get_shipping_total().", tax: ".$o->get_total_tax().", discount_total: ".$o->get_discount_total().", payment_method: '".esc_js($o->get_payment_method_title())."', coupon: '".esc_js($cpn)."', order_date: '".esc_js($o->get_date_created()->format('Y-m-d'))."', lifetime_orders_amount: ".$safe_amt.", lifetime_order_value: ".$safe_val."}, '".$dedup_id."'); }
            </script>"; 
            $o->update_meta_data('_markeflav_bp_fe_tracked', 'yes'); $o->save_meta_data();
        } } 
    }
    
    echo '<script>jQuery(document).ready(function($) { ';
    if (in_array('AddToCart', $web_events)) { 
        echo '$(document.body).on("added_to_cart", function(e, fragments, cart_hash, button) { 
            var productId = String($(button).data("product_id") || $(button).val()); if (!productId || productId==="undefined") return; 
            var d = {content_ids: [productId], contents: [{id: productId, quantity: 1}], content_type: "product", currency: "'.get_woocommerce_currency().'"}; 
            if(window.bpProductData && window.bpProductData.content_ids[0] === productId) { d.content_name = window.bpProductData.content_name; d.content_category = window.bpProductData.content_category; d.value = window.bpProductData.value; } 
            window.bpTrackEvent("AddToCart", d); 
        }); 
        var clickedBtn = null;
        $("form.cart").on("click", "button[type=\'submit\']", function() { clickedBtn = $(this); });
        $("form.cart").on("submit", function(e) {
            var btn = clickedBtn || $(this).find("button[type=\'submit\']").first();
            var btnClass = btn.attr("class") || ""; var btnName = btn.attr("name") || "";
            if(btn.hasClass("ajax_add_to_cart") || btnClass.indexOf("buy_now") !== -1 || btnClass.indexOf("buy-now") !== -1 || btnClass.indexOf("quick_buy") !== -1 || btnClass.indexOf("quick-buy") !== -1 || btnName.indexOf("buy_now") !== -1 || btnName.indexOf("buy-now") !== -1) return;
            if(!$(this).data("markeflav_bp_tracked")) {
                e.preventDefault(); var form = this;
                var parentId = String($(this).find("input[name=\'add-to-cart\']").val() || btn.val());
                var varId = String($(this).find("input[name=\'variation_id\']").val() || "");
                var productId = (varId && varId !== "0") ? varId : parentId;
                if (!productId || productId==="undefined") {
                    $(this).data("markeflav_bp_tracked", true);
                    if (btn.length) { btn.get(0).click(); } else { form.submit(); } return;
                }
                var qty = parseInt($(this).find("input[name=\'quantity\']").val()) || 1;
                var d = {content_ids: [productId], contents: [{id: productId, quantity: qty}], content_type: "product", num_items: qty, currency: "'.get_woocommerce_currency().'"};
                if(window.bpProductData && window.bpProductData.content_ids[0] === parentId && productId !== parentId) {
                    d.content_name = window.bpProductData.content_name; d.content_category = window.bpProductData.content_category;
                    var varPrice = parseFloat($(this).find(".woocommerce-variation-price .amount").text().replace(/[^0-9.]/g, ""));
                    d.value = (!isNaN(varPrice) && varPrice > 0 ? varPrice : window.bpProductData.value) * qty;
                } else if (window.bpProductData && window.bpProductData.content_ids[0] === productId) {
                    d.content_name = window.bpProductData.content_name; d.content_category = window.bpProductData.content_category; d.value = window.bpProductData.value * qty;
                }
                window.bpTrackEvent("AddToCart", d);
                $(this).data("markeflav_bp_tracked", true);
                if (btn.attr("name") && btn.attr("value")) {
                    $("<input>").attr({ type: "hidden", name: btn.attr("name"), value: btn.attr("value") }).appendTo(form);
                }
                setTimeout(function(){
                    if (btn.length) { btn.get(0).click(); } else { form.submit(); }
                }, 350);
            }
        });'; 
    }
    if (in_array('remove_from_cart', $web_events)) { 
        echo '$(document.body).on("click", "a.remove, .remove_from_cart_button", function() { 
            var productId = String($(this).data("product_id")); 
            if (!productId || productId==="undefined") return; 
            var qty = parseInt($(this).closest(".cart_item, .mini_cart_item, .woocommerce-cart-form__cart-item").find(".qty, .quantity input").val()) || 1;
            var d = { content_ids: [productId], contents: [{id: productId, quantity: qty}], content_type: "product", currency: "'.get_woocommerce_currency().'" }; 
            window.bpTrackEvent("remove_from_cart", d); 
        });
        
        $(document.body).on("click", ".minus, .quantity-minus, .qty-minus", function() { 
            var itemBox = $(this).closest(".cart_item, .woocommerce-cart-form__cart-item");
            var productId = String(itemBox.find("a.remove").data("product_id")); 
            if (!productId || productId==="undefined") return; 
            var d = { content_ids: [productId], contents: [{id: productId, quantity: 1}], content_type: "product", currency: "'.get_woocommerce_currency().'" }; 
            window.bpTrackEvent("remove_from_cart", d); 
        });'; 
    }
    echo '});</script>';
}, 20);

add_action('woocommerce_checkout_create_order', function($o) {
    if (!empty($_COOKIE['_fbp'])) $o->update_meta_data('_s_fbp', sanitize_text_field($_COOKIE['_fbp']));
    if (!empty($_COOKIE['_fbc'])) $o->update_meta_data('_s_fbc', sanitize_text_field($_COOKIE['_fbc']));
    if (isset($_SERVER['HTTP_USER_AGENT'])) $o->update_meta_data('_s_ua', sanitize_text_field($_SERVER['HTTP_USER_AGENT']));
    $ip = markeflav_bp_get_client_ip(); if (!empty($ip)) $o->update_meta_data('_s_ip', $ip);
    if (!empty($_POST['_markeflav_bp_fp'])) $o->update_meta_data('_markeflav_bp_fp', sanitize_text_field($_POST['_markeflav_bp_fp']));
    if (get_option('sys_utm_track')) { if (!empty($_COOKIE['_markeflav_bp_utm_campaign'])) $o->update_meta_data('UTM Campaign', sanitize_text_field($_COOKIE['_markeflav_bp_utm_campaign'])); if (!empty($_COOKIE['_markeflav_bp_utm_content'])) $o->update_meta_data('UTM Adset', sanitize_text_field($_COOKIE['_markeflav_bp_utm_content'])); if (!empty($_COOKIE['_markeflav_bp_utm_term'])) $o->update_meta_data('UTM Ad', sanitize_text_field($_COOKIE['_markeflav_bp_utm_term'])); }
}, 25, 1);

// Removed PHP Cookie logic for LTV. It is now handled 100% securely via JS localStorage to prevent caching crashes for millions of visitors.

function markeflav_bp_format_user_data_for_capi($o, $id) {
    $u = array(); $hash = function($val) { $val = strtolower(trim($val ?? '')); return $val === '' ? null : hash('sha256', $val); };
    if ($v = $hash($o->get_billing_email())) $u['em'] = $v; if ($v = $hash($o->get_billing_first_name())) $u['fn'] = $v; if ($v = $hash($o->get_billing_last_name())) $u['ln'] = $v; if ($v = $hash(preg_replace('/\s+/', '', $o->get_billing_city()))) $u['ct'] = $v; if ($v = $hash(preg_replace('/\s+/', '', $o->get_billing_state()))) $u['st'] = $v; if ($v = $hash($o->get_billing_country())) $u['country'] = $v; if ($v = $hash(preg_replace('/[^a-z0-9]/', '', strtolower(trim($o->get_billing_postcode() ?? ''))))) $u['zp'] = $v;
    $ph = preg_replace('/[^0-9]/', '', $o->get_billing_phone() ?? ''); if (!empty($ph)) { if (strlen($ph) === 11 && substr($ph, 0, 2) === '01') $ph = '880' . substr($ph, 1); else if (strlen($ph) > 10 && substr($ph, 0, 2) != '88') $ph = '88' . $ph; $u['ph'] = hash('sha256', $ph); }
    $gender = $o->get_meta('_billing_gender') ?: get_user_meta($o->get_customer_id(), 'billing_gender', true); if ($gender) { $u['ge'] = hash('sha256', strtolower(trim(substr($gender, 0, 1)))); }
    $dob = $o->get_meta('_billing_dob') ?: get_user_meta($o->get_customer_id(), 'billing_dob', true); if ($dob) { $u['db'] = hash('sha256', preg_replace('/[^0-9]/', '', $dob)); }
    $u['external_id'] = hash('sha256', (string)$id); $ua = $o->get_meta('_s_ua') ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''); if (!empty($ua)) $u['client_user_agent'] = $ua; $ip = $o->get_meta('_s_ip') ?: $o->get_customer_ip_address(); if (!empty($ip)) $u['client_ip_address'] = trim($ip);
    $fbp = $o->get_meta('_s_fbp') ?: ($_COOKIE['_fbp'] ?? ''); if (!empty($fbp)) $u['fbp'] = $fbp; 
    $fbc = $o->get_meta('_s_fbc') ?: ($_COOKIE['_fbc'] ?? ''); if (!empty($fbc)) $u['fbc'] = $fbc; 
    return $u;
}

add_action('woocommerce_order_status_changed', function($id, $old, $new, $o) {
    if (!markeflav_bp_is_active()) return;
    $mp = array('completed' => 'Completed_Order', 'cancelled' => 'Cancelled_Order', 'fake-order' => 'Fake_Order', 'returned-order' => 'Returned_Order', 'offline-purchase' => 'Purchase');
    $k = str_replace('wc-', '', $new); $ev = $mp[$k] ?? null; if (!$ev) return;
    $svr_events = get_option('sys_ev_svr'); if(!is_array($svr_events)) $svr_events = ['Completed_Order', 'Purchase'];
    $is_completed_event = ($k === 'completed' && in_array('Completed_Order', $svr_events)); $is__event = ($k === 'offline-purchase' && in_array('Purchase', $svr_events));
    if (!$is_completed_event && !$is__event && !in_array($ev, $svr_events)) return;
    $pd = get_option('sys_pid'); $tk = get_option('sys_tok'); if (!$pd || !$tk) return;
    $ud = markeflav_bp_format_user_data_for_capi($o, $id); $ids = array(); $nm = array(); $qt = 0; $cts = array(); $cats = array();
    foreach ($o->get_items() as $it) { $pid = (string)($it->get_product_id()); $ids[] = $pid; $nm[] = $it->get_name(); $qty = max(1, $it->get_quantity()); $qt += $qty; $cts[] = array('id' => $pid, 'quantity' => $qty, 'item_price' => (float)($it->get_total() / $qty)); $product = wc_get_product($pid); if($product) { $cat_ids = $product->get_category_ids(); foreach($cat_ids as $cid){ $term = get_term_by('id', $cid, 'product_cat'); if($term) $cats[] = $term->name; } } }
    
    // Server logic uses direct order info
    $user_id = $o->get_customer_id(); $ltv = markeflav_bp_fast_ltv($user_id, $o->get_billing_email());
    $safe_amt = max(1, (int)$ltv['amt']); // Safety fallback
    $safe_val = max((float)$o->get_total(), (float)$ltv['val']); // Safety fallback
    $vc_cookie = isset($_COOKIE['lifetime_visit_count']) ? intval($_COOKIE['lifetime_visit_count']) : 1;
    
    $cd = array('value' => (float)$o->get_total(), 'currency' => $o->get_currency() ?: 'BDT', 'content_type' => 'product', 'content_ids' => $ids, 'contents' => $cts, 'content_name' => implode(', ', $nm), 'content_category' => implode(', ', array_unique($cats)), 'num_items' => $qt, 'order_id' => (string)$id, 'transaction_id' => (string)$id, 'shipping_charge' => (float)$o->get_shipping_total(), 'tax' => (float)$o->get_total_tax(), 'discount_total' => (float)$o->get_discount_total(), 'payment_method' => $o->get_payment_method_title(), 'business_type' => 'retail', 'first_party_collection' => true, 'new_customer' => ($vc_cookie === 1), 'lifetime_order_value' => $safe_val, 'lifetime_orders_amount' => $safe_amt, 'customer_location' => ltrim($o->get_billing_address_1() . ', ' . $o->get_billing_city(), ', ')); if($c_codes = $o->get_coupon_codes()) $cd['coupon'] = implode(',', $c_codes);
    
    $cd = array_filter($cd, function($v) { return $v !== '' && $v !== null && (!is_array($v) || !empty($v)); });
    
    $event_id_final = ($ev === 'Purchase') ? 'purchase_' . $id : '_evt_' . strtolower($ev) . '_' . $id . '_' . time();
    $ev_d = array('event_name' => $ev, 'event_id' => $event_id_final, 'event_time' => time(), 'event_source_url' => home_url('/'), 'action_source' => 'website', 'user_data' => $ud, 'custom_data' => $cd);
    $pl = array('data' => array($ev_d), 'access_token' => $tk); if ($tc = get_option('sys_test')) $pl['test_event_code'] = $tc;
    
    wp_remote_post("https://graph.facebook.com/v21.0/{$pd}/events", array('body' => json_encode($pl), 'headers' => array('Content-Type' => 'application/json'), 'blocking' => false, 'timeout' => 1, 'sslverify' => false));
}, 15, 4);

add_action('rest_api_init', function() { register_rest_route('bp/v1', '/capi', array('methods' => 'POST', 'callback' => 'markeflav_bp_frontend_capi_proxy', 'permission_callback' => '__return_true')); });
function markeflav_bp_frontend_capi_proxy(WP_REST_Request $rq) {
    if (!markeflav_bp_is_active()) return new WP_REST_Response(['status'=>'disabled'], 200);
    $d = $rq->get_json_params(); $ev = sanitize_text_field($d['event_name'] ?? ''); $ev_id = sanitize_text_field($d['event_id'] ?? ''); if (!$ev || !$ev_id) return new WP_REST_Response(['status'=>'skip'], 200);
    $pd = get_option('sys_pid'); $tk = get_option('sys_tok'); if (!$pd || !$tk) return new WP_REST_Response([], 200);
    
    $ud = []; if(!empty($d['user_data']['em'])) $ud['em'] = hash('sha256', strtolower(trim($d['user_data']['em']))); if(!empty($d['user_data']['fn'])) $ud['fn'] = hash('sha256', strtolower(trim($d['user_data']['fn']))); if(!empty($d['user_data']['ln'])) $ud['ln'] = hash('sha256', strtolower(trim($d['user_data']['ln']))); if(!empty($d['user_data']['ct'])) $ud['ct'] = hash('sha256', preg_replace('/\s+/', '', strtolower(trim($d['user_data']['ct'])))); if(!empty($d['user_data']['st'])) $ud['st'] = hash('sha256', preg_replace('/\s+/', '', strtolower(trim($d['user_data']['st'])))); if(!empty($d['user_data']['cn'])) $ud['country'] = hash('sha256', strtolower(trim($d['user_data']['cn']))); if(!empty($d['user_data']['zp'])) $ud['zp'] = hash('sha256', preg_replace('/[^a-z0-9]/', '', strtolower(trim($d['user_data']['zp'])))); if(!empty($d['user_data']['ge'])) $ud['ge'] = hash('sha256', strtolower(trim(substr($d['user_data']['ge'], 0, 1)))); if(!empty($d['user_data']['db'])) $ud['db'] = hash('sha256', preg_replace('/[^0-9]/', '', $d['user_data']['db']));
    $ph = preg_replace('/[^0-9]/', '', $d['user_data']['ph'] ?? ''); if (!empty($ph)) { if (strlen($ph) === 11 && substr($ph, 0, 2) === '01') $ph = '880' . substr($ph, 1); else if (strlen($ph) > 10 && substr($ph, 0, 2) != '88') $ph = '88' . $ph; $ud['ph'] = hash('sha256', $ph); }
    if(!empty($d['user_data']['external_id'])) $ud['external_id'] = hash('sha256', $d['user_data']['external_id']);
    
    $ud['client_ip_address'] = !empty($d['user_data']['client_ip_address']) ? sanitize_text_field($d['user_data']['client_ip_address']) : markeflav_bp_get_client_ip(); $ud['client_user_agent'] = !empty($d['user_data']['client_user_agent']) ? sanitize_text_field($d['user_data']['client_user_agent']) : sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    if(!empty($d['user_data']['fbp'])) $ud['fbp'] = sanitize_text_field($d['user_data']['fbp']); if(!empty($d['user_data']['fbc'])) $ud['fbc'] = sanitize_text_field($d['user_data']['fbc']);
    
    $cd = [];
    if(isset($d['custom_data']) && is_array($d['custom_data'])) {
        foreach($d['custom_data'] as $k => $v) {
            if($v !== '' && $v !== null && (!is_array($v) || !empty($v))) { $cd[$k] = $v; }
        }
    }
    
    $ev_d = array('event_name' => $ev, 'event_id' => $ev_id, 'event_time' => time(), 'event_source_url' => sanitize_text_field($d['url'] ?? home_url()), 'action_source' => 'website', 'user_data' => $ud, 'custom_data' => $cd);
    $pl = array('data' => array($ev_d), 'access_token' => $tk); if ($tc = get_option('sys_test')) $pl['test_event_code'] = $tc;
    
    wp_remote_post("https://graph.facebook.com/v21.0/{$pd}/events", array('body' => json_encode($pl), 'headers' => array('Content-Type' => 'application/json'), 'blocking' => false, 'timeout' => 1, 'sslverify' => false));
    return new WP_REST_Response(['status'=>'sent'], 200);
}