<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are set in WordPress but not available
 * during static analysis.
 *
 * @package Hamelp
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wp/' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
