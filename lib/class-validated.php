<?php

/**
 * @name Validated Class
 */
class Validated {

	/**
	 * API URL for the W3C Validator.
	 * @var string 
	 */
	var $api_url = 'https://validator.nu/';

	/**
	 * Singleton instance
	 * @var Validated|Bool
	 */
	private static $instance = false;

	/**
	 * Grab instance of object.
	 * @return Validated
	 */
	public static function get_instance() {
		if ( !self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Actions and Filters
	 */
	function __construct() {
		add_filter( 'manage_posts_columns', array( $this, 'post_columns' ) );
		add_filter( 'manage_pages_columns', array( $this, 'post_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'display_columns' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'display_columns' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_script' ) );
		add_action( 'wp_ajax_validated', array( $this, 'validate_url' ) );
		add_action( 'wp_ajax_validated_results', array( $this, 'generate_report' ) );
		add_action( 'save_post', array( $this, 'save_post' ) );
		add_action( 'admin_footer', array( $this, 'footer' ) );
	}

	/*
	 * Enqueue the CSS, JavaScript and add some localization with a nonce and the ajax url.
	 */

	function load_script() {
		wp_enqueue_script( 'thickbox' );
		wp_enqueue_style( 'thickbox' );
		wp_enqueue_style( 'validated-css', VA_URL . 'assets/css/style.min.css' );
		wp_enqueue_script( 'validated-js', VA_URL . 'assets/js/script.min.js', array( 'jquery' ) );
		wp_localize_script( 'validated-js', 'ajax_object', array(
			'ajax_url'		 => admin_url( 'admin-ajax.php' ),
			'security'		 => wp_create_nonce( 'validated_security' ),
			'val_loading'	 => esc_url( VA_URL ) . '/assets/images/load.gif'
		) );
	}

	/**
	 * Filter the columns on pages and posts.
	 * @param array $columns
	 * @return array
	 */
	function post_columns( $columns ) {
		$columns[ 'validated_is_valid' ] = 'W3C Validation';
		$columns[ 'validated_check' ]	 = 'Check Validation';
		return $columns;
	}

	/**
	 * Populate the columns with post/site related data.
	 * @param string $column
	 * @param int $post_id
	 */
	function display_columns( $column, $post_id ) {
		if ( 'publish' != get_post_status( $post_id ) ) {
			return;
		}
		switch ( $column ) {
			case 'validated_is_valid':
				$results = get_post_meta( $post_id, '__validated', true );
				echo '<div id="validated_' . esc_attr( $post_id ) . '">';
				$this->show_results( $results, $post_id );
				echo '</div>';
				echo '<div id="validated_checking_' . esc_attr( $post_id ) . '" class="validated_loading"><img src="' . esc_url( VA_URL ) . '/assets/images/load.gif" alt="Loading"><br>Checking Now...</div>';
				break;
			case 'validated_check':
				echo '<a title="Validator Results" href="#" class="button-primary a_validated_check" data-pid="' . esc_attr( $post_id ) . '"><span class="dashicons dashicons-search"></span> Check</a>';
				break;
		}
	}

	/**
	 * AJAX callback to check HTML for certain post_id.
	 */
	function validate_url() {
		check_ajax_referer( 'validated_security', 'security' );
		$post_id = $this->get_post_id();
		$check	 = $this->call_api( $post_id );
		if ( is_wp_error( $check ) ) {
			return $this->process_error( $check->get_error_message() );
		}
		$errors				 = $this->check_errors( $check );
		$results			 = array(
			'errors'	 => $errors,
			'results'	 => $check
		);
		update_post_meta( (int) $post_id, '__validated', $results );
		$results[ 'result' ] = $this->show_results( $results, $post_id, false );
		return (0 == $errors) ? wp_send_json_success( $results ) : wp_send_json_error( $results );
	}

	/**
	 * AJAX callback to generate validation report for thickbox modal.
	 */
	function generate_report() {
		check_ajax_referer( 'validated_security', 'security' );
		$post_id = $this->get_post_id();
		$results = get_post_meta( $post_id, '__validated', true );
		ob_start();
		include VA_PATH . 'views/report.php';
		$report	 = ob_get_clean();
		return wp_send_json_success( $report );
	}

	/**
	 * Sanitize and validate that post_id is being passed.
	 * @return string|int
	 */
	private function get_post_id() {
		$post_id = 0;
		if ( isset( $_POST[ 'post_id' ] ) ) {
			$post_id = (int) sanitize_text_field( $_POST[ 'post_id' ] );
		}
		if ( 0 == $post_id ) {
			return $this->process_error( 'Post ID not passed.' );
		}
		if ( 'publish' != get_post_status( $post_id ) ) {
			return $this->process_error( 'Post is not published.' );
		}
		return $post_id;
	}

	/**
	 * Snags local HTML.
	 * @param string $url Local URL
	 * @return string
	 */
	private function snag_local_code( $url ) {
		$request = wp_safe_remote_get( $url );
		if ( is_wp_error( $request ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $request );
	}

	/**
	 * Send back response because of error.
	 */
	private function process_error( $msg = '' ) {
		update_post_meta( (int) $this->get_post_id(), '__validated', '' );
		$result = '<span class="validated_not_valid"><span class="dashicons dashicons-dismiss"></span> ' . esc_html( $msg ) . '</span>';
		return wp_send_json_error( array( 'result' => $result ) );
	}

	/**
	 * Takes returned results from W3C Validator request lets you know if valid or not.
	 * @param int $errors Total errors.
	 * @param object $results Return results object.
	 * @param int $post_id The Post ID.
	 * @param bool $echo Should this function echo out the HTML?
	 * @return string $return
	 */
	private function show_results( $results, $post_id, $echo = true ) {
		if ( !$results || !isset( $results[ 'errors' ] ) ) {
			return;
		}
		$return = '';
		$return.='<span class="validated_';
		$return.=($results[ 'errors' ]) ? 'not_valid' : 'is_valid';
		$return.='">';
		$return.='<span class="dashicons dashicons-';
		$return.=($results[ 'errors' ]) ? 'no' : 'yes';
		$return.='"></span> ';
		if ( $results[ 'errors' ] ) {
			$return.='<a title="Validator Results" href="#TB_inline?width=600&height=350&inlineId=validator-results" class="thickbox validated_show_report" data-pid="' . esc_attr( $post_id ) . '">' . esc_html( $results[ 'errors' ] ) . ' Errors</a>';
		} else {
			$return.='Valid';
		}
		$return.='</span>';
		if ( $echo ) {
			echo $return; // XSS ok
		}
		return $return;
	}

	/**
	 * Fires when a post is saved.
	 * Clears out the post_meta value for saved validation results.
	 * @param int $post_id
	 */
	function save_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		delete_post_meta( $post_id, '__validated' );
	}

	/**
	 * Adds thickbox div to footer.
	 */
	function footer() {
		echo '<div id="validator-results" style="display:none;"></div>';
	}

	/**
	 * Calls the W3C Validator API.
	 * @param int $post_id Post ID
	 * @param int $attempt Number of attempts to validate.
	 * @return boolean|object
	 */
	private function call_api( $post_id, $attempt = 1 ) {
		$method		 = (1 == $attempt) ? 'GET' : 'POST';
		$post_url	 = get_permalink( $post_id );
		$api_url	 = $this->api_url;
		$args		 = array(
			'method' => $method,
			'body'	 => array(
				'doc'	 => $post_url,
				'out'	 => 'json'
			)
		);

		if ( 'POST' == $method ) {
			$args[ 'body' ]		 = $this->snag_local_code( $post_url );
			$args[ 'headers' ]	 = array(
				'Content-Type' => 'text/html; charset=UTF-8'
			);
			$api_url.='?out=json';
		}

		$request = wp_remote_request( $api_url, $args );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$response = json_decode( wp_remote_retrieve_body( $request ) );
		if ( !is_object( $response ) ) {
			return $this->call_api( $post_id, 2 );
		}

		return $response;
	}

	/**
	 * Gets total number of errors.
	 * @param object $results
	 * @return int
	 */
	function check_errors( $results ) {
		$errors = 0;
		foreach ( $results->messages as $result ) {
			if ( 'error' == $result->type ) {
				$errors++;
			}
		}
		return $errors;
	}

}
