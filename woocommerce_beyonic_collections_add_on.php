<?php
/*
Plugin Name: woocommerce beyonic collections addon
Plugin URI: https://github.com/Ajohnie/woocommerce_beyonic_collections_add_on
Description: It works in conjuction with the beyonic woocommerce plugin to process collections
Author: Akankwatsa Johnson
Author URI: mailto:jakankwasa.tech@yahoo.com
Version: 1.0
Text Domain: beyonic_gateways
*/


//link to woocommerce settings
function woocommerce_collections_add_on_settings_link($links)
{
    $link = admin_url('admin.php?page=wc-settings&tab=checkout');
    $links[] = '<a href="' . $link . '">Payment Settings</a>';
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'woocommerce_collections_add_on_settings_link');

// register routes after initialisation of wp
add_action('init', 'registerRoutesWCJohnie');
function registerRoutesWCJohnie()
{
    // register custom routes to handle collection requests
    register_rest_route('beyonic-api', '/collections', array(
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'handleCollectionWCJohnie',
        'permission_callback' => 'checkBeyonicPermissionsWCJohnie',
    ));

    // check if default query param for wc_beyonic plugin is passed
    if (isset($_REQUEST['beyonic_ipn']) && $_REQUEST['beyonic_ipn'] == 1) {
        $req = new WP_REST_Request();
        $req->set_body_params($_REQUEST);
        handleCollectionWCJohnie($req);
    }
}

global $woocommerce; // reference woocommerce

/** converts array from beyonic api post request to object
 * @param $array
 * @return stdClass
 */
function arrayToObjectWCJohnie($array)
{
    $object = new stdClass();
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $object->$key = arrayToObjectWCJohnie($value);
        } else {
            $object->$key = $value;
        }
    }
    return $object;
}

/** process beyonic collection and updates database
 * @param WP_REST_Request $request Full details about the request
 *
 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
 */
function handleCollectionWCJohnie($request)
{
    $response = rest_ensure_response(new WP_REST_Response('Ok !', 200));

    // if the WC payment gateway class is not available or beyonic plugin is not installed, cry like a baby
    if (!class_exists('WC_Payment_Gateway') || !class_exists('Beyonic_Woo_Gw')) {
        $response->set_data('Wakanda Weeps!');
        return $response;
    }
    $body = $request->get_body_params();
    if (!isset($body)) { // empty body
        $response->set_status(404);
        $response->set_data('Smell You Later Loser !');
        return $response;
    }
    if (is_string($body)) { // json body
        $body = json_decode($body, false); // change to object
    }
    if (is_array($body)) { // change to object
        $body = arrayToObjectWCJohnie($body);
    }

    $event = strtolower($body->hook->event);
    $collection_request = null;

    // listen for both collection events
    if ($event === 'collection.received') {
        $collection_request = $body->data->collection_request;
    }
    if ($event === 'collectionrequest.status.changed') {
        $collection_request = $body->data;
    }
    $resp = 'Wakanda Is Thinking !';
    if ($collection_request) {
        //get order id from collection request
        $wc_beyonic = new Beyonic_Woo_Gw();
        $wc_beyonic->authorize_beyonic_gw();
        $collection_request = Beyonic_Collection_Request::get($collection_request->id);
        $order_id = (int)($collection_request->metadata->order_id);
        $status = checkStatusWCJohnie($collection_request->status);

        if ($status > 0 && isset($order_id)) {
            $order = new WC_Order($order_id);
            if ($status === 1) {
                $resp = 'Wakanda Succeeded !';
                $order->update_status('processing');
            }
            if ($status === 2) {
                $resp = 'Wakanda Failed !';
                $order->update_status('cancelled');
            }
        }
    }
    $response->set_data($resp);
    return $response;
}


/**Authenticate REST request,
 * process authorization header and return true or false
 * @param $request WP_REST_Request
 * @return boolean
 */
function checkBeyonicPermissionsWCJohnie($request)
{
    // TODO complete this
    $auth = $request->get_header('authorization');
    // username set in beyonic account
    // password set in beyonic account
    return true;
}

/** check the status of the collection request
 * @param $status string
 * @return int // 0, 1 ,2
 */
function checkStatusWCJohnie($status)
{
    $status = sanitize_text_field($status);
    switch (strtolower($status)) {
        case 'new':
        case 'pending':
        case 'instructions_sent':
            return 0;
        case 'successful':
            return 1;
        case 'reversed':
        case 'failed':
        case 'expired':
        case 'cancelled':
            return 2;
        default:
            break;
    }
    return 2;
}
