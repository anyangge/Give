<?php

/**
 * Class for managing comments
 *
 * @package     Give
 * @subpackage  Classes/Give_Cache
 * @copyright   Copyright (c) 2018, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       2.1.0
 */
class Give_Comment {
	/**
	 * Instance.
	 *
	 * @since  2.1.0
	 * @access private
	 * @var
	 */
	static private $instance;

	/**
	 * Comment Types.
	 *
	 * @since  2.1.0
	 * @access private
	 * @var array
	 */
	private $comment_types;

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
	 * @access pu
	 * @return Give_Comment
	 */
	public static function get_instance() {
		if ( null === static::$instance ) {
			self::$instance = new static();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize
	 *
	 * @since  2.1.0
	 * @access private
	 */
	private function init() {
		/**
		 * Filter the comment type
		 *
		 * @since 2.1.0
		 */
		$this->comment_types = apply_filters(
			'give_comment_type',
			array( 'payment', 'donor' )
		);

		add_action( 'pre_get_comments', array( $this, 'hide_comments' ), 10 );
		add_filter( 'comments_clauses', array( $this, 'hide_comments_pre_wp_41' ), 10, 1 );
		add_filter( 'comment_feed_where', array( $this, 'hide_comments_from_feeds' ), 10, 1 );
		add_filter( 'wp_count_comments', array( $this, 'remove_comments_from_comment_counts' ), 10, 2 );
	}

	/**
	 * Insert comment
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int    $id           Payment|Donor ID.
	 * @param string $note         Comment Text
	 * @param string $comment_type Value can ve donor|payment
	 * @param array  $comment_args Comment arguments
	 *
	 * @return int
	 */
	public static function add( $id, $note, $comment_type, $comment_args = array() ) {
		// Bailout
		if ( empty( $id ) || empty( $note ) || empty( $comment_type ) ) {
			return false;
		}

		/**
		 * Fires before inserting payment|donor comment.
		 *
		 * @param int    $id   Payment|Donor ID.
		 * @param string $note Comment text.
		 *
		 * @since 1.0
		 */
		do_action( "give_pre_insert_{$comment_type}_note", $id, $note );

		$comment_args = wp_parse_args(
			$comment_args,
			array(
				'comment_post_ID'      => $id,
				'comment_content'      => $note,
				'user_id'              => is_admin() ? get_current_user_id() : 0,
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', 1 ),
				'comment_approved'     => 1,
				'comment_parent'       => 0,
				'comment_author'       => '',
				'comment_author_IP'    => '',
				'comment_author_url'   => '',
				'comment_author_email' => '',
				'comment_type'         => "give_{$comment_type}_note",

			)
		);

		$comment_id = wp_insert_comment( wp_filter_comment( $comment_args ) );

		update_comment_meta( $comment_id, "__give_{$comment_type}_id", $id );

		/**
		 * Fires after payment|donor comment inserted.
		 *
		 * @param int    $comment_id Comment ID.
		 * @param int    $id         Payment|Donor ID.
		 * @param string $note       Comment text.
		 *
		 * @since 1.0
		 */
		do_action( "give_insert_{$comment_type}_note", $comment_id, $id, $note );

		return $comment_id;
	}


	/**
	 * Delete comment
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int    $comment_id   The comment ID to delete.
	 * @param int    $id           The payment|Donor ID the note is connected to.
	 * @param string $comment_type Value can ve donor|payment.
	 *
	 * @since  1.0
	 *
	 * @return bool True on success, false otherwise.
	 */
	public static function delete( $comment_id, $id, $comment_type ) {
		$ret = false;

		// Bailout
		if ( empty( $id ) || empty( $comment_id ) || empty( $comment_type ) ) {
			return $ret;
		}

		/**
		 * Fires before deleting donation note.
		 *
		 * @param int $comment_id Comment ID.
		 * @param int $id         Payment|Donor ID.
		 *
		 * @since 1.0
		 */
		do_action( "give_pre_delete_{$comment_type}_note", $comment_id, $id );

		$ret = wp_delete_comment( $comment_id, true );

		/**
		 * Fires after donation note deleted.
		 *
		 * @param int  $comment_id Note ID.
		 * @param int  $id         Payment|Donor ID.
		 * @param bool $ret        Flag to check if comment deleted or not.
		 *
		 * @since 1.0
		 */
		do_action( "give_post_delete_{$comment_type}_note", $comment_id, $id, $ret );

		return $ret;
	}


	/**
	 * Get comments
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param int    $id
	 * @param string $search
	 * @param string $comment_type
	 * @param array  $comment_args
	 *
	 * @return array
	 */
	public static function get( $id, $search = '', $comment_type, $comment_args = array() ) {
		$comments = array();

		// Bailout
		if ( empty( $id ) || empty( $comment_type ) ) {
			return $comments;
		}

		remove_action( 'pre_get_comments', array( self::$instance, 'hide_comments' ), 10 );
		remove_filter( 'comments_clauses', array( self::$instance, 'hide_comments_pre_wp_41' ), 10 );

		switch ( $comment_type ) {
			case 'payment':
				$comments = get_comments( wp_parse_args(
					$comment_args,
					array(
						'post_id'    => $id,
						'order'      => 'ASC',
						'search'     => $search,
						'meta_query' => array(
							array(
								'key'     => '_give_donor_id',
								'compare' => 'NOT EXISTS'
							)
						)
					)
				) );
				break;

			case 'donor':
				$comments = get_comments( wp_parse_args(
					array(
						'meta_query' => array(
							array(
								'key'   => "_give_{$comment_type}_id",
								'value' => $id
							)
						),
						'order'      => 'ASC',
						'search'     => $search,
					)
				) );
				break;
		}

		add_action( 'pre_get_comments', array( self::$instance, 'hide_comments' ), 10, 1 );
		add_filter( 'comments_clauses', array( self::$instance, 'hide_comments_pre_wp_41' ), 10, 1 );

		return $comments;
	}

	/**
	 * Exclude comments from showing in Recent
	 * Comments widgets
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param object $query WordPress Comment Query Object.
	 *
	 * @return void
	 */
	public function hide_comments( $query ) {
		if ( version_compare( floatval( get_bloginfo( 'version' ) ), '4.1', '>=' ) ) {
			$types = isset( $query->query_vars['type__not_in'] ) ? $query->query_vars['type__not_in'] : array();
			if ( ! is_array( $types ) ) {
				$types = array( $types );
			}

			$types = array_merge( $types, $this->comment_types );

			$query->query_vars['type__not_in'] = $types;
		}
	}

	/**
	 * Exclude notes (comments) from showing in Recent Comments widgets
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param array $clauses Comment clauses for comment query.
	 *
	 * @return array $clauses Updated comment clauses.
	 */
	public function hide_comments_pre_wp_41( $clauses ) {
		if ( version_compare( floatval( get_bloginfo( 'version' ) ), '4.1', '<' ) ) {
			foreach ( $this->comment_types as $comment_type ) {
				$clauses['where'] .= " AND comment_type != \"give_{$comment_type}_note\"";
			}
		}

		return $clauses;
	}

	/**
	 * Exclude notes (comments) from showing in comment feeds
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param string $where
	 *
	 * @return string $where
	 */
	public function hide_comments_from_feeds( $where ) {
		global $wpdb;

		$where .= $wpdb->prepare( ' AND comment_type != %s', 'give_payment_note' );

		return $where;
	}

	/**
	 * Remove Give Comments from the wp_count_comments function
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 * @param array $stats   (empty from core filter).
	 * @param int   $post_id Post ID.
	 *
	 * @return array|object Array of comment counts.
	 */
	public function remove_comments_from_comment_counts( $stats, $post_id ) {
		global $wpdb, $pagenow;

		if ( 'index.php' != $pagenow ) {
			return $stats;
		}

		$post_id = (int) $post_id;

		if ( apply_filters( 'give_count_payment_notes_in_comments', false ) ) {
			return $stats;
		}

		$stats = Give_Cache::get_group( "comments-{$post_id}", 'counts' );

		// Return result from cache.
		if ( ! is_null( $stats ) ) {
			return $stats;
		}

		$where = 'WHERE';

		foreach ( $this->comment_types as $index => $comment_type ) {
			$where .= ( $index ? 'AND ' : ' ' ) . "comment_type != \"give_{$comment_type}_note\"";
		}

		if ( $post_id > 0 ) {
			$where .= $wpdb->prepare( ' AND comment_post_ID = %d', $post_id );
		}

		$count = $wpdb->get_results(
			"
				  SELECT comment_approved, COUNT( * ) AS num_comments
				  FROM {$wpdb->comments} {$where}
				  GROUP BY comment_approved",
			ARRAY_A
		);

		$total    = 0;
		$approved = array(
			'0'            => 'moderated',
			'1'            => 'approved',
			'spam'         => 'spam',
			'trash'        => 'trash',
			'post-trashed' => 'post-trashed',
		);
		foreach ( (array) $count as $row ) {
			// Don't count post-trashed toward totals.
			if ( 'post-trashed' != $row['comment_approved'] && 'trash' != $row['comment_approved'] ) {
				$total += $row['num_comments'];
			}
			if ( isset( $approved[ $row['comment_approved'] ] ) ) {
				$stats[ $approved[ $row['comment_approved'] ] ] = $row['num_comments'];
			}
		}

		$stats['total_comments'] = $total;
		foreach ( $approved as $key ) {
			if ( empty( $stats[ $key ] ) ) {
				$stats[ $key ] = 0;
			}
		}

		$stats = (object) $stats;
		Give_Cache::set_group( "comments-{$post_id}", $stats, 'counts' );

		return $stats;
	}
}