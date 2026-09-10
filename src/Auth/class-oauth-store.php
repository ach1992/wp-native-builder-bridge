<?php
/**
 * OAuth transient-backed token storage.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Auth;

/**
 * Stores opaque OAuth artifacts without persisting bearer secrets in plaintext.
 */
final class OAuth_Store {
	const INSTANCE_OPTION = 'wp_native_builder_bridge_oauth_instance';

	const TYPE_CONSENT = 'consent';
	const TYPE_CODE    = 'code';
	const TYPE_ACCESS  = 'access';
	const TYPE_REFRESH = 'refresh';

	/**
	 * Returns the current installation identity, creating it lazily when needed.
	 *
	 * @return string Installation identity.
	 */
	public function instance_id() {
		$stored = get_option( self::INSTANCE_OPTION, '' );
		if ( is_string( $stored ) && preg_match( '/^[a-f0-9]{32}$/', $stored ) ) {
			return $stored;
		}

		$generated = bin2hex( random_bytes( 16 ) );
		if ( add_option( self::INSTANCE_OPTION, $generated, '', false ) ) {
			return $generated;
		}

		$stored = get_option( self::INSTANCE_OPTION, '' );
		return is_string( $stored ) && preg_match( '/^[a-f0-9]{32}$/', $stored ) ? $stored : $generated;
	}

	/**
	 * Issues an opaque artifact and stores only a keyed hash of its secret.
	 *
	 * @param string              $type   Artifact type.
	 * @param array<string,mixed> $claims Claims bound to the artifact.
	 * @param int                 $ttl    Lifetime in seconds.
	 * @return string Opaque artifact.
	 */
	public function issue( $type, array $claims, $ttl ) {
		$prefix = $this->token_prefix( $type );
		$ttl    = max( 1, (int) $ttl );

		if ( '' === $prefix ) {
			throw new \InvalidArgumentException( 'Unsupported OAuth artifact type.' );
		}

		$selector = $this->random_urlsafe( 18 );
		$secret   = $this->random_urlsafe( 32 );
		$token    = $prefix . '.' . $selector . '.' . $secret;

		$claims['secret_hash'] = $this->hash_secret( $secret );
		$claims['expires_at']  = time() + $ttl;
		$claims['instance_id'] = $this->instance_id();

		set_transient( $this->transient_key( $type, $selector ), $claims, $ttl );

		return $token;
	}

	/**
	 * Reads and validates an opaque artifact.
	 *
	 * @param string $type    Artifact type.
	 * @param string $token   Opaque artifact.
	 * @param bool   $consume Whether to remove a successfully validated artifact.
	 * @return array<string,mixed>|false Valid claims or false.
	 */
	public function read( $type, $token, $consume = false ) {
		$parsed = $this->parse( $type, $token );
		if ( false === $parsed ) {
			return false;
		}

		list( $selector, $secret ) = $parsed;
		$key                       = $this->transient_key( $type, $selector );
		$claims                    = get_transient( $key );

		if ( ! is_array( $claims ) || empty( $claims['secret_hash'] ) || empty( $claims['expires_at'] ) || empty( $claims['instance_id'] ) ) {
			return false;
		}

		if ( (int) $claims['expires_at'] < time() ) {
			delete_transient( $key );
			return false;
		}

		if ( ! hash_equals( (string) $claims['instance_id'], $this->instance_id() ) ) {
			return false;
		}

		if ( ! hash_equals( (string) $claims['secret_hash'], $this->hash_secret( $secret ) ) ) {
			return false;
		}

		if ( $consume ) {
			delete_transient( $key );
		}

		unset( $claims['secret_hash'], $claims['instance_id'] );
		return $claims;
	}

	/**
	 * Revokes one opaque artifact when its secret is valid.
	 *
	 * @param string $token Opaque artifact.
	 * @return bool Whether a valid artifact was revoked.
	 */
	public function revoke( $token ) {
		foreach ( array( self::TYPE_CONSENT, self::TYPE_CODE, self::TYPE_ACCESS, self::TYPE_REFRESH ) as $type ) {
			$parsed = $this->parse( $type, $token );
			if ( false === $parsed ) {
				continue;
			}

			list( $selector, $secret ) = $parsed;
			$key                       = $this->transient_key( $type, $selector );
			$claims                    = get_transient( $key );

			if ( ! is_array( $claims ) || empty( $claims['secret_hash'] ) ) {
				return false;
			}

			if ( ! hash_equals( (string) $claims['secret_hash'], $this->hash_secret( $secret ) ) ) {
				return false;
			}

			delete_transient( $key );
			return true;
		}

		return false;
	}

	/**
	 * Parses a token for one expected type.
	 *
	 * @param string $type  Artifact type.
	 * @param mixed  $token Token value.
	 * @return array{0:string,1:string}|false Selector and secret or false.
	 */
	private function parse( $type, $token ) {
		$prefix = $this->token_prefix( $type );
		if ( '' === $prefix || ! is_string( $token ) || strlen( $token ) > 256 ) {
			return false;
		}

		$pattern = '/^' . preg_quote( $prefix, '/' ) . '\.([A-Za-z0-9_-]{20,40})\.([A-Za-z0-9_-]{40,80})$/';
		if ( 1 !== preg_match( $pattern, $token, $matches ) ) {
			return false;
		}

		return array( $matches[1], $matches[2] );
	}

	/**
	 * Returns the public prefix for an artifact type.
	 *
	 * @param string $type Artifact type.
	 * @return string Prefix or empty string.
	 */
	private function token_prefix( $type ) {
		$prefixes = array(
			self::TYPE_CONSENT => 'wpnb_q',
			self::TYPE_CODE    => 'wpnb_c',
			self::TYPE_ACCESS  => 'wpnb_a',
			self::TYPE_REFRESH => 'wpnb_r',
		);

		return isset( $prefixes[ $type ] ) ? $prefixes[ $type ] : '';
	}

	/**
	 * Builds a bounded transient key.
	 *
	 * @param string $type     Artifact type.
	 * @param string $selector Public selector.
	 * @return string Transient key.
	 */
	private function transient_key( $type, $selector ) {
		return 'wpnb_oauth_' . sanitize_key( $type ) . '_' . $selector;
	}

	/**
	 * Hashes a bearer secret with a site-specific WordPress salt.
	 *
	 * @param string $secret Bearer secret.
	 * @return string Secret hash.
	 */
	private function hash_secret( $secret ) {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/**
	 * Generates a URL-safe cryptographically random value.
	 *
	 * @param int $bytes Random byte count.
	 * @return string URL-safe value.
	 */
	private function random_urlsafe( $bytes ) {
		return rtrim( strtr( base64_encode( random_bytes( (int) $bytes ) ), '+/', '-_' ), '=' );
	}
}
