<?php

class Meow_DBCLNR_Support_MeowApps {

  // Tables are matched by prefix, like the options and the crons above, so a new
  // table in one of our plugins is recognized without having to be added to
  // common_tables.csv. The CSV had fallen four tables behind Media Cleaner.
  static private $table_prefixes = [
    'mclean_' => [ 'media-cleaner', 'Media Cleaner' ],
    'mwai_' => [ 'ai-engine', 'AI Engine' ],
    'mgl_' => [ 'meow-gallery', 'Meow Gallery' ],
    'mwflow_' => [ 'meow-workflow', 'Meow Workflow' ],
    'mwcode_' => [ 'code-engine', 'Code Engine' ],
  ];

  // Media Cleaner's original table, from before the mclean_ prefix existed.
  static private $table_names = [
    'wpmcleaner' => [ 'media-cleaner', 'Media Cleaner' ],
  ];

  public function __construct() {
    add_filter( 'dbclnr_check_support_for_option', array( $this, 'check_support_for_option' ), 10, 3 );
    add_filter( 'dbclnr_check_support_for_cron', array( $this, 'check_support_for_cron' ), 10, 3 );
    add_filter( 'dbclnr_check_support_for_table', array( $this, 'check_support_for_table' ), 10, 3 );
	}

  // The table name arrives without the database prefix, already stripped by
  // Meow_DBCLNR_Support::check_table_info().
  function check_support_for_table( $status, $table, $active_plugins ) {
    if ( isset( self::$table_names[ $table ] ) ) {
      list( $plugin, $pluginName ) = self::$table_names[ $table ];
      return $this->check_support_for_option_for( $plugin, $pluginName, $active_plugins );
    }
    foreach ( self::$table_prefixes as $prefix => $owner ) {
      if ( substr( $table, 0, strlen( $prefix ) ) === $prefix ) {
        return $this->check_support_for_option_for( $owner[0], $owner[1], $active_plugins );
      }
    }
    return $status;
  }

  function check_support_for_option_for( $plugin, $pluginName, $active_plugins ) {
    if ( in_array( $plugin, $active_plugins ) ) {
      return [ 'status' => 'ok', 'usedBy' => $pluginName ];
    }
    if ( in_array( $plugin . '-pro', $active_plugins ) ) {
      return [ 'status' => 'ok', 'usedBy' => $pluginName ];
    }
    return [ 'status' => 'warn', 'usedBy' => $pluginName ];
  }

  function check_support_for_cron( $status, $cron, $active_plugins ) {
    if ( substr( $cron, 0, 7 ) === "dbclnr_" ) {
      return $this->check_support_for_option_for( 'database-cleaner', 'Database Cleaner', $active_plugins );
    }
    return $status;
  }

  function check_support_for_option( $status, $option, $active_plugins ) {
    if ( substr( $option, 0, 7 ) === "dbclnr_" ) {
      return $this->check_support_for_option_for( 'database-cleaner', 'Database Cleaner', $active_plugins );
    }
    if ( substr( $option, 0, 5 ) === "mgcl_" ) {
      return $this->check_support_for_option_for( 'custom-gallery-links', 'Custom Gallery Links', $active_plugins );
    }
    if ( substr( $option, 0, 5 ) === "mfrh_" ) {
      return $this->check_support_for_option_for( 'media-file-renamer', 'Media File Renamer', $active_plugins );
    }
    if ( substr( $option, 0, 5 ) === "wr2x_" ) {
      return $this->check_support_for_option_for( 'wp-retina-2x', 'Perfect Images', $active_plugins );
    }
    if ( substr( $option, 0, 5 ) === "wpmc_" ) {
      return $this->check_support_for_option_for( 'media-cleaner', 'Media Cleaner', $active_plugins );
    }
    if ( substr( $option, 0, 5 ) === "mwai_" ) {
      return $this->check_support_for_option_for( 'ai-engine', 'AI Engine', $active_plugins );
    }
    if ( substr( $option, 0, 4 ) === "mct_" ) {
      return $this->check_support_for_option_for( 'image-copytrack', 'Image Copytrack', $active_plugins );
    }
    if ( substr( $option, 0, 4 ) === "mgl_" ) {
      return $this->check_support_for_option_for( 'meow-gallery', 'Meow Gallery', $active_plugins );
    }
    if ( substr( $option, 0, 4 ) === "mwl_" ) {
      return $this->check_support_for_option_for( 'meow-lightbox', 'Meow Lightbox', $active_plugins );
    }
    if ( substr( $option, 0, 9 ) === "meowapps_" ) {
      return [ 'status' => 'ok', 'usedBy' => 'Meow Apps' ];
    }
    return $status;
  }

}