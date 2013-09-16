jQuery( function( $ ) {
    $("#scl_button").click( function( e ) {
        if ( $("#scl").hasClass( 'hidden' ) ) {
            $(this).html("[-]");
            $("#scl").removeClass( 'hidden' );
        } else {
            $(this).html("[+]");
            $("#scl").addClass( 'hidden' );
        }
    });
} );
