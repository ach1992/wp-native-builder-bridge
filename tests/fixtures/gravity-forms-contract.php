<?php
/**
 * Minimal GFAPI contract fixture for transport-level integration tests.
 *
 * This is not Gravity Forms and must never be shipped with the plugin. It exists
 * only to exercise the Bridge fallback against the documented GFAPI method
 * contract when the commercial provider binary is unavailable in CI.
 */

if ( class_exists( 'GFAPI' ) ) {
	return;
}

final class GFAPI {
	/** @var array<int,array<string,mixed>> */
	private static $forms = array(
		100 => array(
			'id'          => 100,
			'title'       => 'WPNB GFAPI readable fixture',
			'description' => 'Stable read fixture across isolated MCP CLI processes.',
			'fields'      => array(),
			'is_active'   => false,
			'is_trash'    => false,
		),
	);
	/** @var int */
	private static $next_id = 1;

	public static function get_forms( $active = null, $trash = false, $sort_column = 'id', $sort_dir = 'ASC' ) {
		unset( $sort_column, $sort_dir );
		$forms = array_values( self::$forms );
		return array_values(
			array_filter(
				$forms,
				static function ( $form ) use ( $active, $trash ) {
					if ( null !== $active && (bool) $form['is_active'] !== (bool) $active ) {
						return false;
					}
					if ( null !== $trash && (bool) $form['is_trash'] !== (bool) $trash ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	public static function get_form( $id ) {
		$id = (int) $id;
		return isset( self::$forms[ $id ] ) ? self::$forms[ $id ] : false;
	}

	public static function form_id_exists( $id ) {
		return isset( self::$forms[ (int) $id ] );
	}

	public static function add_form( $form ) {
		$id                = self::$next_id++;
		$form['id']        = $id;
		$form['is_active'] = false;
		$form['is_trash']  = false;
		self::$forms[ $id ] = $form;
		return $id;
	}

	public static function update_form( $form ) {
		$id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		if ( ! isset( self::$forms[ $id ] ) ) {
			return false;
		}
		self::$forms[ $id ] = array_merge( self::$forms[ $id ], $form );
		return true;
	}

	public static function update_form_property( $id, $property, $value ) {
		$id = (int) $id;
		if ( ! isset( self::$forms[ $id ] ) ) {
			return false;
		}
		self::$forms[ $id ][ $property ] = $value;
		return true;
	}

	public static function delete_form( $id ) {
		$id = (int) $id;
		if ( ! isset( self::$forms[ $id ] ) ) {
			return false;
		}
		unset( self::$forms[ $id ] );
		return true;
	}
}
