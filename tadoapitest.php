<?php
/**
 * Author: Dino Ciuffetti - <dino@tuxweb.it>
 * Release date: 2023-08-04
 * License: MIT
 * NOTE: TadoApi is an unofficial TADO (tm) SDK implementation for PHP (only read only methods). It's a cool way to export metrics from your thermostats.
 *
 * TADO (tm) does not support its public api in no way. I get the api methods from a tado knowledgebase public post.
 * Also, thank to this post: https://shkspr.mobi/blog/2019/02/tado-api-guide-updated-for-2019/
 * It's working for me, may be this is also ok for you. Like any other open source software, the author cannot assume any warranty.
 */
declare(strict_types=1);

use dAm2K\TadoApi;

require "vendor/autoload.php";

$tadoconf = [
	// Tado client ID and secret from https://my.tado.com/webapp/env.js
	'tado.clientId' => 'tado-web-app',
	'tado.clientSecret' => 'taG9tXxzGrIFWixUT1nZnzIjlovENGe0KNAB51ADKZQjSlNBvhs0xbT6tC4jIUaC',
	'tado.username' => 'yourtadoemail@email.com',
	'tado.password' => 'yourtadopassporcoziochenotiziamacomelhamessadentroguarda',
	'tado.homeid' => '36389',
	// your home's ID.
	'statefile' => '/tmp/dam2ktado_aeSh8aem.txt' // we put access token here. When the AT expires a new one get collected and saved here
];

$tado = new TadoApi($tadoconf);
//$o=$tado->getMe();
//$o=$tado->getHome();
//$o=$tado->getWeather();
//$o=$tado->getInstallations();
//$o=$tado->getUsers();
//$o=$tado->getMobiles();
//$o=$tado->getMobileSettings("", "7044858");
//$o=$tado->getZones();
//$o=$tado->getZoneState("", "7");
//$o=$tado->getZoneStates("");
//$o=$tado->getZoneCapabilities("", "7");
//$o=$tado->getZoneEarlyStart("", "7");
//$o=$tado->getZoneOverlay("", "7");
//$o=$tado->getZoneScheduleActiveTimetable("", "7");
//$o=$tado->getZoneScheduleAway("", "7");
//$o=$tado->getHomeState();
//$o=$tado->isAnyoneAtHome();
//$tado->identifyDevice("RU0123456789");
$o = $tado->getHomeMetrics();
print_r(json_encode($o));
