<?php
/**
 * ChatGPT private_key_jwt client authentication.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Auth;

/**
 * Validates the fixed ChatGPT CIMD client's signed OAuth client assertions.
 */
final class Client_Assertion_Validator {
	const CHATGPT_JWKS_URI      = 'https://chatgpt.com/oauth/jwks.json';
	const ASSERTION_TYPE        = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
	const JWKS_CACHE            = 'wpnb_oauth_chatgpt_jwks';
	const JWKS_REFRESH_COOLDOWN = 'wpnb_oauth_chatgpt_jwks_refresh';
	const JWKS_REFRESH_INTERVAL = 60;
	const MAX_ASSERTION_TTL     = 600;
	const CLOCK_SKEW            = 60;

	/**
	 * OAuth artifact/replay store.
	 *
	 * @var OAuth_Store
	 */
	private $store;

	/**
	 * @param OAuth_Store $store OAuth storage service.
	 */
	public function __construct( OAuth_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Validates a private_key_jwt client assertion on an OAuth endpoint request.
	 *
	 * @param \WP_REST_Request $request            OAuth request.
	 * @param string           $expected_client_id Fixed ChatGPT client identifier.
	 * @param array<int,string> $audiences          Accepted authorization-server audience identifiers.
	 * @return true|\WP_Error True when the client assertion is valid.
	 */
	public function validate( $request, $expected_client_id, array $audiences ) {
		if ( ! $request instanceof \WP_REST_Request || ! function_exists( 'openssl_verify' ) ) {
			return new \WP_Error( 'invalid_client', 'Signed OAuth client authentication is unavailable.' );
		}

		$assertion_type = $this->bounded_param( $request, 'client_assertion_type', 128 );
		$assertion      = $this->bounded_param( $request, 'client_assertion', 8192 );
		if ( self::ASSERTION_TYPE !== $assertion_type || '' === $assertion ) {
			return new \WP_Error( 'invalid_client', 'A private_key_jwt client assertion is required.' );
		}

		$parts = explode( '.', $assertion );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion is malformed.' );
		}

		foreach ( $parts as $part ) {
			if ( '' === $part || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $part ) ) {
				return new \WP_Error( 'invalid_client', 'The OAuth client assertion is malformed.' );
			}
		}

		$header_json = $this->base64url_decode( $parts[0] );
		$claims_json = $this->base64url_decode( $parts[1] );
		$signature   = $this->base64url_decode( $parts[2] );
		if ( false === $header_json || false === $claims_json || false === $signature ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion encoding is invalid.' );
		}

		$header = json_decode( $header_json, true );
		$claims = json_decode( $claims_json, true );
		if ( ! is_array( $header ) || ! is_array( $claims ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion JSON is invalid.' );
		}

		$kid = isset( $header['kid'] ) && is_string( $header['kid'] ) ? $header['kid'] : '';
		if ( 'RS256' !== ( $header['alg'] ?? '' ) || '' === $kid || strlen( $kid ) > 160 ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion signing header is invalid.' );
		}

		if (
			( $claims['iss'] ?? '' ) !== $expected_client_id ||
			( $claims['sub'] ?? '' ) !== $expected_client_id ||
			! $this->audience_matches( $claims['aud'] ?? null, $audiences )
		) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion claims are invalid.' );
		}

