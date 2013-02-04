<?php
/**
Plugin Name: Required Fields
Plugin URI:
Description: This plugin allows you to make certain fields on the edit screen required for publishing a post. There is an API to extend it to custom fields too.
Author: Robert O'Rourke
Version: 1.1.beta
Author URI: http://interconnectit.com
*/

/**
Changelog:

1.1.beta
	Added ability to have multiple validations per field

*/

if ( ! class_exists( 'required_fields' ) ) {

add_action( 'plugins_loaded', array( 'required_fields', 'instance' ) );

class required_fields {

	/**
	 * Translation DOM
	 */
	const DOM = __CLASS__;

	/**
	 * Holds the registered validation config
	 */
	public static $fields = array();

	var $post_id;
	var $transient_key;
	var $current_user;
	var $errors;
	var $cache_time = 60;

	/**
	 * Reusable object instance.
	 *
	 * @type object
	 */
	protected static $instance = null;

	/**
	 * Creates a new instance. Called on 'after_setup_theme'.
	 * May be used to access class methods from outside.
	 *
	 * @see    __construct()
	 * @return void
	 */
	public static function instance() {
		null === self :: $instance AND self :: $instance = new self;
		return self :: $instance;
	}


	function __construct() {

		if ( ! is_admin() )
			return;

		// force post to remain as draft if error messages are set
		add_filter( 'wp_insert_post_data', array( $this, 'force_draft' ), 12, 2 );

		// display & clear any errors
		add_action( 'admin_notices', array( $this, 'notice_handler' ) );

		// settings
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// set up vars
		$this->post_id = isset( $_GET[ 'post' ] ) ? intval( $_GET[ 'post' ] ) : 0;
		$this->current_user = get_current_user_id();
		$this->transient_key = "save_post_error_{$this->post_id}_{$this->current_user}"; // key should be specific to post and the user editing the post
	}

	// add setting to writing screen for required custom excerpt/content
	function admin_init() {
		global $pagenow;

		// error handling
		if ( $pagenow == 'post.php' ) {

			// get errors
			$this->errors = get_option( $this->transient_key );

			// if errors unset the 'published' message
			if ( $this->errors && isset( $_GET[ 'message' ] ) && $_GET[ 'message' ] == 6 )
				unset( $_GET[ 'message' ] );

		}

		add_settings_section( 'required_fields', __( 'Required fields', self::DOM ), array( $this, 'section' ), 'writing' );

		$fields = apply_filters( 'required_fields', array(
			'post_title' => array(
					'title' => __( 'Title', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( 'required_fields', 'checkbox_field' ),
					'message' => '',
					'validation_cb' => false,
					'post_types' => 'any' ),
			'post_content' => array(
					'title' => __( 'Content', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( 'required_fields', 'checkbox_field' ),
					'message' => '',
					'validation_cb' => false,
					'post_types' => 'any' ),
			'post_excerpt' => array(
					'title' => __( 'Excerpt', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( 'required_fields', 'checkbox_field' ),
					'message' => '',
					'validation_cb' => false,
					'post_types' => 'post' ),
			'category' => array(
					'title' => __( 'Category', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( 'required_fields', 'checkbox_field' ),
					'message' => __( 'You must choose a category other than the default.', self::DOM ),
					'validation_cb' => array( 'required_fields', '_has_category' ),
					'post_types' => 'post' )
		) );

		foreach( $fields as $name => $field ) {
			$field_name = "require_{$name}";
			$field_value = get_option( $field_name );
			add_settings_field( $field_name , $field[ 'title' ], $field[ 'setting_field' ], 'writing', 'required_fields', array(
				'name' => $field_name,
				'value' => $field_value
			) );
			register_setting( 'writing', $field_name, $field[ 'setting_cb' ] );

			// if the setting validation returns true register the field as required
			if ( call_user_func( $field[ 'setting_cb' ], $field_value ) )
				$this->register( $field[ 'title' ], $name, $field[ 'message' ], $field[ 'validation_cb' ], $field[ 'post_types' ] );
		}

	}

	function section() { ?>
		<p><?php _e( 'Use the checkboxes below to make the corresponding fields required before a post can be published.', self::DOM ); ?></p>
		<?php
	}

	function checkbox_field( $args ) {
		echo '<input type="checkbox" name="' . $args[ 'name' ] . '" value="1" ' . checked( 1, intval( $args[ 'value' ] ), false ) . ' />';
	}

	function register( $label, $name, $message = '', $validation_cb = false, $post_types = 'any' ) {

		if ( $post_types == 'any' )
			$post_types = get_post_types( array( 'public' => true ) );

		foreach( (array)$post_types as $type ) {
			if ( ! isset( $this->fields[ $type ] ) )
				$this->fields[ $type ] = array();
			$this->fields[ $type ][] = array(
				'label' => $label,
				'name' => $name,
				'cb' => is_callable( $validation_cb ) || is_array( $validation_cb ) ? $validation_cb : array( 'required_fields', '_not_empty' ),
				'message' => empty( $message ) ? sprintf( __( '%s is required before you can publish.', self::DOM ), $label ) : $message
				);
		}

	}

	function force_draft( $data, $postarr ) {

		$post_id = $postarr[ 'ID' ];
		if ( ! $post_id )
			return $data;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $data;
		if ( ! current_user_can( 'edit_posts' ) )
			return $data;

		// reset transient key
		$this->transient_key = "save_post_error_{$post_id}_{$this->current_user}";

		// clear errors
		delete_option( $this->transient_key );

		$errors = array();

		// add error messages here
		foreach( $this->fields[ $postarr[ 'post_type' ] ] as $validation ) {
			$value = $this->_find_field( $validation[ 'name' ], $postarr );
			if ( $value === null )
				$value = $postarr;

			// if we're doing multiple validations
			if ( ! is_callable( $validation[ 'cb' ] ) && is_array( $validation[ 'cb' ] ) ) {
				foreach( $validation[ 'cb' ] as $subvalidation ) {
					if ( isset( $subvalidation[ 'cb' ] ) && ! call_user_func( $subvalidation[ 'cb' ], $value ) ) {
						if ( ! isset( $errors[ sanitize_key( $validation[ 'name' ] ) ] ) )
							$errors[ sanitize_key( $validation[ 'name' ] ) ] = array( 'message' => $validation[ 'message' ], 'errors' => array() );
						$errors[ sanitize_key( $validation[ 'name' ] ) ][ 'errors' ][] = $subvalidation[ 'message' ];
					}
				}

			// single validation
			} elseif( is_callable( $validation[ 'cb' ] ) ) {
				if ( ! call_user_func( $validation[ 'cb' ], $value ) )
					$errors[ sanitize_key( $validation[ 'name' ] ) ] = $validation[ 'message' ];
			}
		}

		if( ! empty( $errors ) ) {
			// store errors for display
			update_option( $this->transient_key, $errors );
			// revert to draft
			$data[ 'post_status' ] = 'draft';
		}

		return $data;
	}

	function notice_handler() {
		global $pagenow;

		if( $this->errors && $pagenow == 'post.php' ) {
			echo '<div class="error">';
			foreach( $this->errors as $code => $message ) {
				if ( is_array( $message ) ) {
					if ( ! empty( $message[ 'message' ] ) )
						echo '<p class="' . $code . '">' . $message[ 'message' ] . '</p>';
					echo '<ul class="' . $code . '-list">';
					foreach( $message[ 'errors' ] as $error ) {
						echo '<li>' . $error . '</li>';
					}
					echo '</ul>';
				} else {
					echo '<p class="' . $code . '">' . $message . '</p>';
				}
			}
			echo '</div>';

			// remove errors
			delete_option( $this->transient_key );
		}
	}

	// find field name in post data or custom fields
	function _find_field( $name, $postarr ) {

		if ( array_key_exists( $name, $postarr ) )
			return $postarr[ $name ];

		$custom_fields = get_post_meta( $postarr[ 'ID' ] );
		if ( array_key_exists( $name, $custom_fields ) )
			return array_shift( $custom_fields[ $name ] );

		return null;
	}

	// default validation callback
	function _not_empty( $value ) {
		if ( is_string( $value ) )
			$value = trim( $value );
		return ! empty( $value );
	}

	 // 1 is ID of 'Uncategorized' category
	function _has_category( $postarr ) {
		$cats = $postarr[ 'post_category' ];
		$cats = array_filter( $cats, function( $val ) {
			return $val > 1;
		} );
		return count( $cats );
	}

}

if ( ! function_exists( 'register_required_field' ) ) {

	/**
	 * Registers a field as required for a post to be published.
	 * The default callback checks if the value of the post data or
	 * post meta field corresponding to the $name is empty or not.
	 *
	 * @param string $label         Nice name for the required field
	 * @param string $name          The post data array key or custom field key
	 * @param string $message       The error message to display if validation fails
	 * @param callback|array $validation_cb A callback that returns true if the field value is ok or an array of error messages and callbacks to test.
	 * 			array format: array( array( 'message' => 'My error message', 'cb' => 'my_validation_method' ), ... )
	 * @param string|array $post_type     The post type or post types to run the validation on
	 *
	 * @return void
	 */
	function register_required_field( $label, $name, $message = '', $validation_cb = false, $post_type = 'any' ) {
		$rf = required_fields::instance();
		$rf->register( $label, $name, $message, $validation_cb, $post_type );
	}

}

}

?>
