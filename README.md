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
* require featured image
* minimum size for featured images

![alt text](https://raw.github.com/interconnectit/required-fields/master/screenshot.png "Admin screenshot")

## API

There is an API to add your own required fields too:

```php
/**
 * Registers a field as required for a post to be published.
 * The default callback checks if the value of the post data or
 * post meta field corresponding to the $name is empty or not.
 *
 * @param string 			$name          	The post data array key or custom field key eg: 'post_title', 'my_meta_key'
 * @param string 			$message       	The error message to display if validation fails
 * @param callback|array 	$validation_cb 	A callback that returns true if the field value is ok or an array of error message and callbacks
 * 											Array format: array( array( 'message' => 'Error message', 'cb' => 'callable_function' ), ... )
 * @param string|array 		$post_type     	The post type or post types to run the validation on
 *
 * @return void
 */

register_required_field( $name, $message, $validation_cb, $post_types );
```

If you use a custom `$name` that isn't found in the post data or meta data and you have a custom callback as well
then the entire `$_POST` array is returned to your validation function so you have access to everything you would need.

For example:

```php

register_required_field(
	'twitter',
	'Please enter a twitter username for the post or a default for the site before publishing',
	function( $postarr ) {
		$post_twitter = get_post_meta( $postarr[ 'ID' ], 'twitter', true );
		if ( $post_twitter )
			return true;
		$site_twitter = get_option( 'site_twitter' );
		if ( $site_twitter )
			return true;
		
		// do not publish if there's no twitter account set anywhere
		return false;
	},
	array( 'post', 'custom_post_type' )
);

```

The `$_POST` array gives you access to the taxonomy data being posted as well as many useful values. Use the network panel in your browser console
to examine what is available.

## Thanks!

Any questions or problem give me a shout on Twitter [@sanchothefat](http://twitter.com/sanchothefat)
