<?php
/**
Plugin Name: Required Post Fields
Plugin URI:
Description: This plugin allows you to make certain fields required on the edit screen before a post can be published. There is an API to add your own rules too.
Author: Robert O'Rourke @ interconnect/it
Version: 1.5.1
Author URI: http://interconnectit.com
License: http://www.gnu.org/licenses/gpl-3.0.txt
*/

/**
Changelog:

1.5.1
	Fixed PHP 5.2.x incompatibility
	Improved default error messages

1.5
	Show hidden metaboxes if they contain required fields

1.4
	UX improvements - highlighting error fields and adding links in default message plus option to require tags

1.3
	Added rules for custom post types based on fields the custom post type supports

1.2
	Fixed multi validation and added featured image validation out of the box

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
	 * @var string Plugin basename
	 */
	protected static $plugin;

	/**
	 * Holds the registered validation config
	 */
	public static $fields = array();

	public $post_id;
	public $transient_key;
	public $current_user;
	public $errors;
	public $cache_time = 60;

	public $default_validation = array( __CLASS__, '_not_empty' );

	/**
	 * Reusable object instance.
	 *
	 * @type object
	 */
	protected static $instance = null;

	/**
	 * Creates a new instance. Called on 'plugins_loaded'.
	 * May be used to access class methods from outside.
	 *
	 * @see    __construct()
	 * @return void
	 */
	public static function instance() {
		null === self :: $instance AND self :: $instance = new self;
		return self :: $instance;
	}


	public function __construct() {

		if ( ! is_admin() )
			return;

		// set plugin base
		$this->plugin = plugin_basename( __FILE__ );

		// force post to remain as draft if error messages are set
		add_filter( 'wp_insert_post_data', array( $this, 'force_draft' ), 12, 2 );

		// force non empty title & content so plugin can take over
		add_filter( 'pre_post_title', 	array( $this, 'mask_empty' ) );
		add_filter( 'pre_post_content', array( $this, 'mask_empty' ) );

		// display & clear any errors
		add_action( 'admin_notices', array( $this, 'notice_handler' ) );

		// settings
		add_action( 'admin_init', array( $this, 'admin_init' ), 9 );

		// set up vars
		$this->post_id = isset( $_GET[ 'post' ] ) ? intval( $_GET[ 'post' ] ) : 0;
		$this->current_user = get_current_user_id();
		$this->transient_key = "save_post_error_{$this->post_id}_{$this->current_user}"; // key should be specific to post and the user editing the post

		// add settings link
		add_filter( "plugin_action_links_{$this->plugin}", array( $this, 'settings_link' ), 10, 1 );
	}


	/**
	 * Adds a link direct othe required fields settings on the plugins page
	 *
	 * @param array $links Array of links displayed on plugins page
	 * @return array
	 */
	public function settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-writing.php#required-fields-settings' ) . '">' . __( 'Settings', self::DOM ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}


	// add setting to writing screen for required custom excerpt/content
	public function admin_init() {
		global $pagenow;

		// error handling
		if ( $pagenow == 'post.php' ) {

			// get errors
			$this->errors = get_option( $this->transient_key );

			// if errors or not published unset the 'published' message
			if ( isset( $_GET[ 'message' ] ) && $_GET[ 'message' ] == 6 ) {
				if ( $this->errors || get_post_status( $this->post_id ) !== 'publish' )
					unset( $_GET[ 'message' ] );
			}

			// makes sure hidden required fields are shown and highlights errors
			add_action( 'admin_print_footer_scripts', array( $this, 'error_scripts' ), 100 );

		}

		// easy method of disabling admin area for plugin
		if ( apply_filters( 'required_fields_disable_admin', false ) )
			return;

		add_settings_section( 'required_fields', __( 'Required Post Fields', self::DOM ), array( $this, 'section' ), 'writing' );

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		$fields = array();

		foreach( $post_types as $post_type ) {

			if ( in_array( $post_type->name, apply_filters( 'required_fields_ignored_post_types', array( 'attachment', 'nav_menu_item' ) ) ) )
				continue;

			$post_type_fields = array();

			add_settings_section( "required_fields_{$post_type->name}", sprintf( __( 'Required fields for %s', self::DOM ), $post_type->labels->name ), '__return_false', 'writing' );

			if ( post_type_supports( $post_type->name, 'title' ) ) {
				$post_type_fields[ "post_title_{$post_type->name}" ] = array(
					'name' => 'post_title',
					'title' => __( 'Title', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => sprintf( __( 'Your %s needs a <a href="#titlediv" title="Skip to title">title</a>!', self::DOM ), $post_type->labels->singular_name ),
					'validation_cb' => false,
					'post_type' => $post_type->name,
					'highlight' => '#titlediv' );
			}

			if ( post_type_supports( $post_type->name, 'editor' ) ) {
				$post_type_fields[ "post_content_{$post_type->name}" ] = array(
					'name' => 'post_content',
					'title' => __( 'Content', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => __( 'You should add some <a href="#postdivrich" title="Skip to content">content</a> before you publish.', self::DOM ),
					'validation_cb' => false,
					'post_type' => $post_type->name,
					'highlight' => '#postdivrich' );
			}

			if ( post_type_supports( $post_type->name, 'excerpt' ) ) {
				$post_type_fields[ "post_excerpt_{$post_type->name}" ] = array(
					'name' => 'post_excerpt',
					'title' => __( 'Excerpt', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => __( 'You should write a <a href="#postexcerpt" title="Skip to excerpt">custom excerpt</a> to summarise your post. It can help to encourage users to click through to your content.', self::DOM ),
					'validation_cb' => false,
					'post_type' => $post_type->name,
					'highlight' => '#postexcerpt' );
			}

			if ( is_object_in_taxonomy( $post_type->name, 'category' ) ) {
				$default = get_term( 1, 'category' );
				$post_type_fields[ "category_{$post_type->name}" ] = array(
					'name' => 'category',
					'title' => __( 'Category (non-default)', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => sprintf( __( 'You should choose a <a href="#categorydiv" title="Skip to categories">category</a> other than the default "%s".', self::DOM ), $default->name ),
					'validation_cb' => array( __CLASS__, '_has_category' ),
					'post_type' => $post_type->name,
					'highlight' => '#categorydiv' );
			}

			if ( is_object_in_taxonomy( $post_type->name, 'post_tag' ) ) {
				$post_type_fields[ "tag_{$post_type->name}" ] = array(
					'name' => 'post_tag',
					'title' => __( 'Tags', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => __( 'You should add one or more <a href="#tagsdiv-post_tag" title="Skip to tags">tags</a> to your post to help people find related content.', self::DOM ),
					'validation_cb' => array( __CLASS__, '_has_tag' ),
					'post_type' => $post_type->name,
					'highlight' => '#tagsdiv-post_tag' );
			}

			if ( current_theme_supports( 'post-thumbnails' ) && post_type_supports( $post_type->name, 'thumbnail' ) ) {
				$required_image_size = get_option( "require_image_size_{$post_type->name}", array( 0, 0 ) );

				$image_size_message = implode( __( ' and ', self::DOM ), array_filter( array(
					intval( $required_image_size[ 0 ] ) ? $required_image_size[ 0 ] . 'px ' . __( 'wide', self::DOM ) : '',
					intval( $required_image_size[ 1 ] ) ? $required_image_size[ 1 ] . 'px ' . __( 'tall', self::DOM ) : ''
				) ) );

				$post_type_fields[ "thumbnail_id_{$post_type->name}" ] = array(
					'name' => '_thumbnail_id',
					'title' => __( 'Featured image', self::DOM ),
					'setting_cb' => 'intval',
					'setting_field' => array( __CLASS__, 'checkbox_field' ),
					'message' => __( 'You should set a <a href="#postimagediv" title="Skip to featured image">featured image</a> before you can publish.', self::DOM ),
					'validation_cb' => false,
					'post_type' => $post_type->name,
					'highlight' => '#postimagediv' );
				$post_type_fields[ "image_size_{$post_type->name}" ] = array(
					'name' => 'image_size',
					'title' => __( 'Featured image minimum size', self::DOM ),
					'setting_cb' => array( __CLASS__, '_check_image_size_fields' ),
					'setting_field' => array( __CLASS__, 'image_size_field' ),
					'message' => sprintf( __( 'To keep your site looking perfect your <a href="#postimagediv" title="Skip to featured image">featured image</a> should be at least %s.', self::DOM ), "<strong>$image_size_message</strong>" ),
					'validation_cb' => array( __CLASS__, '_check_image_size' ),
					'post_type' => $post_type->name,
					'highlight' => '#postimagediv' );
			}

			// add post type specific fields
			$fields = array_merge( $fields, $post_type_fields );

		}

		$fields = apply_filters( 'required_fields_settings', $fields );

		foreach( $fields as $name => $field ) {
			$field_name = "require_{$name}";
			$field_value = get_option( $field_name );
			add_settings_field( $field_name , $field[ 'title' ], $field[ 'setting_field' ], 'writing', 'required_fields_' . $field[ 'post_type' ], array(
				'name' => $field_name,
				'value' => $field_value
			) );
			register_setting( 'writing', $field_name, $field[ 'setting_cb' ] );

			// if the setting validation returns true register the field as required
			if ( call_user_func( $field[ 'setting_cb' ], $field_value ) )
				$this->register( $field[ 'name' ], $field[ 'message' ], $field[ 'validation_cb' ], $field[ 'post_type' ], $field[ 'highlight' ] );

		}

	}

	public function section() { ?>
		<p id="required-fields-settings"><?php _e( 'Use the settings below to make the corresponding fields required before content can be published.', self::DOM ); ?></p>
		<?php
	}

	public function checkbox_field( $args ) {
		echo '<input type="checkbox" name="' . $args[ 'name' ] . '" value="1" ' . checked( 1, intval( $args[ 'value' ] ), false ) . ' />';
		if ( isset( $args[ 'description' ] ) )
			echo ' <span class="description">' . $args[ 'description' ] . '</span>';
	}

	public function image_size_field( $args ) {
		if ( ! is_array( $args[ 'value' ] ) )
			$args[ 'value' ] = array( '', '' );
		echo '<input size="4" type="number" name="' . $args[ 'name' ] . '[]" value="' . $args[ 'value' ][ 0 ] . '" /> px wide ' . _( 'by' ) . ' ';
		echo '<input size="4" type="number" name="' . $args[ 'name' ] . '[]" value="' . $args[ 'value' ][ 1 ] . '" /> px high';
		echo '<div class="description">' . __( 'Set to blank or 0 to allow any size', self::DOM ) . '</div>';
	}

	/**
	 * Register a validation callback to determine whether a post can be published or not
	 * The message is displayed if the callback returns false.
	 * The check can be assigned to one or more post types.
	 *
	 * @param string $name          The $_POST array key or post meta key
	 * @param string $message       Error message to display if callback fails
	 * @param callback $validation_cb 	A callback that returns true or false
	 * @param string|array $post_types    The post type or array of post types to register this validation for
	 * @param string $highlight 	A CSS selector for the html element to highlight if the error occurs
	 *
	 * @return void
	 */
	public function register( $name, $message = '', $validation_cb = false, $post_types = 'post', $highlight = '' ) {

		if ( $post_types == 'any' )
			$post_types = get_post_types( array( 'public' => true ) );

		foreach( (array)$post_types as $type ) {
			if ( ! isset( $this->fields[ $type ] ) )
				$this->fields[ $type ] = array();
			$this->fields[ $type ][] = array(
				'name' => $name,
				'cb' => $this->_get_callback( $validation_cb ),
				'message' => empty( $message ) ? sprintf( __( '%s is required before you can publish.', self::DOM ), $this->prettify_name( $name ) ) : $message,
				'highlight' => $highlight
				);
		}

	}


	/**
	 * Unregister a validation callback for certain post types
	 *
	 * @param string $name          The $_POST array key or post meta key
	 * @param callback|bool|string $validation_cb 	The callback to remove. If false removes the default not
	 * 												empty check, if 'all' removes all validations from $name
	 * @param string|array $post_types    The post type or array of post types to register this validation for
	 *
	 * @return void
	 */
	public function unregister( $name, $validation_cb = false, $post_types = 'post' ) {

		if ( $post_types == 'any' )
			$post_types = get_post_types( array( 'public' => true ) );

		foreach( (array)$post_types as $type ) {
			if ( ! isset( $this->fields[ $type ] ) )
				continue;
			foreach( $this->fields[ $type ] as $i => $field ) {
				if ( $validation_cb === 'all' && $field[ 'name' ] === $name ) {
					unset( $this->fields[ $type ][ $i ] );
				} elseif ( array( 'name' => $field[ 'name' ], 'cb' => $field[ 'cb' ] ) == array( 'name' => $name, 'cb' => $this->_get_callback( $validation_cb ) ) ) {
					unset( $this->fields[ $type ][ $i ] );
				}
			}
		}

	}


	/**
	 * Returns the default callback if an invalid callback is passed
	 *
	 * @param callback|array $callback A callback that returns true or false or an array of arrays of callbacks and error messages
	 *
	 * @return callback|array
	 */
	private function _get_callback( $callback ) {
		return is_callable( $callback ) ? $callback : $this->default_validation;
	}


	public function prettify_name( $name ) {
		$name = preg_replace( '/[_\-\[\]]+/', ' ', $name );
		$name = ucwords( trim( $name ) );
		return $name;
	}

	/**
	 * Makes post title and content into a space so built in WP check doesn't kick in
	 *
	 * HT Jan Fabry http://wordpress.stackexchange.com/a/28223/1733
	 *
	 * @param $value string 	Content / Title
	 * @return string
	 */
	public function mask_empty( $value ) {
		if ( empty( $value ) )
			return ' ';
		return $value;
	}

	/**
	 * Main workhorse of the plugin, runs all the validation callback and builds
	 * the error array for display
	 *
	 * @param $data array 	Post data array
	 * @param $postarr 		The $_POST array
	 * @return $data 		The final post data array to be inserted/updated
	 */
	public function force_draft( $data, $postarr ) {

		// reset 'empty' mask
		if ( ' ' === $data['post_title'] )
			$data['post_title'] = '';
		if ( ' ' === $data['post_content'] )
			$data['post_content'] = '';

		$post_id = isset( $postarr[ 'ID' ] ) ? $postarr[ 'ID' ] : false;
		$post_id = isset( $postarr[ 'post_ID' ] ) ? $postarr[ 'post_ID' ] : false;
		if ( ! $post_id )
			return $data;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $data;
		if ( ! current_user_can( 'edit_post', $post_id ) )
			return $data;

		// reset transient key
		$this->transient_key = "save_post_error_{$post_id}_{$this->current_user}";

		// clear errors
		delete_option( $this->transient_key );

		$errors = array();

		// add error messages here
		foreach( $this->fields[ $postarr[ 'post_type' ] ] as $validation ) {
			$value = $this->_find_field( $validation[ 'name' ], $postarr );

			// if we're doing multiple validations
			if( is_callable( $validation[ 'cb' ] ) ) {
				if ( ! call_user_func( $validation[ 'cb' ], $value, $postarr ) )
					$errors[ sanitize_key( $validation[ 'name' ] ) ] = $validation;
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

	public function notice_handler() {
		global $pagenow;

		if( $this->errors && $pagenow == 'post.php' ) {
			echo '<div class="error required-fields-errors">';
			foreach( $this->errors as $code => $validation ) {
				$highlight = $validation[ 'highlight' ] ? ' data-highlight="' . esc_attr( $validation[ 'highlight' ] ) . '"' : '';
				echo '<p class="' . $code . '"' . $highlight . '>' . $validation[ 'message' ] . '</p>';
			}
			echo '</div>';

			// remove errors
			delete_option( $this->transient_key );
		}
	}

	public function error_scripts() {

		$required_fields = array();

		foreach( $this->fields as $post_type => $fields ) {
			$required_fields[ $post_type ] = array();
			foreach( $fields as $field ) {
				$required_fields[ $post_type ][] = array(
					'name' => $field[ 'name' ],
					'message' => $field[ 'message' ],
					'highlight' => $field[ 'highlight' ]
				);
			}
		}

		$required_fields_json = json_encode( $required_fields );

		echo '
			<style>
				#wpbody-content { overflow: visible!important; }
				*:target,
				.required-field-error { outline: #ffebe8 4px solid; }
				.required-fields-errors [data-highlight] { cursor: pointer; }
				.required-fields-errors [data-highlight]:hover,
				.required-fields-errors [data-highlight]:focus { color: #c00; }
				.shake {
					-webkit-animation-duration: .4s;
					-webkit-animation-iteration-count: 1;
					-webkit-animation-timing-function: ease;
					-webkit-animation-fill-mode: both;
					-moz-animation-duration: .4s;
					-moz-animation-iteration-count: 1;
					-moz-animation-timing-function: ease;
					-moz-animation-fill-mode: both;
					-o-animation-duration: .4s;
					-o-animation-iteration-count: 1;
					-o-animation-timing-function: ease;
					-o-animation-fill-mode: both;
					animation-duration: .4s;
					animation-iteration-count: 1;
					animation-timing-function: ease;
					animation-fill-mode: both;
				}

				@-webkit-keyframes shake {
					0% { -webkit-transform: translateX(0px) }
					25% { -webkit-transform: translateX(-10px) }
					50% { -webkit-transform: translateX(10px) }
					75% { -webkit-transform: translateX(-10px) }
					100% { -webkit-transform: translateX(0px) }
				}
				@-moz-keyframes shake {
					0% { -moz-transform: translateX(0px) }
					25% { -moz-transform: translateX(-10px) }
					50% { -moz-transform: translateX(10px) }
					75% { -moz-transform: translateX(-10px) }
					100% { -moz-transform: translateX(0px) }
				}
				@-o-keyframes shake {
					0% { -o-transform: translateX(0px) }
					25% { -o-transform: translateX(-10px) }
					50% { -o-transform: translateX(10px) }
					75% { -o-transform: translateX(-10px) }
					100% { -o-transform: translateX(0px) }
				}
				@keyframes shake {
					0% { transform: translateX(0px) }
					25% { transform: translateX(-10px) }
					50% { transform: translateX(10px) }
					75% { transform: translateX(-10px) }
					100% { transform: translateX(0px) }
				}

				.shake {
					-webkit-animation-name: shake;
					-moz-animation-name: shake;
					-o-animation-name: shake;
					animation-name: shake;
				}

			</style>
			<script>
				(function($){

					var required_fields = ' . $required_fields_json . ',
						required_fields_update_postboxes = false;

					// show hidden required fields
					$.each( required_fields[pagenow], function(i, field) {
						var $field = $(field.highlight),
							postbox_id = $field.hasClass("postbox") ? $field.attr("id") : $field.parents(".postbox").attr("id");
						if ( $field.is(":hidden") ) {
							$("#screen-options-wrap #" + postbox_id + "-hide").trigger("click.postboxes");
							$("#" + postbox_id).show();
							required_fields_update_postboxes = true;
						}
					} );

					// highlight errors
					$(".required-fields-errors [data-highlight]")
						.each(function(){
							$($(this).data("highlight")).addClass("required-field-error");
						})
						.click(function(e){
							e.preventDefault();
							var $field = $($(this).data("highlight"));
							if ( ! $field.length )
								return;
							$field.removeClass( "shake" );
							$("html,body").stop(true,true).animate({scrollTop:($field.offset().top - 40)+"px"}, "normal", function(){
								$field.addClass( "shake" );
							});
						});

					// save post box state
					if ( required_fields_update_postboxes )
						postboxes.save_state( pagenow );

				})(jQuery)
			</script>';

	}

	/**
	 * Finds the field according $name, first checking the $postarr then then post meta
	 * If post meta is found will return the individual value if one found, otherwise an array of values with that key
	 *
	 * @param string $name    The key to search for in the post data / meta data
	 * @param array $postarr The $_POST array
	 *
	 * @return mixed|null
	 */
	private function _find_field( $name, $postarr ) {

		if ( array_key_exists( $name, $postarr ) )
			return $postarr[ $name ];

		$custom_fields = get_post_meta( $postarr[ 'ID' ] );
		if ( array_key_exists( $name, $custom_fields ) ) {
			if ( count( $custom_fields[ $name ] ) > 1 )
				return $custom_fields[ $name ];
			return array_shift( $custom_fields[ $name ] );
		}

		return null;
	}

	// default validation callback
	public function _not_empty( $value, $postarr ) {
		if ( is_string( $value ) )
			$value = trim( $value );
		return ! empty( $value );
	}

	// generic check if true
	public function _is_true( $value, $postarr ) {
		return (bool)$value;
	}

	// 1 is the ID of the 'Uncategorized' category
	public function _has_category( $value, $postarr ) {
		$cats = $postarr[ 'post_category' ];
		$cats = array_filter( $cats, array( __CLASS__, '_has_category_filter' ) );
		return count( $cats );
	}

	public function _has_category_filter( $id ) {
		return intval( $id ) > 1;
	}

	// Check we have at least one tag
	public function _has_tag( $value, $postarr ) {
		$tags = array_filter( explode( ',', $postarr[ 'tax_input' ][ 'post_tag' ] ) );
		return count( $tags );
	}

	// image size field validation
	public function _check_image_size_fields( $value ) {
		if ( ! is_array( $value ) )
			return false;
		return array_map( 'intval', $value );
	}

	// test featured image size
	public function _check_image_size( $value, $postarr ) {

		list( $req_width, $req_height ) = get_option( 'require_image_size_' . $postarr[ 'post_type' ], array( 0, 0 ) );

		// no required size
		if ( ! $req_width && ! $req_height )
			return true;

		// no thumbnail
		if ( ! $thumbnail_id = get_post_thumbnail_id( $postarr[ 'ID' ] ) )
			return true;

		$file = wp_get_attachment_image_src( $thumbnail_id, 'full' );
		if ( $file ) {
			list( $src, $width, $height, $crop ) = $file;
			// original is wider than required
			if ( $width > $req_width && $height > $req_height )
				return true;
		}

		return false;
	}

}

if ( ! function_exists( 'register_required_field' ) ) {

	/**
	 * Registers a field as required for a post to be published.
	 * The default callback checks if the value of the post data or
	 * post meta field corresponding to the $name is empty or not.
	 *
	 * Use on admin_init or any hook after plugins_loaded
	 *
	 * @param string $name          The post data array key or custom field key
	 * @param string $message       The error message to display if validation fails
	 * @param bool|callback $validation_cb 	A callback that returns true if the field value is ok. Takes 2 arguments, $value if found and $postarr - the full $_POST array
	 * 										If false the default not empty check is used
	 * @param string|array $post_type     The post type or array of post types to run the validation on
	 * @param string $highlight 	The CSS selector of the html element to highlight when this error occurs
	 *
	 * @return void
	 */
	function register_required_field( $name, $message = '', $validation_cb = false, $post_type = 'post', $highlight = '' ) {
		$rf = required_fields::instance();
		$rf->register( $name, $message, $validation_cb, $post_type, $highlight );
	}

}

if ( ! function_exists( 'unregister_required_field' ) ) {

	/**
	 * Unregisters a field validation by name and callback and optionally for specific post type(s)
	 * Should be called on admin_init
	 *
	 * @param string $name          The post data array key or custom field key
	 * @param bool|callback|string $validation_cb 	The callback to remove. If false the default not empty check
	 * 												is removed. If 'all' then all validations are removed for $name
	 * @param string|array $post_type     The post type or array of post types to run the validation on
	 *
	 * @return void
	 */
	function unregister_required_field( $name, $validation_cb = false, $post_type = 'post' ) {
		$rf = required_fields::instance();
		$rf->unregister( $name, $validation_cb, $post_type );
	}

}

}

/**
 * Example code for extending the required fields settings page
 * /

add_filter( 'required_fields_settings', 'my_required_fields_settings' );

function my_required_fields_settings( $fields ) {

	$fields[ 'my_page_setting' ] = array(
		'name' => 'page_template',
		'title' => __( 'Template' ),
		'setting_cb' => 'intval',
		'setting_field' => array( 'required_fields', 'checkbox_field' ),
		'message' => __( 'You should select a <a href="#page_template" title="Skip to page template">page template</a> before publishing.' ),
		'validation_cb' => 'has_page_template',
		'post_type' => 'page',
		'highlight' => '#page_template'
	);

	return $fields;
}

function has_page_template( $value, $postarr ) {
	return ! empty( $value ) && $value !== 'default';
}

*/

?>
