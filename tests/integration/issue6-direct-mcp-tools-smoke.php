<?php
/**
 * Live WordPress direct-MCP tool smoke using Bridge OAuth Bearer authentication.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-direct-mcp-tools-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpnb_issue6_direct_tools_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue6_direct_tools_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpnb_issue6_direct_tools_request( $method, $access_token, array $payload = array(), $session_id = '' ) {
	$request = new WP_REST_Request( $method, OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $access_token );
	$request->set_header( 'Accept', 'application/json, text/event-stream' );
	if ( 'POST' === $method ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
	}
	if ( '' !== $session_id ) {
		$request->set_header( 'Mcp-Session-Id', $session_id );
	}
	return rest_do_request( $request );
}

$user_id = get_current_user_id();
wpnb_issue6_direct_tools_assert( $user_id > 0, 'Run this smoke as an authenticated WordPress user.' );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );
$token = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array(
		'user_id'   => $user_id,
		'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'  => $oauth->mcp_endpoint_url(),
		'scope'     => OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE,
	),
	120
);

$before_sessions = class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' )
	? \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id )
	: array();

wp_set_current_user( 0 );
$initialize = wpnb_issue6_direct_tools_request(
	'POST',
	$token,
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => array(
			'protocolVersion' => '2025-11-25',
			'capabilities'    => (object) array(),
			'clientInfo'      => array(
				'name'    => 'wp-native-builder-direct-tools-smoke',
				'version' => '1.0.0',
			),
		),
	)
);
wpnb_issue6_direct_tools_assert( 200 === $initialize->get_status(), 'Direct OAuth MCP initialize failed.' );
$initialize_data = wpnb_issue6_direct_tools_data( $initialize );
wpnb_issue6_direct_tools_assert( '2025-11-25' === ( $initialize_data['result']['protocolVersion'] ?? '' ), 'Direct MCP protocol negotiation failed.' );

$headers    = $initialize->get_headers();
$session_id = isset( $headers['Mcp-Session-Id'] ) ? (string) $headers['Mcp-Session-Id'] : '';
if ( '' === $session_id && class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
	$after_sessions = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $user_id );
	$new_sessions   = array_diff_key( $after_sessions, $before_sessions );
	$session_id     = (string) array_key_first( $new_sessions );
}
wpnb_issue6_direct_tools_assert( '' !== $session_id, 'Direct OAuth MCP initialize did not create a session.' );

$notification = wpnb_issue6_direct_tools_request(
	'POST',
	$token,
	array(
		'jsonrpc' => '2.0',
		'method'  => 'notifications/initialized',
	),
	$session_id
);
wpnb_issue6_direct_tools_assert( 202 === $notification->get_status(), 'Direct MCP initialized notification failed.' );

$tools = wpnb_issue6_direct_tools_request(
	'POST',
	$token,
	array(
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'tools/list',
		'params'  => (object) array(),
	),
	$session_id
);
wpnb_issue6_direct_tools_assert( 200 === $tools->get_status(), 'Direct OAuth MCP tools/list failed.' );
$tools_data  = wpnb_issue6_direct_tools_data( $tools );
$tool_names = array_column( $tools_data['result']['tools'] ?? array(), 'name' );
sort( $tool_names );
wpnb_issue6_direct_tools_assert(
	array(
		'mcp-adapter-discover-abilities',
		'mcp-adapter-execute-ability',
		'mcp-adapter-get-ability-info',
	) === $tool_names,
	'Direct ChatGPT MCP server did not expose exactly the three layered Adapter tools.'
);

$discover = wpnb_issue6_direct_tools_request(
	'POST',
	$token,
	array(
		'jsonrpc' => '2.0',
		'id'      => 3,
		'method'  => 'tools/call',
		'params'  => array(
			'name'      => 'mcp-adapter-discover-abilities',
			'arguments' => (object) array(),
		),
	),
	$session_id
);
wpnb_issue6_direct_tools_assert( 200 === $discover->get_status(), 'Direct OAuth MCP ability discovery failed.' );
$discover_data = wpnb_issue6_direct_tools_data( $discover );
$discover_json = wp_json_encode( $discover_data );
wpnb_issue6_direct_tools_assert( false !== strpos( $discover_json, 'wp-native-builder/bridge-info' ), 'Direct OAuth MCP discovery did not expose bridge-info.' );

$execute = wpnb_issue6_direct_tools_request(
	'POST',
	$token,
	array(
		'jsonrpc' => '2.0',
		'id'      => 4,
		'method'  => 'tools/call',
		'params'  => array(
			'name'      => 'mcp-adapter-execute-ability',
			'arguments' => array(
				'ability_name' => 'wp-native-builder/bridge-info',
				'parameters'   => (object) array(),
			),
		),
	),
	$session_id
);
wpnb_issue6_direct_tools_assert( 200 === $execute->get_status(), 'Direct OAuth MCP bridge-info execution failed.' );
$execute_data = wpnb_issue6_direct_tools_data( $execute );
wpnb_issue6_direct_tools_assert( true === ( $execute_data['result']['structuredContent']['success'] ?? false ), 'Direct OAuth MCP bridge-info execution did not succeed.' );

$delete = wpnb_issue6_direct_tools_request( 'DELETE', $token, array(), $session_id );
wpnb_issue6_direct_tools_assert( in_array( $delete->get_status(), array( 200, 204 ), true ), 'Direct OAuth MCP session termination failed.' );
wpnb_issue6_direct_tools_assert( $store->revoke( $token ), 'Direct OAuth MCP test access token could not be revoked.' );

wp_set_current_user( $user_id );
echo "PASS: Issue #6 direct OAuth MCP tools/discovery/execute smoke.\n";
