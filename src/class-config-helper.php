<?php
/**
 * Wraps PHP constant and WP option lookups for testability.
 *
 * @package Kistn
 */

/**
 * Wraps PHP constant and WP option lookups for testability.
 */
class Kistn_Config_Helper {

	/**
	 * Checks whether a PHP constant is defined.
	 *
	 * @param string $name Constant name.
	 */
	public function is_constant_defined( string $name ): bool {
		return defined( $name );
	}

	/**
	 * Returns the value of a defined PHP constant.
	 *
	 * @param string $name Constant name.
	 */
	public function get_constant( string $name ): mixed {
		return constant( $name );
	}

	/**
	 * Returns the value of a WordPress option.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $fallback Fallback value when option is not set.
	 */
	public function get_wp_option( string $option, mixed $fallback = false ): mixed {
		return get_option( $option, $fallback );
	}
}
