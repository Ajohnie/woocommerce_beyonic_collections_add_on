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

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// register routes after initialisation of wp
add_action('init', 'registerRoutes');
function registerRoutes()
{
    // check if default query param for wc_beyonic plugin is passed
    if (isset($_GET['beyonic_ipn']) && $_GET['beyonic_ipn'] == 1) {
        $req = new WP_REST_Request();
        $req->set_body_params($_REQUEST);
        handleCollection($req);
    }
    // register custom routes to handle collection requests
    register_rest_route('beyonic_api', '/collections', array(
        'methods' => WP_REST_Server::ALLMETHODS,
        'callback' => 'handleCollection',
        'permission_callback' => 'checkBeyonicPermissions',
    ));
}

global $woocommerce; // reference woocommerce

/** converts array from beyonic api post request to object
 * @param $array
 * @return stdClass
 */
function arrayToObject($array)
{
    $object = new stdClass();
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $object->$key = arrayToObject($value);
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
function handleCollection($request)
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
        $body = arrayToObject($body);
    }

    $event = strtolower($body->hook->event);
    $collection_request = null;

    // listen for both collection events
    if ($event === 'collection.received') {
        $collection_request = $body->data->collection_request;
    }
    if ($event === 'collectionrequest.changed') {
        $collection_request = $body->data;
    }
    if ($collection_request) {
        //get order id from collection request
        $wc_beyonic = new Beyonic_Woo_Gw();
        $wc_beyonic->authorize_beyonic_gw();
        $collection_request = Beyonic_Collection_Request::get($collection_request->id);
        $order_id = (int)($collection_request->metadata->order_id);
        $status = checkStatus($collection_request->status);
        if ($status > 0 && isset($order_id)) {
            $order = new WC_Order($order_id);
            if ($status === 1) {
                $order->update_status('processing');
            }
            if ($status === 2) {
                $order->update_status('cancelled');
            }
        }
    }
    $response->set_data('Wakanda Forever !');
    return $response;
}


/**Authenticate REST request,
 * process authorization header and return true or false
 * @param $request WP_REST_Request
 * @return boolean
 */
function checkBeyonicPermissions($request)
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
function checkStatus($status)
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
