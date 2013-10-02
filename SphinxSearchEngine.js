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

    $("#select_all").click( function( e ) {
        if ( $(this).prop( 'checked' ) ) {
            $("#scl input").prop("checked", true);
        } else {
            $("#scl input").prop("checked", false);
        }
    });
} );

