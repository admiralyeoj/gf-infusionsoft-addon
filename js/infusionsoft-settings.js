/* global gform_infusionsoft_pluginsettings_strings, ajaxurl, jQuery */

/**
 * infusionsoft Settings Script.
 */
window.GFinfusionsoftSettings = null;

( function( $ ) {
  var GFinfusionsoftSettings = function() {
    var self = this;

    this.init = function() {
      this.pageURL = gform_infusionsoft_pluginsettings_strings.settings_url;

      this.bindDeauthorize();
    };

    this.bindDeauthorize = function() {
      // De-Authorize infusionsoft .
      $( '.deauth_button' ).on(
        'click',
        function( e ) {
          e.preventDefault();

          // Get button.

          var deauthButton = $( '#gform_infusionsoft_deauth_button' ),
            disconnectMessage = gform_infusionsoft_pluginsettings_strings.disconnect;

          // Confirm deletion.
          if ( ! window.confirm( disconnectMessage ) ) {
            return false;
          }

          // Set disabled state.
          deauthButton.attr( 'disabled', 'disabled' );

          // De-Authorize.
          $.ajax(
            {
              async: false,
              url: ajaxurl,
              dataType: 'json',
              method: 'POST',
              data: {
                action: 'gf_infusionsoft_deauthorize',
                nonce: gform_infusionsoft_pluginsettings_strings.deauth_nonce,
              },
              success: function( response ) {
                if ( response.success ) {
                  window.location.href = self.pageURL;
                } else {
                  window.alert( response.data.message );
                }

                deauthButton.removeAttr( 'disabled' );
              },
            }
          ).fail(
            function( jqXHR, textStatus, error ) {
              window.alert( error );
              deauthButton.removeAttr( 'disabled' );
            }
          );
        }
        
      );
    };

    $( '.custom_button' ).on(
      'click',
      function( e ) {
        e.preventDefault();

        var update_btn = $( this );
        update_btn.prop( 'disabled', true );

        update_btn.append('<i class="fa fa-spinner fa-pulse" style="margin-left: 5px;"></i>');

        $.ajax(
          {
            url: ajaxurl,
            dataType: 'json',
            method: 'POST',
            data: {
              action: 'gf_infusionsoft_update_cf',
              nonce: gform_infusionsoft_pluginsettings_strings.update_cf_nonce,
            },
            success: function( response ) {
              window.alert( response.data.message );
              update_btn.find('.fa-spinner').remove();
              update_btn.prop( 'disabled', false );
            },
          }
        ).fail(
          function( jqXHR, textStatus, error ) {
            window.alert( error );
            update_btn.prop( 'disabled', false );
          }
        );
      }
    );

    $( '.tags_button' ).on(
      'click',
      function( e ) {
        e.preventDefault();

        var update_btn = $( this );
        update_btn.prop( 'disabled', true );

        update_btn.append('<i class="fa fa-spinner fa-pulse" style="margin-left: 5px;"></i>');

        $.ajax(
          {
            url: ajaxurl,
            dataType: 'json',
            method: 'POST',
            data: {
              action: 'gf_infusionsoft_update_tags',
              nonce: gform_infusionsoft_pluginsettings_strings.update_tags_nonce,
            },
            success: function( response ) {
              window.alert( response.data.message );
              update_btn.find('.fa-spinner').remove();
              update_btn.prop( 'disabled', false );
            },
          }
        ).fail(
          function( jqXHR, textStatus, error ) {
            window.alert( error );
            update_btn.prop( 'disabled', false );
          }
        );
      }
    );

    this.init();
  };

  $( document ).ready( GFinfusionsoftSettings );
}( jQuery ) );
