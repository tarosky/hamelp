<?php
/**
 * WP-CLI commands for Hamelp.
 *
 * @package hamelp
 */

namespace Hametuha\Hamelp;

use Hametuha\Hamelp\Services\FaqCatalogBuilder;

/**
 * Manage Pubpla AI Help Center FAQ system.
 */
class Commands extends \WP_CLI_Command {

	/**
	 * Rebuild the FAQ catalog for AI Overview.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hamelp rebuild
	 *
	 * @subcommand rebuild
	 */
	public function rebuild() {
		\WP_CLI::log( __( 'Rebuilding FAQ catalog...', 'hamelp' ) );
		$builder = new FaqCatalogBuilder();
		$catalog = $builder->rebuild();
		\WP_CLI::success(
			sprintf(
				// translators: %d is the number of FAQ entries.
				__( 'FAQ catalog rebuilt. %d entries.', 'hamelp' ),
				count( $catalog )
			)
		);
	}

	/**
	 * Show FAQ catalog status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hamelp status
	 *
	 * @subcommand status
	 */
	public function status() {
		$builder = new FaqCatalogBuilder();
		$catalog = $builder->get_catalog();
		$updated = $builder->get_last_updated();

		\WP_CLI::log(
			sprintf(
				// translators: %d is the number of FAQ entries.
				__( 'Catalog entries: %d', 'hamelp' ),
				count( $catalog )
			)
		);

		if ( $updated ) {
			$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated );
			\WP_CLI::log(
				sprintf(
					// translators: %s is the date and time.
					__( 'Last updated: %s', 'hamelp' ),
					$date
				)
			);
		} else {
			\WP_CLI::warning( __( 'Catalog has never been built. Run "wp hamelp rebuild".', 'hamelp' ) );
		}
	}
}
