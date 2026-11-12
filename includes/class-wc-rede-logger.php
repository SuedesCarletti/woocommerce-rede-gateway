<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Rede_Logger {
    public static $logger;
    const LOG_FILENAME = 'rede-woocommerce-gateway';

    public static function log( $message, $level = 'info' ) {
        if ( ! class_exists( 'WC_Logger' ) ) {
            return;
        }

        if ( empty( self::$logger ) ) {
            self::$logger = wc_get_logger();
        }

        self::$logger->log( $level, $message, array( 'source' => self::LOG_FILENAME ) );
    }

    public static function info( $message ) {
        self::log( $message, 'info' );
    }

    public static function error( $message ) {
        self::log( $message, 'error' );
    }

    public static function debug( $message ) {
        self::log( $message, 'debug' );
    }
}
