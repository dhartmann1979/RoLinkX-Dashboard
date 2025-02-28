<?php
/*
*   RoLinkX Dashboard v2.98
*   Copyright (C) 2023 by Razvan Marin YO6NAM / www.xpander.ro
*
*   This program is free software; you can redistribute it and/or modify
*   it under the terms of the GNU General Public License as published by
*   the Free Software Foundation; either version 2 of the License, or
*   (at your option) any later version.
*
*   This program is distributed in the hope that it will be useful,
*   but WITHOUT ANY WARRANTY; without even the implied warranty of
*   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*   GNU General Public License for more details.
*
*   You should have received a copy of the GNU General Public License
*   along with this program; if not, write to the Free Software
*   Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
*/

/*
* System reporting / actions module
*/

include __DIR__ .'/../includes/functions.php';

$halt				= (isset($_POST['halt'])) ? filter_input(INPUT_POST, 'halt', FILTER_SANITIZE_NUMBER_INT) : '';
$reboot				= (isset($_POST['reboot'])) ? filter_input(INPUT_POST, 'reboot', FILTER_SANITIZE_NUMBER_INT) : '';
$rewifi				= (isset($_POST['rewifi'])) ? filter_input(INPUT_POST, 'rewifi', FILTER_SANITIZE_NUMBER_INT) : '';
$resvx				= (isset($_POST['resvx'])) ? filter_input(INPUT_POST, 'resvx', FILTER_SANITIZE_NUMBER_INT) : '';
$endsvx				= (isset($_POST['endsvx'])) ? filter_input(INPUT_POST, 'endsvx', FILTER_SANITIZE_NUMBER_INT) : '';
$switchHostName		= (isset($_POST['switchHostName'])) ? filter_input(INPUT_POST, 'switchHostName', FILTER_SANITIZE_NUMBER_INT) : '';
$latencyCheck		= (isset($_POST['latencyCheck'])) ? filter_input(INPUT_POST, 'latencyCheck', FILTER_SANITIZE_NUMBER_INT) : '';
$changeFS			= (isset($_POST['changeFS'])) ? filter_input(INPUT_POST, 'changeFS', FILTER_SANITIZE_ADD_SLASHES) : null;
$expandFS			= (isset($_POST['expandFS'])) ? filter_input(INPUT_POST, 'expandFS', FILTER_SANITIZE_ADD_SLASHES) : null;
$updateDash			= (isset($_POST['updateDash'])) ? filter_input(INPUT_POST, 'updateDash', FILTER_SANITIZE_NUMBER_INT) : null;
$updateRoLink		= (isset($_POST['updateRoLink'])) ? filter_input(INPUT_POST, 'updateRoLink', FILTER_SANITIZE_NUMBER_INT) : null;
$getVoices			= (isset($_POST['getVoices'])) ? filter_input(INPUT_POST, 'getVoices', FILTER_SANITIZE_NUMBER_INT) : null;
$makeRO				= (isset($_POST['makeRO'])) ? filter_input(INPUT_POST, 'makeRO', FILTER_SANITIZE_NUMBER_INT) : null;
$timezone			= (isset($_POST['timezone'])) ? filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_ADD_SLASHES) : null;

// Mixer control
$mixerControl	= (isset($_POST['mctrl'])) ? filter_input(INPUT_POST, 'mctrl', FILTER_SANITIZE_ADD_SLASHES) : '';
$mixerValue		= (isset($_POST['mval'])) ? filter_input(INPUT_POST, 'mval', FILTER_SANITIZE_NUMBER_INT) : '';

/* Configuration */
if (isset($_POST)) {
	$changed = false;
	$config = include '../config.php';
	foreach ($config as $cfgItem => $cfgItemValue) {
		if (isset($_POST[$cfgItem])) {
			$oldValue = $config[$cfgItem];
			$newValue = $_POST[$cfgItem];
			if ($oldValue != $newValue) {
				$config[$cfgItem] = $newValue;
				$changed = true;
			}
		}
	}
	if ($changed) {
		toggleFS(true);
		file_put_contents('../config.php', '<?php'. PHP_EOL .'return '. var_export($config, true) .';'. PHP_EOL);
		echo 'Configuration saved!';
		toggleFS(false);
		exit(0);
	}

	/* Time Zone */
	$currentTimezone = trim(file_get_contents('/etc/timezone'));
	if (isset($timezone) && $timezone != $currentTimezone) {
		serviceControl('rolink.service', 'stop');
		toggleFS(true);
		exec('/usr/bin/sudo /usr/bin/timedatectl set-timezone '. $timezone);
		toggleFS(false);
		serviceControl('rolink.service', 'start');
		serviceControl('rsyslog.service', 'restart');
		echo 'Timezone changed to <br/><b>'. $timezone .'</b>';
		exit(0);
	}

	/* Expand File System */
	if (isset($expandFS)) {
		toggleFS(true);
		exec('/usr/bin/sudo /usr/bin/systemctl start armbian-resize-filesystem.service');
		toggleFS(false);
		echo 'File system expanded!';
		exit(0);
	}

	/* Mixer control action */
	if (!empty($mixerControl)) {
		$mixerControls = array
		(
			'vac_out' => 'Line Out',
			'vac_dac' => 'DAC',
			'vac_mb' => 'Mic1 Boost',
			'vac_adc' => 'ADC Gain'
		);
		toggleFS(true);
		exec("/usr/bin/sudo /usr/bin/amixer set '$mixerControls[$mixerControl]' $mixerValue%");
		exec("/usr/bin/sudo /usr/sbin/alsactl store");
		toggleFS(false);
		echo $mixerControls[$mixerControl] .' / '. $mixerValue;
		exit(0);
	}
}

