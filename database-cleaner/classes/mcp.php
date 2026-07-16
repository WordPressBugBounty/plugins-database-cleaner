<?php

/**
 * MCP tools for Database Cleaner, served through AI Engine.
 *
 * Unlike Media Cleaner, Database Cleaner has no trash and no undo: every deletion
 * is immediate and permanent. So every destructive tool here requires an explicit
 * PERMANENT confirmation, states what it will do before it is asked to do it, and
 * refuses anything WordPress itself relies on.
 */
class Meow_DBCLNR_MCP {

	const MAX_ITEMS = 100;
	const SAFETY_NOTE = 'Database Cleaner deletes permanently. There is no trash and no undo: once an entry, option, cron, table or metadata row is deleted, only a database backup can bring it back. Always ask the user for a backup before deleting anything.';

	private $core;
	private $admin;

	public function __construct( $core, $admin ) {
		$this->core = $core;
		$this->admin = $admin;
		add_action( 'init', array( $this, 'init' ), 20 );
	}

	public function init() {
		global $mwai;
		if ( !$this->core->get_option( 'mcp_support' ) || !isset( $mwai ) ) {
			return;
		}
		add_filter( 'mwai_mcp_tools', array( $this, 'register_tools' ) );
		add_filter( 'mwai_mcp_callback', array( $this, 'handle_tool_execution' ), 10, 4 );
	}

	#region Tools Definitions

