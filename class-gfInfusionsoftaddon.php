<?php
defined( 'ABSPATH' ) || die();

// Includes the feeds portion of the add-on framework
GFForms::include_feed_addon_framework();

class GFInfusionsoftAddOn extends GFFeedAddOn {

  protected $_version = GF_INFUSIONSOFT_ADDON_VERSION;
  protected $_min_gravityforms_version = '1.9.16';
  protected $_slug = 'infusionsoft-addon';
  protected $_path = 'gravityforms-infusionsoft-addon/gravityforms-infusionsoft-addon.php';
  protected $_full_path = __FILE__;
  protected $_title = 'Gravity Forms Infusionsoft Add-On';
  protected $_short_title = 'Infusionsoft';

  protected $clientId = 'C93g9h2rl4iWNs7xgBpZVzRD30EqJS71';
  protected $clientSecret = '0S5bq3zpdMwR0iHw';

  protected $_supports_feed_ordering = true;

  private static $_instance = null;

  protected $api = null;

  /**
   * Get an instance of this class.
   *
   * @return GFInfusionsoftAddOn
   */
  public static function get_instance() {
    if ( self::$_instance == null ) {
      self::$_instance = new GFInfusionsoftAddOn();
    }

    return self::$_instance;
  }

  /**
   * Plugin starting point. Handles hooks, loading of language files and PayPal delayed payment support.
   */
  public function init() {
    parent::init();

    if ( ! class_exists( '\Infusionsoft\Infusionsoft' ) ) {
      require_once 'includes/autoload.php';
    }

    $this->api = new \Infusionsoft\Infusionsoft(array(
      'clientId' => $this->clientId,
      'clientSecret' => $this->clientSecret,
      'redirectUri' => admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ),
    ));

    $auth_data = $this->get_auth_data();
    $this->api->setToken($auth_data);

