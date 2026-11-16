<?php

use EE\Tests\TestCase;

/**
 * `ee shell` site resolution, service/shell/user selection and the `--command` exec string (src/Shell_Command.php).
 */
class ShellCommandTest extends TestCase {

	private $path_backup;
	private $error_log_backup;

	protected function setUp(): void {
		parent::setUp();
		$this->path_backup = getenv( 'PATH' );
		// EE::exec( ..., $echo_stderr = true ) error_log()s the (empty) stderr of every --command run.
		$this->error_log_backup = ini_set( 'error_log', '/dev/null' );
		$this->set_config( [ 'skip-tty' => false, 'custom-compose' => null ] );
	}

	protected function tearDown(): void {
		putenv( 'PATH=' . $this->path_backup );
		ini_set( 'error_log', (string) $this->error_log_backup );
		exec( 'rm -rf ' . escapeshellarg( EE_SERVICE_DIR . '/mariadb' ) );
		parent::tearDown();
	}

	/** Insert an enabled site whose directory exists, and answer `config --services` with $services. */
	private function site( $url = 'example.test', $type = 'wp', array $services = [ 'nginx', 'php', 'db' ], array $fields = [] ) {
		$dir = $this->sandbox() . '/' . $url;
		mkdir( $dir );
		$this->insert_site( $fields + [ 'site_url' => $url, 'site_type' => $type, 'site_fs_path' => $dir ] );
		$this->runner->on( '/ config --services$/', implode( "\n", $services ) . "\n" );

		return $dir;
	}

	private function shell( array $args, array $assoc_args ) {
		( new Shell_Command() )->__invoke( $args, $assoc_args + [ 'service' => 'php' ] );
	}

	/** @return string[] The `exec` commands, without the `config --services` probe. */
	private function execs() {
		return $this->runner->matching( '/ exec /' );
	}