	public function register_tools( $tools ) {
		$category = 'Database Cleaner';
		$confirm = array(
			'type' => 'string',
			'description' => 'Must be exactly PERMANENT. Only set it after the user explicitly asked for the deletion, knowing it cannot be undone.',
		);

		$tools[] = array(
			'name' => 'dbclnr_get_capabilities',
			'description' => 'Report what Database Cleaner can and cannot do on this site: which modules are enabled, which items are protected, the age threshold and batch size in use, and the current limitations. ALWAYS call this first, before counting or deleting anything. Its "limitations" and "protections" fields tell you which parts of the database are off limits and why.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$tools[] = array(
			'name' => 'dbclnr_get_status',
			'description' => 'Report the current state of the database: its total size, how it evolved recently, the number of tables, and whether the automatic cleanup (Sweeper) is enabled and when it last ran.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array( 'type' => 'object' ),
		);

		#region Core cleanup

		$tools[] = array(
			'name' => 'dbclnr_get_counts',
			'description' => 'Count the cleanable entries for the WordPress Core items (revisions, auto drafts, trashed posts, orphaned and duplicated metadata, spam, transients) and the post types. Counting is done live and some items are slow on a large database, so pass "items" to count only what you need. The counts respect the configured age threshold: entries more recent than it are never included.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'items' => array(
						'type' => 'array',
						'items' => array( 'type' => 'string' ),
						'description' => 'Item keys to count, as returned by dbclnr_get_capabilities. Omit to count every item, which can be slow.',
					),
					'include_post_types' => array(
						'type' => 'boolean',
						'description' => 'Also count the entries per post type. Defaults to false.',
					),
				),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_preview_entries',
			'description' => 'Return a sample of the actual rows that dbclnr_delete_entries would delete for an item. Call it before any deletion and show the user what is really concerned: the count alone tells them nothing about what they are about to lose.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'item' => array( 'type' => 'string', 'description' => 'The item key, as returned by dbclnr_get_capabilities.' ),
					'offset' => array( 'type' => 'integer', 'description' => 'How many rows to skip, for paging.' ),
				),
				'required' => array( 'item' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_delete_entries',
			'description' => 'PERMANENTLY delete one batch of entries for an item. The batch size comes from the settings, so a large item needs several calls: repeat while finished is false. Nothing is recoverable. Ask the user to confirm, with the count and a preview from dbclnr_preview_entries, before the first call. Items whose clean style is "never" are refused.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'item' => array( 'type' => 'string', 'description' => 'The item key, as returned by dbclnr_get_capabilities.' ),
					'confirm' => $confirm,
				),
				'required' => array( 'item', 'confirm' ),
			),
		);

		#endregion

		#region Tables

		$tools[] = array(
			'name' => 'dbclnr_list_tables',
			'description' => 'List the database tables with their size and, when known, the plugin or theme that owns them. The "status" field is the important one: ok = the owner is installed and active, warn = the table belongs to something no longer installed (a likely leftover), n/a = Database Cleaner has never heard of this table and cannot tell you anything about it.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'all', 'ok', 'warn', 'n/a' ),
						'description' => 'Only return the tables with this status. Defaults to all.',
					),
					'search' => array( 'type' => 'string', 'description' => 'Filter by table name.' ),
					'limit' => array( 'type' => 'integer', 'description' => 'How many tables to return, 1 to 100. Defaults to 50.' ),
					'skip' => array( 'type' => 'integer', 'description' => 'How many tables to skip, for paging.' ),
				),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_preview_table',
			'description' => 'Return the first rows of a table, so you can see what it actually holds before proposing to drop it. A table whose name means nothing to you is not evidence of anything: look inside it first.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'table' => array( 'type' => 'string', 'description' => 'The full table name, including the database prefix.' ),
					'offset' => array( 'type' => 'integer', 'description' => 'How many rows to skip, for paging.' ),
				),
				'required' => array( 'table' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_delete_tables',
			'description' => 'PERMANENTLY drop tables. This destroys all their data at once and is by far the most dangerous tool here. WordPress core tables are always refused. Tables owned by an installed plugin are refused unless the user turned on "Protected Items > Enable Deletion" in the settings. Never propose this on your own initiative: a table with an unknown owner is unknown, not unused.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'tables' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Full table names including the prefix, 100 maximum per call.' ),
					'confirm' => $confirm,
				),
				'required' => array( 'tables', 'confirm' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_optimize_tables',
			'description' => 'Run OPTIMIZE TABLE to reclaim the space freed by past deletions. This does not delete anything and is safe, but it locks the tables while it runs, so avoid it on a busy site at peak hours.',
			'category' => $category,
			'accessLevel' => 'write',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'tables' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Full table names including the prefix, 100 maximum per call.' ),
				),
				'required' => array( 'tables' ),
			),
		);

		#endregion

		#region Options

		$tools[] = array(
			'name' => 'dbclnr_list_options',
			'description' => 'List the options with their size, their autoload flag and, when known, the plugin that owns them. Autoloaded options are loaded on EVERY page of the site, so a large autoloaded option is a real performance problem and is usually the most useful thing to report here. Transients are excluded, clean them with dbclnr_delete_entries instead.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'all', 'ok', 'warn', 'n/a' ),
						'description' => 'Only return the options with this status. warn = the owner is not installed anymore. Defaults to all.',
					),
					'autoload' => array(
						'type' => 'string',
						'enum' => array( 'all', 'yes', 'no' ),
						'description' => 'Only return the autoloaded options, or the others. Defaults to all.',
					),
					'search' => array( 'type' => 'string', 'description' => 'Filter by option name.' ),
					'limit' => array( 'type' => 'integer', 'description' => 'How many options to return, 1 to 100. Defaults to 50.' ),
					'skip' => array( 'type' => 'integer', 'description' => 'How many options to skip, for paging.' ),
				),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_get_option_value',
			'description' => 'Return the stored value of an option, so you can see what it holds before proposing to delete it. Large values are truncated.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'option_name' => array( 'type' => 'string', 'description' => 'The option name.' ),
				),
				'required' => array( 'option_name' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_switch_autoloaded_option',
			'description' => 'Turn autoload on or off for an option. This is the SAFE way to fix a large autoloaded option: it stops being loaded on every page, but its value is kept and the change is reversible. Prefer it over dbclnr_delete_options whenever the goal is performance rather than cleanup.',
			'category' => $category,
			'accessLevel' => 'write',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'option_name' => array( 'type' => 'string', 'description' => 'The option name.' ),
					'autoload' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ), 'description' => 'yes to autoload it on every page, no to load it only on demand.' ),
				),
				'required' => array( 'option_name', 'autoload' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_delete_options',
			'description' => 'PERMANENTLY delete options. Options used by WordPress are always refused, and options owned by an installed plugin are refused unless the user turned on "Protected Items > Enable Deletion". Read the value with dbclnr_get_option_value first, and consider dbclnr_switch_autoloaded_option instead when the goal is only to make the site faster.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'option_names' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Option names, 100 maximum per call.' ),
					'confirm' => $confirm,
				),
				'required' => array( 'option_names', 'confirm' ),
			),
		);

		#endregion

		#region Cron jobs

		$tools[] = array(
			'name' => 'dbclnr_list_crons',
			'description' => 'List the scheduled cron jobs with their schedule, their next run and, when known, the plugin that registered them. A job whose status is warn was registered by something no longer installed: it will never run and is a leftover.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'all', 'ok', 'warn', 'n/a' ),
						'description' => 'Only return the crons with this status. Defaults to all.',
					),
					'search' => array( 'type' => 'string', 'description' => 'Filter by cron name.' ),
				),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_delete_crons',
			'description' => 'PERMANENTLY unschedule cron jobs. WordPress own jobs are always refused, and jobs owned by an installed plugin are refused unless the user turned on "Protected Items > Enable Deletion". Note that a plugin will simply reschedule its job on the next page load, so deleting a job of an active plugin achieves nothing.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'crons' => array(
						'type' => 'array',
						'description' => 'The jobs to unschedule, as returned by dbclnr_list_crons. 100 maximum per call.',
						'items' => array(
							'type' => 'object',
							'properties' => array(
								'name' => array( 'type' => 'string', 'description' => 'The cron hook name.' ),
								'args' => array( 'type' => 'array', 'items' => array(), 'description' => 'The arguments of that specific job, exactly as returned by dbclnr_list_crons.' ),
							),
							'required' => array( 'name' ),
						),
					),
					'confirm' => $confirm,
				),
				'required' => array( 'crons', 'confirm' ),
			),
		);

		#endregion

		#region Metadata

		$tools[] = array(
			'name' => 'dbclnr_list_metadata',
			'description' => 'List the post meta or user meta rows, biggest first, with the plugin that owns each meta key when it is known. Useful to find the meta keys that actually take up the space.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'table' => array( 'type' => 'string', 'description' => 'The metadata table, as returned by dbclnr_get_capabilities. Include the database prefix.' ),
					'search' => array( 'type' => 'string', 'description' => 'Filter by meta key.' ),
					'limit' => array( 'type' => 'integer', 'description' => 'How many rows to return, 1 to 100. Defaults to 25.' ),
					'skip' => array( 'type' => 'integer', 'description' => 'How many rows to skip, for paging.' ),
				),
				'required' => array( 'table' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_delete_metadata',
			'description' => 'PERMANENTLY delete metadata rows by id. Meta keys used by WordPress are always refused, and keys owned by an installed plugin are refused unless the user turned on "Protected Items > Enable Deletion". A meta key you do not recognise usually belongs to a theme or to custom code: that is a reason to leave it alone, not to delete it.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'table' => array( 'type' => 'string', 'description' => 'The metadata table, including the database prefix.' ),
					'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Row ids (meta_id or umeta_id) as returned by dbclnr_list_metadata. 100 maximum per call.' ),
					'confirm' => $confirm,
				),
				'required' => array( 'table', 'ids', 'confirm' ),
			),
		);

		#endregion

		#region Custom queries

		$tools[] = array(
			'name' => 'dbclnr_list_custom_queries',
			'description' => 'List the custom queries the user wrote in the settings, with their SQL. These are the user own queries: Database Cleaner does not know what they do, and neither do you until you read them.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array( 'type' => 'object' ),
		);

		$tools[] = array(
			'name' => 'dbclnr_run_custom_query_count',
			'description' => 'Run the count query of a custom query and return how many rows it matches. This only counts, it deletes nothing.',
			'category' => $category,
			'accessLevel' => 'read',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'item' => array( 'type' => 'string', 'description' => 'The custom query item key, as returned by dbclnr_list_custom_queries.' ),
				),
				'required' => array( 'item' ),
			),
		);

		$tools[] = array(
			'name' => 'dbclnr_run_custom_query_delete',
			'description' => 'PERMANENTLY run the delete query of a custom query. Only the queries the user already saved in the settings can be run: arbitrary SQL is refused. Read the SQL back to the user and get their confirmation first, because only they know what it is meant to do.',
			'category' => $category,
			'accessLevel' => 'admin',
			'inputSchema' => array(
				'type' => 'object',
				'properties' => array(
					'item' => array( 'type' => 'string', 'description' => 'The custom query item key, as returned by dbclnr_list_custom_queries.' ),
					'confirm' => $confirm,
				),
				'required' => array( 'item', 'confirm' ),
			),
		);

		#endregion

		return $tools;
	}

	#endregion

	#region Execution

	public function handle_tool_execution( $result, $tool, $args, $id ) {
		if ( strpos( (string) $tool, 'dbclnr_' ) !== 0 ) {
			return $result;
		}
		$args = is_array( $args ) ? $args : array();
		try {
			switch ( $tool ) {
				case 'dbclnr_get_capabilities': return $this->tool_get_capabilities();
				case 'dbclnr_get_status': return $this->tool_get_status();
				case 'dbclnr_get_counts': return $this->tool_get_counts( $args );
				case 'dbclnr_preview_entries': return $this->tool_preview_entries( $args );
				case 'dbclnr_delete_entries': return $this->tool_delete_entries( $args );
				case 'dbclnr_list_tables': return $this->tool_list_tables( $args );
				case 'dbclnr_preview_table': return $this->tool_preview_table( $args );
				case 'dbclnr_delete_tables': return $this->tool_delete_tables( $args );
				case 'dbclnr_optimize_tables': return $this->tool_optimize_tables( $args );
				case 'dbclnr_list_options': return $this->tool_list_options( $args );
				case 'dbclnr_get_option_value': return $this->tool_get_option_value( $args );
				case 'dbclnr_switch_autoloaded_option': return $this->tool_switch_autoloaded_option( $args );
				case 'dbclnr_delete_options': return $this->tool_delete_options( $args );
				case 'dbclnr_list_crons': return $this->tool_list_crons( $args );
				case 'dbclnr_delete_crons': return $this->tool_delete_crons( $args );
				case 'dbclnr_list_metadata': return $this->tool_list_metadata( $args );
				case 'dbclnr_delete_metadata': return $this->tool_delete_metadata( $args );
				case 'dbclnr_list_custom_queries': return $this->tool_list_custom_queries();
				case 'dbclnr_run_custom_query_count': return $this->tool_run_custom_query( $args, false );
				case 'dbclnr_run_custom_query_delete': return $this->tool_run_custom_query( $args, true );
			}
			return $result;
		}
		catch ( Throwable $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	#endregion

	#region Guards

	private function assert_confirmed( $args ) {
		$confirm = isset( $args['confirm'] ) ? (string) $args['confirm'] : '';
		if ( $confirm !== 'PERMANENT' ) {
			throw new RuntimeException(
				'Deletion refused: confirm must be exactly PERMANENT, and only after the user explicitly asked for it, knowing that Database Cleaner has no trash and that nothing can be restored without a backup.'
			);
		}
	}

	private function read_list( $args, $key ) {
		$list = isset( $args[ $key ] ) ? (array) $args[ $key ] : array();
		$list = array_values( array_filter( $list ) );
		if ( empty( $list ) ) {
			throw new RuntimeException( sprintf( '%s is required and must contain at least one entry.', $key ) );
		}
		if ( count( $list ) > self::MAX_ITEMS ) {
			throw new RuntimeException( sprintf( 'Too many items: %d. Send %d at most per call.', count( $list ), self::MAX_ITEMS ) );
		}
		return $list;
	}

	private function protected_deletion_allowed() {
		return (bool) $this->core->get_option( 'enable_protected_deletion' );
	}

	private function info_for( $kind, $name ) {
		$info = apply_filters( "dbclnr_check_{$kind}_info", $name, null );
		return array(
			'status' => isset( $info['status'] ) ? $info['status'] : 'n/a',
			'used_by' => isset( $info['usedBy'] ) && $info['usedBy'] !== '' ? $info['usedBy'] : null,
		);
	}

	private function is_wordpress_owned( $info ) {
		return $info['used_by'] !== null && strtolower( $info['used_by'] ) === 'wordpress';
	}

	// A table, option, cron or meta key is protected when WordPress or an installed
	// plugin still owns it. Only the "warn" status (owner gone) is a genuine leftover.
	private function guard_deletable( $kind, $name, $info ) {
		if ( $this->is_wordpress_owned( $info ) ) {
			return 'Used by WordPress itself. Database Cleaner never deletes this, whatever the settings say.';
		}
		if ( $info['status'] === 'ok' && !$this->protected_deletion_allowed() ) {
			return sprintf(
				'Protected: still used by %s, which is installed and active. The user can allow this in Settings > Protected Items > Enable Deletion, but they should be sure first.',
				$info['used_by']
			);
		}
		return null;
	}

	private function is_core_table( $table ) {
		$short = $table;
		if ( strpos( $short, $this->core->prefix ) === 0 ) {
			$short = substr( $short, strlen( $this->core->prefix ) );
		}
		return in_array( $short, Meow_DBCLNR_Support::$core_tables, true );
	}

	#endregion

	#region Discovery

	private function tool_get_capabilities() {
		$is_pro = class_exists( 'MeowPro_DBCLNR_Core' );
		$age_threshold = $this->core->get_option( 'aga_threshold' );
		$expert = $this->core->get_option( 'mode' ) === 'expert';

		$items = array();
		foreach ( $this->item_catalogue() as $item ) {
			$items[] = array(
				'item' => $item['item'],
				'name' => $item['name'],
				'clean_style' => $item['clean_style'],
				'deletable' => $item['clean_style'] !== 'never',
				'info' => isset( $item['info'] ) ? $item['info'] : null,
			);
		}

		$limitations = array(
			'Everything Database Cleaner deletes is deleted permanently and immediately. There is no trash, and no tool here can undo anything.',
			'The age threshold is currently "' . $age_threshold . '": entries more recent than that are never counted nor deleted, so a count of 0 does not mean there is nothing there.',
			'Database Cleaner only knows the plugins it has a support list for. A table, option, cron or meta key it never heard of is reported as "n/a": that means unknown, not unused. Themes and custom code are almost never in that list.',
			'A "warn" status means the owner is not installed anymore. That is a good hint, but a deactivated plugin the user intends to reactivate looks exactly the same.',
		);
		if ( !$this->protected_deletion_allowed() ) {
			$limitations[] = 'Protected items (owned by WordPress or by an installed plugin) cannot be deleted right now, which is the safe default. Report the refusal to the user rather than trying to work around it.';
		}
		else {
			$limitations[] = 'WARNING: "Protected Items > Enable Deletion" is ON, so items owned by installed plugins can be deleted. Only WordPress own tables, options, crons and meta keys are still refused. Be much more careful than usual.';
		}
		if ( !$expert ) {
			$limitations[] = 'The plugin is in Easy mode. The tables, options, cron jobs, metadata and custom queries tools still work, but the user does not see those sections in their dashboard, so explain what you did rather than pointing at a tab.';
		}
		if ( $this->core->get_option( 'deep_deletions' ) ) {
			$limitations[] = 'Deep deletions are ON: posts are removed with the WordPress API instead of raw SQL, so the plugins hooked on the deletion also clean up. It is safer but much slower, and each batch takes longer.';
		}

		return array(
			'success' => true,
			'is_pro' => $is_pro,
			'is_registered' => (bool) $this->admin->is_registered(),
			'mode' => $expert ? 'expert' : 'easy',
			'age_threshold' => $age_threshold,
			'bulk_batch_size' => (int) $this->core->get_option( 'bulk_batch_size' ),
			'deep_deletions' => (bool) $this->core->get_option( 'deep_deletions' ),
			'protected_deletion_enabled' => $this->protected_deletion_allowed(),
			'modules_enabled' => array(
				'post_types' => (bool) $this->core->get_option( 'module_posttypes' ),
				'tables' => (bool) $this->core->get_option( 'module_tables' ),
				'options' => (bool) $this->core->get_option( 'module_options' ),
				'metadata' => (bool) $this->core->get_option( 'module_metadata' ),
				'cron_jobs' => (bool) $this->core->get_option( 'module_cronjobs' ),
				'custom_queries' => (bool) $this->core->get_option( 'module_customequeries' ),
			),
			'metadata_tables' => array_values( $this->core->get_metadata_tables() ),
			'items' => $items,
			'limitations' => $limitations,
			'protections' => array(
				'WordPress core tables can never be dropped, whatever the settings say.',
				'Tables, options, crons and meta keys used by WordPress are never deleted.',
				'Items whose clean style is "never" are never deleted.',
				'Every destructive tool requires confirm to be exactly PERMANENT.',
			),
			'how_to_be_safe' => array(
				'Ask the user for a database backup before any deletion. This is not a formality here: nothing is recoverable without one.',
				'Preview before deleting: dbclnr_preview_entries, dbclnr_preview_table, dbclnr_get_option_value.',
				'Start with what is unambiguous (revisions, expired transients, orphaned metadata) and leave anything with an unknown owner alone.',
				'For a large autoloaded option, prefer dbclnr_switch_autoloaded_option over deleting it.',
			),
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function item_catalogue( $include_post_types = true ) {
		$groups = array(
			Meow_DBCLNR_Items::$POSTS,
			Meow_DBCLNR_Items::$POSTS_METADATA,
			Meow_DBCLNR_Items::$USERS,
			Meow_DBCLNR_Items::$COMMENTS,
			Meow_DBCLNR_Items::$TRANSIENTS,
		);
		$items = array();
		foreach ( $groups as $group ) {
			$items = array_merge( $items, $this->core->add_clean_style_data( $group ) );
		}
		if ( $include_post_types ) {
			$list = array();
			foreach ( $this->core->get_post_types() as $post_type ) {
				$list[] = array( 'item' => 'list_post_types_' . $post_type, 'name' => $post_type );
			}
			$items = array_merge( $items, $this->core->add_clean_style_data( $list ) );
		}
		return $items;
	}

	private function tool_get_status() {
		$sizes = $this->core->get_tables_size();
		$history = $this->core->get_option( 'db_historical_sizes' );
		$sweeper_tasks = $this->core->get_option( 'sweeper_tasks' );

		$biggest = array();
		foreach ( array_slice( $sizes, 0, 10 ) as $row ) {
			$biggest[] = array(
				'table' => $row['table'],
				'size_mb' => (float) $row['size'],
				'percent_of_database' => (float) $row['percent'],
			);
		}

		return array(
			'success' => true,
			'database_size_mb' => $this->core->get_database_size(),
			'previous_size_mb' => $this->core->get_yesterday_database_size(),
			'tables_count' => count( $sizes ),
			'biggest_tables' => $biggest,
			'history' => is_array( $history ) ? array_slice( $history, -10 ) : array(),
			'sweeper' => array(
				'enabled' => (bool) $this->core->get_option( 'sweeper_enabled' ),
				'schedule' => $this->core->get_option( 'sweeper_schedule' ),
				'status' => isset( $sweeper_tasks['status'] ) ? $sweeper_tasks['status'] : null,
				'last_execution' => isset( $sweeper_tasks['last_execution'] ) ? $sweeper_tasks['last_execution'] : null,
			),
			'note' => 'The size is what the tables occupy on disk. Deleting rows does not shrink it immediately: the space is only reclaimed by dbclnr_optimize_tables.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	#endregion

	#region Core cleanup

	private function tool_get_counts( $args ) {
		$include_post_types = isset( $args['include_post_types'] ) ? rest_sanitize_boolean( $args['include_post_types'] ) : false;
		$wanted = isset( $args['items'] ) ? array_map( 'sanitize_text_field', (array) $args['items'] ) : null;
		$catalogue = $this->item_catalogue( $include_post_types || !empty( $wanted ) );

		$counts = array();
		$unknown = array();
		foreach ( $catalogue as $item ) {
			if ( $wanted !== null && !in_array( $item['item'], $wanted, true ) ) {
				continue;
			}
			$count = $this->core->get_entry_count( $item['item'] );
			if ( $count === false ) {
				continue;
			}
			$counts[] = array(
				'item' => $item['item'],
				'name' => $item['name'],
				'count' => (int) $count,
				'clean_style' => $item['clean_style'],
				'deletable' => $item['clean_style'] !== 'never',
			);
		}
		if ( $wanted !== null ) {
			$found = array_column( $counts, 'item' );
			$unknown = array_values( array_diff( $wanted, $found ) );
		}

		return array(
			'success' => true,
			'age_threshold' => $this->core->get_option( 'aga_threshold' ),
			'counts' => $counts,
			'unknown_items' => $unknown,
			'note' => 'These counts only include entries older than the age threshold. Items whose clean style is "never" are listed but cannot be deleted.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_preview_entries( $args ) {
		$item = isset( $args['item'] ) ? sanitize_text_field( $args['item'] ) : '';
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		if ( $item === '' ) {
			return array( 'success' => false, 'error' => 'item is required.' );
		}
		$age_threshold = $this->core->get_option( 'aga_threshold' );
		$age_threshold = $age_threshold === 'none' ? 0 : $age_threshold;

		if ( array_key_exists( $item, Meow_DBCLNR_Queries::$GET ) ) {
			// The query classes register themselves on the filters from their constructor,
			// so this has to be instantiated before the static call can resolve anything.
			$queries = new Meow_DBCLNR_Queries();
			$rows = $queries->query_get( $item, $offset, $age_threshold );
		}
		else {
			$param = $this->core->get_item_param( $item );
			if ( !$param ) {
				return array( 'success' => false, 'error' => 'This item does not exist. Call dbclnr_get_capabilities for the list.' );
			}
			$rows = $this->core->get_entries( $param['value'], null, $age_threshold, $offset );
		}

		return array(
			'success' => true,
			'item' => $item,
			'name' => Meow_DBCLNR_Items::getName( $item ) ?? $item,
			'returned' => is_array( $rows ) ? count( $rows ) : 0,
			'rows' => $this->truncate_rows( $rows ),
			'note' => 'This is a sample, not the full list. Show the user what these rows actually are before proposing to delete them.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_delete_entries( $args ) {
		$item = isset( $args['item'] ) ? sanitize_text_field( $args['item'] ) : '';
		if ( $item === '' ) {
			return array( 'success' => false, 'error' => 'item is required.' );
		}
		$this->assert_confirmed( $args );
		// Checked before valid_item_operation(), which reads "{item}_clean_style" straight
		// out of the options and would warn on an item that does not exist.
		if ( !in_array( $item, array_column( $this->item_catalogue(), 'item' ), true ) ) {
			return array( 'success' => false, 'error' => 'This item does not exist. Call dbclnr_get_capabilities for the list.' );
		}
		if ( !$this->admin->valid_item_operation( $item ) ) {
			return array(
				'success' => false,
				'error' => 'This item cannot be deleted: its clean style is set to "never" in the settings. The user chose that on purpose.',
			);
		}
		$name = Meow_DBCLNR_Items::getName( $item ) ?? $item;
		$deleted = $this->core->delete_entries( $item );
		$batch = (int) $this->core->get_option( 'bulk_batch_size' );
		$finished = $deleted < $batch;
		$this->core->log( "✅ Cleaned '{$name}' (MCP)" );

		return array(
			'success' => true,
			'item' => $item,
			'name' => $name,
			'deleted' => (int) $deleted,
			'finished' => $finished,
			'next_action' => $finished
				? 'This item is clean. The disk space is only reclaimed by dbclnr_optimize_tables.'
				: 'One batch was deleted and more remain. Call dbclnr_delete_entries again with the same item.',
			'reversible' => false,
			'warning' => 'These entries are gone. Only a backup can bring them back.',
		);
	}

	#endregion

	#region Tables

	private function tool_list_tables( $args ) {
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'all';
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_ITEMS, (int) $args['limit'] ) ) : 50;
		$skip = isset( $args['skip'] ) ? max( 0, (int) $args['skip'] ) : 0;

		$rows = array();
		foreach ( $this->core->get_tables_size() as $row ) {
			$info = $this->info_for( 'table', $row['table'] );
			if ( $status !== 'all' && $info['status'] !== $status ) {
				continue;
			}
			if ( $search !== '' && stripos( $row['table'], $search ) === false ) {
				continue;
			}
			$rows[] = array(
				'table' => $row['table'],
				'size_mb' => (float) $row['size'],
				'percent_of_database' => (float) $row['percent'],
				'status' => $info['status'],
				'used_by' => $info['used_by'],
				'is_wordpress_core' => $this->is_core_table( $row['table'] ),
				'blocked_because' => $this->is_core_table( $row['table'] )
					? 'WordPress core table, never deletable.'
					: $this->guard_deletable( 'table', $row['table'], $info ),
			);
		}
		$total = count( $rows );

		return array(
			'success' => true,
			'total' => $total,
			'returned' => count( array_slice( $rows, $skip, $limit ) ),
			'tables' => array_values( array_slice( $rows, $skip, $limit ) ),
			'advice' => 'Only "warn" tables are likely leftovers. An "n/a" table is one Database Cleaner has no information about: look inside it with dbclnr_preview_table and ask the user, never assume it is junk.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_preview_table( $args ) {
		$table = isset( $args['table'] ) ? sanitize_text_field( $args['table'] ) : '';
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		if ( !$this->admin->valid_table_name( $table ) ) {
			return array( 'success' => false, 'error' => 'This table does not exist in the database.' );
		}
		$info = $this->info_for( 'table', $table );
		return array(
			'success' => true,
			'table' => $table,
			'status' => $info['status'],
			'used_by' => $info['used_by'],
			'total_rows' => (int) $this->core->get_table_data_count( $table ),
			'rows' => $this->truncate_rows( $this->core->get_table_data( $table, $offset ) ),
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_delete_tables( $args ) {
		$this->assert_confirmed( $args );
		$tables = $this->read_list( $args, 'tables' );

		$results = array();
		$done = 0;
		foreach ( $tables as $table ) {
			$table = sanitize_text_field( $table );
			$error = null;
			if ( !$this->admin->valid_table_name( $table ) ) {
				$error = 'This table does not exist in the database.';
			}
			// Checked here rather than through Meow_DBCLNR_Admin::valid_deletable_table_name(),
			// which prepends the prefix to an already prefixed name and therefore never
			// recognises a core table.
			else if ( $this->is_core_table( $table ) ) {
				$error = 'This is a WordPress core table. Dropping it would destroy the site, so it is never allowed.';
			}
			else {
				$error = $this->guard_deletable( 'table', $table, $this->info_for( 'table', $table ) );
			}
			if ( $error !== null ) {
				$results[] = array( 'table' => $table, 'success' => false, 'error' => $error );
				continue;
			}
			$result = $this->admin->delete_table( $table );
			$ok = $result !== false;
			if ( $ok ) {
				$done++;
				$this->core->log( "✅ Deleted table '{$table}' (MCP)" );
			}
			$results[] = array( 'table' => $table, 'success' => $ok, 'error' => $ok ? null : 'The table could not be dropped, the detail is in the PHP error logs.' );
		}

		return array(
			'success' => $done === count( $tables ),
			'dropped' => $done,
			'failed' => count( $tables ) - $done,
			'results' => $results,
			'reversible' => false,
			'warning' => 'The dropped tables and all their data are gone. Only a backup can bring them back.',
		);
	}

	private function tool_optimize_tables( $args ) {
		$tables = $this->read_list( $args, 'tables' );
		$results = array();
		$done = 0;
		foreach ( $tables as $table ) {
			$table = sanitize_text_field( $table );
			if ( !$this->admin->valid_table_name( $table ) ) {
				$results[] = array( 'table' => $table, 'success' => false, 'error' => 'This table does not exist in the database.' );
				continue;
			}
			$result = $this->admin->optimize_table( $table );
			$ok = $result !== false;
			if ( $ok ) {
				$done++;
				$this->core->log( "✅ Optimized table '{$table}' (MCP)" );
			}
			$results[] = array( 'table' => $table, 'success' => $ok, 'error' => $ok ? null : 'The table could not be optimized, the detail is in the PHP error logs.' );
		}
		return array(
			'success' => $done === count( $tables ),
			'optimized' => $done,
			'failed' => count( $tables ) - $done,
			'results' => $results,
			'nothing_deleted' => true,
			'database_size_mb' => $this->core->get_database_size(),
		);
	}

	#endregion

	#region Options

	private function tool_list_options( $args ) {
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'all';
		$autoload = isset( $args['autoload'] ) ? sanitize_text_field( $args['autoload'] ) : 'all';
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_ITEMS, (int) $args['limit'] ) ) : 50;
		$skip = isset( $args['skip'] ) ? max( 0, (int) $args['skip'] ) : 0;

		$rows = array();
		foreach ( $this->admin->get_options() as $row ) {
			// The options table stores on/off on recent WordPress, yes/no before.
			$is_autoloaded = in_array( $row['autoload'], array( 'yes', 'on' ), true );
			if ( $autoload !== 'all' && $is_autoloaded !== ( $autoload === 'yes' ) ) {
				continue;
			}
			$info = $this->info_for( 'option', $row['option_name'] );
			if ( $status !== 'all' && $info['status'] !== $status ) {
				continue;
			}
			if ( $search !== '' && stripos( $row['option_name'], $search ) === false ) {
				continue;
			}
			$rows[] = array(
				'option_name' => $row['option_name'],
				'size_bytes' => (int) $row['option_value_length'],
				'autoloaded' => $is_autoloaded,
				'status' => $info['status'],
				'used_by' => $info['used_by'],
				'blocked_because' => $this->guard_deletable( 'option', $row['option_name'], $info ),
			);
		}
		$total = count( $rows );

		return array(
			'success' => true,
			'total' => $total,
			'returned' => count( array_slice( $rows, $skip, $limit ) ),
			'options' => array_values( array_slice( $rows, $skip, $limit ) ),
			'advice' => 'The options are sorted biggest first. A big autoloaded option costs time on every single page load: switching its autoload off with dbclnr_switch_autoloaded_option fixes that without deleting anything.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_get_option_value( $args ) {
		$option_name = isset( $args['option_name'] ) ? sanitize_text_field( $args['option_name'] ) : '';
		if ( $option_name === '' ) {
			return array( 'success' => false, 'error' => 'option_name is required.' );
		}
		$value = get_option( $option_name );
		if ( $value === false ) {
			return array( 'success' => false, 'error' => 'This option does not exist.' );
		}
		$info = $this->info_for( 'option', $option_name );
		$serialized = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		$truncated = strlen( $serialized ) > 4000;

		return array(
			'success' => true,
			'option_name' => $option_name,
			'status' => $info['status'],
			'used_by' => $info['used_by'],
			'size_bytes' => strlen( $serialized ),
			'value' => $truncated ? substr( $serialized, 0, 4000 ) : $serialized,
			'truncated' => $truncated,
			'blocked_because' => $this->guard_deletable( 'option', $option_name, $info ),
		);
	}

	private function tool_switch_autoloaded_option( $args ) {
		$option_name = isset( $args['option_name'] ) ? sanitize_text_field( $args['option_name'] ) : '';
		$autoload = isset( $args['autoload'] ) ? sanitize_text_field( $args['autoload'] ) : '';
		if ( $option_name === '' || !in_array( $autoload, array( 'yes', 'no' ), true ) ) {
			return array( 'success' => false, 'error' => 'option_name is required, and autoload must be yes or no.' );
		}
		if ( get_option( $option_name ) === false ) {
			return array( 'success' => false, 'error' => 'This option does not exist.' );
		}
		$this->admin->switch_autoloaded_option( $option_name, $autoload );
		$this->core->log( "✅ Switched autoload of '{$option_name}' to '{$autoload}' (MCP)" );

		return array(
			'success' => true,
			'option_name' => $option_name,
			'autoloaded' => $autoload === 'yes',
			'nothing_deleted' => true,
			'reversible' => true,
			'how_to_undo' => 'Call dbclnr_switch_autoloaded_option again with the previous value. The option value itself was never touched.',
			'note' => 'If the site misbehaves after this, switch it back: some plugins read their options very early and expect them autoloaded.',
		);
	}

	private function tool_delete_options( $args ) {
		$this->assert_confirmed( $args );
		$option_names = $this->read_list( $args, 'option_names' );

		$results = array();
		$deletable = array();
		foreach ( $option_names as $option_name ) {
			$option_name = sanitize_text_field( $option_name );
			$error = $this->guard_deletable( 'option', $option_name, $this->info_for( 'option', $option_name ) );
			if ( $error !== null ) {
				$results[] = array( 'option_name' => $option_name, 'success' => false, 'error' => $error );
				continue;
			}
			$deletable[] = $option_name;
		}

		$deleted = 0;
		if ( !empty( $deletable ) ) {
			$deleted = (int) $this->admin->delete_options( $deletable );
			foreach ( $deletable as $option_name ) {
				$this->core->log( "✅ Deleted option '{$option_name}' (MCP)" );
				$results[] = array( 'option_name' => $option_name, 'success' => true, 'error' => null );
			}
		}

		return array(
			'success' => $deleted === count( $option_names ),
			'deleted' => $deleted,
			'refused' => count( $option_names ) - count( $deletable ),
			'results' => $results,
			'reversible' => false,
			'warning' => 'These options are gone. Only a backup can bring them back.',
		);
	}

	#endregion

	#region Cron jobs

	private function tool_list_crons( $args ) {
		$status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'all';
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';

		$rows = array();
		foreach ( $this->core->format_cron_info( (array) get_option( 'cron' ) ) as $job ) {
			$info = $this->info_for( 'cron', $job['cron_name'] );
			if ( $status !== 'all' && $info['status'] !== $status ) {
				continue;
			}
			if ( $search !== '' && stripos( $job['cron_name'], $search ) === false ) {
				continue;
			}
			$rows[] = array(
				'name' => $job['cron_name'],
				'args' => $job['args'],
				'schedule' => isset( $job['schedule'] ) && $job['schedule'] ? $job['schedule'] : 'once',
				'next_run' => gmdate( 'Y-m-d H:i:s', (int) $job['unixtime'] ) . ' UTC',
				'status' => $info['status'],
				'used_by' => $info['used_by'],
				'blocked_because' => $this->guard_deletable( 'cron', $job['cron_name'], $info ),
			);
		}

		return array(
			'success' => true,
			'total' => count( $rows ),
			'crons' => $rows,
			'advice' => 'Pass the name AND the args back verbatim to dbclnr_delete_crons: the same hook can be scheduled several times with different arguments, and only the matching one is removed.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_delete_crons( $args ) {
		$this->assert_confirmed( $args );
		$crons = $this->read_list( $args, 'crons' );

		$results = array();
		$done = 0;
		foreach ( $crons as $cron ) {
			$name = isset( $cron['name'] ) ? sanitize_text_field( $cron['name'] ) : '';
			$cron_args = isset( $cron['args'] ) ? (array) $cron['args'] : array();
			if ( $name === '' ) {
				$results[] = array( 'name' => null, 'success' => false, 'error' => 'Each cron needs a name.' );
				continue;
			}
			$error = $this->guard_deletable( 'cron', $name, $this->info_for( 'cron', $name ) );
			if ( $error !== null ) {
				$results[] = array( 'name' => $name, 'success' => false, 'error' => $error );
				continue;
			}
			$result = $this->core->remove_cron_entry( $name, $cron_args );
			$ok = $result !== false;
			if ( $ok ) {
				$done++;
				$this->core->log( "✅ Deleted cron '{$name}' (MCP)" );
			}
			$results[] = array( 'name' => $name, 'success' => $ok, 'error' => $ok ? null : 'The job could not be unscheduled. The args may not match any scheduled job.' );
		}

		return array(
			'success' => $done === count( $crons ),
			'deleted' => $done,
			'failed' => count( $crons ) - $done,
			'results' => $results,
			'reversible' => false,
			'note' => 'An active plugin reschedules its own jobs on the next page load, so a job coming back is normal and not an error.',
		);
	}

	#endregion

	#region Metadata

	private function tool_list_metadata( $args ) {
		$table = isset( $args['table'] ) ? sanitize_text_field( $args['table'] ) : '';
		$search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( self::MAX_ITEMS, (int) $args['limit'] ) ) : 25;
		$skip = isset( $args['skip'] ) ? max( 0, (int) $args['skip'] ) : 0;
		if ( !$this->admin->valid_metadata_table( $table ) ) {
			return array(
				'success' => false,
				'error' => 'Invalid metadata table. Use one of the metadata_tables returned by dbclnr_get_capabilities.',
			);
		}
		$search_list = $search === '' ? array() : array( $search );
		$total = (int) $this->admin->get_metadata_count( $table, null, $search_list );
		$rows = $this->admin->get_metadata( $table, null, 'meta_value_length', 'desc', $skip, $limit, $search_list );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$info = $this->info_for( 'metadata', $row['meta_key'] );
			$items[] = array(
				// The queries alias meta_id and umeta_id to a plain "id" column.
				'id' => (int) $row['id'],
				'meta_key' => $row['meta_key'],
				'size_bytes' => isset( $row['meta_value_length'] ) ? (int) $row['meta_value_length'] : null,
				'preview' => isset( $row['meta_value_preview'] ) ? $row['meta_value_preview'] : null,
				'status' => $info['status'],
				'used_by' => $info['used_by'],
				'blocked_because' => $this->guard_deletable( 'metadata', $row['meta_key'], $info ),
			);
		}

		return array(
			'success' => true,
			'table' => $table,
			'total' => $total,
			'returned' => count( $items ),
			'rows' => $items,
			'advice' => 'Most meta keys have no known owner. That is expected: themes and custom fields are not in the support list. Unknown means unknown, so ask the user rather than deleting.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_delete_metadata( $args ) {
		$this->assert_confirmed( $args );
		$table = isset( $args['table'] ) ? sanitize_text_field( $args['table'] ) : '';
		$ids = $this->read_list( $args, 'ids' );
		if ( !$this->admin->valid_metadata_table( $table ) ) {
			return array( 'success' => false, 'error' => 'Invalid metadata table.' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array( 'success' => false, 'error' => 'ids must contain at least one valid id.' );
		}

		// The keys are re-read here rather than trusted from the arguments, so a row
		// that became protected since it was listed is still refused.
		$rows = $this->admin->get_metadata( $table, $ids, 'meta_value_length', 'desc', 0, count( $ids ) );
		$deletable = array();
		$refused = array();
		foreach ( (array) $rows as $row ) {
			$row = (array) $row;
			$id = (int) $row['id'];
			$error = $this->guard_deletable( 'metadata', $row['meta_key'], $this->info_for( 'metadata', $row['meta_key'] ) );
			if ( $error !== null ) {
				$refused[] = array( 'id' => $id, 'meta_key' => $row['meta_key'], 'error' => $error );
				continue;
			}
			$deletable[] = $id;
		}

		$deleted = 0;
		if ( !empty( $deletable ) ) {
			$deleted = (int) $this->admin->delete_metadata( $table, $deletable );
			$this->core->log( "✅ Deleted {$deleted} metadata rows from '{$table}' (MCP)" );
		}

		return array(
			'success' => $deleted === count( $ids ),
			'deleted' => $deleted,
			'refused' => $refused,
			'reversible' => false,
			'warning' => 'These metadata rows are gone. Only a backup can bring them back.',
		);
	}

	#endregion

	#region Custom queries

	private function tool_list_custom_queries() {
		$queries = array();
		foreach ( (array) $this->core->get_option( 'custom_queries' ) as $custom_query ) {
			$queries[] = array(
				'item' => isset( $custom_query['item'] ) ? $custom_query['item'] : null,
				'name' => isset( $custom_query['name'] ) ? $custom_query['name'] : null,
				'clean_style' => isset( $custom_query['clean_style'] ) ? $custom_query['clean_style'] : null,
				'query_count' => isset( $custom_query['query_count'] ) ? $custom_query['query_count'] : null,
				'query_delete' => isset( $custom_query['query_delete'] ) ? $custom_query['query_delete'] : null,
			);
		}
		return array(
			'success' => true,
			'total' => count( $queries ),
			'custom_queries' => $queries,
			'note' => 'These queries were written by the user, not by Database Cleaner. Read the SQL before running anything: only the user knows what it is supposed to do.',
			'warning' => self::SAFETY_NOTE,
		);
	}

	private function tool_run_custom_query( $args, $delete ) {
		$item = isset( $args['item'] ) ? sanitize_text_field( $args['item'] ) : '';
		if ( $item === '' ) {
			return array( 'success' => false, 'error' => 'item is required.' );
		}
		if ( $delete ) {
			$this->assert_confirmed( $args );
		}

		$found = null;
		foreach ( (array) $this->core->get_option( 'custom_queries' ) as $custom_query ) {
			if ( isset( $custom_query['item'] ) && $custom_query['item'] === $item ) {
				$found = $custom_query;
				break;
			}
		}
		if ( !$found ) {
			return array( 'success' => false, 'error' => 'This custom query does not exist. Call dbclnr_list_custom_queries for the list.' );
		}
		// Only the SQL saved in the settings can run: the tools never accept a query
		// as an argument, so an assistant cannot execute arbitrary SQL through them.
		$query = $delete ? $found['query_delete'] : $found['query_count'];
		if ( empty( $query ) ) {
			return array( 'success' => false, 'error' => 'This custom query has no ' . ( $delete ? 'delete' : 'count' ) . ' query saved.' );
		}
		if ( $delete && !$this->admin->valid_custom_query_operation( $found['clean_style'] ) ) {
			return array( 'success' => false, 'error' => 'This custom query cannot be run: its clean style is set to "never" in the settings.' );
		}

		if ( !$delete ) {
			return array(
				'success' => true,
				'item' => $item,
				'name' => $found['name'],
				'count' => (int) $this->core->do_custom_query_count( $query ),
				'query' => $query,
				'nothing_deleted' => true,
			);
		}

		$deleted = (int) $this->core->do_custom_query_delete( $query );
		$this->core->log( "✅ {$found['name']}: deleted {$deleted} entries (MCP)" );
		return array(
			'success' => true,
			'item' => $item,
			'name' => $found['name'],
			'deleted' => $deleted,
			'query' => $query,
			'reversible' => false,
			'warning' => 'Whatever that query deleted is gone. Only a backup can bring it back.',
		);
	}

	#endregion

	#region Helpers

	// The rows can hold whole post contents or serialized blobs, which would flood
	// the context for no benefit: the assistant only needs to recognise the rows.
	private function truncate_rows( $rows ) {
		$data = array();
		foreach ( (array) $rows as $row ) {
			$clean = array();
			foreach ( (array) $row as $key => $value ) {
				if ( is_string( $value ) && strlen( $value ) > 200 ) {
					$value = substr( $value, 0, 200 ) . '… [truncated]';
				}
				$clean[ $key ] = $value;
			}
			$data[] = $clean;
		}
		return $data;
	}

	#endregion
}
