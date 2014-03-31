Required Fields
===============

Adds an extensible API and some basic settings to WordPress to make standard fields on the post edit screen required before a post can be published.

## Usage

Head to the **Writing** settings page and scroll to the bottom. There you will find boxes to check to make certain fields required when a user adds or edits a post or page.

The default options are:

* require title
* require content
* require custom excerpt
* require non default category
* require tags
* require featured image
* minimum size for featured images

![alt text](http://interconnectit.com/cc-assets/required-fields/screenshot-1.jpg "Admin screenshot")

When you add or edit a post and it doesn't meet the requirements the relevant fields will be highlighted.

![alt text](http://interconnectit.com/cc-assets/required-fields/screenshot-2.jpg "Post edit screenshot")

### NEW

As of version 1.6.0 you can choose to make the required fields into warnings. The validation message will serve to nag the users however they won't prevent a post from being published.

## API

There is an API to add/remove your own required fields too:

**`register_required_field( name, message [, callback [, post types [, highlight [, soft ] ] ] ] )`**

```php
/**
 * Registers a field as required for a post to be published.
 * The default callback checks if the value of the post data or
 * post meta field corresponding to the $name is empty or not.
 *
 * Use on admin_init or any hook after plugins_loaded
 *
 * @param string 			$name          	The post data array key or custom field key eg: 'post_title', 'my_meta_key'
 * @param string 			$message       	The error message to display if validation fails
 * @param bool|callback 	$validation_cb 	A callback that returns true if the field value is ok. If false defaults to a
 * 											not empty test. Takes 2 args, $value and $postarr
 * @param string|array 		$post_type     	The post type or post types to run the validation on
 * @param string 			$highlight 		CSS selector to highlight on validation fail eg. '#titlediv'
 * @param bool 				$soft 			If true displays the validation as a warning. Failure does not block publishing
 * @return void
 */

register_required_field( $name, $message, $validation_cb, $post_types, $highlight, $soft );
```

The entire `$_POST` array is available as the second argument to your validation functions so you have
access to everything you would ever need.

A custom callback example:

```php
register_required_field(
	'twitter',
	'Please enter a twitter username for the site before publishing',
	function( $value, $postarr ) {

		$site_twitter = get_option( 'site_twitter' );
		if ( $site_twitter )
			return true;

		// do not publish if there's no twitter account set
		return false;
	},
	array( 'post', 'custom_post_type' )
);
```

The `$postarr` argument array gives you access to the taxonomy data being posted as well as many other
useful values. Use the network panel in your browser console to examine what `$_POST` data is available.

**`unregister_required_field( name, message [, post types ] )`**

```php
/**
 * Unregisters a field validation. Should be used on admin_init
 *
 * @param string 				$name          	The post data array key or custom field key eg: 'post_title', 'my_meta_key'
 * @param bool|callback|string 	$validation_cb 	The callback to remove. If false removes the default not empty check. If 'all' removes all validations for $name
 * @param string|array 			$post_type     	The post type or post types to remove the validation from
 * @return void
 */

unregister_required_field( $name, $validation_cb, $post_types );
```

### Multiple validations

You can call `register_required_field()` against the same `$name` as many times as you want. They are non
destructive so will have a cumulative effect. You can unregister existing validations too.

```php
register_required_field( 'post_title', 'Title must be filled in' );
register_required_field( 'post_title', 'Title must contain a number', 'check_for_number' );
register_required_field( 'post_title', 'Title must be at least 2 words', 'check_word_count' );
```

### Adding options to settings screen

There are plenty of hooks available to modify the behaviour of this plugin. One such hook allows you to
extend the writing settings available to administrators in the settings.

The following is an example of adding an option to require a page template to be set for pages.

```php
add_filter( 'required_fields_settings', 'my_required_fields_settings' );

function my_required_fields_settings( $fields ) {

	$fields[ 'my_page_setting' ] = array(
		'title' => __( 'Template' ), 										// The setting field label
		'setting_cb' => 'intval', 											// The setting field validation (see register_setting())
		'setting_field' => array( 'required_fields', 'checkbox_field' ), 	// A built in checkbox field
		'setting_description' => '',
		'name' => 'page_template',											// The $_POST field to check
		'message' => __( 'You should select a page template before publishing.' ),
		'validation_cb' => 'has_page_template', 							// custom validation callback
		'post_type' => 'page'												// post type for validation
		'highlight' => '#page_template' 									// CSS selector to highlight on fail
	);

	return $fields;
}

// check $value is not empty and not set to 'default'
function has_page_template( $value, $postarr ) {
	return ! empty( $value ) && $value !== 'default';
}
```

### Highlighting fields with an error

In the above example you should note that the `highlight` option contains a CSS selector targeting the error
field.

If you do this the plugin will highlight the field or section that matches the selectors. Clicking on the
error message will scroll the page to the field that has an error and shake it to make it clear what needs
to be actioned.

### Limiting the length of titles and content

You can limit the minimum and maximum number of characters in the titles and content of your posts and pages by copying
the following code into the functions.php file of your theme, and changing the numbers and operators to match your needs.

```php
if ( function_exists( 'register_required_field' ) ) {

function check_title_length( $value, $postarr ) {
   return strlen( $value ) >= 20;
}

function check_content_length( $value, $postarr ) {
   return strlen( strip_tags( $value ) ) >= 200;
}

register_required_field( 'post_title', 'The title must be at least 20 characters long', 'check_title_length', array( 'post' ), '#titlediv' );
register_required_field( 'post_content', 'Your content must be at least 200 characters long', 'check_content_length', array( 'post' ), '#postdivrich' );
}
```

### Make connections required with Posts 2 Posts plugin

You can make connections required using the plugin API, you can add the following code to your themes functions.php where
you register your posts type connections

```php
register_required_field(
    'p2p_connections',
    'Connections are required before publishing', 
    false, 
    array( 'post' ), 
    '#p2p-from-posts_to_pages' 
);
```

## Thanks!

Any questions or problem give me a shout on Twitter [@sanchothefat](https://twitter.com/sanchothefat)

## License

GPL v3
