<?php
class Meow_DBCLNR_Queries_Posts_Revision extends Meow_DBCLNR_Queries_Core
{
    private function get_threshold_timestamp( $age_threshold )
    {
        if ( $age_threshold === 0 ) {
            return 0;
        }
        return strtotime( '-' . $age_threshold );
    }

    public function generate_fake_data_query( $age_threshold = 0 )
    {
        $id = $this->generate_fake_post( $age_threshold );
        wp_save_post_revision( $id );
    }

    public function count_query( $age_threshold = '7 days' )
    {
        global $wpdb;
        $threshold_timestamp = $this->get_threshold_timestamp( $age_threshold );

        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'";
        if ( $threshold_timestamp ) {
            $threshold_date = date( 'Y-m-d H:i:s', $threshold_timestamp );
            $sql .= $wpdb->prepare( " AND post_modified < %s", $threshold_date );
        }

        return (int) $wpdb->get_var( $sql );
    }

    public function delete_query( $deep_deletions_enabled, $limit, $age_threshold = 0 )
    {
        if ( $deep_deletions_enabled ) {
            return MeowPro_DBCLNR_Queries::delete_posts_revision( $age_threshold );
        }

        global $wpdb;
        $threshold_timestamp = $this->get_threshold_timestamp( $age_threshold );

        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'";
        if ( $threshold_timestamp ) {
            $threshold_date = date( 'Y-m-d H:i:s', $threshold_timestamp );
            $sql .= $wpdb->prepare( " AND post_modified < %s", $threshold_date );
        }
        $sql .= $wpdb->prepare( " LIMIT %d", $limit );

        $revision_ids = $wpdb->get_col( $sql );

        $deleted_count = 0;
        foreach ( $revision_ids as $revision_id ) {
            $result = wp_delete_post_revision( $revision_id );
            if ( $result ) {
                $deleted_count++;
            }
        }

        return $deleted_count;
    }

    public function get_query( $offset, $limit, $age_threshold = 0 )
    {
        global $wpdb;
        $threshold_timestamp = $this->get_threshold_timestamp( $age_threshold );

        $sql = "SELECT * FROM {$wpdb->posts} WHERE post_type = 'revision'";
        if ( $threshold_timestamp ) {
            $threshold_date = date( 'Y-m-d H:i:s', $threshold_timestamp );
            $sql .= $wpdb->prepare( " AND post_modified < %s", $threshold_date );
        }
        $sql .= " ORDER BY post_modified ASC";
        $sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $limit, $offset );

        $results = $wpdb->get_results( $sql );
        return array_map( function( $row ) {
            return new WP_Post( $row );
        }, $results );
    }
}