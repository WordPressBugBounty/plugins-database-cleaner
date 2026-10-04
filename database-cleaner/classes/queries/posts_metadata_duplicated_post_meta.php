<?php

class Meow_DBCLNR_Queries_Posts_Metadata_Duplicated_Post_Meta extends Meow_DBCLNR_Queries_Core
{
  public function generate_fake_data_query($age_threshold = 0)
  {
    $id = $this->generate_fake_post($age_threshold);
    add_post_meta($id, $this->fake_data_post_metakey, $this->fake_data_metavalue);
    add_post_meta($id, $this->fake_data_post_metakey, $this->fake_data_metavalue);
    add_post_meta($id, $this->fake_data_post_metakey . "_bis", $this->fake_data_metavalue);
    add_post_meta($id, $this->fake_data_post_metakey . "_bis", $this->fake_data_metavalue);
  }

  private function meta()
  {
    global $wpdb;
    return [ $wpdb->postmeta, 'meta_id', 'post_id' ];
  }

  public function count_query($age_threshold = 0)
  {
    return $this->count_duplicated_meta( $this->meta() );
  }

  public function delete_query($deep_deletions_enabled, $limit, $age_threshold = 0)
  {
    $deep_callback = $deep_deletions_enabled ? [ 'MeowPro_DBCLNR_Queries', 'delete_posts_metadata_duplicated_post_meta' ] : null;
    return $this->delete_duplicated_meta( $this->meta(), $limit, $deep_callback );
  }

  public function get_query($offset, $limit, $age_threshold = 0)
  {
    return $this->get_duplicated_meta( $this->meta(), $offset, $limit );
  }
}
