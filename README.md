# tadoapi
TadoApi is an unofficial TADO (tm) SDK implementation for PHP. I implemented this as a metrics exporter, but you could change your thermostat or AC temperature, for example, writing your overlay and setting data with setZoneOverlay() method:
```
$tado->setZoneOverlay("", "8", '{"type":"MANUAL","setting":{"type":"AIR_CONDITIONING","power":"ON","mode":"COOL","temperature":{"celsius":25},"fanLevel":"LEVEL2","verticalSwing":"OFF"}}');
```

It's working for me, may be this is also ok for you. Like any other open source software, the author cannot assume any warranty.
# Installation
```
composer require dam2k/tadoapi
```
# Public Methods
The implemented and exported public methods are given below. They are self explicative.

For me getHomeMetrics() is the most useful, because it aggregates some of the informations useful if you need a metric exporter.
It will fetch all your zones details and states along with all your devices:

Temperatures, humidity, battery, firmware versions, serial numbers, models, etc in a single json. 
```
public function getMe(): \stdClass
public function getHome(string $homeId = ""): \stdClass
public function getWeather(string $homeId = ""): \stdClass
public function getDevices(string $homeId = ""): array
public function getInstallations(string $homeId = ""): array
public function getUsers(string $homeId = ""): array
public function getMobiles(string $homeId = ""): array
public function getMobileSettings(string $homeId = "", string $deviceId): \stdClass
public function getZones(string $homeId = ""): array
public function getZoneStates(string $homeId = ""): \stdClass
public function getZoneState(string $homeId = "", string $zoneId): \stdClass
public function getZoneCapabilities(string $homeId = "", string $zoneId): \stdClass
public function getZoneEarlyStart(string $homeId = "", string $zoneId): \stdClass
public function getZoneOverlay(string $homeId = "", string $zoneId): \stdClass
public function getZoneScheduleActiveTimetable(string $homeId = "", string $zoneId): \stdClass
public function getZoneScheduleAway(string $homeId = "", string $zoneId): \stdClass
public function identifyDevice(string $homeId = "", string $deviceId): \stdClass
public function getTemperatureOffset(string $homeId = "", string $deviceId): \stdClass
public function getHomeState(string $homeId = ""): \stdClass
public function isAnyoneAtHome(string $homeId = ""): bool
public function getHomeMetrics(string $homeId = ""): \stdClass
public function setZoneOverlay(string $homeId = "", string $zoneId, string $data): \stdClass
```
# Example of usage
```
use dAm2K\TadoApi;
require "vendor/autoload.php";

$tadoconf = [
	// this is the uuid they require: https://support.tado.com/en/articles/8565472-how-do-i-authenticate-to-access-the-rest-api
	'tado.clientId' => '1bb50063-6b0c-4d11-bd99-387f4a91cc46',
	'tado.homeid' => '36389', // your home's ID.
	'statefile' => '/tmp/dam2ktado_aeSh8aem.txt' // we put device code, access and refresh tokens here. On expiration, new tokens are saved here
];

$tado = new TadoApi($tadoconf);

$o = $tado->getHomeMetrics();
print_r(json_encode($o));
```

# No official support
TADO (tm) does not support its public api in no way. I get the api methods from a tado knowledgebase public post.
Also, thank to this post: https://shkspr.mobi/blog/2019/02/tado-api-guide-updated-for-2019/ and https://blog.scphillips.com/posts/2017/01/the-tado-api-v2/

# Device code grant flow
NOTE: TADO requested all its unofficial REST API users to change the authentication method for security reasons.
This library implements the new device code grant flow with automatic refresh token and access token handling.
By design of how the auth scheme works, the first time, you need to authenticate with your tado credentials using your browser.
At this time this API will put the required url on STDERR, I'll work on a log implementation on my spare time.

For more informations, please check their official post: https://support.tado.com/en/articles/8565472-how-do-i-authenticate-to-access-the-rest-api
