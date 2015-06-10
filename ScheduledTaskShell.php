<?php

use Aws\Sqs\SqsClient;

App::uses('CakeEmail', 'Network/Email');
App::import('Lib', 'Aws');

set_time_limit(0);

/**
 * This script is run by cron every minute. It checks the ScheduledTask model 
 * for scripts that need to be run.  For each file that is run, the script will
 * fork itself, leaving the child to run the requested file while the parent script
 * spawns more children as necessary.  Once all children have been spawned, the parent
 * waits for all children to die before quitting.
 */
class ScheduledTaskShell extends AppShell {
	public $uses = array('ScheduledTask');
	
	public $sqs_queue_name = "cms_task_queue";
	public $default_admin_email = 'james.alday@vevo.com';

	public $base_dir;

	private $user;
	
	// suppress cake's boilerplate output
	public function startup() {}

	public function main() {
		// AWS Credentials based on environment
		$this->client = Aws::getCredentials();

		$this->base_dir = APP_DIR . '/Lib/ScheduledTasks/';

		// Set up a copy of elasticache with a shorter duration
		Cache::config('elasticache', array(
			'engine' => 'Memcached',
			'duration'=> 60,
			'probability'=> 100,
			'prefix' => 'vevo_php_cms_',
			'servers' => [ELASTICACHE_SERVER],
			'persistent' => false,
			'compress' => false,
		));

		// Check if there is a lock set (another task running)
		try {
			if ($task_lock = @Cache::read('ScheduledTask', 'elasticache')) {
				// Another process is currently running the scripts, back off!
				exit();
			}
		} catch(Exception $e) {
			CakeLog::write('error', 'Scheduled Task failed to connect to READ cache lock: ' . $e->getMessage());
			exit();
		}

		// current timestamp to use for cache lock and queries
		$now = date('Y-m-d H:i:s');

		// Set an lock until we've updated the records we want to work on
		try{
			if (@Cache::write('ScheduledTask', $now, 'elasticache') === false) {
				throw new Exception;
			}
		} catch(Exception $e) {
			CakeLog::write('error', 'Scheduled Task failed to connect to WRITE cache lock: ' . $e->getMessage());
			exit();
		}

		// Update run_time for outstanding records
		$rows_updated = $this->ScheduledTask->updateRunTime($now);
		if ($rows_updated < 1) {
			exit();
		}

		$ScheduledTaskShell_Tasks = $this->ScheduledTask->findByRunTime($now);

		// With our tasks firmly in hand, we can now get rid of the lock
		try {
			if (@Cache::delete('ScheduledTask', 'elasticache') === false) {
				throw new Exception;
			}
		} catch(Exception $e) {
			// Log error but don't give up just yet - lock will expire anyway
			CakeLog::write('error', 'Scheduled Task failed to connect to DELETE cache lock: ' . $e->getMessage());
		}

		foreach($ScheduledTaskShell_Tasks as $ScheduledTaskShell_Task) {
			$this->user = [
				'name' => $ScheduledTaskShell_Task['User']['first_name'] . " " . $ScheduledTaskShell_Task['User']['last_name'],
				'email' => $ScheduledTaskShell_Task['User']['email']
			];
			
			$pid = pcntl_fork();
			
			switch($pid) {
				//Fork Failed!
				case -1:
					CakeLog::write('error', 'Scheduled Tasks Failed To Fork: ' . __FILE__);
					$this->emailOutput($ScheduledTaskShell_Task['ScheduledTask']['script_to_run'], 'Error: Failed To Fork Process');
					exit();
					break;
				
				// Child Process
				case 0:
					// Remove child pids from other children
					if (isset($child_pids)) {
						unset($child_pids);
					}
					
					// Makes sure we have a DB connection
					$db_connection = ConnectionManager::getDataSource('default')->connect();
					
					// Remove memory limit if requested
					if ($ScheduledTaskShell_Task['ScheduledTask']['remove_memory_limit']) {
						ini_set('memory_limit', '-1');
					}
					
					// Set nice value if requested
					if ($ScheduledTaskShell_Task['ScheduledTask']['nice_addend'] > 0) {
						proc_nice($ScheduledTaskShell_Task['ScheduledTask']['nice_addend']);
					}

					// Start output buffering
					ob_start();
					
					// Run as Shell Command Task
					if ($ScheduledTaskShell_Task['ScheduledTask']['controller'] === "Tasks") {
						// Parse parameters into variables to be used by script
						parse_str($ScheduledTaskShell_Task['ScheduledTask']['parameters'], $params);

						$task = $this->Tasks->load($ScheduledTaskShell_Task['ScheduledTask']['script_to_run']);
						
						$task->connection = $db_connection;
						$task->params = $params;
						
						$task->execute();
					} else {
						// Run as included script

						// Parse parameters into variables to be used by script
						parse_str($ScheduledTaskShell_Task['ScheduledTask']['parameters']);

						// Include the script to run (from /Lib/ScheduledTasks/<controller>/<script_to_run>)
						// This script will need to bootstrap itself for this to have any effect
						$script_to_run = $this->base_dir . $ScheduledTaskShell_Task['ScheduledTask']['controller'] . DS . $ScheduledTaskShell_Task['ScheduledTask']['script_to_run'];

						if (!file_exists($script_to_run)) {
							CakeLog::write('error', 'Scheduled Tasks Failed To Find: ' . $script_to_run);
							$this->emailOutput($script_to_run, 'Error: Script Not Found');

							exit();
							break;
						} else {
							require($script_to_run);

							//Reconnect to DB since included script will close connection (again!)
							ConnectionManager::getDataSource('default')->connect();
						}
					}
					
					// Mark the script as completed
					$this->ScheduledTask->id = $ScheduledTaskShell_Task['ScheduledTask']['id'];
					$this->ScheduledTask->saveField('end_time', date('Y-m-d H:i:s', time()));
				
					// Capture any output
					$results = ob_get_contents();
					ob_end_clean();
					
					// If there was any output, send it to the user/admin in an email
					if (!empty($results)) {
						$this->emailOutput($ScheduledTaskShell_Task['ScheduledTask']['script_to_run'], $results);
					}

					// If a recurrence rule is set, insert script to run again
					if (!empty($ScheduledTaskShell_Task['ScheduledTask']['rrule'])) {
						$this->recur($ScheduledTaskShell_Task['ScheduledTask']);
					}
					
					exit();
					break;
			
				// Parent Process
				default:
					$db_connection = ConnectionManager::getDataSource('default')->connect();
					
					$this->ScheduledTask->id = $ScheduledTaskShell_Task['ScheduledTask']['id'];

					$this->ScheduledTask->saveField('host', gethostname());
					$this->ScheduledTask->saveField('pid', $pid);
					
					if (!isset($child_pids) || !is_array($child_pids)) {
						$child_pids = array();
					}
					$child_pids[$pid] = $pid;
					
					continue;
					break;
			}
		}
		
		// Forking children complete - wait on children to complete
		if (isset($child_pids) && is_array($child_pids)) {
			
			while (count($child_pids) > 0) {
				$status = null;
				
				$pid_wait = pcntl_waitpid(-1, $status, WNOHANG);
				
				foreach ($child_pids as $child_pid) {
					if ($pid_wait == $child_pid) {
						unset($child_pids[$child_pid]);
					}
				}
				
				usleep(100);
			}
		}
	}

