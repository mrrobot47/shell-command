Feature: ee shell --command on a php site
  Non-interactive mode: the command runs through `bash -c` in the service container, output is captured and replayed.

  Background:
    Given the php site 'bh-shell-command-2.test' exists with flags '--with-db --local-db'

  Scenario: STDOUT of the command is printed
    When I run 'bin/ee shell bh-shell-command-2.test --command="echo hello-from-php"'
    Then the return code should be 0
    And STDOUT should be:
      """
      hello-from-php
      """
    And STDERR should be empty

  Scenario: The default service is php, as www-data, in the web root
    When I run 'bin/ee shell bh-shell-command-2.test --command="whoami; pwd"'
    Then the return code should be 0
    And STDOUT should be:
      """
      www-data
      /var/www/htdocs
      """

  Scenario: The php container runs the site's PHP version
    When I run 'bin/ee shell bh-shell-command-2.test --command="php -v"'
    Then the return code should be 0
    And STDOUT should match '/^PHP 8\.4\./m'

  Scenario: STDERR is kept separate and a failing command exits 1
    When I run 'bin/ee shell bh-shell-command-2.test --command="echo out; echo err >&2; exit 3"'
    Then the return code should be 1
    And STDOUT should be:
      """
      out
      """
    And STDERR should contain:
      """
      err
      """

  Scenario Outline: Exit status of the command
    When I run 'bin/ee shell bh-shell-command-2.test --command="<command>"'
    Then the return code should be <code>

    Examples:
      | command  | code |
      | true     | 0    |
      | false    | 1    |
      | exit 2   | 1    |
      | exit 255 | 1    |

  # SHELL-2 (proposed): --command collapses every failure to exit 1 (#25), while the interactive mode propagates the real status.
  @known-bug
  Scenario: The exact exit status of the command is propagated
    When I run 'bin/ee shell bh-shell-command-2.test --command="exit 3"'
    Then the return code should be 3

  Scenario: An unknown binary fails with the shell's error
    When I run 'bin/ee shell bh-shell-command-2.test --command=bh-no-such-binary'
    Then the return code should be 1
    And STDERR should contain:
      """
      bh-no-such-binary: command not found
      """

  Scenario Outline: Shell syntax is interpreted inside the container
    When I run 'bin/ee shell bh-shell-command-2.test --command="<command>"'
    Then the return code should be 0
    And STDOUT should be:
      """
      <output>
      """

    Examples:
      | command                              | output    |
      | cd /tmp && pwd                       | /tmp      |
      | echo abc \| tr a-c x-z               | xyz       |
      | false \|\| echo recovered            | recovered |
      | echo 'single  quoted'                | single  quoted |
      | test -d /var/www/htdocs && echo yes  | yes       |

  Scenario Outline: STDIN is forwarded to the command
    When I run 'printf "l1\nl2\n" | bin/ee shell bh-shell-command-2.test <flag> --command="wc -l"'
    Then the return code should be 0
    And STDOUT should be:
      """
      2
      """

    Examples:
      | flag       |
      |            |
      | --skip-tty |

  Scenario: Multi-line output is preserved
    When I run 'bin/ee shell bh-shell-command-2.test --command="seq 1 3"'
    Then the return code should be 0
    And STDOUT should be:
      """
      1
      2
      3
      """

  # SHELL-4 (proposed): the command is wrapped as bash -c "<command>" on the host, so double quotes end the wrapper early.
  @known-bug
  Scenario: Double quotes inside the command are preserved
    When I run 'bin/ee shell bh-shell-command-2.test --command='echo "a  b"''
    Then the return code should be 0
    And STDOUT should be:
      """
      a  b
      """

  # SHELL-4 (proposed): the host shell expands $VAR and $(...) before the container sees the command.
  @known-bug
  Scenario: Variables in the command are expanded inside the container
    When I run 'bin/ee shell bh-shell-command-2.test --command='X=inner; echo $X''
    Then the return code should be 0
    And STDOUT should be:
      """
      inner
      """

  Scenario Outline: --skip-tty is accepted in command mode
    When I run 'bin/ee shell bh-shell-command-2.test <flag> --command="echo ok"'
    Then the return code should be 0
    And STDOUT should be:
      """
      ok
      """

    Examples:
      | flag            |
      | --skip-tty      |
      | --skip-tty=true |

  Scenario: --skip-tty does not hide a failure
    When I run 'bin/ee shell bh-shell-command-2.test --skip-tty --command=false'
    Then the return code should be 1

  Scenario: Command mode never allocates a TTY, with or without --skip-tty
    When I run 'bin/ee shell bh-shell-command-2.test --command="[ -t 1 ] && echo tty || echo notty"'
    Then STDOUT should be:
      """
      notty
      """
    When I run 'bin/ee shell bh-shell-command-2.test --skip-tty --command="[ -t 1 ] && echo tty || echo notty"'
    Then STDOUT should be:
      """
      notty
      """

  Scenario: Command mode captures output even when ee runs in a terminal
    When I run 'script -qec "bin/ee shell bh-shell-command-2.test --command=tty" /dev/null'
    Then the return code should be 1
    And STDOUT should contain:
      """
      not a tty
      """

  Scenario: --command without a value warns and falls back to the interactive shell
    When I run 'printf "echo fell-back\n" | bin/ee shell bh-shell-command-2.test --command'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: --command parameter needs a value
      """
    And STDOUT should be:
      """
      fell-back
      """

  # SHELL-5 (proposed): an empty --command silently opens the interactive shell, so `--command="$CMD"` with an empty CMD reads stdin instead of failing.
  @known-bug
  Scenario: An empty --command is rejected
    When I run 'bin/ee shell bh-shell-command-2.test --command="" < /dev/null'
    Then the return code should not be 0

  # SHELL-1 (proposed): the validated user is turned into "--user=<user>" and then quoted again by docker_compose_exec(), giving --user='--user=<user>'.
  @known-bug
  Scenario Outline: --user selects the user of the command
    When I run 'bin/ee shell bh-shell-command-2.test --user=<user> --command=whoami'
    Then the return code should be 0
    And STDOUT should be:
      """
      <user>
      """

    Examples:
      | user     |
      | root     |
      | www-data |

  Scenario: An unknown --user falls back to the default user with a warning
    When I run 'bin/ee shell bh-shell-command-2.test --user=bh-nouser --command=whoami'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: bh-nouser is not available in php, falling back to default user.
      """
    And STDOUT should be:
      """
      www-data
      """

  Scenario: --user without a value warns and uses the default user
    When I run 'bin/ee shell bh-shell-command-2.test --user --command=whoami'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: --user parameter needs a value
      """
    And STDOUT should be:
      """
      www-data
      """

  Scenario: The docblock example on the nginx service works
    When I run 'bin/ee shell bh-shell-command-2.test --service=nginx --command="nginx -t && nginx -s reload"'
    Then the return code should be 0
    And STDERR should contain:
      """
      test is successful
      """

  Scenario: The postfix service shell runs in the postfix container
    When I run 'bin/ee shell bh-shell-command-2.test --service=postfix --command=hostname'
    Then the return code should be 0
    And STDOUT should be:
      """
      bh-shell-command-2.test
      """

  Scenario: The local db service shell runs in the site's mariadb container
    When I run 'bin/ee shell bh-shell-command-2.test --service=db --command="mariadb --version"'
    Then the return code should be 0
    And STDOUT should contain:
      """
      MariaDB
      """

  Scenario: A service that exists but is not started fails at exec time
    When I run 'bin/ee shell bh-shell-command-2.test --service=mailhog --command=true'
    Then the return code should be 1
    And STDERR should not contain:
      """
      does not have support to launch
      """
    And STDERR should contain:
      """
      is not running
      """

  Scenario: A service that is not in the compose file is rejected
    When I run 'bin/ee shell bh-shell-command-2.test --service=redis --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: bh-shell-command-2.test site does not have support to launch redis shell.
      """

  Scenario: A service from docker-compose-custom.yml gets a shell, and --custom-compose overrides it
    Given the file '/opt/easyengine/sites/bh-shell-command-2.test/docker-compose-custom.yml' is restored after the scenario
    And the file '/opt/easyengine/sites/bh-shell-command-2.test/docker-compose-custom.yml' contains:
      """
      services:
        bh-extra:
          image: easyengine/redis:v4.11.0
          networks:
            - bh-shell-command-2.test
      """
    And the service 'bh-extra' of site 'bh-shell-command-2.test' is up
    When I run 'bin/ee shell bh-shell-command-2.test --service=bh-extra --command="redis-cli ping"'
    Then the return code should be 0
    And STDOUT should be:
      """
      PONG
      """
    When I run 'bin/ee shell bh-shell-command-2.test --custom-compose=bh-missing.yml --service=bh-extra --command="redis-cli ping"'
    Then the return code should be 1
    And STDERR should contain:
      """
      Warning: File: user-docker-compose/bh-missing.yml does not exist. Falling back to default compose file.
      """
    And STDERR should contain:
      """
      Error: bh-shell-command-2.test site does not have support to launch bh-extra shell.
      """
