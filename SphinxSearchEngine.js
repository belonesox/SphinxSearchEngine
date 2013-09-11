jQuery( function( $ ) {
    $("#scl_button").click( function( e ) {
        e.preventDefault( );
        if ( $("#scl").hasClass( 'hidden' ) ) {
            $(this).html("[-]");
            $("#scl").removeClass( 'hidden' );
        } else {
            $(this).html("[+]");
            $("#scl").addClass( 'hidden' );
        }
    });

    $("#scl_submit").click( function( ) {
        var url_this = window.location.toString().split("&category[]" );
        var url = url_this[0];
        $("#scl input:checked").each( function( ) {
            url += '&category[]=' + encodeURIComponent( $(this).val() );
        } );
        window.location.href = url;
    } );

    $('.mw-search-pager-bottom a, .search-types a, .mw-search-formheader a').click(function( e ) {
        e.preventDefault( );
        var href = this.href;
        $("#scl input:checked").each( function( ) {
            href += '&category[]=' + encodeURIComponent( $(this).val() );
        } );
        window.location.href = href;
    } );

    $('#search_sort_button').click( function( e ) {
        e.preventDefault( );
        var url_this = window.location.toString().split("&orderBy=" );
        var url = url_this[0];
        $(".mw-search-sort select").each( function( ) {
            url += '&' + $(this).prop('id') + '=' + encodeURIComponent( $(this).val() );
        } );
        window.location.href = url;
    } );
} );
