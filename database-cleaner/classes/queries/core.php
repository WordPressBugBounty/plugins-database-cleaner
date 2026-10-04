<?php

class Meow_DBCLNR_Queries_Core
{
  private $item = "";
  protected $fake_data_post_type = 'dbclnr_fake_post';
  protected $fake_data_post_metakey = '_dbclnr_fake_post_metakey';
  protected $fake_data_metavalue = 'dbclnr_fake_metavalue';
  protected $fake_data_taxonomy = 'dbclnr_fake_taxonomy';
  protected $fake_data_term_metakey = '_dbclnr_fake_term_metakey';
  protected $fake_data_term_name = 'term_name';
  protected $fake_data_user_metakey = '_dbclnr_fake_user_metakey';
  protected $fake_data_user_slug = 'dbclnr-fake-user';
  protected $fake_data_comment_metakey = '_dbclnr_fake_comment_metakey';

  public function __construct()
  {
    $class = get_called_class();
    $this->item = strtolower( str_replace( 'Meow_DBCLNR_Queries_', '', $class ) );
    add_filter( 'dbclnr_get_queries', function ( $queries ) {
      $queries[$this->item] = function (...$args) { return $this->get_query(...$args); };
      return $queries;
    }, 10, 1 );
    add_filter( 'dbclnr_delete_queries', function ( $queries ) {
      $queries[$this->item] = function (...$args) { return $this->delete_query(...$args); };
      return $queries;
    }, 10, 1 );
    add_filter( 'dbclnr_count_queries', function ( $queries ) {
      $queries[$this->item] = function (...$args) { return $this->count_query(...$args); };
      return $queries;
    }, 10, 1 );
    add_filter( 'dbclnr_generate_fake_data_queries', function ( $queries ) {
      $queries[$this->item] = function (...$args) { return $this->generate_fake_data_query(...$args); };
      return $queries;
    }, 10, 1 );
  }

  public function count_query( $age_threshold = 0 )
  {
    throw new Error( 'Not implemented' );
  }

  public function delete_query( $deep_deletions_enabled, $limit, $age_threshold = 0 )
  {
    throw new Error( 'Not implemented' );
  }

  public function get_query( $offset, $limit, $age_threshold = 0 )
  {
    throw new Error( 'Not implemented' );
  }

  public function generate_fake_data_query($age_threshold = 0)
  {
    throw new Error( 'Not implemented' );
  }

  public function generate_fake_post( $age_threshold, $post_status = 'draft' )
  {
    list( $post_modified, $post_modified_gmt ) = $this->get_dates_with_age_threshold( $age_threshold );

    $post = [
      'post_content' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
      'post_title' => 'Fake Post',
      'post_status' => $post_status,
      'post_type' => $this->fake_data_post_type,
      'post_date' => $post_modified,
      'post_date_gmt' => $post_modified_gmt,
      'comments_status' => 'open',
    ];

    $result = wp_insert_post( $post, true );
    if ( is_wp_error( $result ) ) {
      throw new Error( $result->get_error_message() );
    }

    return $result;
  }

  public function generate_fake_comment( $age_threshold, $post_id, $comment_approved = '0', $comment_type = 'comment' )
  {
    list( $comment_modified, $comment_modified_gmt ) = $this->get_dates_with_age_threshold( $age_threshold );
    $comment = [
        'comment_post_ID' => $post_id,
        'comment_approved' => $comment_approved,
        'comment_content' => 'fake comment',
        'comment_date' => $comment_modified,
        'comment_date_gmt' => $comment_modified_gmt,
        'comment_type' => $comment_type,
    ];
    return wp_insert_comment( $comment );
  }

  protected function get_dates_with_age_threshold( $age_threshold )
  {
    $week_ago = new DateTime('-' . $age_threshold);
    $week_ago = $week_ago->modify('-1 day');
    $post_modified = $week_ago->format('Y-m-d H:i:s');
    $post_modified_gmt = $week_ago->setTimezone(new DateTimeZone('GMT'))->format('Y-m-d H:i:s');

    return [ $post_modified, $post_modified_gmt ];
  }

  // Shared by the duplicated post/term/user/comment meta items. $meta is [ table, id column, object column ].
  // A duplicate is a row with the same object, key and exact value as a newer row; the newest is kept.
  // Rows sharing a key with different values are legit multi-value meta and must never match: the old
  // queries grouped on object + key only, which deleted them (term, user, comment) or made the count
  // never reach zero (post). MD5 groups in one scan; the CAST(... AS BINARY) check makes the match
  // exact (case, trailing spaces, hash collisions), since the default collations consider 'Hello' and
  // 'hello ' equal.
  protected function duplicated_meta_sql( $meta, $select )
  {
    list( $table, $id, $object ) = $meta;
    return "
      SELECT $select
      FROM (
        SELECT $object AS object_id, meta_key, MD5(meta_value) AS hash, MAX($id) AS keep_id
        FROM $table
        GROUP BY $object, meta_key, hash
        HAVING COUNT(*) > 1
      ) d
      INNER JOIN $table t
        ON t.$object = d.object_id AND t.meta_key <=> d.meta_key AND t.$id < d.keep_id
      INNER JOIN $table k
        ON k.$id = d.keep_id
      WHERE CAST(t.meta_value AS BINARY) <=> CAST(k.meta_value AS BINARY)
    ";
  }

  protected function count_duplicated_meta( $meta )
  {
    global $wpdb;
    $count = $wpdb->get_var( $this->duplicated_meta_sql( $meta, 'COUNT(*)' ) );
    if ( $count === null ) {
      throw new Error( 'Failed to count the duplicated meta: ' . $wpdb->last_error );
    }
    return (int)$count;
  }

  protected function get_duplicated_meta( $meta, $offset, $limit )
  {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
      $this->duplicated_meta_sql( $meta, 't.*' ) . " ORDER BY t.{$meta[1]} LIMIT %d, %d",
      $offset,
      $limit
    ), ARRAY_A );
  }

  // Deletes up to $limit duplicates. With deep deletions, $deep_callback receives the ids so WordPress
  // deletes them one by one (hooks and caches included).
  protected function delete_duplicated_meta( $meta, $limit, $deep_callback = null )
  {
    global $wpdb;
    list( $table, $id ) = $meta;
    $ids = $wpdb->get_col( $wpdb->prepare(
      $this->duplicated_meta_sql( $meta, "t.$id" ) . " ORDER BY t.$id LIMIT %d",
      $limit
    ) );
    if ( $wpdb->last_error ) {
      throw new Error( 'Failed to find the duplicated meta: ' . $wpdb->last_error );
    }
    if ( empty( $ids ) ) {
      return 0;
    }
    if ( $deep_callback ) {
      return call_user_func( $deep_callback, $ids );
    }
    $placeholder = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
    $result = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE $id IN ($placeholder)", $ids ) );
    if ( $result === false ) {
      throw new Error( 'Failed to delete the duplicated meta: ' . $wpdb->last_error );
    }
    return $result;
  }
}
