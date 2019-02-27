# Jurassic Ninja Private Repos
Experimental extension for [Jurassic Ninja](https://github.com/Automattic/jurassic.ninja/) that adds the ability to add 1 or more WordPress plugins (from private GitHub repositories) to ephemeral sites.



## Setup

1. Setup [Jurassic Ninja](https://github.com/Automattic/jurassic.ninja) on your WordPress site.
1. Install `Jurassic Ninja Private Repos` on your WordPress site.
   1. Clone this repo to `plugins` directory
   1. Activate
1. Define GitHub username and password for private repo access in `wp_config.php`:
```php
define( 'JN_PR_GH_USERNAME', 'your_name' );
define( 'JN_PR_GH_PASSWORD', 'abc123' );
```
GitHub username and password can be a standard user, or you can use a Personal Access token: https://help.github.com/en/articles/creating-a-personal-access-token-for-the-command-line

## Use

yourjurassicsite.ninja/create?jn_pr_repos=<url_encoded_json_data>

Example JSON for `jn_pr_repos`:
```json
[
	{
		"name": "super-secret-repo-name",
		"url": "github.com/c-shultz",
		"build": true,
		"branch": "master"
	}
]
```
The above configuration would add the master branch from the repo at `github.com/c-shultz/super-secret-repo-name`, with `build` set to `true`, it would attempt to build the plugin with Node.js by running `npm run build`.

The final url after encoding would be: `yourjurassicsite.ninja/create?=%5B%0A%09%7B%0A%09%09%22name%22%3A%20%22super-secret-repo-name%22%2C%0A%09%09%22url%22%3A%20%22github.com%2Fc-shultz%22%2C%0A%09%09%22build%22%3A%20true%2C%0A%09%09%22branch%22%3A%20%22master%22%0A%09%7D%0A%5D`

## Major Known issues/limitations

- Error handling and debug logging is limited
- The only `build` option is to use `npm run` build. This will not be useful with many plugins.
