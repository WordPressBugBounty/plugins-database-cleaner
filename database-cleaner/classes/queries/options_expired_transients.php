<?php

class Meow_DBCLNR_Queries_Options_Expired_Transients extends Meow_DBCLNR_Queries_Core
{
    public function generate_fake_data_query($age_threshold = 0)
    {
        set_transient( 'dbclnr_fake_for_timeout', 'dbclnr_fake_transient_timeout_data', 1 );
    }

    public function count_query($age_threshold = 0)
    {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare(
			"
			SELECT COUNT(option_id)
			FROM $wpdb->options
			WHERE option_name LIKE '_transient_timeout_%'
				AND option_value <= %d
			",
			time()
		) );
        return $result;
    }

    public function delete_query($deep_deletions_enabled, $limit, $age_threshold = 0)
    {
        if ($deep_deletions_enabled) {
            return MeowPro_DBCLNR_Queries::delete_options_expired_transients();
        }

        global $wpdb;
        $count = $this->count_query();
        if ($count === 0) {
            return 0;
        }
        // delete_transient() removes both the value and its timeout row (and clears the object cache).
        $result = 0;
        foreach ( $this->get_query( 0, $limit ) as $transient ) {
            if ( delete_transient( $transient['option_name'] ) ) {
                $result++;
            }
        }
        return $result;
    }

    public function get_query($offset, $limit, $age_threshold = 0)
    {
        global $wpdb;
        $result = $wpdb->get_results( $wpdb->prepare(
            "
			SELECT REPLACE( option_name, '_transient_timeout_', '' ) option_name
			FROM $wpdb->options
			WHERE option_name LIKE '_transient_timeout_%'
				AND option_value <= %d
			LIMIT %d, %d
			",
			time(), $offset, $limit
        ), ARRAY_A );

        return $result;
    }
}
