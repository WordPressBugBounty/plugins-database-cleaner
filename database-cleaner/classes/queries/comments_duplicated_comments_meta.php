<?php

class Meow_DBCLNR_Queries_Comments_Duplicated_Comments_Meta extends Meow_DBCLNR_Queries_Core
{
    public function generate_fake_data_query($age_threshold = 0)
    {
        $post_id = $this->generate_fake_post( $age_threshold );
        $comment_id = $this->generate_fake_comment( $age_threshold, $post_id );
        if ( !$comment_id ) {
            return;
        }

        add_comment_meta( $comment_id, $this->fake_data_comment_metakey, $this->fake_data_metavalue );
        add_comment_meta( $comment_id, $this->fake_data_comment_metakey, $this->fake_data_metavalue );
    }

    private function meta()
    {
        global $wpdb;
        return [ $wpdb->commentmeta, 'meta_id', 'comment_id' ];
    }

    public function count_query($age_threshold = 0)
    {
        return $this->count_duplicated_meta( $this->meta() );
    }

    public function delete_query($deep_deletions_enabled, $limit, $age_threshold = 0)
    {
        $deep_callback = $deep_deletions_enabled ? [ 'MeowPro_DBCLNR_Queries', 'delete_comments_duplicated_comments_meta' ] : null;
        return $this->delete_duplicated_meta( $this->meta(), $limit, $deep_callback );
    }

    public function get_query($offset, $limit, $age_threshold = 0)
    {
        return $this->get_duplicated_meta( $this->meta(), $offset, $limit );
    }
}
