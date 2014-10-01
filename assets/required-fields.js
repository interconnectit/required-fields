/**
 * Required Post Fields behaviour
 * @author Robert O'Rourke
 */
(function($){

	var required_fields = required_fields_l10n.fields,
		required_fields_update_postboxes = false;

	// show hidden required fields
	if ( typeof required_fields !== 'undefined' && required_fields.length ) {
		$.each( required_fields[ pagenow ], function( i, field ) {
			var $field = $(field.highlight),
				postbox_id = $field.hasClass( "postbox" ) ? $field.attr( "id" ) : $field.parents( ".postbox" ).attr( "id" );
			if ( $field.is( ":hidden" ) ) {
				$( "#screen-options-wrap #" + postbox_id + "-hide" ).trigger( "click.postboxes" );
				$( "#" + postbox_id ).show();
				required_fields_update_postboxes = true;
			}
		} );
	}

	// highlight errors
	$( ".required-fields-errors [data-highlight]" )
		.each( function(){
			$( $( this ).data( "highlight" ) ).addClass( "required-field-" + ( $( this ).parent().hasClass( 'required-fields-warnings' ) ? "warning" : "error" ) );
		} )
		.click( function( e ){
			e.preventDefault();
			var $field = $( $( this ).data( "highlight" ) );
			if ( ! $field.length )
				return;
			$field.removeClass( "shake" );
			$( "html,body" ).stop( true, true ).animate( { scrollTop: ( $field.offset().top - 40 ) + "px"}, "normal", function(){
				$field.addClass( "shake" );
			} );
		} );

	// save post box state
	if ( required_fields_update_postboxes )
		postboxes.save_state( pagenow );

})(jQuery)
