<?php

if ( !class_exists( 'MeowCommon_Helpers' ) ) {

  class MeowCommon_Helpers {
    //public static $version = MeowCommon_Admin::version;
    private static $startTimes = [];
    private static $startQueries = [];

    public static function is_divi_builder() {
      return isset( $_GET['et_fb'] ) && $_GET['et_fb'] === '1';
    }

    public static function is_beaver_builder() {
      return isset( $_GET['fl_builder'] );
    }

    public static function is_cornerstone_builder() {
      return isset( $_GET['cs-render'] ) && $_GET['cs-render'] === '1';
    }

    public static function is_pagebuilder_request() {
      return self::is_divi_builder() || self::is_cornerstone_builder() || self::is_beaver_builder();
    }

    public static function is_asynchronous_request() {
      return self::is_ajax_request() || self::is_woocommerce_ajax_request() || self::is_rest();
    }

    public static function is_ajax_request() {
      return wp_doing_ajax();
    }

    public static function is_woocommerce_ajax_request() {
      return !empty( $_GET['wc-ajax'] );
    }

    // This function makes sure that only the allowed HTML element names,
    // attribute names, attribute values, and HTML entities will occur in the given text string.
    public static function wp_kses( $html ) {
      return wp_kses( $html, [
        'style' => [],
        'script' => [],
        'div' => [
          'class' => [],
          'data-rating-date' => [],
          'style' => [],
        ],
        'img' => [
          'src' => [],
          'decoding' => [],
          'class' => [],
          'style' => [],
        ],
        'p' => [
          'style' => [],
        ],
        'h2' => [
          'class' => [],
        ],
        'br' => [],
        'label' => [],
        'b' => [],
        'small' => [],
        'a' => [
          'href' => [],
          'target' => [],
          'class' => [],
          'style' => [],
        ],
        'form' => [
          'method' => [],
          'action' => [],
          'class' => [],
          'style' => [],
        ],
        'input' => [
          'type' => [],
          'checked' => [],
          'name' => [],
          'value' => [],
          'id' => [],
          'class' => [],
        ],
      ] );
    }

    // Diff between two strings
    public static function diff( $first, $second ) {
      $first = explode( ' ', $first );
      $second = explode( ' ', $second );
      $diff = array_diff( $first, $second );
      return implode( ' ', $diff );
    }

    // Originally created by matzeeable, modified by jordymeow
    public static function is_rest() {

      // WP_REST_Request init.
      $is_rest_request = defined( 'REST_REQUEST' ) && REST_REQUEST;
      if ( $is_rest_request ) {
        MeowCommon_Rest::init_once();
        return true;
      }

      // Plain permalinks.
      $prefix = rest_get_url_prefix();
      $request_contains_rest = isset( $_GET['rest_route'] ) && strpos( trim( $_GET['rest_route'], '\\/' ), $prefix, 0 ) === 0;
      if ( $request_contains_rest ) {
        MeowCommon_Rest::init_once();
        return true;
      }

      // It can happen that WP_Rewrite is not yet initialized, so better to do it.
      global $wp_rewrite;
      if ( $wp_rewrite === null ) {
        $wp_rewrite = new WP_Rewrite();
      }
      $rest_url = wp_parse_url( trailingslashit( get_rest_url() ) );
      $current_url = wp_parse_url( add_query_arg( [] ) );
      if ( !$rest_url || !$current_url ) {
        return false;
      }

      // URL Path begins with wp-json.
      if ( !empty( $current_url['path'] ) && !empty( $rest_url['path'] ) ) {
        $request_contains_rest = strpos( $current_url['path'], $rest_url['path'], 0 ) === 0;
        if ( $request_contains_rest ) {
          MeowCommon_Rest::init_once();
          return true;
        }
      }

      return false;
    }

    public static function test_error( $error = 'timeout', $diceSides = 1 ) {
      if ( mt_rand( 1, $diceSides ) === 1 ) {
        if ( $error === 'timeout' ) {
          header( 'HTTP/1.0 408 Request Timeout' );
          die();
        }
        else {
          trigger_error( 'Error', E_USER_ERROR );
        }
      }
    }

    public static function php_error_logs() {
      $errorpath = ini_get( 'error_log' );
      $output_lines = [];
      if ( !empty( $errorpath ) && file_exists( $errorpath ) ) {
        try {
          $file = new SplFileObject( $errorpath, 'r' );
          $file->seek( PHP_INT_MAX );
          $last_line = $file->key();
          $iterator = new LimitIterator( $file, $last_line > 3500 ? $last_line - 3500 : 0, $last_line );
          $lines = iterator_to_array( $iterator );
          $previous_line = null;
          foreach ( $lines as $line ) {

            // Parse the date
            $date = '';
            try {
              $dateArr = [];
              preg_match( '~^\[(.*?)\]~', $line, $dateArr );
              if ( isset( $dateArr[0] ) ) {
                $line = str_replace( $dateArr[0], '', $line );
                $line = trim( $line );
                $date = new DateTime( $dateArr[1] );
                $date = get_date_from_gmt( $date->format( 'Y-m-d H:i:s' ), 'Y-m-d H:i:s' );
              }
              else {
                continue;
              }
            }
            catch ( Exception $e ) {
              continue;
            }

            // Parse the error
            $type = '';
            if ( preg_match( '/PHP Fatal error/', $line ) ) {
              $line = trim( str_replace( 'PHP Fatal error:', '', $line ) );
              $type = 'fatal';
            }
            else if ( preg_match( '/PHP Warning/', $line ) ) {
              $line = trim( str_replace( 'PHP Warning:', '', $line ) );
              $type = 'warning';
            }
            else if ( preg_match( '/PHP Notice/', $line ) ) {
              $line = trim( str_replace( 'PHP Notice:', '', $line ) );
              $type = 'notice';
            }
            else if ( preg_match( '/PHP Parse error/', $line ) ) {
              $line = trim( str_replace( 'PHP Parse error:', '', $line ) );
              $type = 'parse';
            }
            else if ( preg_match( '/PHP Exception/', $line ) ) {
              $line = trim( str_replace( 'PHP Exception:', '', $line ) );
              $type = 'exception';
            }
            else {
              continue;
            }

            // Skip the error if is the same as before.
            if ( $line !== $previous_line ) {
              array_push( $output_lines, [ 'date' => $date, 'type' => $type, 'content' => $line ] );
              $previous_line = $line;
            }
          }
        }
        catch ( OutOfBoundsException $e ) {
          error_log( $e->getMessage() );
          return [];
        }
      }
      return $output_lines;

      // else {
      //   $output_lines = array_reverse( $output_lines );
      //   $html = '';
      //   $previous = '';
      //   foreach ( $output_lines as $line ) {
      //     // Let's avoid similar errors, since it's not useful. We should also make this better
      //     // and not only theck this depending on tie.
      //     if ( preg_replace( '/\[.*\] PHP/', '', $previous ) !== preg_replace( '/\[.*\] PHP/', '', $line ) ) {
      //       $html .=  $line;
      //       $previous = $line;
      //     }
      //   }
      //   return $html;
      // }
    }

    public static function timer_start( $timerName = 'default' ) {
      MeowCommon_Helpers::$startQueries[ $timerName ] = get_num_queries();
      MeowCommon_Helpers::$startTimes[ $timerName ] = microtime( true );
    }

    public static function timer_elapsed( $timerName = 'default' ) {
      return microtime( true ) - MeowCommon_Helpers::$startTimes[ $timerName ];
    }

    public static function timer_log_elapsed( $timerName = 'default' ) {
      $elapsed = MeowCommon_Helpers::timer_elapsed( $timerName );
      $queries = get_num_queries() - MeowCommon_Helpers::$startQueries[ $timerName ];
      error_log( $timerName . ': ' . $elapsed . 'ms (' . $queries . ' queries)' );
    }
  }

  // Asked by WP Security Team to remove this.

  // if ( MeowCommon_Helpers::is_rest() ) {
  //   ini_set( 'display_errors', 0 );
  // }
}
