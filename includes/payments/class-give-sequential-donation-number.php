<?php
// Exit if access directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Give_Sequential_Donation_Number {
	/**
	 * Instance.
	 *
	 * @since  2.1.0
	 * @access private
	 * @var
	 */
	static private $instance;

	/**
	 * Donation tile prefix
	 *
	 * @since 2.1.0
	 * @var string
	 */
	private $donation_title_prefix = 'give-donation-';

	/**
	 * Singleton pattern.
	 *
	 * @since  2.1.0
	 * @access private
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @since  2.1.0
	 * @access public
	 * @return Give_Sequential_Donation_Number
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			self::$instance = new static();

			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin, bailing if any required conditions are not met,
	 * including minimum WooCommerce version
	 *
	 * @since 2.1.0
	 */
	public function init() {
		if ( give_is_setting_enabled( give_get_option( 'sequential-ordering_status', 'enabled' ) ) ) {
			add_action( 'wp_insert_post', array( $this, '__save_donation_title' ), 10, 3 );
			add_action( 'after_delete_post', array( $this, '__remove_serial_number' ), 10, 1 );
		}

		/**
		 * Filter the donariton title prefix.
		 *
		 * This will prevent donation title from conflict from other post type slugs.
		 * Do not mistaken this will serial code prefix.
		 */
		$this->donation_title_prefix = apply_filters(
			'give_sequential_orderingl_donation_title_prefix',
			$this->donation_title_prefix
		);
	}

	/**
	 * Set serialize donation number as donation title.
	 * Note: only for internal use
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int     $donation_id
	 * @param WP_Post $post
	 * @param bool    $existing_donation_updated
	 *
	 * @return void
	 */
	public function __save_donation_title( $donation_id, $post, $existing_donation_updated ) {
		// Bailout
		if (
			$existing_donation_updated
			|| 'give_payment' !== $post->post_type
		) {
			return;
		}

		$serial_number = $this->__set_donation_number( $donation_id );
		$serial_code   = $this->__set_number_padding( $serial_number );

		// Add prefix.
		if ( $prefix = give_get_option( 'sequential-ordering_number_prefix', '' ) ) {
			$serial_code = $prefix . $serial_code;
		}

		// Add suffix.
		if ( $suffix = give_get_option( 'sequential-ordering_number_suffix', '' ) ) {
			$serial_code = $serial_code . $suffix;
		}

		$serial_code = give_time_do_tags( $serial_code );

		try {
			/* @var WP_Error $wp_error */
			$wp_error = wp_update_post(
				array(
					'ID'         => $donation_id,
					'post_name'  => "{$this->donation_title_prefix}-{$donation_id}",
					'post_title' => $this->donation_title_prefix . trim( $serial_code )
				)
			);

			if ( is_wp_error( $wp_error ) ) {
				throw new Exception( $wp_error->get_error_message() );
			}

			give_update_option( 'sequential-ordering_number', ( $serial_number + 1 ) );
		} catch ( Exception $e ) {
			error_log( "Give caught exception: {$e->getMessage()}" );
		}
	}

	/**
	 * Set donation number
	 * Note: only for internal use
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int $donation_id
	 *
	 * @return int
	 */
	public function __set_donation_number( $donation_id ) {
		// Customize sequential donation number starting point if needed.
		if (
			get_option( '_give_reset_sequential_number' ) &&
			( $number = give_get_option( 'sequential-ordering_number', 0 ) )
		) {
			if( Give()->sequential_donation_db->get_id_auto_increment_val() <= $number ){
				delete_option( '_give_reset_sequential_number' );
			}

			return Give()->sequential_donation_db->insert( array(
				'id'         => $number,
				'payment_id' => $donation_id
			) );
		}

		return Give()->sequential_donation_db->insert( array(
			'payment_id' => $donation_id
		) );
	}


	/**
	 * Remove sequential donation data
	 * Note: only internal use.
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param $donation_id
	 *
	 * @return bool
	 */
	public function __remove_serial_number( $donation_id ) {
		return Give()->sequential_donation_db->delete( $this->get_serial_number( $donation_id ) );
	}

	/**
	 * Set number padding in serial code.
	 *
	 * @since
	 * @access private
	 *
	 * @param $serial_number
	 *
	 * @return string
	 */
	private function __set_number_padding( $serial_number ) {
		if ( $number_padding = give_get_option( 'sequential-ordering_number_padding', 0 ) ) {
			$serial_number = str_pad( $serial_number, $number_padding, '0', STR_PAD_LEFT );
		}

		return $serial_number;
	}

	/**
	 * Get donation number serial code
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int|Give_Payment $donation
	 * @param array            $args
	 *
	 * @return string
	 */
	public function get_serial_code( $donation, $args = array() ) {
		$donation = $donation instanceof Give_Payment ? $donation : new Give_Payment( $donation );

		// Bailout.
		if (
			empty( $donation->ID )
			|| ! give_is_setting_enabled( give_get_option( 'sequential-ordering_status', 'enabled' ) )
		) {
			return $donation->ID;
		}

		// Set default params.
		$args = wp_parse_args(
			$args,
			array(
				'with_hash' => false,
				'default'   => true
			)
		);

		$serial_code = $args['default'] ? $donation->ID : '';

		if ( $donation_number = $this->get_serial_number( $donation->ID ) ) {
			$serial_code = get_the_title( $donation->ID );
		}

		// Remove donation title prefix.
		$serial_code = preg_replace( "/{$this->donation_title_prefix}/", '', $serial_code, 1 );

		$serial_code = $args['with_hash'] ? "#{$serial_code}" : $serial_code;

		/**
		 * Filter the donation serial code
		 *
		 * @since 2.1.0
		 */
		return apply_filters( 'give_get_donation_serial_code', $serial_code, $donation, $args, $donation_number );
	}

	/**
	 * Get serial number
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int $donation_id_or_serial_code
	 *
	 * @return string
	 */
	public function get_serial_number( $donation_id_or_serial_code ) {
		if ( is_numeric( $donation_id_or_serial_code ) ) {
			return Give()->sequential_donation_db->get_column_by( 'id', 'payment_id', $donation_id_or_serial_code );
		}

		return $this->get_serial_number( $this->get_donation_id( $donation_id_or_serial_code ) );
	}


	/**
	 * Get donation id with donation number or serial code
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param string $donation_number_or_serial_code
	 *
	 * @return string
	 */
	public function get_donation_id( $donation_number_or_serial_code ) {
		global $wpdb;

		if ( is_numeric( $donation_number_or_serial_code ) ) {
			return Give()->sequential_donation_db->get_column_by(
				'payment_id',
				'id',
				$donation_number_or_serial_code
			);
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT ID
				FROM $wpdb->posts
				WHERE post_title=%s
				",
				$donation_number_or_serial_code
			)
		);
	}

	/**
	 * Get maximum donation number
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @return int
	 */
	public function get_max_number() {
		global $wpdb;
		$table_name = Give()->sequential_donation_db->table_name;

		return absint(
			$wpdb->get_var(
				"
				SELECT ID
				FROM {$table_name}
				ORDER BY id DESC 
				LIMIT 1
				"
			)
		);
	}

	/**
	 * Get maximum donation number
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @return int
	 */
	public function get_next_number() {
		return ( $this->get_max_number() + 1 );
	}
}