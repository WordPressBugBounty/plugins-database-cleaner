<?php

class Meow_DBCLNR_Queries_Users_Duplicated_User_Meta extends Meow_DBCLNR_Queries_Core
{
    public function generate_fake_data_query($age_threshold = 0)
    {
        $user_id = null;
        $user = get_user_by( 'slug', $this->fake_data_user_slug );
        if ( !$user ) {
            $result = register_new_user( $this->fake_data_user_slug, 'dbclnr-fake-user@example.com' );
            if ( is_wp_error( $result ) ) {
                throw new Error( $result->get_error_message() );
            }
            $user_id = $result;
        } else {
            $user_id = $user->ID;
        }

        add_user_meta( $user_id, $this->fake_data_user_metakey, $this->fake_data_metavalue );
        add_user_meta( $user_id, $this->fake_data_user_metakey, $this->fake_data_metavalue );
    }

    private function meta()
    {
        global $wpdb;
        return [ $wpdb->usermeta, 'umeta_id', 'user_id' ];
    }

    public function count_query($age_threshold = 0)
    {
        return $this->count_duplicated_meta( $this->meta() );
    }

    public function delete_query($deep_deletions_enabled, $limit, $age_threshold = 0)
    {
        $deep_callback = $deep_deletions_enabled ? [ 'MeowPro_DBCLNR_Queries', 'delete_users_duplicated_user_meta' ] : null;
        return $this->delete_duplicated_meta( $this->meta(), $limit, $deep_callback );
    }

    public function get_query($offset, $limit, $age_threshold = 0)
    {
        return $this->get_duplicated_meta( $this->meta(), $offset, $limit );
    }
}
