<?php
/**
 * Negative live WordPress regressions for the direct ChatGPT OAuth/MCP boundary.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-direct-oauth-negative-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpnb_issue6_oauth_negative_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue6_oauth_bearer_request( $token ) {
	$request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	return $request;
}

$user_id = get_current_user_id();
wpnb_issue6_oauth_negative_assert( $user_id > 0, 'Run this smoke as an authenticated WordPress user.' );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );
$scope = OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE;

$base_claims = array(
	'user_id'   => $user_id,
	'client_id' => OAuth_Server::CHATGPT_CLIENT_ID,
	'resource'  => $oauth->mcp_endpoint_url(),
	'scope'     => $scope,
);

// A structurally valid access token for any other OAuth resource must fail closed.
$wrong_resource = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge(
		$base_claims,
		array(
			'resource' => 'https://invalid.example/wp-json/wp-native-builder/v1/mcp',
		)
	),
	60
);
wp_set_current_user( 0 );
wpnb_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpnb_issue6_oauth_bearer_request( $wrong_resource ) ),
	'A valid access token bound to another resource was accepted.'
);
$store->revoke( $wrong_resource );

// Expiry is checked from server-side state and an expired artifact is removed on read.
$expired = $store->issue( OAuth_Store::TYPE_ACCESS, $base_claims, 60 );
$parts   = explode( '.', $expired );
wpnb_issue6_oauth_negative_assert( 3 === count( $parts ), 'Expired-token fixture shape is invalid.' );
$transient_key = 'wpnb_oauth_access_' . $parts[1];
$stored        = get_transient( $transient_key );
wpnb_issue6_oauth_negative_assert( is_array( $stored ), 'Expired-token fixture backing state is unavailable.' );
$stored['expires_at'] = time() - 1;
set_transient( $transient_key, $stored, 60 );
wp_set_current_user( 0 );
wpnb_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpnb_issue6_oauth_bearer_request( $expired ) ),
	'Expired access token was accepted.'
);
wpnb_issue6_oauth_negative_assert( false === get_transient( $transient_key ), 'Expired access-token state was not deleted after validation.' );

// Token validity never substitutes for a live WordPress user identity.
$missing_user = $store->issue(
	OAuth_Store::TYPE_ACCESS,
	array_merge(
		$base_claims,
		array(
			'user_id' => 999999999,
		)
	),
	60
);
wp_set_current_user( 0 );
wpnb_issue6_oauth_negative_assert(
	false === $oauth->authenticate_mcp_request( wpnb_issue6_oauth_bearer_request( $missing_user ) ),
	'Access token for a missing WordPress user was accepted.'
);
$store->revoke( $missing_user );

wp_set_current_user( $user_id );
echo "PASS: Issue #6 direct OAuth negative bearer regressions.\n";
