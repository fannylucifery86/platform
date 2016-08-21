<?php
/**
 * GC and background tasks
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2016, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 *
 * This file is generously sponsored by Leigh Marble
 * Leigh Marble, independent musician, Portland, OR -- www.leighmarble.com --
 *
 */class CASHDaemon extends CASHData {
	private $user_id 	= false;
	private $history 	= false;
	private $runtime 	= 0;
	// define the schedule — it's a fuzzy schedule based on traffic
	// the jobs will run ~5min early/late, depending. keep that in mind.
	private $schedule	= array(
		"soundscan-digital" => array(
			"type" => "friday", // lowercase day
			"time" => "5:00 AM America/New_York" // time with timezone
		),
		"soundscan-physical" => array(
			"type" => "tuesday",
			"time" => "5:00 AM America/New_York"
		)
	);

	public function __construct($user_id=false) {
		$this->user_id = $user_id;
		$this->connectDB();
		$this->runtime = time();
		// get stored history
		$history_request = new CASHRequest(
			array(
				'cash_request_type' => 'system',
				'cash_action' => 'getsettings',
				'type' => 'daemon',
				'user_id' => -1
			)
		);
		if ($history_request->response['payload']) {
			$this->history = $history_request->response['payload'];
		} else {
			$this->history = array(
				'total_runs' 		=> 1,
				'last_run' 			=> $this->runtime,
				'last3_runs' 		=> array($this->runtime),
				'last_sceduled'	=> array()
			);
		}
	}

	private function cleanTempData($table,$conditional_column,$timestamp) {
		$this->db->deleteData(
			$table,
			array(
				$conditional_column => array(
					'condition' => '<',
					'value' => $timestamp
				)
			)
		);
	}

	private function clearExpiredSessions() {
		$this->cleanTempData('sessions','expiration_date',time());
	}

	private function clearOldTokens() {
		$this->cleanTempData('people_resetpassword','creation_date',time() - 86400);
	}

	private function runSchedule() {
		$total_runs = count($this->history['last3_runs']);
		// create an array of the gaps between tun times
		$spans = array($this->runtime - $this->history['last3_runs'][$total_runs - 1]);
		$i = $total_runs;
		while ($i > 1) {
			$spans[] = $this->history['last3_runs'][$i - 1] - $this->history['last3_runs'][$i - 2];
			$i--;
		}
		// assuming we have 3 last runs, we now have 3 spans. let's add a minimum span:
		$spans[] = 300;
		// now let's get a max, plus, you know...a little extra
		$max_span = floor(max($spans) * 1.15);

		// last thing we need to know is what day it is (lol)
		$today = strtolower(date('l'));

		foreach ($this->schedule as $key => $details) {
			if ($details['type'] == 'daily' || $today == $details['type']) {
				$target = strtotime($details['time']);
				// in case of first run
				$already_run = false;
				if (isset($this->history['last_sceduled'][$key])) {
					// if we ran the job this same day OR within an hour of the deadline
					// (within the hour so midnight jobs / timezones don't mess us up)
					if (date('mdY',$this->history['last_sceduled'][$key]) == date('mdY') ||
						abs($this->history['last_sceduled'][$key] - time()) < 3600) {
						$already_run = true;
					}
				}
				// if it hasn't already been run AND we're within the max span (+15%) of
				// the scheduled run time then we go. (the max span stuff is an attempt
				// to balance for slowed traffic load...)
				if (!$already_run && ($this->runtime + $max_span) > $target) {
					$this->runScheduledJob($key);
				}
			}
		}
	}

	private function runScheduledJob($type) {
		if (!$type) {
			return false;
		}
		switch ($type) {
			case 'soundscan-digital':
				doSoundScanReport('digital');
				break;
			case 'soundscan-physical':
				doSoundScanReport('physical');
				break;
		}
		$this->history['last_sceduled'][$type] = time();
	}

	private function doSoundScanReport($type) {
		if ($type == 'physical') {

		}
		if ($type == 'digital') {

		}
	}


	/****************************************************************************
	 *
	 * The destructor function is where all the magic actually happens
	 *
	 * 1. clean up old sessions and tokens
	 * 2. check/run scheduled jobs
	 * 3. update all the runtime stats/data for the daemon
	 *
	 ***************************************************************************/
	public function __destruct() {
		if ($this->history['last_run'] <= time() - 300) {
			$this->clearExpiredSessions();
			$this->clearOldTokens();
			$this->runSchedule();
			// update history
			$this->history['total_runs'] 		= $this->history['total_runs'] + 1;
			$this->history['last_run'] 		= $this->runtime;
			$this->history['last3_runs'][]	= $this->runtime;
			if (count($this->history['last3_runs']) > 3) {
				$this->history['last3_runs'] = array_slice($this->history['last3_runs'],-3);
			}
			// store settings for next run
			$history_request = new CASHRequest(
				array(
					'cash_request_type' => 'system',
					'cash_action' => 'setsettings',
					'type' => 'daemon',
					'user_id' => -1,
					'value' => $this->history
				)
			);
		}
	}
} // END class
?>
