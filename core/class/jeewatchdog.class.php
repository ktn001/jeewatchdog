<?php
// vi: tabstop=4 autoindent
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class jeewatchdog extends eqLogic {
	/*     * **************************************************************** */
	/*     * *************************Attributs****************************** */
	/*     * **************************************************************** */


	/*     * **************************************************************** */
	/*     * ***********************Methodes static************************** */
	/*     * **************************************************************** */

	/**
	*** Traitement des event reçus du watchdog via l'API de jeedom
	***/
	public static function event() {
		log::add(__CLASS__,'debug', json_encode($_GET));
		if (init('id') != '') {
			$cmd = jeewatchdogCmd::byId(init('id'));
			if (!is_object($cmd) || $cmd->getEqType() != __CLASS__) {
				throw new Exception(sprintf(__('Commande %s introuvable ou pas de type %s', __FILE__), init('id'), __CLASS__));
			}
		}
		$cmd->event(init('value'));
	}

	/**
	*** Methode static appelée par le cron
	***/
	public static function kickWatchdog($_options) {
		log::add(__CLASS__, 'debug', 'kickWatchdog');
		$jeewatchdog = eqLogic::byId($_options['EqLogic_id']);
		if (is_object($jeewatchdog) && $jeewatchdog->getIsEnable() == 1) {
			$jeewatchdog->_kickWatchdog();
		}
	}

	/**
	*** Définitions des modèles Shelly reconnus
	***/
	public static function getModel($model = null) {
		$models = file_get_contents(__DIR__ . "/../config/models.json");
		$models = json_decode($models, true);
		if ($model === null) {
			return $models;
		}
		if (isset($models[$model])){
			return $models[$model];
		}
		return null;
	}

	/*     * ***************************************************************** */
	/*     * *********************Méthodes d'instance************************* */
	/*     * ***************************************************************** */

	/**
	*** Configuration par défaut à la créaton de l'eqLogic
	***/
	public function preInsert() {
		$this->setConfiguration('kickmode', 'cron');
		$this->setConfiguration('watchdogTimeout', 15);
		$this->setConfiguration('offDuration', 3);
	}

	/**
	*** Vérification de la validité des configuration avant la à jour de l'eqLogic
	***/
	public function preUpdate() {
		if ($this->getConfiguration('watchdogTimeout', 0) == 0) {
			$this->setConfiguration('watchdogTimeout', 15);
		}
		if ($this->getConfiguration('offDuration', 0) == 0) {
			$this->setConfiguration('offDuration', 3);
		}
	}

	/**
	*** Création des commandes en même temps que l'eqLogic
	***/
	public function postInsert() {
		$this->createCmds();
	}

	/**
	*** Mise à jour du cron et de l'appareil
	***/
	public function postSave() {
		$this->createOrUpdateCron();
	}

	/**
	*** Suppression du cron à la suppression de l'eqLogic
	***/
	public function preRemove() {
		$cron = $this->getCron(false);
		if (is_object($cron)) {
			$cron->remove();
		}
	}

	/**
	*** Le password est crypté en DB
	***/
	public function decrypt() {
		$this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
	}
	public function encrypt() {
		$this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
	}

	/**
	*** Création des commandes
	***/
	public function createCmds() {
		$cmd = $this->getCmd('info','maintenance');
		if (!is_object($cmd)) {
			$cmd = new jeewatchdogCmd();
			$cmd->setEqLogic_Id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('binary');
			$cmd->setLogicalId('maintenance');
			$cmd->setName(__('maintenance',__FILE__));
			$cmd->save();
		}

		$cmd = $this->getCmd('action','kick');
		if (!is_object($cmd)) {
			$cmd = new jeewatchdogCmd();
			$cmd->setEqLogic_Id($this->getId());
			$cmd->setType('action');
			$cmd->setSubType('other');
			$cmd->setLogicalId('kick');
			$cmd->setName('kick');
			$cmd->save();
		}
	}

	/**
	*** Lecture du cron pour l'eqLogic
	***/
	private function getCron($createNew = true) {
		$options = ['EqLogic_id' => intval($this->getId())];
		$cron = cron::byClassAndFunction(__CLASS__, 'kickWatchdog', $options);
		if (!is_object($cron) && $createNew) {
			log::add(__CLASS__,'debug',sprintf(__("Création du cron pour %s (id: %s)", __FILE__),$this->getName(),$this->getId()));
			$cron = new cron();
			$cron->setClass(__CLASS__);
			$cron->setFunction('kickWatchdog');
			$cron->setOption($options);
			$cron->setDeamon(0);
		}
		return $cron;
	}

	/**
	*** Création ou mise à jour du cron de l'eqLogic
	***/
	private function createOrUpdateCron() {
		if ($this->getConfiguration("kickmode") != 'cron') {
			$cron = $this->getCron(false);
			if (is_object($cron)) {
				$cron->remove();
			}
			return;
		}
		$watchdogTimeout = $this->getConfiguration('watchdogTimeout');
		if ($watchdogTimeout == '') {
			log::add(__CLASS__,'warning',__("Le timeout du watchdog n'est pas défini!",__FILE__));
			return;
		}
		$watchdogTimeout = intval($watchdogTimeout);
		$minutes = 10;
		if ($watchdogTimeout <= 30) {
			$minutes = 5;
		}
		if ($watchdogTimeout <= 15) {
			$minutes = 3;
		}
		if ($watchdogTimeout <= 10) {
			$minutes = 1;
		}
		$cron = $this->getCron();
		$cron->setSchedule("*/{$minutes} * * * *");
		$cron->setTimeout(1);
		$cron->setEnable($this->getIsEnable());
		$cron->save();
	}

	private function postRequest ($data) {
		$deviceIP = $this->getConfiguration('deviceIP');

		// Ajout d'une identification de la quequête
		$data['id'] = $this->getCache('requestId',1);
		$this->setCache('requestId',$data['id']+1);

		// Ajout, si nécessaire, de la source de la requête
		if (!isset($data['src'])) {
			$data['src'] = "jeedom::" . config::byKey('name');
		}

		log::add(__CLASS__,"debug", "  " . sprintf(__("Envoi de la requete %s",__FILE__),json_encode($data)));
		$ch = curl_init('http://' . $deviceIP . "/rpc");
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		$response = curl_exec($ch);
		if ($response === false) {
			log::add(__CLASS__,'error', __("Erreur lors de l'envoi de la requête",__FILE__) . ': ['. curl_errno($ch) . '] ' . curl_error($ch));
			throw new Exception (__("Erreur lors de l'envoi de la requête",__FILE__));
		}

		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$return = [
			'header' => substr($response, 0, $headerSize),
			'body'   => substr($response, $headerSize),
			'code'   => curl_getinfo($ch, CURLINFO_HTTP_CODE),
		];
		curl_close($ch);
		return $return;
	}

	/**
	*** Envoi de données à l'appareil avec gestion de l'authentication
	***/
	private function sendToDevice($data) {
		$indent = '';
		// récupération des données d'authentication
		$password = $this->getConfiguration('password');
		$currentNonce = $this->getCache('currentNonce', null);
		$ncCounter = $this->getCache('ncCounter', 0);
		$currentRealm = $this->getCache('currentRealm', null);

		/**
		*** Fonction interne pour isoler le calcul de la signature Shelly
		***/
		$injectShellyAuth = function ($data, $password, $realm, $nonce, $nc) {
			$cnonce = rand(100000, 999999);

			$ha1 = hash('sha256', "admin:" . $realm . ":" . $password);
			$ha2 = hash('sha256', "dummy_method:dummy_uri");

			$responseParts = [$ha1, $nonce, (string)$nc, (string)$cnonce, 'auth', $ha2];
			$responseHash = hash('sha256', implode(':', $responseParts));

			$data['auth'] = [
				'realm'    => $realm,
				'username' => 'admin',
				'nonce'    => $nonce,
				'cnonce'   => $cnonce,
				'nc'       => $nc,
				'response' => $responseHash,
				'algorithm'=> 'SHA-256'
			];
			return $data;
		};

		// 1. Si nous avons déjà un nonce en mémoire, on tente directement une requête signée
		if ($currentNonce !== null) {
			$ncCounter++; // Incrémentation du compteur à chaque nouvel appel
			$data = $injectShellyAuth($data, $password, $currentRealm, $currentNonce, $ncCounter);
		}

		$indent .= '  ';
		$response = $this->postRequest($data);

		// 2. Si le nonce a expiré entre temps ou si c'est le tout premier appel (401)
		if ($response['code'] == 401) {
			log::add(__CLASS__,"debug",$indent . __("(ré)authentification",__FILE__));
			$headers = explode("\n", $response['header']);
			$vars = [];
			foreach ($headers as $line) {
				if (str_starts_with(trim($line), 'WWW-Authenticate:')){
					preg_match_all('/(\w+)=(?:"([^"]*)"|([^,\s]+))/', $line, $matches, PREG_SET_ORDER);
					foreach ($matches as $match) {
						$key = $match[1];
						$value = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3];
						$vars[$key] = $value;
					}
					$this->setCache('requestId',1);
					break;
				}
			}

			if (count($vars) == 0){
				throw new Exception(__("Infos d'authentification non fournies", __FILE__));
			}

			// Sauvegarde des nouvelles informations dans l'instance de classe
			$currentNonce = $vars['nonce'];
			$currentRealm = $vars['realm'];
			$ncCounter    = 0; // On réinitialise le compteur à 1 pour ce nouveau nonce

			// On injecte la nouvelle authentification fraîchement générée
			$ncCounter++;
			$data = $injectShellyAuth($data, $password, $currentRealm, $currentNonce, $ncCounter);

			// Deuxième essai avec les bonnes informations
			$response = $this->postRequest($data);
		}

		if ($response['code'] == 401) {
			// On retire du cache les donnée d'authentication
			$this->setCache('currentNonce', null);
			$this->setCache('ncCounter', null);
			$this->setCache('currentRealm', 0);
			throw new Exception (__("Accès au switch non autorisé. Veuillez vérifier le password.",__FILE__));
		}

		// mise en cache des données d'authentication
		$this->setCache('currentNonce', $currentNonce);
		$this->setCache('ncCounter', $ncCounter);
		$this->setCache('currentRealm', $currentRealm);

		if ($response['code'] != 200) {
			log::add(__CLASS__, "error", "Code HTTP final : " . $response['code']);
			log::add(__CLASS__, "error", "header: ". $response['header']);
			log::add(__CLASS__, "error", "body: ". $response['body']);
			throw new Exception (
				sprintf(__("Code HTTP: %s: %s <br>Veuillez vérifier le log du plugin pour plus d'info.",__FILE__),$code,$body));
		}
		$indent .= '  ';
		log::add(__CLASS__, "debug", $indent . "Code HTTP final : " . $response['code']);
		log::add(__CLASS__, "debug", $indent . "body: ". $response['body']);

		$return = json_decode($response['body'], true);
		if (isset($return['error'])) {
			throw new Exception(sprintf("error(%s): %s",$return['error']['code'],$return['error']['message']));
		}
		if (isset($return['result']) and isset($return['result']['restart_required']) and $return['result']['restart_required']) {
			$this->_restartRequired = true;
		}
		return $return;
	}

	/**
	*** Vérification d'une réponse du Shelly
	***/
	private function checkShellyAnswer ($answer) {
		if (isset ($answer['error'])) {
			throw new Exception(sprintf("error(%s): %s",$answer['error']['code'],$answer['error']['message']));
		}
		return true;
	}

	/**
	*** Récupération de la config du Device
	***/
	private function deviceConfig($l1Key=null, $l2Key=null, $l3Key=null, $l4key=null) {
		if (!isset($this->_deviceConfig) or !is_array($this->_deviceConfig)) {
			log::add(__CLASS__,"info",__("Récupération de la configuration actuelle de l'appareil",__FILE__));
			$data = [ "method" => "Shelly.GetConfig" ];
			$answer = $this->sendToDevice($data);
			$this->_deviceConfig = $answer['result'];
		}
		$return = $this->_deviceConfig;
		if ($l1Key !== null) {
			if (isset($return[$l1Key])) {
				$return = $return[$l1Key];
				if ($l2Key !== null) {
					if (isset($return[$l2Key])) {
						$return = $return[$l2Key];
						if ($l3Key !== null) {
							if (isset($return[$l3Key])) {
								$return = $return[$l3Key];
								if ($l4key !== null) {
									if (isset($return[$l4key])) {
										$return = $return[$l4key];
									} else {
										$return = null;
									}
								}
							} else {
								$return = null;
							}
						}
					} else {
						$return = null;
					}
				}
			} else {
				$return = null;
			}
		}
		return $return;
	}

	/**
	*** Configuration du composant "System" de l'appareil
	***/
	private function configureDeviceSystem() {
		$config = [];

		// Configuration du nom de l'appareil
		$jeedomName = config::byKey('name');
		$deviceName = $jeedomName == '' ? $this->getName() : $jeedomName . "::" . $this->getName();
		$actualDeviceName = $this->deviceConfig('sys','device','name');
		if ($actualDeviceName != $deviceName) {
			log::add(__CLASS__,"info",__("Configuration du nom de l'appareil",__FILE__) . ": '$actualDeviceName' => '$deviceName'");
			if (!isset($config['device'])) $config['device'] = [];
			$config["device"]["name"] = $deviceName;
		}

		// Configuration de debug mqtt
		if ($this->deviceConfig('sys','debug','mqtt','enable')){
			log::add(__CLASS__,"info",__("Désactivation de debug mqtt",__FILE__));
			if (!isset($config['debug'])) $config['debug'] = [];
			if (!isset($config['debug']['mqtt'])) $config['debug']['mqtt'] = [];
			$config['debug']['mqtt']['enable'] = false;
		}

		// Configuration de debug websocket
		if (!$this->deviceConfig('sys','debug','websocket','enable')){
			log::add(__CLASS__,"info",__("Désactivation de debug websocket",__FILE__));
			if (!isset($config['debug'])) $config['debug'] = [];
			if (!isset($config['debug']['websocket'])) $config['debug']['websocket'] = [];
			$config['debug']['websocket']['enable'] = true;
		}

		if (count($config) > 0) {
			$data = [
				"method" => "Sys.SetConfig",
				"params" => [
					'config' => $config
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "Wifi" de l'appareil
	***/
	private function configureDeviceWifi() {
		// Configuration le l'access point
		if ($this->deviceConfig('wifi','ap','enable')){
			log::add(__CLASS__,"info",__("Désactivation de l'access point",__FILE__));
			if (!isset($config['ap'])) $config['ap'] = [];
			$data = [
				"method" => "Wifi.SetConfig",
				"params" => [
					'config' => [
						'ap' => [
							'enable' => false
						]
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "BLE" de l'appareil
	***/
	private function configureDeviceBLE() {
		if ($this->deviceConfig('ble','rpc','enable')){
			log::add(__CLASS__,"info",__("Désactivation de BLE",__FILE__));
			if (!isset($config['rpc'])) $config['rpc'] = [];
			$data = [
				"method" => "BLE.SetConfig",
				"params" => [
					'config' => [
						'rpc' => [
							'enable' => false
						]
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "Cloud" de l'appareil
	***/
	private function configureDeviceCloud() {
		if ($this->deviceConfig('cloud','enable')){
			log::add(__CLASS__,"info",__("Désactivation du cloud",__FILE__));
			$data = [
				"method" => "Cloud.SetConfig",
				"params" => [
					'config' => [
						'enable' => false
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "Modbus" de l'appareil
	***/
	private function configureDeviceModbus() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		if ($device['modbus'] == 0) {
			return;
		}
		if ($this->deviceConfig('modbus','enable')){
			log::add(__CLASS__,"info",__("Désactivation du modbus",__FILE__));
			$data = [
				"method" => "Modbus.SetConfig",
				"params" => [
					'config' => [
						'enable' => false
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "KNX" de l'appareil
	***/
	private function configureDeviceKNX() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		if ($device['knx'] == 0) {
			return;
		}
		if ($this->deviceConfig('knx','enable')){
			log::add(__CLASS__,"info",__("Désactivation du KNX",__FILE__));
			$data = [
				"method" => "KNX.SetConfig",
				"params" => [
					'config' => [
						'enable' => false
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "Mqtt" de l'appareil
	***/
	private function configureDeviceMqtt() {
		if ($this->deviceConfig('mqtt','enable')){
			log::add(__CLASS__,"info",__("Désactivation du mqtt",__FILE__));
			$data = [
				"method" => "MQTT.SetConfig",
				"params" => [
					'config' => [
						'enable' => false
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du composant "Outbound Websocket" de l'appareil
	***/
	private function configureDeviceWs() {
		if ($this->deviceConfig('ws','enable')){
			log::add(__CLASS__,"info",__("Désactivation de outbound Websocket",__FILE__));
			$data = [
				"method" => "Ws.SetConfig",
				"params" => [
					'config' => [
						'enable' => false
					]
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration de l'input pour le bouton de maintenance
	***/
	private function configureDeviceInput() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		if ($device['nbInput'] == 0) {
			return;
		}
		if ($device['nbInput'] > 1) {
			throw new Exception (__("Les appareils ayant plusieurs inputs ne sont pas supportés",__FILE__));
		}
		$config = [];

		if (! $this->deviceConfig('input:0','enable')){
			log::add(__CLASS__,"info",__("Activation de l'input 0 (bouton maintenance)",__FILE__));
			$config['enable'] = true;
		}

		$name = config::byKey('name') . "::" . __("maintenance",__FILE__);
		$inputName = $this->deviceConfig('input:0','name');
		if ( $inputName != $name ) {
			log::add(__CLASS__,"info",__("Configuration du nom de l'input (bouton maintenance)",__FILE__));
			$config['name'] = $name;
		}

		if ( "switch" != $this->deviceConfig('input:0','type')){;
			log::add(__CLASS__,"info",__("Activation de l'input 0 (bouton maintenance)",__FILE__));
			$config['type'] = 'switch';
		}

		if (count($config) > 0) {
			$params['id'] = 0;
			$data = [
				"method" => "Input.SetConfig",
				"params" => [
					'config' => $config
				]
			];
			$answer = $this->sendToDevice($data);
		}
	}

	/**
	*** Configuration du/des switch(es)
	***/
	private function configureDeviceSwitch() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		$switches = $this->getConfiguration('switches');
		if ($device['nbSwitch'] == 1) {
			$switches[0] = 1;
		}
		foreach (range(0, $device['nbSwitch']-1) as $switchId) {
			$switchKey = 'switch:' . $switchId;
			$config = [];
			if ($switches[$switchId] == 1) {

				$actualName = $this->deviceConfig($switchKey, 'name');
				$name = config::byKey('name') . "::supply:" . $this->getId() . "_" . $switchId;
				if ($actualName != $name) {
					log::add(__CLASS__,"info",sprintf(__("Configuration du nom du switch %s",__FILE__),$switchKey));
					$config['name'] = $name;
				}

				if ("detached" != $this->deviceConfig($switchKey, 'in_mode')){
					log::add(__CLASS__,"info",sprintf(__("Détachement du switch %s",__FILE__),$switchKey));
					$config['in_mode'] = "detached";
				}

				if ("on" != $this->deviceConfig($switchKey, 'initial_state')){
					log::add(__CLASS__,"info",sprintf(__("Configuration de l'état du switch %s au boot",__FILE__),$switchKey));
					$config['initial_state'] = "on";
				}

				if ($this->deviceConfig($switchKey, 'in_locked')){
					log::add(__CLASS__,"info",sprintf(__("Désactivation du lock du switch %s",__FILE__),$switchKey));
					$config['in_locked'] = false;
				}

				if ($this->deviceConfig($switchKey, 'auto_on')){
					log::add(__CLASS__,"info",sprintf(__("Désactivation de l'auto_on du switch %s",__FILE__),$switchKey));
					$config['auto_on'] = false;
				}

				if ($this->deviceConfig($switchKey, 'auto_off')){
					log::add(__CLASS__,"info",sprintf(__("Désactivation de l'auto_off du switch %s",__FILE__),$switchKey));
					$config['auto_off'] = false;
				}

				if (count($config) > 0) {
					$data = [
						"method" => "Switch.SetConfig",
						"params" => [
							'id' => $switchId,
							'config' => $config,
						],
					];
					$answer = $this->sendToDevice($data);
				}
			} else {
				$actualName = $this->deviceConfig($switchKey, 'name');
				$name = config::byKey('name') . "::supply:" . $this->getId() . "_" . $switchId;
				if ($actualName == $name) {
					log::add(__CLASS__,"info",sprintf(__("Déconfiguration du nom du switch %s",__FILE__),$switchKey));
					$data = [
						"method" => "Switch.SetConfig",
						"params" => [
							'id' => $switchId,
							'config' => [
								'name' => ''
							],
						],
					];
					$answer = $this->sendToDevice($data);
				}
			}
		}
	}

	/**
	*** Configuration des webhooks pour signaler l'état du bouton "maintenance" à Jeedom
	***/
	private function	configureDeviceWebhook(){
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		if ($device['nbInput'] == 0) {
			return;
		}
		if ($device['nbInput'] > 1) {
			throw new Exception (__("Les appareils ayant plusieurs inputs ne sont pas supportés",__FILE__));
		}
		$inputId = 0;

		// Les adresses IP de Jeedom
		//
		$interfaces = network::getInterfacesInfo();
		$ips = [];
		foreach ($interfaces as $interface) {
			if (in_array('LOOPBACK',$interface['flags'])){
				continue;
			}
			if (!in_array('UP',$interface['flags'])){
				continue;
			}
			foreach ($interface['addr_info'] as $addrInfo) {
				if ($addrInfo['family'] != 'inet') {
					continue;
				}
				$ips[] = $addrInfo['local'];
			}
		}
		if (count($ips) == 0) {
			throw new Exception(__("Adresse IP de jeedom introuvable!",__FILE__));
		}

		// l'ID de la commande info maintenance
		//
		$cmd = $this->getCmd('info','maintenance');
		if (!is_object($cmd)) {
			throw new Exception(sprintf(__("Commande '%s' introuvable!",__FILE__),'maintenance'));
		}
		$cmdId = $cmd->getId();

		// La clé API du plugin dans Jeedom
		//
		$apiModeKey = 'api::' . __CLASS__ . '::mode';
		if (config::byKey($apiModeKey) != 'enable') {
			config::save($apiModeKey, 'enable');
		}
		$apiKey = jeedom::getApiKey(__CLASS__);
		if  ($apiKey == '') {
			throw new Exception(__("Clé API du plugin introuvable!"));
		}

		// Path pour les URLs
		$path  = '/core/api/jeeApi.php';
		$path .= '?plugin=' . __CLASS__;
		$path .= '&type=event';
		$path .= '&apikey=' . $apiKey;
		$path .= '&id=' . $cmdId;
		$path .= '&value=';

		// Définition des hooks
		//
		$hooks = [];
		foreach (['on', 'off'] as $state) {
			$name = config::byKey('name') . "::" . $this->getId() . "::" . "maintenance_" . $state;
			$urls = [];
			foreach ($ips as $ip) {
				switch ($state) {
					case 'on':
						$value = 1;
						break;
					case 'off':
						$value = 0;
						break;
				}
				$urls[] = 'http://' . $ip . $path . $value;
			}
			$hooks[$state] = [
				"name" => $name,
				"event" => "input.toggle_". $state,
				"cid"	=> $inputId,
				"urls"  => $urls,
				"status" => "notFound",
			];
		}

		// Traitement des webhooks existants
		//
		log::add(__CLASS__,"info",__("Récupération de la liste de webhooks",__FILE__));
		$data = [ "method" => "Webhook.List" ];
		$answer = $this->sendToDevice($data);
		$actualHooks= $answer['result']['hooks'];

		foreach ($actualHooks as $actualHook){
			if ($actualHook['cid'] != $inputId) {
				continue;
			}
			if (substr($actualHook['event'],0,13) !== 'input.toggle_') {
				log::add(__CLASS__,"info",sprintf(__("Suppression du webHook %s (event %s inconnu)",__FILE__),$actualHook['id'],$actualHook['event']));
				$data = [
					'method' => "Webhook.Delete",
					'params' => [
						'id' => $actualHook['id']
					]
				];
				$this->sendToDevice($data);
				continue;
			}
			$state = substr($actualHook['event'],13);  // "on" ou "off"

			if (!isset($hooks[$state])){
				log::add(__CLASS__,"info",sprintf(__("Suppression du webHook %s (event %s inconnu)",__FILE__),$actualHook['id'],$actualHook['event']));
				$data = [
					'method' => "Webhook.Delete",
					'params' => [
						'id' => $actualHook['id']
					]
				];
				$this->sendToDevice($data);
				continue;
			}

			//Supression de duplicate
			//
			if ($hooks[$state]['status'] !== 'notFound') {
				log::add(__CLASS__,"info",sprintf(__("Suppression du webHook %s (dupliqué)",__FILE__),$actualHook['id']));
				$data = [
					'method' => "Webhook.Delete",
					'params' => [
						'id' => $actualHook['id']
					]
				];
				$this->sendToDevice($data);
				continue;
			}

			$hooks[$state]['status'] = 'found';

			// Check name
			//
			$params = [];
			if ($hooks[$state]['name'] !== $actualHook['name']){
				$params['name'] = $hooks[$state]['name'];
			}

			// Check enable
			//
			if (!$actualHook['enable']){
				$params['enable'] = true;
			}

			// Check urls
			//
			$urlsOK = true;
			if (count($hooks[$state]['urls']) != count($actualHook['urls'])) {
				log::add(__CLASS__,"debug",__("Le nombre d'urls ne correspond pas",__FILE__));
				$urlsOK = false;
			} else {
				foreach ($hooks[$state]['urls'] as $url){
					$found = false;
					foreach($actualHook['urls'] as $actualUrl){
						if ($url === $actualUrl) {
							$found = true;
							continue;
						}
					}
					if (!$found){
						$urlsOK = false;
						continue;
					}
				}
			}
			if (!$urlsOK){
				$params['urls'] = $hooks[$state]['urls'];
			}

			if (count($params)){
				$params['id'] = $actualHook['id'];
				$data = [
					"method" => "Webhook.Update",
					"params" => $params,
				];
				$this->sendToDevice($data);
			}

		}

		// Création des hooks qui n'ont pas été trouvés
		//
		foreach (array_keys($hooks) as $state) {
			if ($hooks[$state]['status'] == 'notFound') {
				$data = [
					"method" => "Webhook.Create",
					"params" => [
						"event" => $hooks[$state]['event'],
						"cid"	=> $hooks[$state]['cid'],
						"enable"=> true,
						"name"  => $hooks[$state]['name'],
						"urls"  => $hooks[$state]['urls'],
					]
				];
				$answer = $this->sendToDevice($data);
			}
		}
	}

	/**
	*** Configuration du script
	***/
	private function configureDeviceScript(){
		// Code javascript pour récupérer la config du script
		$getConfig = <<<GETCONFIG
			let conf = "{}"
			if (typeof CONFIG !== 'undefined') {
				conf = JSON.stringify(CONFIG)
			}
			conf
		GETCONFIG;

		$name = config::byKey('name') . "::" . $this->getId() . "::" . "script";

		$scriptId = $this->getScriptId();
		$scriptInfos = [];
		if (is_numeric($scriptId)){
			$data = ["method" => "Script.List"];
			$answer = $this->sendToDevice($data);
			foreach ($answer['result']['scripts'] as $actualScript) {
				if ($actualScript['id'] == $scriptId){
					$scriptInfos['meta'] = $actualScript;
					if ($actualScript['running']){
						$data = [
							"method" => "Script.Eval",
							"params" => [
								"id" => $actualScript['id'],
								"code" => $getConfig,
							]
						];
						$scriptInfos['config'] = json_decode($answer['result']['result'],true);
					}
				}
				break;
			}
		} else {
			// On récupère la liste des scripts running qui peuvent être pour cet eqLogic
			//
			$scripts = [];
			$data = ["method" => "Script.List"];
			$answer = $this->sendToDevice($data);
			foreach ($answer['result']['scripts'] as $actualScript) {
				if (!$actualScript['running']){
					continue;
				}
				$data = [
					"method" => "Script.Eval",
					"params" => [
						"id" => $actualScript['id'],
						"code" => $getConfig,
					]
				];
				$answer = $this->sendToDevice($data);
				$scriptConfig = json_decode($answer['result']['result'],true);
				if (!isset($scriptConfig['relayId']) or !isset($scriptConfig['inputId']) or !isset($scriptConfig['watchdogTimeout'])){
					continue;
				}
				if (isset($scriptConfig['eqLogicId']) and $scriptConfig['eqLogicId'] != $this->getId()) {
					continue;
				}
				if (isset($scriptConfig['jeedomKey']) and ($scriptConfig['jeedomKey'] != config::byKey('jeedom::installKey'))){
					continue;
				}
				$scripts[$actualScript['id']] = [
					'meta' => $actualScript,
					'config' => $scriptConfig,
				];
			}
			if (count($scripts) == 0) {
				$data = [
					"method" => "Script.Create",
					"params" => [
						"name" => $name,
					]
				];
				$answer = $this->sendToDevice($data);
				$scriptId = $answer['result']['id'];
				$scriptInfos = [
					'meta' => [
						'id'     => $scriptId,
						'name'   => $name,
						'enable' => false,
						'running'=> false,
					],
				];
			} else {
				$scriptId = array_keys($scripts)[0];
				$scriptInfos = $scripts[$scriptId];
			}
		}

		/* Creation du script */
		$model = jeewatchdog::getModel($this->getConfiguration('deviceModel'));
		$scriptFile = __DIR__ . '/../config/' . $model['script'];

		$switchesToDrive = [];
		if ($model['nbSwitch'] > 1) {
			$switches = $this->getConfiguration('switches');
			foreach ($switches as $switchId => $value){
				if ($switchId >= $model['nbSwitch']) {
					break;
				}
				if ($value == 1) {
					$switchesToDrive[] = $switchId;
				}
			}
		} else {
			$switchesToDrive[] = 0;
		}

		if ($model['nbInput'] == 0) {
			$inputId = -1;
		} else {
			$inputId = 0;
		}

		log::add(__CLASS__,"info",sprintf(__("Préparation du script pour le switch %s",__FILE__),$switchId));
		$code = file_get_contents($scriptFile);
		$watchdogTimeout = $this->getConfiguration('watchdogTimeout') * 60;

		$replace = [
			'#jeedomName#'      => config::byKey('name'),
			'#jeedomKey#'       => config::byKey('jeedom::installKey'),
			'#eqLogicName#'     => $this->getName(),
			'#eqLogicId#'       => $this->getId(),
			'#watchdogTimeout#' => $watchdogTimeout,
			'#offDuration#'     => $this->getConfiguration('offDuration'),
			'#relayId#'         => join(',',$switchesToDrive),
			'#inputId#'         => $inputId,
		];
		$code = str_replace(array_keys($replace), $replace, $code);

		$data = [
			'method' => 'Script.GetCode',
			'params' => [
				'id' => $scriptId,
			]
		];
		$answer = $this->sendToDevice($data);
		$actualCode = $answer['result']['data'];
		file_put_contents("/tmp/code.shelly",$actualCode);
		file_put_contents("/tmp/code.plugin",$code);
		if ($actualCode !== $code) {
			if ($scriptInfos['meta']['running']) {
				$data = [
					'method' => 'Script.Stop',
					"params" => [
						"id" => $scriptId,
					]
				];
				$this->sendToDevice($data);
				$scriptInfos['meta']['running'] = false;
			}
			$data = [
				"method" => "Script.putCode",
				"params" => [
					"id" => $scriptId,
					"code" => $code
				]
			];
			$this->sendToDevice($data);
		}

		$config = [];
		if ($scriptInfos['meta']['name'] !== $name) {
			$config['name'] = $name;
		}
		if (!$scriptInfos['meta']['enable']) {
			$config['enable'] = true;
		}
		if (count($config) > 0){
			$data = [
				'method' => 'Script.SetConfig',
				'params' => [
					'id'     => $scriptId,
					'config' => $config
				]
			];
			$this->sendToDevice($data);
		}

		if (!$scriptInfos['meta']['running']) {
			$data = [
				'method' => 'Script.Start',
				'params' => [
					'id'     => $scriptId,
				]
			];
			$this->sendToDevice($data);
		}
		$this->setScriptId($scriptId);


	}

	/**
	*** Configuration de l'appareil
	***/
	public function configureDevice() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$device = self::getModel($deviceModel);
		if ($device === null) {
			throw new Exception (sprintf(__("Le modèle %s est inconnu.",__FILE__),$deviceModel));
		}
		$jeedomName = config::byKey('name');

		$deviceIP = $this->getConfiguration('deviceIP');
		if ($deviceIP == '') {
			throw new Exception (__("Adresse IP ou nom DNS du device inconnu!",__FILE__));
		}
		$deviceName = $jeedomName == '' ? $this->getName() : $jeedomName . "::" . $this->getName();

		$restartRequired = false;
		$this->_restartRequired = false;

		$this->configureDeviceSystem();
		$this->configureDeviceWifi();
		$this->configureDeviceBLE();
		$this->configureDeviceCloud();
		$this->configureDeviceModbus();
		$this->configureDeviceKNX();
		$this->configureDeviceMqtt();
		$this->configureDeviceWs();
		$this->configureDeviceInput();
		$this->configureDeviceSwitch();
		$this->configureDeviceWebhook();
		$this->configureDeviceScript();

		if ($this->_restartRequired) {
			$data = [ "method" => "Shelly.reboot" ];
			$this->sendToDevice($data);
			unset ($this->_restartRequired);
		}
	}

	public function _kickWatchdog() {
		$watchdogTimeout = $this->getConfiguration('watchdogTimeout') * 60;
		$data = [
			"id" => 1,
			"method" => "Script.Eval",
			"params" => [
				"id" => 1,
				"code" => "setCounterRemote(" . $watchdogTimeout . ")"
			]
		];
		$this->sendToDevice($data);
	}

	/*     * ***************************************************************** */
	/*     * **********************Getteurs Setteurs************************** */
	/*     * ***************************************************************** */

	public function getDeviceModel() {
		return $this->getConfiguration('deviceModel');
	}

	public function setScriptId($_scriptId) {
		$this->setCache($_scriptId);
		return $this;
	}

	public function getScriptId() {
		return $this->getCache('scriptId',null);
	}
}

class jeewatchdogCmd extends cmd {
	/*     * ***************************************************************** */
	/*     * *************************Attributs******************************* */
	/*     * ***************************************************************** */

	/*
	public static $_widgetPossibility = array();
	*/

	/*     * ***************************************************************** */
	/*     * ***********************Methode static**************************** */
	/*     * ***************************************************************** */


	/*     * ***************************************************************** */
	/*     * *********************Methode d'instance************************** */
	/*     * ***************************************************************** */

	/*
	* Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
	*/
	public function dontRemoveCmd() {
		return true;
	}

	// Exécution d'une commande
	public function execute($_options = array()) {
		if ($this->getLogicalId() == 'kick') {
			log::add("jeewatchdog","info","Kick watchdog");
			$this->getEqLogic()->_kickWatchdog();
		}
	}

	/*     * ***************************************************************** */
	/*     * **********************Getteurs Setteurs************************** */
	/*     * ***************************************************************** */

}