	/** container_status() runs `which docker` through PHP's exec(); a stub on PATH lets it pass without Docker. */
	private function stub_docker_binary() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 0\n" );
		chmod( "$bin/docker", 0755 );
		putenv( "PATH=$bin:/usr/bin:/bin" );
	}

	public function test_command_runs_in_the_service_through_a_bash_wrapper_from_the_site_dir() {
		$dir = $this->site();

		$this->shell( [ 'example.test' ], [ 'command' => 'wp plugin list' ] );

		$this->assertSame( [ 'docker-compose -f docker-compose.yml  exec   php bash -c "wp plugin list"' ], $this->execs() );
		$this->assertSame( realpath( $dir ), getcwd() );
	}

	public function test_skip_tty_adds_dash_t_to_the_command_and_the_user_check() {
		$this->site();
		$this->set_config( [ 'skip-tty' => true ] );

		$this->shell( [ 'example.test' ], [ 'command' => 'ls', 'user' => 'root' ] );

		$execs = $this->execs();
		$this->assertCount( 2, $execs );
		$this->assertSame( "docker-compose -f docker-compose.yml  exec -T --user='root' php sh -c \"exit\"", $execs[0] );
		$this->assertMatchesRegularExpression( '/ exec -T .* php bash -c "ls"$/', $execs[1] );
	}

	/**
	 * @dataProvider service_shell_provider
	 */
	public function test_service_and_shell_selection( $site_type, $service, array $services, $expected ) {
		$this->site( 'example.test', $site_type, $services );

		$this->shell( [ 'example.test' ], [ 'command' => 'id', 'service' => $service ] );

		$this->assertSame( [ "docker-compose -f docker-compose.yml  exec   $expected -c \"id\"" ], $this->execs() );
	}

	public function service_shell_provider() {
		return [
			'wp site, default php service' => [ 'wp', 'php', [ 'nginx', 'php' ], 'php bash' ],
			'html site: php falls back to nginx' => [ 'html', 'php', [ 'nginx' ], 'nginx bash' ],
			'explicit nginx on a php site'  => [ 'php', 'nginx', [ 'nginx', 'php' ], 'nginx bash' ],
			'mailhog has no bash'           => [ 'wp', 'mailhog', [ 'nginx', 'php', 'mailhog' ], 'mailhog sh' ],
		];
	}

	public function test_a_service_missing_from_the_site_compose_file_is_refused() {
		$this->site( 'example.test', 'wp', [ 'nginx', 'php' ] );

		$this->assert_ee_error(
			function () {
				$this->shell( [ 'example.test' ], [ 'command' => 'id', 'service' => 'redis' ] );
			},
			'example.test site does not have support to launch redis shell.'
		);
		$this->assertSame( [], $this->execs() );
	}

	public function test_an_unknown_user_falls_back_to_the_default_user() {
		$this->site();
		$this->runner->on( "/ exec +--user='bob' php sh -c \"exit\"$/", '', 1 );

		$this->shell( [ 'example.test' ], [ 'command' => 'id', 'user' => 'bob' ] );

		$this->assertStringContainsString( 'bob is not available in php, falling back to default user.', $this->stderr() );
		$execs = $this->execs();
		$this->assertCount( 2, $execs );
		$this->assertSame( 'docker-compose -f docker-compose.yml  exec   php bash -c "id"', $execs[1] );
	}

	/**
	 * SHELL-1: an existing --user is prefixed with `--user=` and then quoted again by docker_compose_exec().
	 *
	 * @group known-bug
	 * @group SHELL-1
	 */
	public function test_an_available_user_reaches_the_command_as_a_plain_user_flag() {
		$this->site();

		$this->shell( [ 'example.test' ], [ 'command' => 'id', 'user' => 'root' ] );

		$execs = $this->execs();
		$this->assertCount( 2, $execs );
		$this->assertMatchesRegularExpression( "/ exec +--user='?root'? php bash -c/", $execs[1] );
	}

	/**
	 * SHELL-1: --user is interpolated into the user check and the command with single quotes only.
	 *
	 * @group known-bug
	 * @group SHELL-1
	 */
	public function test_user_is_shell_safe() {
		$this->site();
		$this->assert_shell_safe(
			function ( $user ) {
				$this->shell( [ 'example.test' ], [ 'command' => 'id', 'user' => $user ] );
			},
			'/ exec /'
		);
	}

	/**
	 * SHELL-4: --command lands inside `bash -c "..."` in a host shell string, so quotes end the wrapper and $(...) runs on the host.
	 *
	 * @group known-bug
	 * @group SHELL-4
	 */
	public function test_command_is_shell_safe() {
		$this->site();
		$this->assert_shell_safe(
			function ( $command ) {
				$this->shell( [ 'example.test' ], [ 'command' => $command ] );
			},
			'/ exec /'
		);
	}

	public function test_the_site_is_detected_from_the_working_directory() {
		$dir = $this->site();
		$this->runner->on( '/^pwd$/', "$dir/app/htdocs\n" );

		$this->shell( [], [ 'command' => 'id' ] );

		$this->assertSame( realpath( $dir ), getcwd() );
		$this->assertCount( 1, $this->execs() );
	}

	public function test_an_unknown_site_outside_a_site_dir_is_an_error() {
		$this->site();
		$this->runner->on( '/^pwd$/', "/root\n" );

		$this->assert_ee_error(
			function () {
				$this->shell( [ 'nope.test' ], [ 'command' => 'id' ] );
			},
			'Could not find the site you wish to run shell  command on.'
		);
		$this->assertSame( [], $this->execs() );
	}

	public function test_an_unknown_site_inside_a_site_dir_suggests_that_site() {
		$dir = $this->site();
		$this->runner->on( '/^pwd$/', "$dir\n" );

		$this->assert_ee_error(
			function () {
				$this->shell( [ 'nope.test' ], [ 'command' => 'id' ] );
			},
			'nope.test is not a valid site-name. Did you mean `ee shell  example.test`?'
		);
	}

	public function test_a_disabled_site_is_refused() {
		$this->site( 'example.test', 'wp', [ 'php' ], [ 'site_enabled' => 0 ] );

		$this->assert_ee_error(
			function () {
				$this->shell( [ 'example.test' ], [ 'command' => 'id' ] );
			},
			'Site example.test is not enabled.'
		);
		$this->assertSame( [], $this->execs() );
	}

	public function test_a_trailing_slash_on_the_site_name_is_accepted() {
		$this->site();

		$this->shell( [ 'example.test/' ], [ 'command' => 'id' ] );

		$this->assertCount( 1, $this->execs() );
	}

	/**
	 * SHELL-3: auto_site_name() strips the scheme only for its own lookup, so get_site_info() looks up `http://example.test`.
	 *
	 * @group known-bug
	 * @group SHELL-3
	 */
	public function test_a_site_url_with_a_scheme_resolves_to_the_site() {
		$this->site();

		try {
			$this->shell( [ 'http://example.test' ], [ 'command' => 'id' ] );
		} catch ( EE\ExitException $e ) {
			$this->fail( 'ee shell http://example.test exited: ' . $this->stderr() );
		}
		$this->assertCount( 1, $this->execs() );
	}

	public function test_a_global_service_that_is_not_running_is_refused() {
		$this->stub_docker_binary();
		$this->runner->on( "/^docker inspect -f '\{\{\.State\.Running\}\}' services_global-redis_1$/", "false\n" );

		$this->assert_ee_error(
			function () {
				$this->shell( [], [ 'command' => 'redis-cli ping', 'service' => 'global-redis' ] );
			},
			'global-redis service container is not running.'
		);
		$this->assertSame( [], $this->execs() );
	}

	public function test_global_db_gets_a_root_login_file_and_runs_from_the_services_dir() {
		$this->stub_docker_binary();
		EE::db()->table( 'options' )->insert( [ 'key' => 'global-db', 'value' => 'r00t-pass' ] );
		$this->runner->on( '/^docker inspect .* services_global-db_1$/', "true\n" );

		$this->shell( [], [ 'command' => 'mysql -e status', 'service' => 'global-db' ] );

		$this->assertSame( "[mysql]\nuser=root\npassword=r00t-pass", trim( file_get_contents( EE_SERVICE_DIR . '/mariadb/conf/conf.d/my.cnf' ) ) );
		$this->assertSame( realpath( EE_SERVICE_DIR ), getcwd() );
		$this->assertSame( [ 'docker-compose -f docker-compose.yml  exec   global-db bash -c "mysql -e status"' ], $this->execs() );
	}

	public function test_an_existing_global_db_login_file_is_kept() {
		$this->stub_docker_binary();
		$my_cnf = EE_SERVICE_DIR . '/mariadb/conf/conf.d/my.cnf';
		mkdir( dirname( $my_cnf ), 0755, true );
		file_put_contents( $my_cnf, "custom\n" );
		$this->runner->on( '/^docker inspect /', "true\n" );

		$this->shell( [], [ 'command' => 'mysql', 'service' => 'global-db' ] );

		$this->assertSame( "custom\n", file_get_contents( $my_cnf ) );
	}

	/**
	 * SHELL-8: the my.cnf with the DB root password is written 0644 (0666 & ~umask).
	 *
	 * @group known-bug
	 * @group SHELL-8
	 */
	public function test_global_db_login_file_is_private() {
		$this->stub_docker_binary();
		EE::db()->table( 'options' )->insert( [ 'key' => 'global-db', 'value' => 'r00t-pass' ] );
		$this->runner->on( '/^docker inspect /', "true\n" );

		$this->shell( [], [ 'command' => 'mysql', 'service' => 'global-db' ] );

		clearstatcache();
		$this->assertSame( '0600', sprintf( '%04o', fileperms( EE_SERVICE_DIR . '/mariadb/conf/conf.d/my.cnf' ) & 0777 ) );
	}
}
