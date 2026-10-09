<?php
if (!defined('ABSPATH')) exit;

add_action('wp_head', function() {
    if (is_admin() || !markeflav_bp_is_active()) return;
    
    $ga4_id = get_option('sys_ga4_id'); 
    $clarity_id = get_option('sys_clarity_id'); 
    $web_events = get_option('sys_ev_web'); if(!is_array($web_events)) $web_events = [];
    
    if($ga4_id): echo '<script async src="https://www.googletagmanager.com/gtag/js?id='.esc_attr($ga4_id).'"></script><script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date()); gtag("config", "'.esc_attr($ga4_id).'", { "send_page_view": false });</script>'; endif;
    if($clarity_id): echo '<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, "clarity", "script", "'.esc_attr($clarity_id).'");</script>'; endif;
    
    if($ga4_id || $clarity_id):
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var validWebEvents = <?php echo json_encode($web_events); ?>;
        
        // Listen for the broadcasted event from the main tracking file
        document.addEventListener('markeflav_bp_analytics_trigger', function(e) {
            var ev = e.detail.event;
            var d = e.detail.data;
            
            if (typeof window.clarity === 'function') { window.clarity("set", "Action_Event", ev); }
            
            if (typeof window.gtag === 'function' && validWebEvents.indexOf(ev) !== -1) {
                var gaEvent = ev, gaParams = { event_id: d.event_id };
                
                if(ev === 'PageView') gaEvent = 'page_view';
                if(ev === 'ViewContent' && d.content_ids) { 
                    gaEvent = 'view_item'; gaParams.value = d.value; gaParams.currency = d.currency;
                    gaParams.items = [{item_id: d.content_ids[0], item_name: d.content_name || 'Product', price: d.value, item_category: d.content_category || ''}]; 
                }
                if(ev === 'AddToCart' && d.contents) {
                    gaEvent = 'add_to_cart'; gaParams.value = d.value; gaParams.currency = d.currency;
                    gaParams.items = d.contents.map(function(i){ return {item_id: i.id, price: i.item_price, quantity: i.quantity}; });
                }
                if(ev === 'InitiateCheckout') { 
                    gaEvent = 'begin_checkout'; gaParams.value = d.value; gaParams.currency = d.currency; 
                }
                if(ev === 'Purchase') { 
                    gaEvent = 'purchase'; gaParams.transaction_id = d.transaction_id || d.order_id; gaParams.value = d.value; gaParams.currency = d.currency; 
                }
                
                window.gtag('event', gaEvent, gaParams);
            }
        });
    });
    </script>
    <?php
    endif;
}, 10);