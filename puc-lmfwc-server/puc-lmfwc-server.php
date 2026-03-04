<?php
/**
 * Plugin Name: PUC LMFWC Server
 * Plugin URI: 
 * Description: Server for plugin update checker with License Manager for WooCommerce integration
 * Version: 0.0.5
 * Author: 
 * License: GPL v2 or later
 * Text Domain: puc-lmfwc-server
 */

// Prevent direct access
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class PUCLMFWCServer {
    
    private static $instance = null;
    private $puc_lmfwc_server_endpoints_option = 'puc_lmfwc_server_endpoints';
    private $menu_slug = 'puc-lmfwc-server';
    
    public static function get_instance() {
        try {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred in get_instance: ' . $e->getMessage());
            return null;
        }
    }
    
    private function __construct() {
        try {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
            // add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
            
            // Add request handling
            add_action( 'parse_request', array( $this, 'maybe_handle_endpoint_request' ) );
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred in constructor: ' . $e->getMessage());
        }
    }
    
    public function add_admin_menu() {
        try {
            add_menu_page( 
                'PUC LMFWC Server',
                'PUC LMFWC Server',
                'manage_options',
                $this->menu_slug,
                array( $this, 'render_admin_page' ),
                'dashicons-update',
                80
             );
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when adding admin menu: ' . $e->getMessage());
        }
    }
    
    public static function puc_lmfwc_server_log_info(  $message  ) {
        try {
            $can_log_info = get_option(  'puc_lmfwc_server_can_log_info', false  );
            if (  $can_log_info  ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Informational logging controlled by admin setting
                error_log(  'ℹ️ INFO 🖥️ 📦 ' . $message  );
            }
        } catch (Exception $e) {
            // Silently fail for logging function to avoid infinite loops
            // Don't log the error here to avoid recursion
        }
    }

    /**
     * Check if current request matches any registered endpoint and handle it
     */
    public function maybe_handle_endpoint_request() {
        try {
            // Get the current request path
            $request_uri = $_SERVER['REQUEST_URI'];
            $parsed_url = parse_url( $request_uri );
            $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
            
            // Remove leading/trailing slashes
            $path = trim( $path, '/' );
            
            // Get all registered endpoints
            $endpoints = $this->get_endpoints();
            
            // Check if the path matches any endpoint
            foreach ( $endpoints as $endpoint => $data ) {
                if ( $path === $endpoint ) {
                    self::puc_lmfwc_server_log_info( "Endpoint matched: {$endpoint}" );
                    self::puc_lmfwc_server_log_info( "Request URI: {$request_uri}" );
                    self::puc_lmfwc_server_log_info( "GET parameters: " . print_r( $_GET, true ) );
                    $this->handle_endpoint_request( $endpoint, $data );
                    exit; // Stop further processing
                }
            }

            self::puc_lmfwc_server_log_info( "No endpoint matched for path: {$path}" );
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to check the endpoint request: ' . $e->getMessage());
        }
    }

    /**
     * Handle request for a specific endpoint
     */
    private function handle_endpoint_request( $endpoint, $endpoint_data ) {
        try {
            self::puc_lmfwc_server_log_info( "Handling request for endpoint: {$endpoint}" );
            
            // Check if this is a download request ( second endpoint call )
            $is_download = isset( $_GET['download'] ) && $_GET['download'] === '1';
            
            self::puc_lmfwc_server_log_info( "Is download request: " . ( $is_download ? 'yes' : 'no' ) );
            
            if ( $is_download ) {
                $this->handle_download_request( $endpoint, $endpoint_data );
            } else {
                $this->handle_update_info_request( $endpoint, $endpoint_data );
            }
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to handle the endpoint request: ' . $e->getMessage());
        }
    }

    /**
     * Handle update information request ( first endpoint call )
     */
    private function handle_update_info_request( $endpoint, $endpoint_data ) {
        try {
            self::puc_lmfwc_server_log_info( "Handling update info request for endpoint: {$endpoint}" );
        
            // Check if license validation is enabled
            if ( $endpoint_data['validate_license'] ) {
                // Get license key from request
                $license_key = isset( $_GET['license'] ) ? sanitize_text_field( $_GET['license'] ) : '';
                if ( empty( $license_key ) ){
                    $this->send_error_response( 'Invalid license: No license key provided',  401 );
                    return;
                }

                // Get the product ID from endpoint configuration
                $endpoint_product_id = isset( $endpoint_data['product_id'] ) ? $endpoint_data['product_id'] :   null;

                $license_valid = $this->validate_license( $license_key, $endpoint_product_id );
                if ( !$license_valid ) {
                    $this->send_error_response( 'Invalid license or license does not match the requested    product', 401 );
                    return;
                }
            }
        
        
            // Load and return the updator.json content
            $updator_path = $endpoint_data['updator_path'];
            
            if ( !file_exists( $updator_path ) ) {
                self::puc_lmfwc_server_log_info( "Updator.json file not found" );
                $this->send_error_response( 'Updator.json file not found', 409);
                return;
            }
            
            $json_content = file_get_contents( $updator_path );
            $data = json_decode( $json_content, true );
            
            if ( $data === null ) {
                self::puc_lmfwc_server_log_info( "Invalid JSON in updator.json" );
                $this->send_error_response( 'Invalid JSON in updator.json', 409 );
                return;
            }
            
            // Modify download_url to include the endpoint for the second call
            // We need to intercept ALL downloads to serve files from protected locations
            // and to handle license validation for endpoints that require it
            if ( isset( $data['download_url'] ) ) {
                self::puc_lmfwc_server_log_info( "Original download_url: " . $data['download_url'] );
                $current_url = home_url( '/' . $endpoint );
                
                // Get all current query parameters (preserve everything except 'download')
                $query_params = $_GET;
                
                // Remove 'download' parameter if it exists (we'll add it fresh)
                if ( isset( $query_params['download'] ) ) {
                    unset( $query_params['download'] );
                }
                
                // Add 'download=1' to the parameters
                $query_params['download'] = '1';
                
                // Build URL with all preserved query parameters
                $data['download_url'] = add_query_arg( $query_params, $current_url );
                
                self::puc_lmfwc_server_log_info( "Modified download_url: " . $data['download_url'] );
                self::puc_lmfwc_server_log_info( "Preserved query parameters: " . print_r( $query_params,   true ) );
            }
            
            // Send JSON response
            header( 'Content-Type: application/json' );
            $json_data = json_encode( $data );
            echo $json_data;
        
            self::puc_lmfwc_server_log_info("Returning PUC check JSON: {$json_data}");
        
            exit;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to handle update info request: ' . $e->getMessage());
        }
    }

    /**
     * Handle download request ( second endpoint call )
     */
    private function handle_download_request( $endpoint, $endpoint_data ) {
        try {
        self::puc_lmfwc_server_log_info( "Handling download request for endpoint: {$endpoint}" );

        // Check if license validation is enabled
        if ( $endpoint_data['validate_license'] ) {
            // Get license key from request
            $license_key = isset( $_GET['license'] ) ? sanitize_text_field( $_GET['license'] ) : '';

            if ( empty( $license_key ) ){
                $this->send_error_response( 'Invalid license: No license key provided', 401 );
                return;
            }
            
            // Get the product ID from endpoint configuration
            $endpoint_product_id = isset( $endpoint_data['product_id'] ) ? $endpoint_data['product_id'] : null;
            
            $license_valid = $this->validate_license( $license_key, $endpoint_product_id );
            if ( !$license_valid ) {
                $this->send_error_response( 'Invalid license or license does not match the requested product', 401 );
                return;
            }
        }
        
        // Load updator.json to get the actual download URL
        $updator_path = $endpoint_data['updator_path'];
        
        if ( !file_exists( $updator_path ) ) {
            $this->send_error_response( 'Updator.json file not found', 409 );
            return;
        }
        
        $json_content = file_get_contents( $updator_path );
        $data = json_decode( $json_content, true );
        
        if ( $data === null || !isset( $data['download_url'] ) ) {
            $this->send_error_response( 'Invalid updator.json or missing download_url', 409 );
            return;
        }
        
        $download_url = $data['download_url'];
        
        self::puc_lmfwc_server_log_info( "Download URL from updator.json: {$download_url}" );
        
        // Serve the file
        $this->serve_download( $download_url );
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to handle download request: ' . $e->getMessage());
        } 
    }

    /**
     * Validate license using License Manager for WooCommerce
     */
    private function validate_license( $license_key, $endpoint_product_id = null ) {
        try {
        self::puc_lmfwc_server_log_info( "Validating license" );
        self::puc_lmfwc_server_log_info( "Endpoint Product ID: " . ( $endpoint_product_id ? $endpoint_product_id : 'not set' ) );
        
        // Check if License Manager for WooCommerce is active
        if ( !function_exists( 'lmfwc_get_license' ) ) {
            self::puc_lmfwc_server_log_info( "License Manager for WooCommerce functions not available" );
            return false;
        }
        
        if ( empty( $license_key ) ) {
            self::puc_lmfwc_server_log_info( "No license key provided" );
            return false;
        }
        
        // Get license using License Manager for WooCommerce
        $license = lmfwc_get_license( $license_key );
        
        if ( !$license ) {
            self::puc_lmfwc_server_log_info( "License not found: {$license_key}" );
            return false;
        }
        
        // Check if license is active ( status = 3 )
        // Using the LicenseResourceModel's getStatus() method

        // I couldn't figure out how to let the user change status, and using unsecure endpoint api couldn't be handled due to local development, hence it's commented out

        // $status = $license->getStatus();
        
        // if ( $status !== \LicenseManagerForWooCommerce\Enums\LicenseStatus::ACTIVE ) { // 3 = ACTIVE status
        //     $expected_active_status = \LicenseManagerForWooCommerce\Enums\LicenseStatus::ACTIVE;
        //     self::puc_lmfwc_server_log_info( "License status invalid: {$status} ( expected {$expected_active_status} for ACTIVE )" );
        //     return false;
        // }
        
        // Optional: Check if license is expired
        $expires_at = $license->getExpiresAt();
        if ( $expires_at ) {
            $expiry_date = new DateTime( $expires_at );
            $current_date = new DateTime();
            
            if ( $current_date > $expiry_date ) {
                self::puc_lmfwc_server_log_info( "License expired: {$license_key}" );
                return false;
            }
        }
        
        // Validate whether the license product ID matches the endpoint product ID
        $license_product_id = $license->getProductId();
        self::puc_lmfwc_server_log_info( "License product ID: " . ( $license_product_id ? $license_product_id : 'not set in license' ) );
        
        if ( $endpoint_product_id !== null && $endpoint_product_id !== '' ) {
            // Check if license has a product ID
            if ( empty( $license_product_id ) ) {
                self::puc_lmfwc_server_log_info( "License has no product ID assigned, but endpoint requires product ID: {$endpoint_product_id}" );
                return false;
            }
            
            // Check if product IDs match
            if ( $license_product_id != $endpoint_product_id ) {
                self::puc_lmfwc_server_log_info( "License product ID mismatch. Endpoint requires: {$endpoint_product_id}, License has: {$license_product_id}" );
                return false;
            }
            
            self::puc_lmfwc_server_log_info( "Product ID validation passed" );
        } else {
            self::puc_lmfwc_server_log_info( "No product ID specified in endpoint - skipping product validation" );
        }
        
        self::puc_lmfwc_server_log_info( "License is valid: {$license_key}" );
        return true;
        }
        catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to validate license: ' . $e->getMessage());
        }
    }

    /**
     * Serve download file
     */
    private function serve_download( $download_url ) {
        try {
        self::puc_lmfwc_server_log_info( "Serving download from URL: {$download_url}" );
        self::puc_lmfwc_server_log_info( "Site URL: " . site_url() );
        self::puc_lmfwc_server_log_info( "ABSPATH: " . ABSPATH );
        
        // Check if it's a local file
        $site_url = site_url();
        if ( strpos( $download_url, $site_url ) === 0 ) {
            self::puc_lmfwc_server_log_info( "URL is local to this site" );
            // Local file - serve directly
            $file_path = str_replace( $site_url, ABSPATH, $download_url );
            
            self::puc_lmfwc_server_log_info( "File path: {$file_path}" );
            self::puc_lmfwc_server_log_info( "File exists: " . ( file_exists( $file_path ) ? 'yes' : 'no' ) );
            
            if ( file_exists( $file_path ) ) {
                header( 'Content-Type: application/zip' );
                header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
                header( 'Content-Length: ' . filesize( $file_path ) );
                readfile( $file_path );
                exit;
            } else {
                self::puc_lmfwc_server_log_info( "File does not exist!" );
                header( 'HTTP/1.1 503 Service Unavailable' );
                echo 'File not found';
                exit;
            }
        }
    
        // External URL - redirect
        wp_redirect( $download_url );
        exit;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to serve download: ' . $e->getMessage());
        }
    }

    /**
     * Send error response
     */
    private function send_error_response( $message, $response_code = 400 ) {
        try {
        self::puc_lmfwc_server_log_info( "Error response: {$message}" );
        
        header( 'Content-Type: application/json' );
        http_response_code( $response_code );
        echo json_encode( array( 'error' => $message ) );
        exit;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to send error response: ' . $e->getMessage());
        }
    }

    public function get_endpoints() {
        try{
        $endpoints = get_option( $this->puc_lmfwc_server_endpoints_option, array() );
        self::puc_lmfwc_server_log_info( 'Retrieving endpoints: ' . json_encode( $endpoints ) );
        return is_array( $endpoints ) ? $endpoints : array();
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to get endpoints: ' . $e->getMessage());
        }
    }
    
    public function save_endpoints( $endpoints ) {
        try {
        self::puc_lmfwc_server_log_info( 'Saving endpoints: ' . json_encode( $endpoints ) );
        update_option( $this->puc_lmfwc_server_endpoints_option, $endpoints );
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to save endpoints: ' . $e->getMessage());
        }
    }
    
    public function handle_form_submission() {
        try {
        if ( !isset( $_POST['puc_lmfwc_nonce'] ) || !isset( $_POST['action'] ) ) {
            return;
        }
        
        if ( !wp_verify_nonce( $_POST['puc_lmfwc_nonce'], 'puc_lmfwc_action' ) ) {
            wp_die( 'Security check failed' );
        }
        
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        $action = sanitize_text_field( $_POST['action'] );
        
        if ( $action === 'add_endpoint' ) {
            $this->add_new_endpoint();
        } elseif ( $action === 'show_edit_endpoint' ) {
            // This just shows the edit form, no actual editing happens
            // We don't need to do anything here, the render_admin_page will handle it
        } elseif ( $action === 'edit_endpoint' ) {
            $this->edit_endpoint();
        } elseif ( $action === 'delete_endpoint' ) {
            $this->delete_endpoint();
        } elseif ( $action === 'toggle_logging' ) {
            $this->toggle_logging();
        }
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to handle form submission: ' . $e->getMessage());
        } 
    }
    
    private function add_new_endpoint() {
        try {
        $endpoint = sanitize_text_field( $_POST['endpoint'] );
        $updator_path = sanitize_text_field( $_POST['updator_path'] );
        $validate_license = isset( $_POST['validate_license'] ) ? 1 : 0;
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( $_POST['product_id'] ) : '';

        self::puc_lmfwc_server_log_info( "Adding new endpoint: {$endpoint} Updator path: {$updator_path} Validate license: {$validate_license} Product ID: {$product_id}" );
        
        // Validate inputs
        if ( empty( $endpoint ) || empty( $updator_path ) ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'empty_fields',
                'Endpoint and Updator.json path are required.',
                'error'
             );
            return;
        }
        
        // Remove leading/trailing slashes from endpoint
        $endpoint = trim( $endpoint, '/' );
        
        // Validate endpoint format ( alphanumeric and hyphens only )
        if ( !preg_match( '/^[a-z0-9\-.]+$/i', $endpoint ) ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'invalid_endpoint',
                'Endpoint can only contain letters, numbers, periods , and hyphens.',
                'error'
             );
            return;
        }
        
        // Convert user input to absolute path
        $wp_root_path = ABSPATH;
        $file_path = $wp_root_path . ltrim( $updator_path, '/' );
        
        // Check if updator.json file exists
        if ( !file_exists( $file_path ) ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'file_not_found',
                'The updator.json file was not found at the specified path.',
                'error'
             );
            self::puc_lmfwc_server_log_info( 'The updator.json file was not found at the specified path: ' . $file_path  );
            return;
        }
        
        // Check if it's a valid JSON file
        $json_content = file_get_contents( $file_path );
        if ( json_decode( $json_content ) === null ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'invalid_json',
                'The specified file is not a valid JSON file.',
                'error'
             );
            return;
        }
        
        $endpoints = $this->get_endpoints();
        
        // Check if endpoint already exists
        if ( isset( $endpoints[$endpoint] ) ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'endpoint_exists',
                'This endpoint already exists.',
                'error'
             );
            return;
        }
        
        // Add new endpoint
        $endpoints[$endpoint] = array( 
            'updator_path' => $file_path,
            'validate_license' => $validate_license,
            'product_id' => $product_id,
            'created_at' => current_time( 'mysql' )
         );
        
        $this->save_endpoints( $endpoints );
        
        add_settings_error( 
            'puc_lmfwc_messages',
            'endpoint_added',
            'Endpoint added successfully.',
            'success'
         );
        
        // Redirect to clear POST data and prevent refresh issues
        wp_redirect( add_query_arg( 'page', $this->menu_slug, admin_url( 'admin.php' ) ) );
        exit;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to add new endpoint: ' . $e->getMessage());
        }
    }
    
    private function edit_endpoint() {
        try {
        if ( !isset( $_POST['endpoint_to_edit'] ) ) {
            return;
        }
        
        $endpoint = sanitize_text_field( $_POST['endpoint_to_edit'] );
        $updator_path = isset( $_POST['updator_path'] ) ? sanitize_text_field( $_POST['updator_path'] ) : '';
        $validate_license = isset( $_POST['validate_license'] ) ? 1 : 0;
        $product_id = isset( $_POST['product_id'] ) ? sanitize_text_field( $_POST['product_id'] ) : '';

        self::puc_lmfwc_server_log_info( "Editing endpoint: {$endpoint} Updator path: {$updator_path} Validate license: {$validate_license} Product ID: {$product_id}" );
        
        // Get existing endpoints
        $endpoints = $this->get_endpoints();
        
        // Check if endpoint exists
        if ( !isset( $endpoints[$endpoint] ) ) {
            add_settings_error( 
                'puc_lmfwc_messages',
                'endpoint_not_found',
                'Endpoint not found.',
                'error'
             );
            return;
        }
        
        // If updator path is provided, validate it
        if ( !empty( $updator_path ) ) {
            // Convert user input to absolute path
            $wp_root_path = ABSPATH;
            $file_path = $wp_root_path . ltrim( $updator_path, '/' );
            
            // Check if updator.json file exists
            if ( !file_exists( $file_path ) ) {
                add_settings_error( 
                    'puc_lmfwc_messages',
                    'file_not_found',
                    'The updator.json file was not found at the specified path.',
                    'error'
                 );
                self::puc_lmfwc_server_log_info( 'The updator.json file was not found at the specified path: ' . $file_path  );
                return;
            }
            
            // Check if it's a valid JSON file
            $json_content = file_get_contents( $file_path );
            if ( json_decode( $json_content ) === null ) {
                add_settings_error( 
                    'puc_lmfwc_messages',
                    'invalid_json',
                    'The specified file is not a valid JSON file.',
                    'error'
                 );
                return;
            }
            
            $endpoints[$endpoint]['updator_path'] = $file_path;
        }
        
        // Update other fields
        $endpoints[$endpoint]['validate_license'] = $validate_license;
        $endpoints[$endpoint]['product_id'] = $product_id;
        
        $this->save_endpoints( $endpoints );
        
        add_settings_error( 
            'puc_lmfwc_messages',
            'endpoint_updated',
            'Endpoint updated successfully.',
            'success'
         );
        
        // Redirect to clear POST data and prevent refresh issues
        wp_redirect( add_query_arg( 'page', $this->menu_slug, admin_url( 'admin.php' ) ) );
        exit;
        }
        catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to edit end point: ' . $e->getMessage());
        }
    }
    
    private function delete_endpoint() {
        try {
        if ( !isset( $_POST['endpoint_to_delete'] ) ) {
            return;
        }
        
        $endpoint_to_delete = sanitize_text_field( $_POST['endpoint_to_delete'] );
        $endpoints = $this->get_endpoints();
        
        if ( isset( $endpoints[$endpoint_to_delete] ) ) {
            unset( $endpoints[$endpoint_to_delete] );
            $this->save_endpoints( $endpoints );
            
            add_settings_error( 
                'puc_lmfwc_messages',
                'endpoint_deleted',
                'Endpoint deleted successfully.',
                'success'
             );
        } else {
            add_settings_error( 
                'puc_lmfwc_messages',
                'endpoint_does_not_exist',
                'This endpoint does not exist.',
                'error'
             );
        }
        
        // Redirect to clear POST data and prevent refresh issues
        wp_redirect( add_query_arg( 'page', $this->menu_slug, admin_url( 'admin.php' ) ) );
        exit;
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to delete endpoint: ' . $e->getMessage());
        }
    }
    
    private function toggle_logging() {
        try {
        $current_value = get_option( 'puc_lmfwc_server_can_log_info', false );
        $new_value = !$current_value;
        
        update_option( 'puc_lmfwc_server_can_log_info', $new_value );
        
        $status = $new_value ? 'enabled' : 'disabled';
        add_settings_error( 
            'puc_lmfwc_messages',
            'logging_toggled',
            "Informational logging has been $status.",
            'success'
         );
        
        // Log the change
        self::puc_lmfwc_server_log_info( "Informational logging has been $status." );
        }
        catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when trying to toggle logging: ' . $e->getMessage());
        }
    }
    
    public function render_admin_page() {
        try {
            $endpoints = $this->get_endpoints();
            $logging_enabled = get_option( 'puc_lmfwc_server_can_log_info', false );
            
            // Check if we're in edit mode
            $is_edit_mode = false;
            $endpoint_to_edit = '';
            $endpoint_data = array();
            
            // Only show edit form if we're clicking "Edit" button, not after submitting the edit form
            if ( isset( $_POST['action'] ) && $_POST['action'] === 'show_edit_endpoint' && isset( $_POST['endpoint_to_edit'] ) ) {
                $is_edit_mode = true;
                $endpoint_to_edit = sanitize_text_field( $_POST['endpoint_to_edit'] );
                if ( isset( $endpoints[$endpoint_to_edit] ) ) {
                    $endpoint_data = $endpoints[$endpoint_to_edit];
                }
            }
            
            // If we just submitted an edit form, don't show edit mode
            if ( isset( $_POST['action'] ) && $_POST['action'] === 'edit_endpoint' ) {
                $is_edit_mode = false;
            }
            ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <?php settings_errors( 'puc_lmfwc_messages' ); ?>
            
            <div class="puc-lmfwc-admin-container">
                <!-- Logging Settings Card -->
                <div class="card">
                    <h2>Logging Settings</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'puc_lmfwc_action', 'puc_lmfwc_nonce' ); ?>
                        <input type="hidden" name="action" value="toggle_logging">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label>Informational Logging</label>
                                </th>
                                <td>
                                    <div style="margin-bottom: 10px;">
                                        <p>
                                            <?php if ( $logging_enabled ) : ?>
                                                <span class="dashicons dashicons-yes" style="color: #46b450;"></span> 
                                                <strong style="color: #46b450;">Currently Enabled</strong>
                                            <?php else : ?>
                                                <span class="dashicons dashicons-no" style="color: #dc3232;"></span> 
                                                <strong style="color: #dc3232;">Currently Disabled</strong>
                                            <?php endif; ?>
                                        </p>
                                        <p class="description">
                                            When enabled, informational messages will be logged to the PHP error log.
                                            This includes actions like adding/deleting endpoints and other plugin operations.
                                        </p>
                                        <p class="description">
                                            Logs appear in the PHP error log with the prefix: <code>ℹ️ INFO 🖥️ 📦</code>
                                        </p>
                                    </div>
                                    
                                    <?php if ( $logging_enabled ) : ?>
                                        <button type="submit" class="button button-secondary">
                                            <span class="dashicons dashicons-no" style="vertical-align: middle;"></span>
                                            Disable Logging
                                        </button>
                                        <p class="description" style="margin-top: 5px;">
                                            Click to disable informational logging.
                                        </p>
                                    <?php else : ?>
                                        <button type="submit" class="button button-primary">
                                            <span class="dashicons dashicons-yes" style="vertical-align: middle;"></span>
                                            Enable Logging
                                        </button>
                                        <p class="description" style="margin-top: 5px;">
                                            Click to enable informational logging.
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </form>
                </div>
                
                <?php if ( $is_edit_mode ) : ?>
                <!-- Edit Endpoint Form -->
                <div class="card">
                    <h2>Edit Endpoint: <?php echo esc_html( $endpoint_to_edit ); ?></h2>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'puc_lmfwc_action', 'puc_lmfwc_nonce' ); ?>
                        <input type="hidden" name="action" value="edit_endpoint">
                        <input type="hidden" name="endpoint_to_edit" value="<?php echo esc_attr( $endpoint_to_edit ); ?>">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="updator_path">Updator.json Path</label>
                                </th>
                                <td>
                                    <?php 
                                    $current_path = '';
                                    if ( isset( $endpoint_data['updator_path'] ) ) {
                                        $current_path = str_replace( ABSPATH, '', $endpoint_data['updator_path'] );
                                    }
                                    ?>
                                    <input type="text" 
                                           id="updator_path" 
                                           name="updator_path" 
                                           class="regular-text" 
                                           placeholder="/path/to/updator.json"
                                           value="<?php echo esc_attr( $current_path ); ?>">
                                    <p class="description">
                                        Path to the updator.json file. Can be:
                                        <ul style="margin-top: 5px; margin-bottom: 5px;">
                                            <li><strong>Relative to wp root:</strong> <code>updator-1/updator.json</code> (relative to ABSPATH, which is defined in config.php)</li>   
                                        </ul>
                                        <br>Leave empty to keep the current path.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="validate_license">Validate License</label>
                                </th>
                                <td>
                                    <?php 
                                    $current_validate_license = isset( $endpoint_data['validate_license'] ) ? $endpoint_data['validate_license'] : 0;
                                    ?>
                                    <input type="checkbox" 
                                           id="validate_license" 
                                           name="validate_license" 
                                           value="1" 
                                           <?php checked( $current_validate_license, 1 ); ?>>
                                    <label for="validate_license">
                                        Enable License Manager for WooCommerce license validation
                                    </label>
                                    <p class="description" style="margin-top: 5px;">
                                        If checked, the endpoint will validate licenses using License Manager for WooCommerce.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="product_id">Product ID (Optional)</label>
                                </th>
                                <td>
                                    <?php 
                                    $current_product_id = isset( $endpoint_data['product_id'] ) ? $endpoint_data['product_id'] : '';
                                    ?>
                                    <input type="text" 
                                           id="product_id" 
                                           name="product_id" 
                                           class="regular-text" 
                                           placeholder="123"
                                           value="<?php echo esc_attr( $current_product_id ); ?>">
                                    <p class="description">
                                        If specified, licenses will be validated against this Product ID.<br>
                                        The license must be assigned to this specific product to be valid.<br>
                                        Leave empty to allow any product (only validate license existence).
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button( 'Update Endpoint', 'primary', 'submit', false ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug ) ); ?>" class="button button-secondary">Cancel</a>
                    </form>
                </div>
                <?php else : ?>
                <!-- Add New Endpoint Form -->
                <div class="card">
                    <h2>Add New Endpoint</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'puc_lmfwc_action', 'puc_lmfwc_nonce' ); ?>
                        <input type="hidden" name="action" value="add_endpoint">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="endpoint">Endpoint URL</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="endpoint" 
                                           name="endpoint" 
                                           class="regular-text" 
                                           placeholder="my-plugin-updates"
                                           required>
                                    <p class="description">
                                        The endpoint URL path ( e.g., "my-plugin-updates" ). This will be accessible at:<br>
                                        <code><?php echo esc_url( home_url( '/' ) ); ?><strong class="endpoint-example"></strong></code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="updator_path">Updator.json Path</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="updator_path" 
                                           name="updator_path" 
                                           class="regular-text" 
                                           placeholder="/path/to/updator.json"
                                           required>
                                    <p class="description">
                                        Path to the updator.json file. Can be:
                                        <ul style="margin-top: 5px; margin-bottom: 5px;">
                                            <li><strong>Relative to wp root:</strong> <code>updator-1/updator.json</code> (relative to ABSPATH, which is defined in config.php)</li>   
                                        </ul>
                                        
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="validate_license">Validate License</label>
                                </th>
                                <td>
                                    <input type="checkbox" 
                                           id="validate_license" 
                                           name="validate_license" 
                                           value="1" 
                                           checked>
                                    <label for="validate_license">
                                        Enable License Manager for WooCommerce license validation
                                    </label>
                                    <p class="description" style="margin-top: 5px;">
                                        If checked, the endpoint will validate licenses using License Manager for WooCommerce.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="product_id">Product ID (Optional)</label>
                                </th>
                                <td>
                                    <input type="text" 
                                           id="product_id" 
                                           name="product_id" 
                                           class="regular-text" 
                                           placeholder="123">
                                    <p class="description">
                                        If specified, licenses will be validated against this Product ID.<br>
                                        The license must be assigned to this specific product to be valid.<br>
                                        Leave empty to allow any product (only validate license existence).
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <?php submit_button( 'Add Endpoint', 'primary', 'submit', false ); ?>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Existing Endpoints Table -->
                <div class="card">
                    <h2>Existing Endpoints</h2>
                    
                    <?php if ( empty( $endpoints ) ) : ?>
                        <p>No endpoints configured yet.</p>
                    <?php else : ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>Updator.json Path</th>
                                    <th>Validate License</th>
                                    <th>Product ID</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $endpoints as $endpoint => $data ) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $endpoint ); ?></strong><br>
                                            <small>
                                                URL: <code><?php echo esc_url( home_url( '/' . $endpoint ) ); ?></code>
                                            </small>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html( $data['updator_path'] ); ?></code>
                                        </td>
                                        <td>
                                            <?php if ( $data['validate_license'] ) : ?>
                                                <span class="dashicons dashicons-yes" style="color: #46b450;"></span> Enabled
                                            <?php else : ?>
                                                <span class="dashicons dashicons-no" style="color: #dc3232;"></span> Disabled
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ( !empty( $data['product_id'] ) ) {
                                                echo esc_html( $data['product_id'] );
                                            } else {
                                                echo '<span style="color: #999;">—</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $data['created_at'] ) ) ); ?>
                                        </td>
                                        <td>
                                            <form method="post" action="" style="display: inline;">
                                                <?php wp_nonce_field( 'puc_lmfwc_action', 'puc_lmfwc_nonce' ); ?>
                                                <input type="hidden" name="action" value="show_edit_endpoint">
                                                <input type="hidden" name="endpoint_to_edit" value="<?php echo esc_attr( $endpoint ); ?>">
                                                <button type="submit" class="button button-small">
                                                    Edit
                                                </button>
                                            </form>
                                            <form method="post" action="" style="display: inline;">
                                                <?php wp_nonce_field( 'puc_lmfwc_action', 'puc_lmfwc_nonce' ); ?>
                                                <input type="hidden" name="action" value="delete_endpoint">
                                                <input type="hidden" name="endpoint_to_delete" value="<?php echo esc_attr( $endpoint ); ?>">
                                                <button type="submit" 
                                                        class="button button-small button-link-delete"
                                                        onclick="return confirm( 'Are you sure you want to delete this endpoint?' );">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Information Card -->
                <div class="card">
                    <h2>How It Works</h2>
                    <ol>
                        <li><strong>First endpoint:</strong> Receives license from request, generates URL from updator.json, and sends JSON response to PUC.</li>
                        <li><strong>Second endpoint:</strong> When PUC automatically returns to the second endpoint, it delivers the ZIP folder from the URL in updator.json.</li>
                        <li><strong>License Validation:</strong> When enabled, the system will validate licenses using License Manager for WooCommerce.</li>
                    </ol>
                    <p><strong>Note:</strong> The actual endpoint handlers will be implemented in the next phase.</p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery( document ).ready( function( $ ) {
            // Update endpoint example in real-time
            $( '#endpoint' ).on( 'input', function() {
                var endpoint = $( this ).val();
                $( '.endpoint-example' ).text( endpoint );
            } ).trigger( 'input' );
        } );
        </script>
        
        <style>
        .puc-lmfwc-admin-container {
            margin-top: 20px;
        }
        
        .puc-lmfwc-admin-container .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba( 0,0,0,.04 );
            margin-bottom: 20px;
            padding: 20px;
        }
        
        .puc-lmfwc-admin-container .card h2 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .endpoint-example {
            color: #0073aa;
            font-weight: bold;
        }
        
        .button-link-delete {
            color: #a00;
        }
        
        .button-link-delete:hover {
            color: #dc3232;
        }
        </style>
        <?php
        } catch (Exception $e) {
            error_log('PUC LMFWC Server: An error occurred when rendering admin page: ' . $e->getMessage());
            echo '<div class="wrap"><h1>Error</h1><p>An error occurred while loading the admin page. Please check the error logs.</p></div>';
        }
    }
}

// Initialize the plugin
PUCLMFWCServer::get_instance();

// Register activation hook
register_activation_hook( __FILE__, function() {
    try {
        // Ensure our option exists
        if ( !get_option( 'puc_lmfwc_server_endpoints' ) ) {
            update_option( 'puc_lmfwc_server_endpoints', array() );
        }
        
        // Initialize logging option if it doesn't exist
        if ( get_option( 'puc_lmfwc_server_can_log_info' ) === false ) {
            update_option( 'puc_lmfwc_server_can_log_info', false );
        }
    } catch (Exception $e) {
        error_log('PUC LMFWC Server: An error occurred during activation: ' . $e->getMessage());
    }
} );

// Register deactivation hook  
register_deactivation_hook( __FILE__, function() {
    try {
        // Optionally clean up on deactivation
        // delete_option( 'puc_lmfwc_server_endpoints' );
    } catch (Exception $e) {
        error_log('PUC LMFWC Server: An error occurred during deactivation: ' . $e->getMessage());
    }
} );

?>