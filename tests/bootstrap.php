<?php
/**
 * PHPUnit Bootstrap for N88 RFQ Platform Tests
 * 
 * Loads plugin files for testing
 */

// Define ABSPATH if not already defined (required by plugin files)
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/' );
}

// Mock WordPress functions needed by plugin
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}

// Load Intelligence class directly (doesn't require WordPress)
require_once dirname( dirname( __FILE__ ) ) . '/includes/class-n88-intelligence.php';