// Check details about connection (TCP Bandwidth / Latency & UDP Latency)
if ($latencyCheck == 1) echo latencyCheck();
function latencyCheck() {
	$cfgFile = '/opt/rolink/conf/rolink.conf';
	if (!is_file($cfgFile)) return 'RoLink not installed!';
	if (empty(exec("command -v qperf"))) return 'Application not installed!<br/>Please install <b>qperf</b> manually';
	preg_match('/HOST=(\S+)/', file_get_contents($cfgFile), $host);
	if (empty($host)) return 'Missing or wrong server address!';
	exec("/usr/bin/qperf -m 5k -t 1 $host[1] tcp_bw tcp_lat udp_bw udp_lat", $qperf);
	if (preg_match('/failed/', $qperf[1])) return 'Server not available.<br/>Try again later!';
	preg_match('/=\s+(.*)$/', $qperf[1], $tcp_bw);
	preg_match('/=\s+(.*)$/', $qperf[3], $tcp_lat);
	preg_match('/=\s+(.*)$/', $qperf[5], $udp_sbw);
	preg_match('/=\s+(.*)$/', $qperf[6], $udp_rbw);
	preg_match('/=\s+(.*)$/', $qperf[8], $udp_lat);
	return json_encode(array($tcp_bw[1], $tcp_lat[1], $udp_sbw[1], $udp_rbw[1], $udp_lat[1]));
}

/* Stop SVXLink */
if ($endsvx == 1) echo stopSVXLink();
function stopSVXLink() {
	unstick();
	serviceControl('rolink.service', 'stop');
	return true;
}

/* Restart SVXLink */
if ($resvx == 1) echo restartSVXLink();
function restartSVXLink() {
	unstick();
	serviceControl('rolink.service', 'restart');
	return true;
}

/* Restart Wi-Fi */
if ($rewifi == 1) echo wifiRestart();
function wifiRestart() {
	exec("/usr/bin/sudo /sbin/wpa_cli -i wlan0 reconfigure");
	return true;
}

/* Power Off System */
if ($halt == 1) sysHalt();
function sysHalt() {
	/* If stuck in TX, force exit */
	unstick();
	exec('/usr/bin/sudo /usr/sbin/halt -p');
	exit(0);
}

/* Reboot System */
if ($reboot == 1) sysReboot();
function sysReboot() {
	exec("/usr/bin/sudo /usr/sbin/reboot");
	exit(0);
}

/* Switch Host Name */
if ($switchHostName == 1) echo switchHostName();
function switchHostName() {
	$cfgFile = '/opt/rolink/conf/rolink.conf';
	if (!is_file($cfgFile)) return 'RoLink not installed!';
	$hostName = gethostname();
	preg_match('/CALLSIGN=(\S+)/', file_get_contents($cfgFile), $callSign);
	$newHostName = preg_replace('/[^a-zA-Z0-9\-\._]/', '', trim(strtolower($callSign[1])));
	if ($newHostName != 'N0CALL' && $hostName != $newHostName) {
		toggleFS(true);
		exec("/usr/bin/sudo /usr/bin/hostnamectl set-hostname $newHostName");
		exec("/usr/bin/sudo /usr/bin/sed -i 's/$hostName/$newHostName/' /etc/hosts");
		toggleFS(false);
		return 'Hostname has been changed from <br/><b>'. $hostName .'</b> to <b>'. $newHostName .'</b><br/>You need to reboot to apply changes.';
	} else {
		return 'Nothing changed.<br/>New and old hostnames are the same.';
	}
	return false;
}

/* Switch file system state */
if (!empty($changeFS)) echo switchFSState($changeFS);
function switchFSState($changeFS) {
	$askedFSS = ($changeFS == 'ro') ? true : false;
	toggleFS($askedFSS);
	echo 'File system status changed!';
}

/* Update dashboard */
if ($updateDash == 1) echo updateDashboard();
function updateDashboard() {
	toggleFS(true);
	exec("/usr/bin/sudo /opt/rolink/scripts/init update_dash", $reply);
	$result = ($reply[0] == 'Finished!') ? 'Update succeeded!' : 'Update failed!';
	toggleFS(false);
	return $result;
}

/* Update RoLink (svxlink) */
if ($updateRoLink == 1) echo updateRoLink();
function updateRoLink() {
	toggleFS(true);
	exec("/usr/bin/sudo /opt/rolink/scripts/init update_rolink", $reply);
	toggleFS(false);
	return $reply[0];
}

/* Download & install voice pack */
if ($getVoices == 1) echo getVoices();
function getVoices() {
	toggleFS(true);
	exec("/usr/bin/sudo /opt/rolink/scripts/init get_sounds", $reply);
	toggleFS(false);
	return $reply[0];
}

/* Make FS Read-only */
if ($makeRO == 1) echo makeRO();
function makeRO() {
	exec("/usr/bin/sudo /opt/rolink/scripts/init ro s", $reply);
	sleep(1);
	if (is_numeric($reply[0])) {
		$result = ($reply[0] == '0') ? '<b>Success! Please reboot</b>' : '<b>Completed with warnings (no watchdog)!</br>Please reboot</b>';
	} elseif (!empty($reply[0])) {
		$result = $reply[0];
	}
	return $result;
}