		$now = time();
		$exp = isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ? (int) $claims['exp'] : 0;
		$iat = isset( $claims['iat'] ) && is_numeric( $claims['iat'] ) ? (int) $claims['iat'] : 0;
		$jti = isset( $claims['jti'] ) && is_string( $claims['jti'] ) ? $claims['jti'] : '';
		if (
			$exp <= ( $now - self::CLOCK_SKEW ) ||
			$exp > ( $now + self::MAX_ASSERTION_TTL ) ||
			( $iat && ( $iat > ( $now + self::CLOCK_SKEW ) || $iat < ( $now - self::MAX_ASSERTION_TTL ) ) ) ||
			'' === $jti ||
			strlen( $jti ) > 256
		) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion lifetime or identifier is invalid.' );
		}

		if ( isset( $claims['nbf'] ) && is_numeric( $claims['nbf'] ) && (int) $claims['nbf'] > ( $now + self::CLOCK_SKEW ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion is not active yet.' );
		}

		$jwk = $this->find_signing_key( $kid );
		if ( is_wp_error( $jwk ) ) {
			return $jwk;
		}

		$pem = $this->rsa_jwk_to_pem( $jwk );
		if ( is_wp_error( $pem ) ) {
			return $pem;
		}

		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion signature is invalid.' );
		}

		$replay_ttl = max( 1, min( self::MAX_ASSERTION_TTL, $exp - $now + self::CLOCK_SKEW ) );
		if ( ! $this->store->claim_client_assertion( $jti, $replay_ttl ) ) {
			return new \WP_Error( 'invalid_client', 'The OAuth client assertion has already been used.' );
		}

		return true;
	}

	/**
	 * Returns whether the assertion audience names this authorization server.
	 *
	 * @param mixed             $claim     aud claim.
	 * @param array<int,string> $audiences Accepted audiences.
	 * @return bool Match result.
	 */
	private function audience_matches( $claim, array $audiences ) {
		$claimed = is_string( $claim ) ? array( $claim ) : ( is_array( $claim ) ? $claim : array() );
		$claimed = array_values( array_filter( $claimed, 'is_string' ) );
		foreach ( array_slice( $claimed, 0, 8 ) as $value ) {
			if ( in_array( $value, $audiences, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieves one current ChatGPT RS256 signing key, refreshing once for rotation.
	 *
	 * @param string $kid Key identifier.
	 * @return array<string,mixed>|\WP_Error JWK or error.
	 */
	private function find_signing_key( $kid ) {
		$jwks = $this->jwks();
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}

		$key = $this->select_signing_key( $jwks, $kid );
		if ( false !== $key ) {
			return $key;
		}

		// Unknown kids can indicate legitimate ChatGPT key rotation, but an
		// unauthenticated caller must not be able to force an outbound JWKS fetch
		// on every request. Permit at most one rotation refresh per short window.
		if ( false !== get_transient( self::JWKS_REFRESH_COOLDOWN ) ) {
			return new \WP_Error( 'invalid_client', 'No trusted ChatGPT signing key matches the client assertion.' );
		}
		set_transient( self::JWKS_REFRESH_COOLDOWN, 1, self::JWKS_REFRESH_INTERVAL );

		$jwks = $this->jwks( true );
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}
		$key = $this->select_signing_key( $jwks, $kid );
		return false !== $key ? $key : new \WP_Error( 'invalid_client', 'No trusted ChatGPT signing key matches the client assertion.' );
	}

	/**
	 * Selects one exact RS256 signing key from a bounded JWKS set.
	 *
	 * @param array<int,array<string,mixed>> $jwks Signing keys.
	 * @param string                         $kid  Requested key ID.
	 * @return array<string,mixed>|false Matching key or false.
	 */
	private function select_signing_key( array $jwks, $kid ) {
		foreach ( $jwks as $key ) {
			if (
				is_array( $key ) &&
				( $key['kid'] ?? '' ) === $kid &&
				'RSA' === ( $key['kty'] ?? '' ) &&
				'RS256' === ( $key['alg'] ?? '' ) &&
				( ! isset( $key['use'] ) || 'sig' === $key['use'] )
			) {
				return $key;
			}
		}
		return false;
	}

	/**
	 * Fetches and validates the bounded fixed-origin ChatGPT JWKS document.
	 *
	 * @param bool $force_refresh Whether to bypass the local WordPress cache.
	 * @return array<int,array<string,mixed>>|\WP_Error Signing keys or error.
	 */
	private function jwks( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::JWKS_CACHE );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_safe_remote_get(
			self::CHATGPT_JWKS_URI,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'temporarily_unavailable', 'ChatGPT signing keys could not be verified.' );
		}

		$document = json_decode( wp_remote_retrieve_body( $response ), true );
		$keys     = isset( $document['keys'] ) && is_array( $document['keys'] ) ? array_slice( $document['keys'], 0, 10 ) : array();
		$valid    = array();
		foreach ( $keys as $key ) {
			if (
				is_array( $key ) &&
				'RSA' === ( $key['kty'] ?? '' ) &&
				'RS256' === ( $key['alg'] ?? '' ) &&
				isset( $key['kid'], $key['n'], $key['e'] ) &&
				is_string( $key['kid'] ) && strlen( $key['kid'] ) <= 160 &&
				is_string( $key['n'] ) && strlen( $key['n'] ) <= 1024 &&
				is_string( $key['e'] ) && strlen( $key['e'] ) <= 32
			) {
				$valid[] = $key;
			}
		}
		if ( empty( $valid ) ) {
			return new \WP_Error( 'invalid_client', 'ChatGPT signing-key metadata is invalid.' );
		}

		set_transient( self::JWKS_CACHE, $valid, 15 * MINUTE_IN_SECONDS );
		set_transient( self::JWKS_REFRESH_COOLDOWN, 1, self::JWKS_REFRESH_INTERVAL );
		return $valid;
	}

	/**
	 * Converts an RSA JWK to an OpenSSL SubjectPublicKeyInfo PEM value.
	 *
	 * @param array<string,mixed> $jwk RSA JWK.
	 * @return string|\WP_Error PEM or error.
	 */
	private function rsa_jwk_to_pem( array $jwk ) {
		$modulus  = $this->base64url_decode( (string) ( $jwk['n'] ?? '' ) );
		$exponent = $this->base64url_decode( (string) ( $jwk['e'] ?? '' ) );
		if (
			false === $modulus ||
			false === $exponent ||
			strlen( $modulus ) < 256 ||
			strlen( $modulus ) > 512 ||
			'' === $exponent ||
			strlen( $exponent ) > 8
		) {
			return new \WP_Error( 'invalid_client', 'The ChatGPT RSA signing key is invalid.' );
		}

		$rsa_public = $this->der_sequence( $this->der_integer( $modulus ) . $this->der_integer( $exponent ) );
		$algorithm  = hex2bin( '300d06092a864886f70d0101010500' );
		if ( false === $algorithm ) {
			return new \WP_Error( 'invalid_client', 'The RSA signing-key algorithm is unavailable.' );
		}
		$subject_public_key = $this->der_sequence( $algorithm . $this->der_bit_string( $rsa_public ) );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $subject_public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/** @param string $bytes Raw unsigned integer. @return string DER integer. */
	private function der_integer( $bytes ) {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes ) {
			$bytes = "\x00";
		}
		if ( ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}
		return "\x02" . $this->der_length( strlen( $bytes ) ) . $bytes;
	}

	/** @param string $bytes DER payload. @return string DER sequence. */
	private function der_sequence( $bytes ) {
		return "\x30" . $this->der_length( strlen( $bytes ) ) . $bytes;
	}

	/** @param string $bytes DER payload. @return string DER bit string. */
	private function der_bit_string( $bytes ) {
		$payload = "\x00" . $bytes;
		return "\x03" . $this->der_length( strlen( $payload ) ) . $payload;
	}

	/** @param int $length Payload length. @return string DER length. */
	private function der_length( $length ) {
		$length = (int) $length;
		if ( $length < 128 ) {
			return chr( $length );
		}
		$encoded = '';
		while ( $length > 0 ) {
			$encoded  = chr( $length & 0xff ) . $encoded;
			$length >>= 8;
		}
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	/**
	 * Strict base64url decoder.
	 *
	 * @param string $value Encoded value.
	 * @return string|false Decoded bytes or false.
	 */
	private function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Reads one bounded scalar REST parameter.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name    Parameter name.
	 * @param int              $max     Maximum length.
	 * @return string Bounded scalar or empty string.
	 */
	private function bounded_param( $request, $name, $max ) {
		$value = $request->get_param( $name );
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		return strlen( $value ) <= (int) $max ? $value : '';
	}
}