    // If we are going to use stored auth tokens, maybe they should be refreshed.
    if (! is_array( $auth_data ) || time() > $auth_data->endOfLife  ) {
      $this->maybe_renew_token( );
    }
  }

  public function init_admin() {
    parent::init_admin();
    add_action( 'admin_init', array( $this, 'maybe_update_auth_tokens' ) );
  }

  public function init_ajax() {
    parent::init_ajax();
    add_action( 'wp_ajax_gf_infusionsoft_deauthorize', array( $this, 'ajax_deauthorize' ) );
    add_action( 'wp_ajax_gf_infusionsoft_update_cf', array( $this, 'ajax_update_custom_fields' ) );
    add_action( 'wp_ajax_gf_infusionsoft_update_tags', array( $this, 'ajax_update_tags' ) );
  }

  public function ajax_deauthorize() {
    check_ajax_referer( 'gf_infusionsoft_deauth', 'nonce' );
    // If user is not authorized, exit.
    if ( ! GFCommon::current_user_can_any( $this->_capabilities_settings_page ) ) {
      wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'infusionsoftaddon' ) ) );
    }

    // If API instance is not initialized, return error.
    if ( ! $this->api ) {
      $this->log_error( __METHOD__ . '(): Unable to de-authorize because API is not initialized.' );

      wp_send_json_error();
    }

    // Remove access token from settings.
    $settings     = $this->get_plugin_settings();
    $auth_setting = $settings['auth_data'];
    // If the authentication token is not set, nothing to do.
    if ( rgblank( $auth_setting ) ) {
      wp_send_json_success();
    }
    if ( ! empty( $auth_setting ) ) {
      unset( $auth_setting );
    }
    $settings['auth_data'] = $auth_setting;

    $this->update_plugin_settings( $settings );

    // Return success response.
    wp_send_json_success();
  }

  function ajax_update_custom_fields() {
    
    $return = $this->update_custom_fields();

    wp_send_json_success( array('message' => __( "Custom fields successfully updated"), 'custom_fields' => $values, 'is_updated' => $update ) );
  }

  function update_custom_fields() {
    if(!$this->api->getToken())
      wp_send_json_error();

    $return = $this->api->customfields()->get()->toArray();
    $values = array();
    if(!empty($return)) {
      foreach ($return as $row) {
        $values[] = $row->toArray();
      }
    }
    $update = update_option('infusionsoft_custom_fields', $values);

    return $return;
  }

  function ajax_update_tags(  ) {
    $return = $this->update_tags();

    wp_send_json_success(array('message' => __( "Tags successfully updated", 'infusionsoftaddon' ), 'tags' => $return));
  }

  function update_tags() {
    if(!$this->api->getToken())
      wp_send_json_error();

    $category_ids = array(26, 32, 34, 52, 40, 54, 66, 96, 98, '');
    $categoryList = array();
    $return = array();

    foreach ($category_ids as $id) {
      $categoryList[] = $this->api->tags()->where('category', $id)->get();
    }

    if(!empty($categoryList)) {
      foreach ($categoryList as $cat) {
        $catArray = $cat->toArray();
        foreach ($catArray as $tag) {
          if(empty($return[$tag->category['id']])) {
            $return[$tag->category['id']]['category'] = $tag->category;
          }
          $return[$tag->category['id']][] = $tag->toArray();
        }
      }
    }

    $update = update_option('infusionsoft_tags', $return);

    return $return;
  }

  // # FEED PROCESSING -----------------------------------------------------------------------------------------------

  /**
   * Process the feed e.g. subscribe the user to a list.
   *
   * @param array $feed The feed object to be processed.
   * @param array $entry The entry object currently being processed.
   * @param array $form The form object currently being processed.
   *
   * @return bool|void
   */
  public function process_feed( $feed, $entry, $form ) {
    if($feed['addon_slug'] != $this->_slug)
      return;

    switch ($feed['meta']['feedType']) {
      case 'contact':
        $this->create_contact($feed, $entry, $form);
        break;

      case 'apply_tags':
        $this->apply_tags($feed, $entry, $form);
        break;
    }

    $feedName  = $feed['meta']['feedName'];

    // Loop through the fields from the field map setting building an array of values to be passed to the third-party service.
    $merge_vars = array();
    foreach ( $field_map as $name => $field_id ) {

      // Get the field value for the specified field id
      $merge_vars[ $name ] = $this->get_field_value( $form, $entry, $field_id );

    }

    // Send the values to the third-party service.
  }

  public function create_contact( $feed, $entry, $form ) {
    if(!$this->api->getToken())
      return false;

    $standard_fields = $this->get_field_map_fields( $feed, 'contactStandardFields' );
    $email_fields = $this->get_field_map_fields( $feed, 'contactEmailFields' );
    $phone_fields = $this->get_field_map_fields( $feed, 'contactPhoneFields' );

    $billing = $this->get_field_map_fields( $feed, 'contactBillingAddressFields' );
    $shipping = $this->get_field_map_fields( $feed, 'contactShippingAddressFields' );
    $other = $this->get_field_map_fields( $feed, 'contactOtherAddressFields' );

    $meta_fields = $this->get_dynamic_field_map_fields( $feed, 'customFields' );
       

    $state = $country.'-'.rgar( $entry, $standard_fields['address_state'] ); 
    $email_info = $phone_info = $address_info = array();

    /* Email */
    foreach ($email_fields as $key => $value) {
      if(empty($value))
        continue;

      $emailObj = new \stdClass;
      $emailObj->field = $key;
      $emailObj->email = rgar( $entry, $value );
      $email_info[] = $emailObj;
    }

    foreach ($phone_fields as $key => $value) {
      if(empty($value))
        continue;

      $phoneObj = new \stdClass;
      $phoneObj->field = $key;
      $phoneObj->type = 'Work';
      $phoneObj->number = rgar( $entry, $value );;

      $phone_info[] = $phoneObj;
    }
    
    

    /* Billing Address */
    $country = rgar( $entry, $billing['country'] );
    $state = $country.'-'.rgar( $entry, $billing['state'] );
    $country_code = ($country == 'CA') ? 'CAN' : 'USA';

    $addressObj = new \stdClass;
    $addressObj->field = 'BILLING';
    $addressObj->line1 = rgar( $entry, $billing['line1'] ) ?: '';
    $addressObj->line2 = rgar( $entry, $billing['line2'] ) ?: '';
    $addressObj->locality = rgar( $entry, $billing['city'] ) ?: '';
    $addressObj->region = $state;

    if($country == 'CA')
      $addressObj->postal_code = rgar( $entry, $billing['zip'] ) ?: '';
    else
      $addressObj->zip_code = rgar( $entry, $billing['zip'] ) ?: '';

    $addressObj->country_code = $country_code; 

    $address_info[] = $addressObj;


    /* Shipping Address */
    if(!empty($shipping['city']) || !empty($shipping['state'])) {
      $country = rgar( $entry, $shipping['country'] );
      $state = $country.'-'.rgar( $entry, $shipping['state'] );
      $country_code = ($country == 'CA') ? 'CAN' : 'USA';

      $addressObj = new \stdClass;
      $addressObj->field = 'SHIPPING';
      $addressObj->line1 = rgar( $entry, $shipping['line1'] ) ?: '';
      $addressObj->line2 = rgar( $entry, $shipping['line2'] ) ?: '';
      $addressObj->locality = rgar( $entry, $shipping['city'] ) ?: '';
      $addressObj->region = $state;
      
      if($country == 'CA')
        $addressObj->postal_code = rgar( $entry, $shipping['zip'] ) ?: '';
      else
        $addressObj->zip_code = rgar( $entry, $shipping['zip'] ) ?: '';

      $addressObj->country_code = $country_code; 

      $address_info[] = $addressObj;
    }

    /* Other Address */
    if(!empty($other['city']) || !empty($other['state'])) {
      $country = rgar( $entry, $other['country'] );
      $state = $country.'-'.rgar( $entry, $other['state'] );
      $country_code = ($country == 'CA') ? 'CAN' : 'USA';

      $addressObj = new \stdClass;
      $addressObj->field = 'OTHER';
      $addressObj->line1 = rgar( $entry, $other['line1'] ) ?: '';
      $addressObj->line2 = rgar( $entry, $other['line2'] ) ?: '';
      $addressObj->locality = rgar( $entry, $other['city'] ) ?: '';
      $addressObj->region = $state;
      
      if($country == 'CA')
        $addressObj->postal_code = rgar( $entry, $other['zip'] ) ?: '';
      else
        $addressObj->zip_code = rgar( $entry, $other['zip'] ) ?: '';

      $addressObj->country_code = $country_code; 

      $address_info[] = $addressObj;
    }
    

    $data = array(
      'given_name' => rgar( $entry, $standard_fields['first_name'] ),
      'family_name' => rgar( $entry, $standard_fields['last_name'] ),
      'email_addresses' => $email_info,
      'phone_numbers' => $phone_info,
      'addresses' => $address_info,
      'custom_fields' => array(),
      'opt_in_reason' => 'Customer opted-in through webform',
    );

    if($birthday = rgar( $entry, $standard_fields['birthday'] )) {
      $birthday_time = strtotime($birthday);
      $data['birthday'] = gmDate("Y-m-d\TH:i:s\Z", $birthday_time);
    }

    if(!empty($meta_fields)) {
      foreach ( $meta_fields as $name => $field_id ) {
        $object = new \stdClass;
        $object->content = $this->get_field_value( $form, $entry, $field_id );
        $object->id = $name;
        $data['custom_fields'][] = $object;
      }
    }

    

    if($feed['meta']['prevDup']) {
      $contact_list = $this->api->contacts()->where('email', $email)->where('given_name', $data['given_name'])->where('family_name', $data['family_name'])->get();
      $contact_listArray = $contact_list->toArray();
      $contact = reset($contact_listArray);
    }

    if(!empty($contact)) {
      try {
        $update = $this->api->contacts()->mock($data);
        $update->id = $contact->id;

        $update->save();
      } catch (\Infusionsoft\InfusionsoftException $e) {

        GFAPI::update_entry_field($entry['id'], $feed['meta']['contact_id'], 'Error');
        $this->log_error( __METHOD__ . '() : Error updating contact: '.$e->getMessage() );
        return;
      }
    } else {

      try {        
        $contact = $this->api->contacts()->create($data);
      } catch (\Infusionsoft\InfusionsoftException $e) {
        GFAPI::update_entry_field($entry['id'], $feed['meta']['contact_id'], 'Error');
        $this->log_error( __METHOD__ . '() : Error creating contact: '.$e->getMessage() );

        return;
      }
    }

    GFAPI::update_entry_field($entry['id'], $feed['meta']['contact_id'], $contact->id);

    $tags = array();
    foreach ($feed['meta']['selected_tags'] as $value) {
      if(!empty($value))
        $tags[] = $value;
    }

    try {
      $return = $contact->addTags($tags);
    } catch (\Infusionsoft\InfusionsoftException $e) {
      $this->log_error( __METHOD__ . '() : Error adding tags on contact: '.$e->getMessage() );
      return;
    }

  }

  public function apply_tags( $feed, $entry, $form ) {
    if(!$this->api->getToken())
      return false;

    $entry = GFAPI::get_entry( $entry['id'] );
    $contact_id = rgar( $entry, $feed['meta']['contact_id'] );

    if(empty($contact_id))
      return;

    $tags = array();
    foreach ($feed['meta']['selected_tags'] as $value) {
      if(!empty($value))
        $tags[] = $value;
    }

    try {
      $contact = $this->api->contacts()->mock(array('id' => $contact_id))->addTags($tags);
    } catch (\Infusionsoft\InfusionsoftException $e) {
      $this->log_error( __METHOD__ . '() : Error adding tags on contact: '.$e->getMessage() );
      return;
    }
  }

  /**
   * Custom format the phone type field values before they are returned by $this->get_field_value().
   *
   * @param array $entry The Entry currently being processed.
   * @param string $field_id The ID of the Field currently being processed.
   * @param GF_Field_Phone $field The Field currently being processed.
   *
   * @return string
   */
  public function get_phone_field_value( $entry, $field_id, $field ) {

    // Get the field value from the Entry Object.
    $field_value = rgar( $entry, $field_id );

    // If there is a value and the field phoneFormat setting is set to standard reformat the value.
    if ( ! empty( $field_value ) && $field->phoneFormat == 'standard' && preg_match( '/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $field_value, $matches ) ) {
      $field_value = sprintf( '%s-%s-%s', $matches[1], $matches[2], $matches[3] );
    }

    return $field_value;
  }

  // # SCRIPTS & STYLES -----------------------------------------------------------------------------------------------

  /**
   * Return the scripts which should be enqueued.
   *
   * @return array
   */
  public function scripts() {
    $scripts = array(
      // Plugin settings script ( admin pages ).
      array(
        'handle'  => 'gform_infusionsoft_pluginsettings',
        'deps'    => array( 'jquery' ),
        'src'     => $this->get_base_url() . "/js/infusionsoft-settings.js",
        'version' => $this->_version,
        'enqueue' => array(
          array(
            'admin_page' => array( 'plugin_settings', 'entry_view' ),
            'tab'        => $this->_slug,
          ),
        ),
        'strings' => array(
          'settings_url' => admin_url( 'admin.php?page=gf_settings&subview=' . $this->get_slug() ),
          'deauth_nonce' => wp_create_nonce( 'gf_infusionsoft_deauth' ),
          'update_cf_nonce' => wp_create_nonce( 'gf_infusionsoft_update_cf' ),
          'update_tags_nonce' => wp_create_nonce( 'gf_infusionsoft_update_tags' ),
          'ajaxurl'      => admin_url( 'admin-ajax.php' ),
          'disconnect'   => wp_strip_all_tags( __( 'Are you sure you want to disconnect from infusionsoft for this website?', 'infusionsoftaddon' ) ),
        ),
      ),
    );

    return array_merge( parent::scripts(), $scripts );
  }

  /**
   * Return the stylesheets which should be enqueued.
   *
   * @return array
   */
  public function styles() {

    $styles = array(
      array(
        'handle'  => 'my_styles_css',
        'src'     => $this->get_base_url() . '/css/my_styles.css',
        'version' => $this->_version,
        'enqueue' => array(
          array( 'field_types' => array( 'poll' ) ),
        ),
      ),
    );

    return array_merge( parent::styles(), $styles );
  }

  // # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------

  /**
   * Configures the settings which should be rendered on the add-on settings tab.
   *
   * @return array
   */
  public function plugin_settings_fields() {
    $fields = array(
      array(
        'title'  => esc_html__( 'Account Settings', 'infusionsoftaddon' ),
        'fields' => array(
          array(
            'name' => 'reauth_version',
            'type' => 'hidden',
          ),
          array(
            'name' => 'auth_button',
            'type' => 'auth_button',
          ),
          array(
            'name' => 'update_custom_fields',
            'type' => 'update_custom_fields',
          ),
          array(
            'name' => 'update_tags_fields',
            'type' => 'update_tags_fields',
          ),
          array(
            'name' => 'auth_data',
            'type' => 'hidden',
          ),
        ),
      ),
    );

    return $fields;
  }

  /**
   * Configures the settings which should be rendered on the feed edit page in the Form Settings > Simple Feed Add-On area.
   *
   * @return array
   */
  public function feed_settings_fields() {
    $fields = array();

    $fields['feed_settings'] = array(
      'title'       => esc_html__( 'Feed Settings', 'infusionsoftaddon' ),
      'description' => '',
      'fields'      => array(
        array(
          'name'     => 'feedName',
          'label'    => esc_html__( 'Name', 'infusionsoftaddon' ),
          'type'     => 'text',
          'required' => true,
          'class'    => 'medium',
          'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Name', 'infusionsoftaddon' ), esc_html__( 'Enter a feed name to uniquely identify this setup.', 'infusionsoftaddon' ) )
        ),
        array(
          'name'     => 'feedType',
          'label'    => esc_html__( 'Action', 'infusionsoftaddon' ),
          'type'     => 'radio',
          'required' => true,
          'tooltip'  => sprintf(
            '<h6>%s</h6> <p>%s</p><p>%s</p><p>%s</p><p>%s</p>',
            esc_html__( 'Action', 'infusionsoftaddon' ),
            esc_html__( 'Select the type of feed you would like to create.', 'infusionsoftaddon'), 
            esc_html__( '"Create" feeds will create a new contact.', 'infusionsoftaddon' ), 
            esc_html__( '"Apply Tags" will only run after a create or update tag has occured and the lead has a contact ID from Infusionsoft.', 'infusionsoftaddon' ),
          ),
          'choices'  => $this->get_available_feed_actions(),
          'onchange' => 'jQuery( this ).parents( "form" ).submit();',
        ),

        array(
          'name'     => 'prevDup',
          'label'    => esc_html__( 'Prevent Duplicate Entries', 'infusionsoftaddon' ),
          'type'     => 'checkbox',
          'choices' => array(
            array(
              'label' => 'Enabled',
              'name'  => 'prevDup'
            )
          ),
          'tooltip'  => sprintf(
            '<h6>%s</h6> <p>%s</p>',
            esc_html__( 'Prevent Duplicate Entries', 'infusionsoftaddon' ),
            esc_html__( 'If email exists, it will update the lead. If lead does not exist, it will create the lead.', 'infusionsoftaddon'), 
          ),
          'dependency'  => array(
            'field'   => 'feedType',
            'values'  => array('contact'),
          ),
        ),
        array(
          'name'     => 'contact_id',
          'label'    => esc_html__( 'Contact ID Field', 'infusionsoftaddon' ),
          'type'     => 'field_select',
          'args'     => array(
          ),
          'class'    => 'medium',
          'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Contact ID', 'infusionsoftaddon' ), esc_html__( 'This field populates the contact ID from infusionsoft when updating or creating a contact. This is nessary for adding tags.', 'infusionsoftaddon' ) ),
        ),
        /*array(
          'type'    => 'checkbox',
          'name'    => 'enable_custom_fields',
          'label'   => 'Custom Fields',
          'onclick' => 'jQuery( this ).parents( "form" ).submit();',
          'choices' => array(
            array(
                'label' => 'Enable Custom Fields',
                'name'  => 'enable_custom_fields',
                'value' => 1,
            ),
          ),
          'dependency'  => array(
            'field'   => 'feedType',
            'values'  => array('contact'),
          ),
        ),*/
      )
    );

    $fields['contact_fields'] = array(
        'title'  => esc_html__( 'Contact Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactStandardFields',
            'label'     => '',
            'type'      => 'field_map',
            'field_map' => $this->contact_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
        ),
      );

    $fields['phone_fields'] = array(
        'title'  => esc_html__( 'Phone Number Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactPhoneFields',
            'label'     => '',
            'type'      => 'field_map',
            'field_map' => $this->phone_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
        ),
      );

    $fields['email_fields'] = array(
        'title'  => esc_html__( 'Email Address Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactEmailFields',
            'label'     => '',
            'type'      => 'field_map',
            'field_map' => $this->email_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
        ),
      );

    /*$fields['address_fields'] = array(
        'title'  => esc_html__( 'Address Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactBillingAddressFields',
            'label'     => 'Billing',
            'type'      => 'field_map',
            'field_map' => $this->billing_address_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
          array(
            'name'      => 'contactShippingAddressFields',
            'label'     => 'Shipping',
            'type'      => 'field_map',
            'field_map' => $this->shipping_address_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
          array(
            'name'      => 'contactOtherAddressFields',
            'label'     => 'Other',
            'type'      => 'field_map',
            'field_map' => $this->other_address_fields_mapping(),
            // 'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'sometextdomain' ) . '</h6>' . esc_html__( 'Select which Gravity Form fields pair with their respective third-party service fields.', 'sometextdomain' )
          ),
        ),
      );*/

      $fields['billing_address'] = array(
        'title'  => esc_html__( 'Billing Address Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactBillingAddressFields',
            'type'      => 'field_map',
            'field_map' => $this->billing_address_fields_mapping(),
          ),
        ),
      );

      $fields['shipping_address'] = array(
        'title'  => esc_html__( 'Shipping Address Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactShippingAddressFields',
            'type'      => 'field_map',
            'field_map' => $this->shipping_address_fields_mapping(),
          ),
        ),
      );

      $fields['other_address'] = array(
        'title'  => esc_html__( 'Other Address Fields', 'infusionsoftaddon' ),
        'dependency'  => array(
          'field'   => 'feedType',
          'values'  => array('contact'),
        ),
        'fields' => array(
          array(
            'name'      => 'contactOtherAddressFields',
            'type'      => 'field_map',
            'field_map' => $this->other_address_fields_mapping(),
          ),
        ),
      );

    $custom_fields = get_option('infusionsoft_custom_fields');

    $values = array( array( 'label' => 'Select Custom Field', 'value' => '' ) );
    if(!empty($custom_fields)) {
      foreach ($custom_fields as $row) {
        $values[] = array(
          'label' => $row['label'],
          'value' => $row['id'],
        );
      }
    }

    $fields['custom_fields'] = array(
      'title'       => esc_html__( 'Custom Fields', 'infusionsoftaddon' ),
      'description' => '',
      'dependency'  => array(
        'field'   => 'feedType',
        'values'  => array( 'contact' ),
      ),
      'fields'      => array(
        array(
          'name'      => 'customFields',
          'label'     => '',
          'type'      => 'dynamic_field_map',
          'class'     => 'medium',
          'field_map'  => $values,
          'enable_custom_key' => false,
        )
      )
    );

    $categoryList = get_option('infusionsoft_tags');

    $tag_choices_list = array(
      '-1' => array(
          'label' => 'Select A Tag',
          'value' => '',
        ),
    );

    foreach ($categoryList as $cat) {
      $tag_choices = array();
      $cat_id = $cat['category']['id'];

      if(empty($cat_id))
        $cat_id = 0;

      foreach ($cat as $key => $tag) {
        if($key === 'category') {
          if(empty($tag['name'])) {
            $tag['name'] = 'Uncategorized';
          }

          $tag_choices_list[$cat_id] = array(
            'label' => $tag['name'],
            'value' => $cat_id,
          );

          
          continue;
        }

        $tag_choices[] = array(
          'label' => $tag['name'],
          'value' => $tag['id'],
        );
      }
      $tag_choices_list[$cat_id]['choices'] = $tag_choices;
    }
    

    $fields['tag_settings'] = array(
      'title'       => esc_html__( 'Tags to Apply', 'infusionsoftaddon' ),
      'description' => 'Choose up to 5 to apply',
      'dependency'  => array(
        'field'   => 'feedType',
        'values'  => array( 'contact', 'apply_tags' ),
      ),
      'fields'      => array(
        array(
          'name'      => 'selected_tags[0]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[1]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[2]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[3]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[4]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
      )
    );

    /*$fields['tag_settings'] = array(
      'title'       => esc_html__( 'Tags to Apply', 'infusionsoftaddon' ),
      'description' => 'Choose up to 5 to apply',
      'dependency'  => array(
        'field'   => 'feedType',
        'values'  => array( 'contact', 'apply_tags' ),
      ),
      'fields'      => array(
        array(
          'name'      => 'selected_tags[0]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[1]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[2]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[3]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
        array(
          'name'      => 'selected_tags[4]',
          'label'     => '',
          'type'      => 'select',
          'class'     => 'large',
          'choices'  => $tag_choices_list,
        ),
      )
    );*/

    $feedType = $this->get_setting( 'feedType' );

    if($feedType == 'apply_tags') {
      $values = array(
        'label' => esc_html__( 'Apply Tags Condition', 'infusionsoftaddon' ),
        'instructions' => esc_html__( 'Apply Tags to Contact if', 'infusionsoftaddon' ),
        'tooltip' => sprintf( '<h6>%s</h6> %s', 
          esc_html__( 'Create or Update Condition', 'infusionsoftaddon' ),
          esc_html__( 'When the create or update condition is enabled, form submissions will only create or update the contact when the condition is met.', 'infusionsoftaddon' )
        )
      );
    } else {
      $values = array(
        'label' => esc_html__( 'Creation Condition', 'infusionsoftaddon' ),
        'instructions' => esc_html__( 'Create Contact if', 'infusionsoftaddon' ),
        'tooltip' => sprintf( '<h6>%s</h6> %s', 
          esc_html__( 'Creation Condition', 'infusionsoftaddon' ),
          esc_html__( 'When the creation condition is enabled, form submissions will only create the contact when the condition is met.', 'infusionsoftaddon' )
        )
      );
    }

    $fields['additional_settings'] = array(
      'title'       => esc_html__( 'Additional Options', 'infusionsoftaddon' ),
      'description' => '',
      'dependency'  => array(
        'field'   => 'feedType',
        'values'  => '_notempty_'
      ),
      'fields'      => array(
        array(
          'name'           => 'contactCondition',
          'label'          => $values['label'],
          'type'           => 'feed_condition',
          'checkbox_label' => esc_html__( 'Enable', 'infusionsoftaddon' ),
          'instructions'   => $values['instructions'],
          'tooltip'        => $values['tooltip'],
        )
      )
    );

    $form = $this->get_current_form();

    $fields['save'] = array(
      'fields' => array(
        array(
          'type' => 'save',
          'onclick' => '( function( $, elem, event ) {
            var $form       = $( elem ).parents( "form" ),
              action      = $form.attr( "action" ),
              hashlessUrl = document.URL.replace( window.location.hash, "" );

            if( ! action && hashlessUrl != document.URL ) {
              event.preventDefault();
              $form.attr( "action", hashlessUrl );
              $( elem ).click();
            };

          } )( jQuery, this, event );'
        )
      )
    );

    // sections cannot be an associative array
    return array_values( $fields );
    
  }

  public function test_field_values() {
    return array(
      array(
            'label' => 'test 1',
            'value' => ''
          ),
          array(
            'label' => '',
            'value' => ''
          ),
          array(
            'label' => '',
            'value' => ''
          ),
    );
  }

  public function contact_fields_mapping() {
    return array(
      array(
        'name'     => 'first_name',
        'label'    => esc_html__( 'First Name', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => true,
        'args'     => array(
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'First Name', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s first name.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'last_name',
        'label'    => esc_html__( 'Last Name', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => true,
        'args'     => array(
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Last Name', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s last name.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'birthday',
        'label'    => esc_html__( 'Birthday', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'date' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Birthday', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s birthday.', 'infusionsoftaddon' ) )
      ),
      /*array(
        'name'     => 'gender',
        'label'    => esc_html__( 'Gender', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(),
        'class'    => 'large',
      ),*/
      array(
        'name'     => 'website',
        'label'    => esc_html__( 'Website', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'website' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Website', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s Website.', 'infusionsoftaddon' ) )
      ),
    );
  }
  
  public function billing_address_fields_mapping() {
    return array(
      array(
        'name'     => 'line1',
        'label'    => esc_html__( 'Billing Address 1', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Street Address', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s street address.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'line2',
        'label'    => esc_html__( 'Billing Address 2', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Address Line 2', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s address line 2.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'city',
        'label'    => esc_html__( 'Billing City', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'City', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s city.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'state',
        'label'    => esc_html__( 'Billing State', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'State', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s state.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'zip',
        'label'    => esc_html__( 'Billing Zip', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Zip Code', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s zip code.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'country',
        'label'    => esc_html__( 'Billing Country', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Country', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s country.', 'infusionsoftaddon' ) )
      ),
    );
  }

  public function shipping_address_fields_mapping() {
    return array(
      array(
        'name'     => 'line1',
        'label'    => esc_html__( 'Shipping Address', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Street Address', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s street address.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'line2',
        'label'    => esc_html__( 'Shipping Address 2', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Address Line 2', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s address line 2.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'city',
        'label'    => esc_html__( 'Shipping City', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'City', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s city.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'state',
        'label'    => esc_html__( 'Shipping State', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'State', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s state.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'zip',
        'label'    => esc_html__( 'Shipping Zip', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Zip Code', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s zip code.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'country',
        'label'    => esc_html__( 'Shipping Country', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Country', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s country.', 'infusionsoftaddon' ) )
      ),
    );
  }

  public function other_address_fields_mapping() {
    return array(
      array(
        'name'     => 'line1',
        'label'    => esc_html__( 'Other Address', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Street Address', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s street address.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'line2',
        'label'    => esc_html__( 'Other Address 2', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Address Line 2', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s address line 2.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'city',
        'label'    => esc_html__( 'Other City', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'City', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s city.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'state',
        'label'    => esc_html__( 'Other State', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'State', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s state.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'zip',
        'label'    => esc_html__( 'Other Zip', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Zip Code', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s zip code.', 'infusionsoftaddon' ) )
      ),
      array(
        'name'     => 'country',
        'label'    => esc_html__( 'Other Country', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'args'     => array(
          'input_types' => array( 'address' ),
        ),
        'class'    => 'large',
        'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Country', 'infusionsoftaddon' ), esc_html__( 'Select the form field that should be used for the contact\'s country.', 'infusionsoftaddon' ) )
      ),
    );
  }

  public function email_fields_mapping() {
    return array(
      array(
        'name'     => 'EMAIL1',
        'label'    => esc_html__( 'Email 1', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'email' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'EMAIL2',
        'label'    => esc_html__( 'Email 2', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'email' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'EMAIL3',
        'label'    => esc_html__( 'Email 3', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'email' ),
        ),
        'class'    => 'large',
      ),
    );
  }

  public function phone_fields_mapping() {
    return array(
      array(
        'name'     => 'PHONE1',
        'label'    => esc_html__( 'Phone 1', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'phone' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'PHONE2',
        'label'    => esc_html__( 'Phone 2', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'phone' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'PHONE3',
        'label'    => esc_html__( 'Phone 3', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'phone' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'PHONE4',
        'label'    => esc_html__( 'Phone 4', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'phone' ),
        ),
        'class'    => 'large',
      ),
      array(
        'name'     => 'PHONE5',
        'label'    => esc_html__( 'Phone 5', 'infusionsoftaddon' ),
        'type'     => 'field_select',
        'required' => false,
        'args'     => array(
          'input_types' => array( 'phone' ),
        ),
        'class'    => 'large',
      ),
    );
  }

  public function tags_fields_mapping($choices = array()) {
    return array(
      array(
        'name'     => 'tag1',
        'label'    => esc_html__( 'Tag 1', 'infusionsoftaddon' ),
        'type'     => 'select',
        'choices'  => $choices,
        'required' => false,
        'class'    => 'large',
      ),
      array(
        'name'     => 'tag2',
        'label'    => esc_html__( 'Tag 2', 'infusionsoftaddon' ),
        'type'     => 'select',
        'choices'  => $choices,
        'required' => false,
        'class'    => 'large',
      ),
      array(
        'name'     => 'tag3',
        'label'    => esc_html__( 'Tag 3', 'infusionsoftaddon' ),
        'type'     => 'select',
        'choices'  => $choices,
        'required' => false,
        'class'    => 'large',
      ),
    );
  }

  public function get_available_feed_actions() {
    $actions = $this->get_feed_actions();

    $form = GFAPI::get_form( rgget( 'id' ) );
    if ( is_wp_error( $form ) ) {
      return $actions;
    }

    $feed = $this->get_current_feed();

    return $actions;
  }

  public function get_feed_actions() {
    return array(
      'contact' => array(
        'label' => esc_html__( 'Contact', 'infusionsoftaddon' ),
        'value' => 'contact',
        'icon'  => 'fa-user-plus',
      ),
      'apply_tags' => array(
        'label' => esc_html__( 'Apply Tags', 'infusionsoftaddon' ),
        'value' => 'apply_tags',
        'icon'  => 'fa-tag',
      ),
    );
  }

  public function has_feed_type( $feed_type, $form, $current_feed_id = false ) {

    $feeds = $this->get_feeds( $form['id'] );

    foreach ( $feeds as $feed ) {

      // skip current feed as it may be changing feed type
      if ( $current_feed_id && $feed['id'] == $current_feed_id ) {
        continue;
      }

      if ( rgars( $feed, 'meta/feedType' ) == $feed_type ) {
        return true;
      }

    }

    return false;
  }

  /**
   * Configures which columns should be displayed on the feed list page.
   *
   * @return array
   */
  public function feed_list_columns() {
    return array(
      'feedName'  => esc_html__( 'Name', 'infusionsoftaddon' ),
      'mytextbox' => esc_html__( 'My Textbox', 'infusionsoftaddon' ),
    );
  }

  /**
   * Format the value to be displayed in the mytextbox column.
   *
   * @param array $feed The feed being included in the feed list.
   *
   * @return string
   */
  public function get_column_value_mytextbox( $feed ) {
    return '<b>' . rgars( $feed, 'meta/mytextbox' ) . '</b>';
  }

  public function get_column_value_feedType( $item ) {

    $feed_types        = $this->get_feed_actions();
    $feed_type         = $item['meta']['feedType'];
    $feed_type_options = $feed_types[ $feed_type ];

    return $feed_type_options['label'];
  }

  /**
   * Prevent feeds being listed or created if an api key isn't valid.
   *
   * @return bool
   */
  public function can_create_feed() {

    // $formID = rgget( 'id' );
    // $feeds = $this->get_feeds($formID);

    // if($feeds == 0)

    // echo "<pre>"; print_r($feeds); echo "</pre>";

    // Get the plugin settings.
    $settings = $this->get_plugin_settings();

    // Access a specific setting e.g. an api key
    $key = rgar( $settings, 'apiKey' );

    return true;
  }

  public function can_duplicate_feed( $feed ) {
    return true;
  }

  /* ------- Auth Fuctions ------- */
  public function maybe_update_auth_tokens() {

    if ( rgget( 'subview' ) !== $this->get_slug() ) {
      return;
    }

    $tokens_updated = null;
    $code           = sanitize_text_field( rgget( 'code' ) );
    if ( ! empty( $code ) && ! $this->is_save_postback() ) {
      $state = sanitize_text_field( rgget( 'state' ) );
      $this->get_tokens( $code );

      $tokens_updated = $this->update_auth_tokens(  );

      $this->update_tags();
      $this->update_custom_fields();

      if ( false !== $tokens_updated && ! is_wp_error( $tokens_updated ) ) {
        wp_redirect( remove_query_arg( array( 'code', 'mode', 'state', 'scope' ) ) );
      }
      
    }

    // If error is provided or couldn't update tokens, Add error message.
    if ( false === $tokens_updated || is_wp_error( $tokens_updated ) ) {
      GFCommon::add_error_message( esc_html__( 'Unable to connect your Infusionsoft account.', 'infusionsoftaddon' ) );
    }
  }

  /**
   * Generates Auth button settings field.
   *
   * @since 1.0.0
   * @param array $field Field properties.
   *
   * @param bool  $echo Display field contents. Defaults to true.
   *
   * @return string
   */
  public function settings_auth_button( $field, $echo = true ) {
    $html = '';
    // If we could initialize api, means we are connected, so show disconnect UI.
    if ( $this->api && $this->api->getToken() ) {
      $html = '<p>' . esc_html__( 'Connected to Infusionsoft', 'infusionsoftaddon' ) . '</p>';

      $button_text = esc_html__( 'Disconnect', 'infusionsoftaddon' );
      $html       .= sprintf(
        ' <a href="#" id="gform_infusionsoft_deauth_button" class="button deauth_button" value="site">%1$s</a>',
        $button_text
      );
    } else {

      $html .= '<tr><td></td><td>';
      // Display custom app OAuth button if App ID & Secret exit.
        $auth_url = $this->api->getAuthorizationUrl();
        // Connect button markup.
        $button_text = esc_attr__( 'Click here to connect your Infusionsoft account', 'infusionsoftaddon' );
        $html       .= sprintf(
          '<a href="%2$s" class="button infusionsoft-connect" id="gform_infusionsoft_auth_button">%s</a>',
          $button_text,
          $auth_url
        );
      $html .= '</td></tr>';
    }

    if ( $echo ) {
      echo $html;
    }

    return $html;

  }

  /**
   * Generates custom Fields Update button settings field.
   *
   * @since 1.0.0
   * @param array $field Field properties.
   *
   * @param bool  $echo Display field contents. Defaults to true.
   *
   * @return string
   */
  public function settings_update_custom_fields( $field, $echo = true ) {
    $html = '';
    // If we could initialize api, means we are connected, so show disconnect UI.
    if ( $this->api && $this->api->getToken() ) {

      $button_text = esc_html__( 'Update Custom Fields', 'infusionsoftaddon' );
      $html       .= sprintf(
        ' <a href="#" id="gform_infusionsoft_custom_button" class="button custom_button" value="site">%1$s</a>',
        $button_text
      );
    }

    if ( $echo ) {
      echo $html;
    }

    return $html;

  }

  /**
   * Generates tags Update button settings field.
   *
   * @since 1.0.0
   * @param array $field Field properties.
   *
   * @param bool  $echo Display field contents. Defaults to true.
   *
   * @return string
   */
  public function settings_update_tags_fields( $field, $echo = true ) {
    $html = '';
    // If we could initialize api, means we are connected, so show disconnect UI.
    if ( $this->api && $this->api->getToken() ) {

      $button_text = esc_html__( 'Update Tags', 'infusionsoftaddon' );
      $html       .= sprintf(
        ' <a href="#" id="gform_infusionsoft_tags_button" class="button tags_button" value="site">%1$s</a>',
        $button_text
      );
    }

    if ( $echo ) {
      echo $html;
    }

    return $html;

  }


  public function auth_data_exists( ) {
    $auth_setting = $this->get_plugin_setting( 'auth_data' );

    if ( ! is_array( $auth_setting ) || empty( $auth_setting ) ) {
      return false;
    }

    return ! empty( $auth_setting );
  }

  public function get_auth_data( ) {
    // Get the authentication setting.
    $auth_setting = $this->get_plugin_setting( 'auth_data' );
    // If the authentication token is not set, return null.
    if ( rgblank( $auth_setting ) ) {
      return null;
    }
    $encrypted_auth_data = empty( $auth_setting ) ? null : $auth_setting;
    if ( is_null( $encrypted_auth_data ) ) {
      return null;
    }

    // Decrypt data.
    $decrypted_auth_data = GFCommon::openssl_decrypt( $encrypted_auth_data, $this->get_encryption_key() );
    $auth_data           = @unserialize( base64_decode( $decrypted_auth_data ) );

    return $auth_data;
  }

  public function maybe_renew_token( ) {
    $auth_data = $this->get_auth_data( );

    if ( ! is_object( $auth_data ) || empty( $auth_data->refreshToken ) || empty( $auth_data->endOfLife ) ) {
      $this->log_error( __METHOD__ . '() : empty or corrupt auth data;' );
      return false;
    }

    if ( time() > $auth_data->endOfLife ) {
      $new_auth_data = $this->renew_token( );
      $token_updated = $this->update_auth_tokens( $new_auth_data );

      if ( is_wp_error( $token_updated ) ) {
        $this->log_error( __METHOD__ . '(): Failed to renew token; ' . $token_updated->get_error_message() );
      } else {
        $this->log_debug( __METHOD__ . '(): Token renewed' );
        return true;
      }
    }

    return false;
  }

  public function renew_token( ) {
    try {
      $auth_data = $this->get_auth_data();
      if(!is_object($auth_data))
        return false;

      return $this->api->refreshAccessToken();
    } catch (\Infusionsoft\InfusionsoftException $e) {
      $this->log_error( __METHOD__ . '() : Error updating contact: '.$e->getMessage() );
      return false;
    }
  }

  public function update_auth_tokens(  ) {
    try {
      $token = $this->api->getToken();

      $settings = $this->get_plugin_settings();
      if ( ! is_array( $settings ) ) {
        $settings = array();
      }

      // Make sure payload contains the required data.
      if ( empty( $token->accessToken ) || empty( $token->refreshToken ) ) {
        return new WP_Error( '1', esc_html__( 'Missing authentication data.', 'infusionsoftaddon' ) );
      }

      // Encrypt.
      $auth_data = GFCommon::openssl_encrypt( base64_encode( serialize( $token ) ), $this->get_encryption_key() );

      $settings['auth_data'] = $auth_data;

      // Save plugin settings.
      $this->update_plugin_settings( $settings );

      $this->log_debug( __METHOD__ . '(): Connected using gravityforms app.' );
    } catch (\Infusionsoft\InfusionsoftException $e) {
      $this->log_error( __METHOD__ . '() : Error updating contact: '.$e->getMessage() );
      return;
    }

    return true;
  }

  /**
   * Returns the encryption key
   *
   * @since 1.0.0
   *
   * @return string encryption key.
   */
  public function get_encryption_key() {
    // Check if key exists in config file.
    if ( defined( 'GRAVITYFORMS_INFUSIONSOFT_ENCRYPTION_KEY' ) && GRAVITYFORMS_INFUSIONSOFT_ENCRYPTION_KEY ) {
      return GRAVITYFORMS_INFUSIONSOFT_ENCRYPTION_KEY;
    }

    // Check if key exists in Database.
    $key = get_option( 'gravityformsinfusionsoft_key' );

    if ( empty( $key ) ) {
      // Key hasn't been generated yet, generate it and save it.
      $key = wp_generate_password( 64, true, true );
      update_option( 'gravityformsinfusionsoft_key', $key );
    }

    return $key;
  }

  public function get_tokens( $code ) {
    // Get Tokens.
    try{
      $this->api->requestAccessToken($code);
    } catch( Exception $e ) {
      $this->log_error( __METHOD__ . '(): Unable to get token; ' . $e->getMessage() );
      return false;
    }
  }

  public function get_country_codes() {
    return array(
      'Aruba' => 'ABW',
      'Afghanistan' => 'AFG',
      'Angola' => 'AGO',
      'Anguilla' => 'AIA',
      'Åland Islands' => 'ALA',
      'Albania' => 'ALB',
      'Andorra' => 'AND',
      'United Arab Emirates' => 'ARE',
      'Argentina' => 'ARG',
      'Armenia' => 'ARM',
      'American Samoa' => 'ASM',
      'Antarctica' => 'ATA',
      'French Southern Territories' => 'ATF',
      'Antigua and Barbuda' => 'ATG',
      'Australia' => 'AUS',
      'Austria' => 'AUT',
      'Azerbaijan' => 'AZE',
      'Burundi' => 'BDI',
      'Belgium' => 'BEL',
      'Benin' => 'BEN',
      'Bonaire, Sint Eustatius and Saba' => 'BES',
      'Burkina Faso' => 'BFA',
      'Bangladesh' => 'BGD',
      'Bulgaria' => 'BGR',
      'Bahrain' => 'BHR',
      'Bahamas' => 'BHS',
      'Bosnia and Herzegovina' => 'BIH',
      'Saint Barthélemy' => 'BLM',
      'Belarus' => 'BLR',
      'Belize' => 'BLZ',
      'Bermuda' => 'BMU',
      'Bolivia, Plurinational State of' => 'BOL',
      'Brazil' => 'BRA',
      'Barbados' => 'BRB',
      'Brunei Darussalam' => 'BRN',
      'Bhutan' => 'BTN',
      'Bouvet Island' => 'BVT',
      'Botswana' => 'BWA',
      'Central African Republic' => 'CAF',
      'Canada' => 'CAN',
      'Cocos (Keeling) Islands' => 'CCK',
      'Switzerland' => 'CHE',
      'Chile' => 'CHL',
      'China' => 'CHN',
      'Côte d\'Ivoire' => 'CIV',
      'Cameroon' => 'CMR',
      'Congo, the Democratic Republic of the' => 'COD',
      'Congo' => 'COG',
      'Cook Islands' => 'COK',
      'Colombia' => 'COL',
      'Comoros' => 'COM',
      'Cape Verde' => 'CPV',
      'Costa Rica' => 'CRI',
      'Cuba' => 'CUB',
      'Curaçao' => 'CUW',
      'Christmas Island' => 'CXR',
      'Cayman Islands' => 'CYM',
      'Cyprus' => 'CYP',
      'Czech Republic' => 'CZE',
      'Germany' => 'DEU',
      'Djibouti' => 'DJI',
      'Dominica' => 'DMA',
      'Denmark' => 'DNK',
      'Dominican Republic' => 'DOM',
      'Algeria' => 'DZA',
      'Ecuador' => 'ECU',
      'Egypt' => 'EGY',
      'Eritrea' => 'ERI',
      'Western Sahara' => 'ESH',
      'Spain' => 'ESP',
      'Estonia' => 'EST',
      'Ethiopia' => 'ETH',
      'Finland' => 'FIN',
      'Fiji' => 'FJI',
      'Falkland Islands (Malvinas)' => 'FLK',
      'France' => 'FRA',
      'Faroe Islands' => 'FRO',
      'Micronesia, Federated States of' => 'FSM',
      'Gabon' => 'GAB',
      'United Kingdom' => 'GBR',
      'Georgia' => 'GEO',
      'Guernsey' => 'GGY',
      'Ghana' => 'GHA',
      'Gibraltar' => 'GIB',
      'Guinea' => 'GIN',
      'Guadeloupe' => 'GLP',
      'Gambia' => 'GMB',
      'Guinea-Bissau' => 'GNB',
      'Equatorial Guinea' => 'GNQ',
      'Greece' => 'GRC',
      'Grenada' => 'GRD',
      'Greenland' => 'GRL',
      'Guatemala' => 'GTM',
      'French Guiana' => 'GUF',
      'Guam' => 'GUM',
      'Guyana' => 'GUY',
      'Hong Kong' => 'HKG',
      'Heard Island and McDonald Islands' => 'HMD',
      'Honduras' => 'HND',
      'Croatia' => 'HRV',
      'Haiti' => 'HTI',
      'Hungary' => 'HUN',
      'Indonesia' => 'IDN',
      'Isle of Man' => 'IMN',
      'India' => 'IND',
      'British Indian Ocean Territory' => 'IOT',
      'Ireland' => 'IRL',
      'Iran, Islamic Republic of' => 'IRN',
      'Iraq' => 'IRQ',
      'Iceland' => 'ISL',
      'Israel' => 'ISR',
      'Italy' => 'ITA',
      'Jamaica' => 'JAM',
      'Jersey' => 'JEY',
      'Jordan' => 'JOR',
      'Japan' => 'JPN',
      'Kazakhstan' => 'KAZ',
      'Kenya' => 'KEN',
      'Kyrgyzstan' => 'KGZ',
      'Cambodia' => 'KHM',
      'Kiribati' => 'KIR',
      'Saint Kitts and Nevis' => 'KNA',
      'Korea, Republic of' => 'KOR',
      'Kuwait' => 'KWT',
      'Lao People\'s Democratic Republic' => 'LAO',
      'Lebanon' => 'LBN',
      'Liberia' => 'LBR',
      'Libya' => 'LBY',
      'Saint Lucia' => 'LCA',
      'Liechtenstein' => 'LIE',
      'Sri Lanka' => 'LKA',
      'Lesotho' => 'LSO',
      'Lithuania' => 'LTU',
      'Luxembourg' => 'LUX',
      'Latvia' => 'LVA',
      'Macao' => 'MAC',
      'Saint Martin (French part)' => 'MAF',
      'Morocco' => 'MAR',
      'Monaco' => 'MCO',
      'Moldova, Republic of' => 'MDA',
      'Madagascar' => 'MDG',
      'Maldives' => 'MDV',
      'Mexico' => 'MEX',
      'Marshall Islands' => 'MHL',
      'Macedonia, the former Yugoslav Republic of' => 'MKD',
      'Mali' => 'MLI',
      'Malta' => 'MLT',
      'Myanmar' => 'MMR',
      'Montenegro' => 'MNE',
      'Mongolia' => 'MNG',
      'Northern Mariana Islands' => 'MNP',
      'Mozambique' => 'MOZ',
      'Mauritania' => 'MRT',
      'Montserrat' => 'MSR',
      'Martinique' => 'MTQ',
      'Mauritius' => 'MUS',
      'Malawi' => 'MWI',
      'Malaysia' => 'MYS',
      'Mayotte' => 'MYT',
      'Namibia' => 'NAM',
      'New Caledonia' => 'NCL',
      'Niger' => 'NER',
      'Norfolk Island' => 'NFK',
      'Nigeria' => 'NGA',
      'Nicaragua' => 'NIC',
      'Niue' => 'NIU',
      'Netherlands' => 'NLD',
      'Norway' => 'NOR',
      'Nepal' => 'NPL',
      'Nauru' => 'NRU',
      'New Zealand' => 'NZL',
      'Oman' => 'OMN',
      'Pakistan' => 'PAK',
      'Panama' => 'PAN',
      'Pitcairn' => 'PCN',
      'Peru' => 'PER',
      'Philippines' => 'PHL',
      'Palau' => 'PLW',
      'Papua New Guinea' => 'PNG',
      'Poland' => 'POL',
      'Puerto Rico' => 'PRI',
      'Korea, Democratic People\'s Republic of' => 'PRK',
      'Portugal' => 'PRT',
      'Paraguay' => 'PRY',
      'Palestinian Territory, Occupied' => 'PSE',
      'French Polynesia' => 'PYF',
      'Qatar' => 'QAT',
      'Réunion' => 'REU',
      'Romania' => 'ROU',
      'Russian Federation' => 'RUS',
      'Rwanda' => 'RWA',
      'Saudi Arabia' => 'SAU',
      'Sudan' => 'SDN',
      'Senegal' => 'SEN',
      'Singapore' => 'SGP',
      'South Georgia and the South Sandwich Islands' => 'SGS',
      'Saint Helena, Ascension and Tristan da Cunha' => 'SHN',
      'Svalbard and Jan Mayen' => 'SJM',
      'Solomon Islands' => 'SLB',
      'Sierra Leone' => 'SLE',
      'El Salvador' => 'SLV',
      'San Marino' => 'SMR',
      'Somalia' => 'SOM',
      'Saint Pierre and Miquelon' => 'SPM',
      'Serbia' => 'SRB',
      'South Sudan' => 'SSD',
      'Sao Tome and Principe' => 'STP',
      'Suriname' => 'SUR',
      'Slovakia' => 'SVK',
      'Slovenia' => 'SVN',
      'Sweden' => 'SWE',
      'Swaziland' => 'SWZ',
      'Sint Maarten (Dutch part)' => 'SXM',
      'Seychelles' => 'SYC',
      'Syrian Arab Republic' => 'SYR',
      'Turks and Caicos Islands' => 'TCA',
      'Chad' => 'TCD',
      'Togo' => 'TGO',
      'Thailand' => 'THA',
      'Tajikistan' => 'TJK',
      'Tokelau' => 'TKL',
      'Turkmenistan' => 'TKM',
      'Timor-Leste' => 'TLS',
      'Tonga' => 'TON',
      'Trinidad and Tobago' => 'TTO',
      'Tunisia' => 'TUN',
      'Turkey' => 'TUR',
      'Tuvalu' => 'TUV',
      'Taiwan, Province of China' => 'TWN',
      'Tanzania, United Republic of' => 'TZA',
      'Uganda' => 'UGA',
      'Ukraine' => 'UKR',
      'United States Minor Outlying Islands' => 'UMI',
      'Uruguay' => 'URY',
      'United States' => 'USA',
      'Uzbekistan' => 'UZB',
      'Holy See (Vatican City State)' => 'VAT',
      'Saint Vincent and the Grenadines' => 'VCT',
      'Venezuela, Bolivarian Republic of' => 'VEN',
      'Virgin Islands, British' => 'VGB',
      'Virgin Islands, U.S.' => 'VIR',
      'Viet Nam' => 'VNM',
      'Vanuatu' => 'VUT',
      'Wallis and Futuna' => 'WLF',
      'Samoa' => 'WSM',
      'Yemen' => 'YEM',
      'South Africa' => 'ZAF',
      'Zambia' => 'ZMB',
      'Zimbabwe' => 'ZWE',
    );
  }

}
