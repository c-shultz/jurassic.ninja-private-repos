<?php

namespace jn_pr;
/*
 * Plugin Name: Jurassic Ninja Private Repos
 * Description: Allows private repos to be added to Jurassic Ninja sites.
 * Version: 0.1
 * Author: c-shultz
 **/


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'JN_PRIVATE_REPOS_ABSPATH' ) ) {
	define( 'JN_PRIVATE_REPOS_ABSPATH', dirname( __FILE__ ) . '/' );
}

function init() {
	// Add list of repo data from request paramters to features data.
	add_filter( 'jurassic_ninja_rest_create_request_features', 'jn_pr\add_private_repos_features', 10, 2);

	// Download repos to local server and push them to new site.
	add_action( 'jurassic_ninja_create_app', 'jn_pr\transfer_private_repos', 999, 6 );
}

function admin_init() {
	add_filter( 'jurassic_ninja_settings_options_page', 'jn_pr\plugin_settings', 1 );
}

add_action( 'jurassic_ninja_init', 'jn_pr\init', 10, 2 );
add_action( 'jurassic_ninja_admin_init', 'jn_pr\admin_init', 10, 2 );

/**
 * Add private repo settings to JN.
 *
 * @return array Array of options
 */
function plugin_settings ( $options_page ) {
	$fields = [
		'gh_username' => [
			'id'    => 'gh_username',
			'title' => __( 'GitHub Username', 'jurassic-ninja' ),
			'type'  => 'text',
		],
		'gh_pat' => [
			'id'    => 'gh_pat',
			'title' => __( 'GitHub Personal Access Token', 'jurassic-ninja' ),
			'type'  => 'text',
		],
	];
	$settings = [
		'title' => __( 'Private Repositories', 'jurassic-ninja' ),
		'text' => '<p>' . __( 'Configure private plugin repository access.', 'jurassic-ninja' ) . '</p>',
		'fields' => $fields,
	];

	$options_page[ SETTINGS_KEY ]['sections']['private_repos'] = $settings;
	return $options_page;
}

/**
 * Get array of repos from which plugins should be installed on the remote site.
 * 
 * @return array {
 *    @return array {
 *       @type string 'name'   Repo name (slug from full repo URL (e.g. 'jetpack' from https://github.com/Automattic/jetpack.git)
 *       @type string 'url'    URL for GitHub user/organization for repo (e.g. 'github.com/woocommerce')
 *       @type string 'branch' Branch to clone from repo.
 *       @type bool   'build'  If true, plugin will be built with 'npm install && npm run build'.
 *    }
 * }
 */
function add_private_repos_features( $features, $json_params ) {
	if ( isset( $json_params['jn_pr_repos'] ) ) {
		$features['repos'] = json_decode( urldecode( $json_params['jn_pr_repos'] ), true );
	}
	return $features;
}

// Downloads archive(s) from private GitHub repo and upload to the new site, and build.
function transfer_private_repos( &$app, $user, $php_version, $domain, $wordpress_options, $features ) {
	if ( is_wp_error( $app ) ) {
		return;
	}

	$password = $wordpress_options['admin_password'];
	$username = $user->data->name;
	$repos    = $features['repos'];
	if ( empty( $repos ) ) {
		return;
	}

	// Install npm by uploading installer script and running it on the remote server.
	upload_file_to_jn( JN_PRIVATE_REPOS_ABSPATH . 'bin/node-install.sh', '~/node-install.sh', $domain, $username, $password );
	run_command( $username, $password, $domain, 'chmod +x ~/node-install.sh && ~/node-install.sh' );

	foreach ( $repos as $repo ) {
		// Get archive of repository from GitHub.
		$archive_file = get_repo_archive( $repo );
		$dest_filename = "{$repo['name']}.zip";
		upload_file_to_jn( $archive_file, $dest_filename, $domain, $username, $password );
		unlink( $archive_file );

		// Now install, build, and activate the plugin.
		$wp_home = "~/apps/$username/public";
		$plugins_dir = "$wp_home/wp-content/plugins";
		$build_cmd = $repo['build'] ? ' && npm install && npm run build' : '';
		run_command( $username, $password, $domain, 
			'source ~/.nvm/nvm.sh'                     . // Sets up Node environment so 'npm' is available.
			" && unzip $dest_filename -d $plugins_dir" . // Unzip plugin archive.
			" && cd $plugins_dir/{$repo['name']}"      .
			$build_cmd                                 .
			" && cd .."                                .
			" && wp plugin activate {$repo['name']}"
		);
	}
}

// Upload source_filename to the home directory of server @ $domain
function upload_file_to_jn( $source_filename, $dest_filename, $domain, $username, $password) {
	$run = "SSHPASS=$password sshpass -e scp -o StrictHostKeyChecking=no $source_filename $username@$domain:$dest_filename";
	exec( $run, $output, $return_value );
	// phpcs:enable
	if ( 0 !== $return_value ) {
		\jn\debug( 'Commands run finished with code %s and output: %s',
			$return_value,
			implode( ' -> ', $output )
		);
		return new \WP_Error(
			'commands_did_not_run_successfully',
			"Commands didn't run OK"
		);
	}
}

// Download repo with git, archive it, and return temporary file location.
function get_repo_archive( $repo ) {
	$gh_username = \jn\settings( 'gh_username' );
	$gh_password = \jn\settings( 'gh_pat' );
	$sys_tmp     = sys_get_temp_dir();
	$tmp_dir     = exec( "mktemp -d" );
	$clone_url   = "https://$gh_username:$gh_password@{$repo['url']}/{$repo['name']}";
	$output      = shell_exec(
		"cd $tmp_dir"                                      .
		" && git clone -b {$repo['branch']} $clone_url"    .
		" && zip -r {$repo['name']}.zip {$repo['name']}/*" .
		" && rm -rf {$repo['name']}"
	);
	return "$tmp_dir/{$repo['name']}.zip";
}

// Run a shell command on remote site.
function run_command( $user, $password, $domain, $cmd ) {
	// Redirect all errors to stdout so exec shows them in the $output parameters
	$run = "SSHPASS=$password sshpass -e ssh -oStrictHostKeyChecking=no $user@$domain '$cmd' 2>&1";
	$output = null;
	$return_value = null;
	// Use exec instead of shell_exect so we can know if the commands failed or not
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( $run, $output, $return_value );
	// phpcs:enable
	if ( 0 !== $return_value ) {
		\jn\debug( 'Commands run finished with code %s and output: %s',
			$return_value,
			implode( ' -> ', $output )
		);
		return new \WP_Error(
			'commands_did_not_run_successfully',
			"Commands didn't run OK"
		);
	}
	return null;
}