	private function emailOutput($script, $results) {
		$host = gethostname();

		$output = [
			'user' => $this->user,
			'script' => $script,
			'results' => $results,
			'debug' => [
				'Environment' => APPLICATION_ENV,
				'HostName' => $host,
				'IP Address' => gethostbyname($host),
			],
		];

		$to[] = $this->default_admin_email;

		if (isset($this->user['email']) && $this->user['email'] !== $this->default_admin_email) {
			$to[] = $this->user['email'];
		}

		$subject = "Scheduled Task Output for $script";

		// send them an email with the new password
		$email = new CakeEmail();
		$email->transport('Amazon')
			->template('scheduled_tasks_output', 'default')
			->helpers('Html')
			->emailFormat('both')
			->from('cms@vevo.com')
			->to($to)
			->subject($subject)
			->viewVars(['output' => $output])
			->send();
	}

	// Interprets rrule and re-inserts the script to run based on the given schedule
	private function recur($task) {
		// return next run date based on rrule
		// todo: set timezone! - make sure all dates are in UTC
		$timezone = 'America/New_York';
		$dtz = new \DateTimeZone('UTC');
		$now = new \DateTime(null, $dtz);
		$startDate   = new \DateTime($task['start_time'], $dtz);
		
		$rrule = $task['rrule'];
		$rule = new \Recurr\Rule($rrule, $startDate, null, $timezone);
		$transformer = new \Recurr\Transformer\ArrayTransformer();

		// Fix last day of month issues - if set to run on the 31st, it will run on Feb 28, etc
		$transformerConfig = new \Recurr\Transformer\ArrayTransformerConfig();
		$transformerConfig->enableLastDayOfMonthFix();
		$transformer->setConfig($transformerConfig);

		// find the next date the script should run (after the current start time)
		// todo: test this!  seems to work for a simple minutely rule, but untested further...
		$next_start = $transformer->transform($rule)->startsAfter($now)->first()->getStart();
		// $next_start->setTimezone(new DateTimeZone('UTC'));

		$task['start_time'] = $next_start->format('Y-m-d H:i:s');
		$task['parent_id'] = $task['id'];
		unset($task['id'], $task['pid'], $task['run_time'], $task['end_time']);

		//todo: decrement COUNT in rrule for each successive run (may be other conditions like this that i haven't tested yet)
		$task['rrule'] = $rule->getString();

		// $task['rrule'] = "FREQ=MINUTELY;COUNT=1;INTERVAL=5;WKST=MO";

		// Check if a COUNT is set
		preg_match('~COUNT=(\d+);*~', $task['rrule'], $match);

		if (!empty($match)) {
			if ($match[1] > 1) {
				$count = (int)$match[1] - 1;
				// update rule with decremented count
				$task['rrule'] = str_replace($match[1], $count, $task['rrule']);
			} else {
				// end of COUNT, don't recurr
				return false;
			}
		}

		$this->ScheduledTask->create();
		if (!$this->ScheduledTask->save(['ScheduledTask' => $task])) {
			$this->emailOutput($task['script_to_run'], 'Error: Failed to insert script for recurrence');
		}
	}
}