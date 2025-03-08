<?php
/**
 * Author: Dino Ciuffetti - <dino@tuxweb.it>
 * Release date: 2025-03-07
 * License: MIT
 * NOTE: TadoApi is an unofficial TADO (tm) SDK implementation for PHP (only read only methods). It's a cool way to export metrics from your thermostats.
 *
 * TADO (tm) does not support its public api in no way. I get the api methods from a tado knowledgebase public post.
 * Also, thank to this post: https://shkspr.mobi/blog/2019/02/tado-api-guide-updated-for-2019/
 * It's working for me, may be this is also ok for you. Like any other open source software, the author cannot assume any warranty.
 *
 * NOTE: TADO requested all its unofficial REST API users to change the authentication method for security reasons.
 * This library implements the new device code grant flow with automatic refresh token and access token handling.
 * By design of how the auth scheme works, the first time, you need to authenticate with your tado credentials using your browser.
 * At this time this API will put the required url on STDERR, I'll work on a log implementation on my spare time.
 */
declare(strict_types=1);

use dAm2K\TadoApi;

require "vendor/autoload.php";

$tadoconf = [
	// this is the uuid they require: https://support.tado.com/en/articles/8565472-how-do-i-authenticate-to-access-the-rest-api
	'tado.clientId' => '1bb50063-6b0c-4d11-bd99-387f4a91cc46',
	'tado.homeid' => '36389', // your home's ID.
	'statefile' => '/var/tmp/dam2ktado_aeSh8aem.txt' // we put device code, access and refresh tokens here. On expiration, new tokens are saved here
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
