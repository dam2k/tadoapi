<?php
/**
 * Author: Dino Ciuffetti - <dino@tuxweb.it>
 * Release date: 2023-08-04
 * License: MIT
 * NOTE: TadoApi is an unofficial TADO (tm) SDK implementation for PHP (only read only methods). It's a cool way to export metrics from your thermostats.
 *
 * TADO (tm) does not support its public api in no way. I get the api methods from a tado knowledgebase public post.
 * Also, thank to this post: https://shkspr.mobi/blog/2019/02/tado-api-guide-updated-for-2019/ and https://blog.scphillips.com/posts/2017/01/the-tado-api-v2/
 * It's working for me, may be this is also ok for you. Like any other open source software, the author cannot assume any warranty.
 */
declare(strict_types=1);

namespace dAm2K;

use League\OAuth2\Client\Provider\GenericProvider;
use GuzzleHttp\Client;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TadoApi
{
	private const TADO_MYAPI_BASEURI = 'https://my.tado.com/api/v2/';
	private array $config; // configuration
	private GenericProvider $provider; // tado oauth provider
	private string $access_token; // jwt access token taken from oauth provider
	private string $homeId; // just bind to a single home id
	private Client $client; // HTTP Client

	// true if expiration time is valid, false if expired or invalid
	private function checkJwtExpiration(string $access_token = ""): bool
	{
		$tks = explode('.', $access_token);
		if (empty($tks))
			return false;
		if (count($tks) < 3)
			return false;
		list($headb64, $bodyb64, $cryptob64) = $tks;
		$payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64));
		if (!isset($payload->exp))
			return false;
		$current_date = new \DateTime();
		$jwt_date = \DateTime::createFromFormat('U', (string) $payload->exp);
		if ($current_date > $jwt_date)
			return false; // expired
		return true; // not expired
	}

	private function getStateFile()
	{ // get state data from state file
		if (is_readable($this->config['statefile'])) {
			$state = json_decode(file_get_contents($this->config['statefile']));
			if (!empty($state->access_token))
				$this->access_token = $state->access_token;
		}
	}

	private function setStateFile()
	{ // set state data to state file
		$state = [
			'access_token' => $this->access_token
		];
		file_put_contents($this->config['statefile'], json_encode((object) $state));
	}

	private function renewAccessTokenIfNeeded()
	{
		if (!$this->checkJwtExpiration($this->access_token)) { // access token invalid or expired
			// get a new access token
			$this->access_token = $this->provider->getAccessToken('password', [
				'username' => $this->config['tado.username'],
				'password' => $this->config['tado.password'],
				'scope' => 'home.user',
			])->getToken();
			$this->setStateFile();
			return;
		}
		// using old access token
	}

	private function getData(string $method, string $url): \stdClass|array
	{
		$this->renewAccessTokenIfNeeded();
		$options['headers']['content-type'] = 'application/json';
		$request = $this->provider->getAuthenticatedRequest($method, $url, $this->access_token, $options);
		$response = $this->client->send($request);
		$responseContents=$response->getBody()->getContents();
		if(empty($responseContents)) return [];
		return json_decode($responseContents);
	}

	public function __construct(array $config, string $access_token = "")
	{
		$this->config = $config;
		$this->access_token = "";
		$this->getStateFile();
		if (!empty($access_token))
			$this->access_token = $access_token;
		$this->homeId = $config['tado.homeid'];
		$this->client = new Client();
		$this->provider = new GenericProvider([
			'clientId' => $this->config['tado.clientId'],
			'clientSecret' => $this->config['tado.clientSecret'],
			'urlAuthorize' => 'https://auth.tado.com/oauth/authorize',
			'urlAccessToken' => 'https://auth.tado.com/oauth/token',
			'urlResourceOwnerDetails' => null,
		]);
		// not checking access token now
	}

	public function getMe(): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'me');
	}

	public function getHome(string $homeId = ""): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId));
	}

	public function getWeather(string $homeId = ""): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/weather');
	}

	public function getDevices(string $homeId = ""): array
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/devices');
	}

	public function getInstallations(string $homeId = ""): array
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/installations');
	}

	public function getUsers(string $homeId = ""): array
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/users');
	}

	public function getMobiles(string $homeId = ""): array
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/mobileDevices');
	}

	public function getMobileSettings(string $homeId = "", string $deviceId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/mobileDevices/' . $deviceId . '/settings');
	}

	public function getZones(string $homeId = ""): array
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones');
	}

	public function getZoneStates(string $homeId = ""): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zoneStates');
	}

	public function getZoneState(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/state');
	}

	public function getZoneCapabilities(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/capabilities');
	}

	public function getZoneEarlyStart(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/earlyStart');
	}

	public function getZoneOverlay(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/overlay');
	}

	public function getZoneScheduleActiveTimetable(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/schedule/activeTimetable');
	}

	public function getZoneScheduleAway(string $homeId = "", string $zoneId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/schedule/awayConfiguration');
	}

	public function identifyDevice(string $deviceId): void
	{
		$this->getData('POST', self::TADO_MYAPI_BASEURI . 'devices/' . $deviceId . '/identify');
	}

	public function getTemperatureOffset(string $homeId = "", string $deviceId): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/devices/' . $deviceId . '/temperatureOffset');
	}

	public function getHomeState(string $homeId = ""): \stdClass
	{
		return $this->getData('GET', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/state');
	}

	public function isAnyoneAtHome(string $homeId = ""): bool
	{
		$users = $this->getUsers($homeId);
		foreach ($users as $user) {
			foreach ($user->mobileDevices as $device) {
				if ($device->location->atHome) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * This one aggregates some of the informations useful if you need a metric exporter.
	 * Get all your zones details and states along with all your devices.
	 * Temperatures, humidity, battery, firmware versions, serial numbers, models, etc in a single json. 
	 */
	public function getHomeMetrics(string $homeId = ""): \stdClass
	{
		$data = new \stdClass;
		$data->zones = [];

		$zones = $this->getZones($homeId);
		foreach ($zones as $zone) {
			$data->zones[$zone->id]['id'] = $zone->id;
			$data->zones[$zone->id]['name'] = $zone->name;
			$data->zones[$zone->id]['zonetype'] = $zone->type;
			foreach ($zone->devices as $key => $device) {
				$data->zones[$zone->id]['devices'][$key]['zoneid'] = $zone->id;
				$data->zones[$zone->id]['devices'][$key]['zonename'] = $zone->name;
				$data->zones[$zone->id]['devices'][$key]['zonetype'] = $zone->type;
				$data->zones[$zone->id]['devices'][$key]['deviceType'] = $device->deviceType;
				$data->zones[$zone->id]['devices'][$key]['serialNo'] = $device->serialNo;
				$data->zones[$zone->id]['devices'][$key]['shortSerialNo'] = $device->shortSerialNo;
				$data->zones[$zone->id]['devices'][$key]['currentFwVersion'] = $device->currentFwVersion;
				$data->zones[$zone->id]['devices'][$key]['connectionState'] = $device->connectionState->value;
				if (!empty($device->batteryState)) {
					$data->zones[$zone->id]['devices'][$key]['batteryState'] = $device->batteryState;
				} else {
					$data->zones[$zone->id]['devices'][$key]['batteryState'] = 'n/a';
				}
				if (!empty($device->mountingState->value)) {
					$data->zones[$zone->id]['devices'][$key]['mountingState'] = $device->mountingState->value;
				} else {
					$data->zones[$zone->id]['devices'][$key]['mountingState'] = 'n/a';
				}
				$data->zones[$zone->id]['devices'][$key] = (object) $data->zones[$zone->id]['devices'][$key];
			}
		}

		$states = $this->getZoneStates($homeId);
		// fprintf(STDERR, "%s\n", print_r($states, true));
		foreach ($states->zoneStates as $zoneId => $state) {
			//$data->zones[$zoneId]['type']=$state->setting->type;
			if (!empty($state->overlayType) && $state->overlayType == "MANUAL") { // manual overlay setted
				$data->zones[$zoneId]['type'] = "MANUAL";
			} else {
				$data->zones[$zoneId]['type'] = "AUTOMATIC";
			}
			$data->zones[$zoneId]['power'] = $state->setting->power;
			if (!empty($state->setting->temperature->celsius)) {
				$data->zones[$zoneId]['desired_celsius'] = $state->setting->temperature->celsius;
			} else {
				$data->zones[$zoneId]['desired_celsius'] = '0.00';
			}
			$data->zones[$zoneId]['linkstate'] = $state->link->state;
			$data->zones[$zoneId]['celsius'] = $state->sensorDataPoints->insideTemperature->celsius;
			$data->zones[$zoneId]['humidity'] = $state->sensorDataPoints->humidity->percentage;
			if (!empty($state->activityDataPoints->heatingPower->percentage)) {
				$data->zones[$zoneId]['heating_power'] = $state->activityDataPoints->heatingPower->percentage;
			} else {
				$data->zones[$zoneId]['heating_power'] = 0;
			}
			$data->zones[$zoneId] = (object) $data->zones[$zoneId];
		}
		$data->zones = array_values($data->zones);
		return $data;
	}
}
