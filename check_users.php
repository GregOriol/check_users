<?php

require_once(__DIR__.'/vendor/autoload.php');

require_once(__DIR__.'/inc/nagios.php');

// Command line parser
$parser = new Console_CommandLine(array(
	'description'	=> 'Check logged in users count',
	'version'		=> '0.0.1',
	'force_posix'	=> true
));

$parser->addOption('ok', array(
	'long_name'		=> '--ok',
	'description'	=> 'Users to consider as ok for the status, comma-separated (ex: "cacti,nagios")',
	'action'			=> 'StoreString'
));

$parser->addOption('test', array(
	'long_name'		=> '--test',
	'description'	=> 'File to use for test instead of real w values',
	'action'			=> 'StoreString'
));

try {
	$result = $parser->parse();

	$ok = [];
	if ($result->options['ok'] !== null) {
		$ok = explode(',', $result->options['ok']);
	}

	if ($result->options['test'] !== null) {
		$output = file($result->options['test']);
		$return = 0;
	} else {
		exec('w', $output, $return);
	}

	if ($return !== 0) {
		echo 'Couldn\'t retrieve output from w: returned '.$return."\n";
		exit(NAGIOS_UNKNOWN);
	}

	// var_dump($output);

	$columns = [];
	$regex = '';
	$regexMatches = [];
	$users = [];
	$i = 1;
	foreach ($output as $row) {
		$row = trim($row);

		// var_dump($i);

		if ($i === 1) {
			// 13:13:47 up 191 days, 22:40,  2 users,  load average: 1.12, 1.44, 1.22
			// 14:10  up 13 days,  3:16, 76 users, load averages: 4,78 6,04 6,74
			if (!preg_match('/^.*users.*load average.*$/', $row, $matches)) {
				echo 'Couldn\'t retrieve output from w: first row does not match rules'."\n";
				exit(NAGIOS_UNKNOWN);
			}
		} elseif ($i === 2) {
			// USER     TTY      FROM             LOGIN@   IDLE   JCPU   PCPU  WHAT
			// USER       TTY      FROM    LOGIN@  IDLE WHAT
			if (!preg_match('/^USER .* WHAT$/', $row, $matches)) {
				echo 'Couldn\'t retrieve output from w: second row does not match rules'."\n";
				exit(NAGIOS_UNKNOWN);
			}

			$r = explode(' ', $row);
			$columns = array_values(array_filter($r, function ($item) { return $item !== ''; }));
			// var_dump($columns);

			if (empty($columns)) {
				echo 'Couldn\'t retrieve output from w: second row does not contain columns'."\n";
				exit(NAGIOS_UNKNOWN);
			}

			$j = 1;
			foreach ($columns as $column) {
				// var_dump($column);
				if ($column === 'USER') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '(\w+)';
					$regexMatches[$column] = $j;
				}
				elseif ($column === 'TTY') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '(\w+| )';
					$regexMatches[$column] = $j;
				}
				elseif ($column === 'FROM') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '([0-9a-z:.-]+)';
					$regexMatches[$column] = $j;
				}
				elseif ($column === 'LOGIN@') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '([0-9a-z:.]+)';
					$regexMatches[$column] = $j;
				}
				elseif ($column === 'IDLE') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '([0-9a-z:.]+)';
					$regexMatches[$column] = $j;
				}
				elseif ($column === 'WHAT') {
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '(.+)';
					$regexMatches[$column] = $j;
				}
				else if (!in_array($column, ['USER', 'TTY', 'FROM', 'LOGIN@', 'IDLE', 'WHAT'])) {
					// Unknown column
					if ($regex !== '') {
						$regex .= '[ ]+';
					}
					$regex .= '([^ ]+)';
					$regexMatches[$column] = $j;
				}

				$j += 1;
			}
			if (empty($regex)) {
				echo 'Couldn\'t retrieve output from w: second row does not contain known columns'."\n";
				exit(NAGIOS_UNKNOWN);
			}

			$regex = '/^'.$regex.'/';

			// var_dump($regex);
			// var_dump($regexMatches);
		} else/*if ($i === 3)*/ {
			// root              2a01:cb00:38a:e1 10:54    5:01m  0.00s  0.67s sshd: root@pts/0
			// fortin     console  -      27oct.2 13days -
			// fortin     s000     -      27oct.2 2days -bash

			if (preg_match($regex, $row, $matches)) {
				// var_dump($matches);
				$user = $matches[$regexMatches['USER']];
				$tty = $matches[$regexMatches['TTY']];
				$from = $matches[$regexMatches['FROM']];
				$login = $matches[$regexMatches['LOGIN@']];
				$idle = $matches[$regexMatches['IDLE']];
				$what = $matches[$regexMatches['WHAT']];

				if (!array_key_exists($user, $users)) {
					$users[$user] = [
						'count' => 0,
						'from' => [],
						'what' => [],
					];
				}
				$users[$user]['count'] += 1;
				if (!in_array($from, $users[$user]['from'])) {
					$users[$user]['from'][] = $from;
				}
				if (!in_array($what, $users[$user]['what'])) {
					$users[$user]['what'][] = $what;
				}
			} else {
				if (empty($regex)) {
					echo 'Couldn\'t retrieve output from w: could not parse row'."\n";
					exit(NAGIOS_UNKNOWN);
				}
			}
		}

		$i += 1;
	}

	// var_dump($users);

	$status = NAGIOS_OK;
	$output = '';

	$actualUsers = array_keys($users);
	sort($actualUsers);
	$actualUsersCount = count($actualUsers);
	// var_dump($actualUsers);

	// var_dump($ok);
	$warningUsers = array_values(array_filter($actualUsers, function ($item) use ($ok) { return !in_array($item, $ok); }));
	sort($warningUsers);
	// var_dump($warningUsers);
	$warningUsersCount = count($warningUsers);

	if ($warningUsersCount === 0) {
		$output .= 'USERS OK - '.$actualUsersCount.'';
		if ($actualUsersCount > 0) {
			$output .= ' ('.implode(', ', $actualUsers).')';
		}
	} else {
		$status = NAGIOS_WARNING;
		$output .= 'USERS WARNING - '.$warningUsersCount.'';
		if ($warningUsersCount !== $actualUsersCount) {
			$output .= ' of '.$actualUsersCount.'';
		}
		if ($actualUsersCount > 0) {
			$output .= ' ('.implode(', ', $actualUsers).')';
		}
	}

	// var_dump($status);
	// var_dump($output);

	echo $output."\n";
	exit($status);
} catch (Exception $exc) {
	$parser->displayError($exc->getMessage());
	exit(NAGIOS_UNKNOWN);
}
