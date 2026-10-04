<?php

class Meow_DBCLNR_Queries_Posts_Metadata_Duplicated_Term_Meta extends Meow_DBCLNR_Queries_Core
{
    public function generate_fake_data_query($age_threshold = 0)
    {
		$term = get_term_by( 'name', $this->fake_data_term_name, $this->fake_data_taxonomy, ARRAY_A );
        if ( !$term ) {
            $term = wp_insert_term( $this->fake_data_term_name, $this->fake_data_taxonomy );
        }
        add_term_meta( $term['term_id'], $this->fake_data_term_metakey, $this->fake_data_metavalue );
        add_term_meta( $term['term_id'], $this->fake_data_term_metakey, $this->fake_data_metavalue );
    }

    private function meta()
    {
        global $wpdb;
        return [ $wpdb->termmeta, 'meta_id', 'term_id' ];
    }

    public function count_query($age_threshold = 0)
    {
        return $this->count_duplicated_meta( $this->meta() );
    }

    public function delete_query($deep_deletions_enabled, $limit, $age_threshold = 0)
    {
        $deep_callback = $deep_deletions_enabled ? [ 'MeowPro_DBCLNR_Queries', 'delete_posts_metadata_duplicated_term_meta' ] : null;
        return $this->delete_duplicated_meta( $this->meta(), $limit, $deep_callback );
    }

    public function get_query($offset, $limit, $age_threshold = 0)
    {
        return $this->get_duplicated_meta( $this->meta(), $offset, $limit );
    }
}
