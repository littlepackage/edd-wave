jQuery( document ).ready( function($) {

	$( document ).on( "change", ".edd-wave-select", function() {		
		$( this ).closest( 'tr' ).find( 'td:first input' ).val( $( this ).val() );
	}).trigger( "change" );

	// mostly functions on page load, sets selected attribute on <option>
	$( ".edd-wave-select" ).each( function() {
		let value = $( this ).data( "selected" );
		$( this ).find( "option[value='" + value + "']" ).attr( 'selected', 'selected' );

	});

});