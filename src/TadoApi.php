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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class TadoApi
{
	private const TADO_MYAPI_BASEURI = 'https://my.tado.com/api/v2/';
	private array $config; // configuration
	//private GenericProvider $provider; // oauth provider for TADO API
	private string $access_token;
	private string $refresh_token;
	private string $device_code; 
	private int $device_code_retry_interval;
	private \DateTimeImmutable $device_code_expires;
	private \DateTimeImmutable $access_token_expires;
	private string $homeId; // just bind to a single home id
	private Client $client; // HTTP Client

	private function getStateFile(): void { // get state data from state file
		if (is_readable($this->config['statefile'])) {
			$state = json_decode(file_get_contents($this->config['statefile']));
			if (!empty($state->access_token))
				$this->access_token = $state->access_token;
			if (!empty($state->refresh_token))
				$this->refresh_token = $state->refresh_token;
			if (!empty($state->device_code))
				$this->device_code = $state->device_code;
			if (!empty($state->device_code_expires))
				$this->device_code_expires = new \DateTimeImmutable($state->device_code_expires);
			if (!empty($state->access_token_expires))
				$this->access_token_expires = new \DateTimeImmutable($state->access_token_expires);
			if (!empty($state->device_code_retry_interval))
				$this->device_code_retry_interval = $state->device_code_retry_interval;
		}
	}

	private function setStateFile():void { // set state data to state file
		$state = [
			'access_token' => $this->access_token,
			'refresh_token' => $this->refresh_token,
			'device_code' => $this->device_code,
			'device_code_expires' => $this->device_code_expires->format(\DateTimeImmutable::ATOM),
			'access_token_expires' => $this->access_token_expires->format(\DateTimeImmutable::ATOM),
			'device_code_retry_interval' => $this->device_code_retry_interval
		];
		file_put_contents($this->config['statefile'], json_encode((object) $state));
	}
	
	private function getData(string $method, string $url): \stdClass|array {
		$this->getAccessToken();
		$options['headers']['content-type'] = 'application/json';
		$options['headers']['Authorization'] = 'Bearer '.$this->access_token;
		$options['timeout'] = 5; // Response timeout
		$options['connect_timeout'] = 5; // Connection timeout
		$response = $this->client->request($method, $url, $options);
		$responseContents=$response->getBody()->getContents();
		if(empty($responseContents)) return [];
		return json_decode($responseContents);
	}
	
	private function setData(string $method, string $url, string $data): \stdClass|array {
		$this->getAccessToken();
		$options['headers']['content-type'] = 'application/json';
		$options['headers']['Authorization'] = 'Bearer '.$this->access_token;
		$options['timeout'] = 5; // Response timeout
		$options['connect_timeout'] = 5; // Connection timeout
		$options['body']=$data;
		$response = $this->client->request($method, $url, $options);
		$responseContents=$response->getBody()->getContents();
		if(empty($responseContents)) return [];
		return json_decode($responseContents);
	}
	
	/**
	 * Get deviceCode from the stateFile (if not expired), or create a new one to be authenticated by the user's browser.
	 */
	private function getDeviceCode(): void {
		$cd = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
		if(!empty($this->device_code) && !empty($this->device_code_expires && !empty($this->device_code_retry_interval))) { // device code got from the state file
			if($cd->getTimestamp() < $this->device_code_expires->getTimestamp()) { // device code has NOT expired
				return;
			} // device code expired before user auth. Request another one
		}
		
		$response = $this->client->post('https://login.tado.com/oauth2/device_authorize', [
			'form_params' => [
				'client_id' => $this->config['tado.clientId'],
				'scope'     => 'offline_access',
			], [
				'timeout' => 5, // Response timeout
				'connect_timeout' => 5, // Connection timeout
			]
		]);
		
		/* {"device_code":"ftcrinX_KQNXUNI1wkh-5zxMmmYOUug43SAYWORssAU","expires_in":300,"interval":5,"user_code":"9HBZPZ","verification_uri":"https://login.tado.com/oauth2/device","verification_uri_complete":"https://login.tado.com/oauth2/device?user_code=9HBZPZ"} */
		
		$data = json_decode($response->getBody()->getContents(), true);
		$this->device_code = $data['device_code'];
		$this->device_code_retry_interval = $data['interval'];
		$ed = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
		$ed = $ed->add(new \DateInterval('PT'.($data['expires_in']-5).'S'));
		$this->device_code_expires = $ed;
		$this->setStateFile();
		// Show the user the authorization link to be executed on its browser with its tado credentials.
		throw new TadoApiDeviceCodeAuthException("deviceCode ". $this->device_code ." must be manually verified by a browser. Please, go to: " . $data['verification_uri_complete'] . " before " . $ed->format('Y-m-d H:i:s') . " and put in this user code to the web form: " . $data['user_code']);
		return;
	}
	
	/**
	 * Get access token and refresh token from the stateFile, create access token from refresh token or request new tokens if refresh is not valid
	 */
	private function getAccessToken(): void {
		$cd = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
		if(!empty($this->access_token) && !empty($this->refresh_token) && !empty($this->access_token_expires)) { // got access token from the state file
                        if($cd->getTimestamp() < $this->access_token_expires->getTimestamp()) { // access token has NOT expired
				// we hope that the access token is valid, because it's not expired
                                return;
                        } // access token expired. Request a new access token with the refresh token, hoping the refresh token it's still valid
			$response = $this->client->post('https://login.tado.com/oauth2/token', [
				'form_params' => [
					'client_id' => $this->config['tado.clientId'],
					'grant_type' => 'refresh_token',
					'refresh_token' => $this->refresh_token
				], [
					'timeout' => 5, // Response timeout
					'connect_timeout' => 5, // Connection timeout
				]
			]);
			// TODO: handle case where refresh token is not valid: invalid_grant
			$data = json_decode($response->getBody()->getContents(), true);
			if (isset($data['access_token'])) {
				$this->access_token=$data['access_token'];
				$this->refresh_token=$data['refresh_token'];
				$ed = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
				$ed = $ed->add(new \DateInterval('PT'.($data['expires_in']-5).'S'));
				$this->access_token_expires = $ed;
				$this->setStateFile();
				return;
			}
                }
		
		$interval = $this->device_code_retry_interval ?? 5; // sleep 5 seconds between each retry
		$maxAttempts = 120 / $interval; // retry for about two minutes in case we need the browser to authenticate
		for($i=0; $i<$maxAttempts; $i++) {
			try {
				$this->getDeviceCode(); // we will get this one from the state file, or a new one if expired
				$response = $this->client->post('https://login.tado.com/oauth2/token', [
					'form_params' => [
						'client_id' => $this->config['tado.clientId'],
						'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
						'device_code' => $this->device_code
					],
				]);
				$data = json_decode($response->getBody()->getContents(), true);
				if (isset($data['access_token'])) {
					/*
					echo "Access Token: " . $data['access_token'] . "\n";
					echo "Refresh Token: " . $data['refresh_token'] . "\n";
					echo "Expires in: " . $data['expires_in'] . " secondi\n";
					*/
					/*
					[access_token] => eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImd0eSI..............................KcYbYQ
					[expires_in] => 599
					[refresh_token] => 6Vu0vQadysY-1G6n6R8gdp_y-TgFtakh72C7KVKN-uUxgbM3EWHTzxTe2D6ZD88W
					[refresh_token_id] => 9f75bb86-8d55-4778-9268-313bbd1bc1a5
					[scope] => offline_access
					[token_type] => Bearer
					[userId] => 595e4511-098f-8000-3325-0adc23930000
					*/
				}
				$this->access_token=$data['access_token'];
				$this->refresh_token=$data['refresh_token'];
				$ed = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
				$ed = $ed->add(new \DateInterval('PT'.($data['expires_in']-5).'S'));
				$this->access_token_expires = $ed;
				$this->setStateFile();
				return;
			} catch (ClientException $e) {
				$errorResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
				if ($errorResponse['error'] === 'authorization_pending') {
					fprintf(STDERR, "%s", "Still pending user OAUTH2 authorization. Device code expires at: ".$this->device_code_expires->format('Y-m-d H:i:s')."\n");
				} else {
					throw new \Exception($errorResponse['error_description']);
				}
			} catch (TadoApiDeviceCodeAuthException $e) {
				fprintf(STDERR, "%s\n", $e->getMessage());
			}
			sleep($interval);
		}
		throw new TadoApiDeviceCodeAuthException("Cannot authenticate with device code to obtain refresh and access tokens.");
	}
	
	public function __construct(array $config)
	{
		$this->config = $config;
		$this->access_token = "";
		$this->refresh_token = "";
		$this->access_token_expires = new \DateTimeImmutable();
		$this->access_token_expires = $this->access_token_expires->sub(new \DateInterval('P1Y')); // 1 year ago
		//$this->device_code_expires=new \DateTimeImmutable;
		$this->device_code = "";
		//$this->device_code_retry_interval=5;
		$this->getStateFile();
		// TODO: write how to find Tado homeId
		$this->homeId = $config['tado.homeid'];
		$this->client = new Client();
		$this->getAccessToken();
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

	public function setZoneOverlay(string $homeId = "", string $zoneId, string $data): \stdClass
	{
		return $this->setData('PUT', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/overlay', $data);
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

        public function disableVSwing(string $homeId = "", string $zoneId): \stdClass
        {
                return $this->getData('PUT', self::TADO_MYAPI_BASEURI . 'homes/' . (!empty($homeId) ? $homeId : $this->homeId) . '/zones/' . $zoneId . '/overlay');
        }

}
