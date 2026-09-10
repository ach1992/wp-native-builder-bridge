<?php
/**
 * Live WordPress smoke for the direct ChatGPT OAuth/MCP endpoint.
 *
 * Run with:
 * wp eval-file tests/integration/issue6-direct-oauth-smoke.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Auth\OAuth_Store;

function wpnb_issue6_oauth_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue6_oauth_data( $response ) {
	return json_decode( wp_json_encode( $response->get_data() ), true );
}

function wpnb_issue6_oauth_token_request( OAuth_Server $oauth, array $params ) {
	$request = new WP_REST_Request( 'POST', '/wp-native-builder/v1/oauth/token' );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	return $oauth->handle_token_request( $request );
}

$authenticated_user_id = get_current_user_id();
wpnb_issue6_oauth_assert( $authenticated_user_id > 0, 'Run this smoke as an authenticated WordPress user.' );

$store = new OAuth_Store();
$oauth = new OAuth_Server( $store );

$protected = $oauth->protected_resource_metadata();
wpnb_issue6_oauth_assert( $oauth->mcp_endpoint_url() === ( $protected['resource'] ?? '' ), 'Protected-resource metadata does not use the canonical direct MCP URL.' );
wpnb_issue6_oauth_assert( in_array( $oauth->issuer_url(), $protected['authorization_servers'] ?? array(), true ), 'Protected-resource metadata does not advertise the WordPress OAuth issuer.' );
wpnb_issue6_oauth_assert( in_array( OAuth_Server::SCOPE_MCP, $protected['scopes_supported'] ?? array(), true ), 'Protected-resource metadata does not advertise the MCP scope.' );
wpnb_issue6_oauth_assert( in_array( OAuth_Server::SCOPE_OFFLINE, $protected['scopes_supported'] ?? array(), true ), 'Protected-resource metadata does not advertise offline_access.' );

$authorization = $oauth->authorization_server_metadata();
wpnb_issue6_oauth_assert( true === ( $authorization['client_id_metadata_document_supported'] ?? false ), 'Authorization metadata does not advertise Client ID Metadata Document support.' );
wpnb_issue6_oauth_assert( array( 'S256' ) === ( $authorization['code_challenge_methods_supported'] ?? array() ), 'Authorization metadata does not require PKCE S256.' );
wpnb_issue6_oauth_assert( in_array( 'none', $authorization['token_endpoint_auth_methods_supported'] ?? array(), true ), 'Authorization metadata does not offer the ChatGPT-compatible public-client token method.' );
wpnb_issue6_oauth_assert( in_array( 'refresh_token', $authorization['grant_types_supported'] ?? array(), true ), 'Authorization metadata does not advertise refresh_token.' );
wpnb_issue6_oauth_assert( true === ( $authorization['authorization_response_iss_parameter_supported'] ?? false ), 'Authorization metadata does not advertise authorization-response issuer identification.' );

$routes = rest_get_server()->get_routes();
wpnb_issue6_oauth_assert( isset( $routes[ OAuth_Server::MCP_REQUEST_ROUTE ] ), 'Bridge-owned direct MCP REST route was not registered by MCP Adapter.' );
wpnb_issue6_oauth_assert( isset( $routes['/wp-native-builder/v1/oauth/token'] ), 'OAuth token REST route was not registered.' );
wpnb_issue6_oauth_assert( isset( $routes['/wp-native-builder/v1/oauth/revoke'] ), 'OAuth revocation REST route was not registered.' );

$verifier  = str_repeat( 'A', 64 );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
$scope     = OAuth_Server::SCOPE_MCP . ' ' . OAuth_Server::SCOPE_OFFLINE;
$claims    = array(
	'user_id'        => $authenticated_user_id,
	'client_id'      => OAuth_Server::CHATGPT_CLIENT_ID,
	'redirect_uri'   => OAuth_Server::CHATGPT_REDIRECT_URI,
	'code_challenge' => $challenge,
	'resource'       => $oauth->mcp_endpoint_url(),
	'scope'          => $scope,
);

$code = $store->issue( OAuth_Store::TYPE_CODE, $claims, OAuth_Server::CODE_TTL );
wpnb_issue6_oauth_assert( 0 === strpos( $code, 'wpnb_c.' ), 'Authorization code is not an opaque Bridge code.' );

$token_response = wpnb_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	)
);
wpnb_issue6_oauth_assert( 200 === $token_response->get_status(), 'Valid authorization code + PKCE exchange failed.' );
$token_data = wpnb_issue6_oauth_data( $token_response );
$access     = isset( $token_data['access_token'] ) ? (string) $token_data['access_token'] : '';
$refresh    = isset( $token_data['refresh_token'] ) ? (string) $token_data['refresh_token'] : '';
wpnb_issue6_oauth_assert( 0 === strpos( $access, 'wpnb_a.' ), 'Access token is not an opaque Bridge access token.' );
wpnb_issue6_oauth_assert( 0 === strpos( $refresh, 'wpnb_r.' ), 'Refresh token is not an opaque Bridge refresh token.' );
wpnb_issue6_oauth_assert( 'Bearer' === ( $token_data['token_type'] ?? '' ), 'Token response does not use Bearer token_type.' );
wpnb_issue6_oauth_assert( $scope === ( $token_data['scope'] ?? '' ), 'Token response changed the authorized scope.' );
wpnb_issue6_oauth_assert( OAuth_Server::ACCESS_TTL === ( $token_data['expires_in'] ?? 0 ), 'Token response does not advertise the bounded access-token lifetime.' );
wpnb_issue6_oauth_assert( 'no-store' === ( $token_response->get_headers()['Cache-Control'] ?? '' ), 'Token response is not cache-disabled.' );

$access_parts = explode( '.', $access );
wpnb_issue6_oauth_assert( 3 === count( $access_parts ), 'Access token shape is invalid.' );
$stored_access = get_transient( 'wpnb_oauth_access_' . $access_parts[1] );
wpnb_issue6_oauth_assert( is_array( $stored_access ) && ! empty( $stored_access['secret_hash'] ), 'Access-token backing record does not contain a secret hash.' );
wpnb_issue6_oauth_assert( false === strpos( serialize( $stored_access ), $access ), 'Plaintext access token was persisted in its backing transient.' );
wpnb_issue6_oauth_assert( false === strpos( serialize( $stored_access ), $access_parts[2] ), 'Plaintext access-token secret was persisted in its backing transient.' );

$replay_response = wpnb_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => $verifier,
	)
);
wpnb_issue6_oauth_assert( 400 === $replay_response->get_status(), 'Authorization code replay was accepted.' );
wpnb_issue6_oauth_assert( 'invalid_grant' === ( wpnb_issue6_oauth_data( $replay_response )['error'] ?? '' ), 'Authorization code replay did not fail as invalid_grant.' );

$bad_code = $store->issue( OAuth_Store::TYPE_CODE, $claims, OAuth_Server::CODE_TTL );
$bad_pkce = wpnb_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'authorization_code',
		'code'          => $bad_code,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'redirect_uri'  => OAuth_Server::CHATGPT_REDIRECT_URI,
		'resource'      => $oauth->mcp_endpoint_url(),
		'code_verifier' => str_repeat( 'B', 64 ),
	)
);
wpnb_issue6_oauth_assert( 400 === $bad_pkce->get_status(), 'Wrong PKCE verifier was accepted.' );
wpnb_issue6_oauth_assert( 'invalid_grant' === ( wpnb_issue6_oauth_data( $bad_pkce )['error'] ?? '' ), 'Wrong PKCE verifier did not fail as invalid_grant.' );

wp_set_current_user( 0 );
$missing_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
wpnb_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $missing_request ), 'Direct MCP transport accepted a request without a Bearer token.' );
$challenge_response = $oauth->add_mcp_authentication_challenge( new WP_REST_Response( array( 'code' => 'rest_forbidden' ), 403 ), rest_get_server(), $missing_request );
$challenge_headers  = $challenge_response->get_headers();
wpnb_issue6_oauth_assert( 401 === $challenge_response->get_status(), 'Missing Bearer token did not produce HTTP 401.' );
wpnb_issue6_oauth_assert( false !== strpos( $challenge_headers['WWW-Authenticate'] ?? '', 'Bearer resource_metadata="' . $oauth->protected_resource_metadata_url() . '"' ), 'HTTP 401 does not advertise protected-resource metadata.' );
wpnb_issue6_oauth_assert( 'no-store' === ( $challenge_headers['Cache-Control'] ?? '' ), 'Authentication challenge is cacheable.' );

$invalid_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$invalid_request->set_header( 'Authorization', 'Bearer wpnb_a.invalid.invalid' );
wpnb_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $invalid_request ), 'Malformed/unknown Bearer token was accepted.' );

$authorized_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$authorized_request->set_header( 'Authorization', 'Bearer ' . $access );
wpnb_issue6_oauth_assert( true === $oauth->authenticate_mcp_request( $authorized_request ), 'Valid resource-bound access token did not authenticate the WordPress user.' );
wpnb_issue6_oauth_assert( $authenticated_user_id === get_current_user_id(), 'Bearer token did not restore the authorized WordPress user identity.' );

$initialize = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$initialize->set_header( 'Authorization', 'Bearer ' . $access );
$initialize->set_header( 'Accept', 'application/json, text/event-stream' );
$initialize->set_header( 'Content-Type', 'application/json' );
$initialize->set_body(
	wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => (object) array(),
				'clientInfo'      => array(
					'name'    => 'wp-native-builder-direct-oauth-smoke',
					'version' => '1.0.0',
				),
			),
		)
	)
);
$initialized = rest_do_request( $initialize );
wpnb_issue6_oauth_assert( 200 === $initialized->get_status(), 'OAuth-authenticated direct MCP initialize did not return HTTP 200.' );
$initialized_data = wpnb_issue6_oauth_data( $initialized );
wpnb_issue6_oauth_assert( '2025-11-25' === ( $initialized_data['result']['protocolVersion'] ?? '' ), 'Direct OAuth MCP route did not negotiate the expected MCP protocol.' );

$refresh_response = wpnb_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'      => $oauth->mcp_endpoint_url(),
	)
);
wpnb_issue6_oauth_assert( 200 === $refresh_response->get_status(), 'Valid refresh token rotation failed.' );
$refreshed       = wpnb_issue6_oauth_data( $refresh_response );
$new_access      = isset( $refreshed['access_token'] ) ? (string) $refreshed['access_token'] : '';
$new_refresh     = isset( $refreshed['refresh_token'] ) ? (string) $refreshed['refresh_token'] : '';
wpnb_issue6_oauth_assert( '' !== $new_access && $new_access !== $access, 'Refresh did not rotate the access token.' );
wpnb_issue6_oauth_assert( '' !== $new_refresh && $new_refresh !== $refresh, 'Refresh did not rotate the refresh token.' );

$refresh_replay = wpnb_issue6_oauth_token_request(
	$oauth,
	array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh,
		'client_id'     => OAuth_Server::CHATGPT_CLIENT_ID,
		'resource'      => $oauth->mcp_endpoint_url(),
	)
);
wpnb_issue6_oauth_assert( 400 === $refresh_replay->get_status(), 'Rotated refresh token was reusable.' );
wpnb_issue6_oauth_assert( 'invalid_grant' === ( wpnb_issue6_oauth_data( $refresh_replay )['error'] ?? '' ), 'Refresh-token replay did not fail as invalid_grant.' );

$revoke = new WP_REST_Request( 'POST', '/wp-native-builder/v1/oauth/revoke' );
$revoke->set_param( 'token', $new_access );
$revoke_response = $oauth->handle_revoke_request( $revoke );
wpnb_issue6_oauth_assert( 200 === $revoke_response->get_status(), 'Access-token revocation endpoint failed.' );

$revoked_request = new WP_REST_Request( 'POST', OAuth_Server::MCP_REQUEST_ROUTE );
$revoked_request->set_header( 'Authorization', 'Bearer ' . $new_access );
wpnb_issue6_oauth_assert( false === $oauth->authenticate_mcp_request( $revoked_request ), 'Revoked access token remained valid.' );

$store->revoke( $access );
$store->revoke( $new_refresh );
wp_set_current_user( $authenticated_user_id );

echo "PASS: Issue #6 direct ChatGPT OAuth/MCP smoke.\n";
