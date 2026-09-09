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
	 ** Traitement des event reçus du watchdog via l'API de jeedom
	 **/
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
	 ** Methode static appelée par le cron
	 **/
	public static function kickWatchdog($_options) {
		log::add(__CLASS__, 'debug', 'kickWatchdog');
		$jeewatchdog = eqLogic::byId($_options['EqLogic_id']);
		if (is_object($jeewatchdog) && $jeewatchdog->getIsEnable() == 1) {
			$jeewatchdog->_kickWatchdog();
		}
	}

	/**
	 ** Définitions des modèles Shelly reconnus
	 **/
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
	 ** Configuration par défaut à la créaton de l'eqLogic
	 **/
	public function preInsert() {
		$this->setConfiguration('kickmode', 'cron');
		$this->setConfiguration('watchdogTimeout', 15);
		$this->setConfiguration('offDuration', 3);
	}

	/**
	 ** Vérification de la validité des configuration avant la à jour de l'eqLogic
	 **/
	public function preUpdate() {
		if ($this->getConfiguration('watchdogTimeout', 0) == 0) {
			$this->setConfiguration('watchdogTimeout', 15);
		}
		if ($this->getConfiguration('offDuration', 0) == 0) {
			$this->setConfiguration('offDuration', 3);
		}
	}

	/**
	 ** Création des commandes en même temps que l'eqLogic
	 **/
	public function postInsert() {
		$this->createCmds();
	}

	/**
	 ** Mise à jour du cron et de l'appareil
	 **/
	public function postSave() {
		$this->createOrUpdateCron();
	}

	/**
	 ** Suppression du cron à la suppression de l'eqLogic
	 **/
	public function preRemove() {
		$cron = $this->getCron(false);
		if (is_object($cron)) {
			$cron->remove();
		}
	}

	/**
	 ** Le password est crypté en DB
	 **/
	public function decrypt() {
		$this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
	}
	public function encrypt() {
		$this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
	}

	/**
	 ** Création des commandes
	 **/
	public function createCmds() {
		$cmd = $this->getCmd('info','maintenance');
		if (!is_object($cmd)) {
			$cmd = new jeewatchdogCmd();
			$cmd->setEqLogic_Id($this->getId());
			$cmd->setType('info');
			$cmd->setSubType('binary');
			$cmd->setLogicalId('maintenance');
			$cmd->setName('maintenance');
			$cmd->save();
		}

		$cmd = $this->getCmd('action','ping');
		if (!is_object($cmd)) {
			$cmd = new jeewatchdogCmd();
			$cmd->setEqLogic_Id($this->getId());
			$cmd->setType('action');
			$cmd->setSubType('other');
			$cmd->setLogicalId('ping');
			$cmd->setName('ping');
			$cmd->save();
		}
	}

	/**
	 ** Lecture du cron pour l'eqLogic
	 **/
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
	 ** Création ou mise à jour du cron de l'eqLogic
	 **/
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

	/**
	 ** Envoi de données à l'appareil avec gestion de l'authentication
	 **/
	public function sendToDevice($data) {
		$deviceIP = $this->getConfiguration('deviceIP');
		$password = $this->getConfiguration('password');

		// récupération des données d'authentication
		$currentNonce = $this->getCache('currentNonce', null);
		$ncCounter = $this->getCache('ncCounter', null);
		$currentRealm = $this->getCache('currentRealm', null);

		// 1. Si nous avons déjà un nonce en mémoire, on tente directement une requête signée
		if ($currentNonce !== null) {
			$ncCounter++; // Incrémentation du compteur à chaque nouvel appel
			$data = $this->injectShellyAuth($data, $password, $currentRealm, $currentNonce, $ncCounter);
		}

		$ch = curl_init('http://' . $deviceIP . "/rpc");
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
		$response = curl_exec($ch);

		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header = substr($response, 0, $headerSize);
		$body = substr($response, $headerSize);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// 2. Si le nonce a expiré entre temps ou si c'est le tout premier appel (401)
		if ($code == 401) {
			log::add(__CLASS__,"debug",__("(ré)authentification",__FILE__));
			$headers = explode("\n", $header);
			$vars = [];
			foreach ($headers as $line) {
				if (str_starts_with(trim($line), 'WWW-Authenticate:')){
					preg_match_all('/(\w+)=(?:"([^"]*)"|([^,\s]+))/', $line, $matches, PREG_SET_ORDER);
					foreach ($matches as $match) {
						$key = $match[1];
						$value = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3];
						$vars[$key] = $value;
					}
					break;
				}
			}

			if (count($vars) == 0){
				throw new Exception(__("Infos d'authentification non fournies", __FILE__));
			}

			// Sauvegarde des nouvelles informations dans l'instance de classe
			$currentNonce = $vars['nonce'];
			$currentRealm = $vars['realm'];
			$ncCounter    = 1; // On réinitialise le compteur à 1 pour ce nouveau nonce

			// On injecte la nouvelle authentification fraîchement générée
			$data = $this->injectShellyAuth($data, $password, $currentRealm, $currentNonce, $ncCounter);

			// Deuxième essai avec les bonnes informations
			$ch = curl_init('http://' . $deviceIP . "/rpc");
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			$response = curl_exec($ch);

			$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$header = substr($response, 0, $headerSize);
			$body = substr($response, $headerSize);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
		}

		if ($code == 401) {
			// On retire du cache les donnée d'authentication 
			$this->setCache('currentNonce', null);
			$this->setCache('ncCounter', null);
			$this->setCache('currentRealm', null);
			throw new Exception (__("Accès au switch non autorisé. Veuillez vérifier le password.",__FILE__));
		}

		// mise en cache des données d'authentication
		$this->setCache('currentNonce', $currentNonce);
		$this->setCache('ncCounter', $ncCounter);
		$this->setCache('currentRealm', $currentRealm);

		if ($code != 200) {
			log::add(__CLASS__, "error", "Code HTTP final : " . $code);
			log::add(__CLASS__, "error", "header: ". $header);
			log::add(__CLASS__, "error", "body: ". $body);
			throw new Exception (sprintf(__("Code HTTP: %s: %s <br>Veuillez vérifier le log du plugin pour plus d'info.",__FILE__), $code, $body));
		}
		log::add(__CLASS__, "debug", "Code HTTP final : " . $code);
		log::add(__CLASS__, "debug", "body: ". $body);

		return json_decode($body, true);
	}

	/**
	 ** Fonction interne pour isoler le calcul de la signature Shelly
	 **/
	private function injectShellyAuth($data, $password, $realm, $nonce, $nc) {
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
	}

	/**
	 ** Vérification d'une réponse du Shelly
	 **/
	public function checkShellyAnswer ($answer) {
		if (isset ($answer['error'])) {
			throw new Exception(sprintf("error(%s): %s",$answer['error']['code'],$answer['error']['message']));
		}
		return true;
	}

	/**
	 ** Configuration de l'appareil
	 **/
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
		$id = 1;

		// Configuration system
		// ////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration system de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Sys.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"device" => [
						"name" => $deviceName,
					],
					"debug" => [
						"websocket" => [
							"enable" => true,
						],
					],
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration de l'access point
		// ///////////////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration l'access point de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Wifi.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"ap" => [
						"enable" => false,
					],
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuraton du Bluetooth
		// /////////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration de BLE de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "BLE.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"rpc" => [
						"enable" => false,
					],
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration du cloud
		// //////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration du cloud de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Cloud.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"enable" => false,
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration Modbus
		// ////////////////////
		if ($device['modbus'] == 1) {
			log::add(__CLASS__,"info",sprintf(__("Configuration du Modbus de l'appareil %s",__FILE__),$deviceName));
			$data = [
				"id"     => $id++,
				"src"    => "jeedom_" . $jeedomName,
				"method" => "Modbus.SetConfig",
				"params" => [
					"id" => 0,
					"config" => [
						"enable" => false,
					],
				],
			];
			$answer = $this->sendToDevice($data);
			$this->checkShellyAnswer($answer);
			if ($answer['result']['restart_required'] != 'false') {
				$restartRequided = true;
			}
		}

		// Configuration MQTT
		// //////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration de MQTT de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "MQTT.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"enable" => false,
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration Outbound Websocket
		// ////////////////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration de Outbound Websocket de l'appareil %s",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Ws.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"enable" => false,
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration de l'interrupteur "maintenance"
		// /////////////////////////////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration de l'interrupteur <maintenances> de l'appareil",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Input.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"name"          => $jeedomName . '_maintenance',
					"type"          => 'switch',
					"enable"        => true,
					"factory_reset" => true,
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration du switch
		// ///////////////////////
		log::add(__CLASS__,"info",sprintf(__("Configuration du switch de l'appareil",__FILE__),$deviceName));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Switch.SetConfig",
			"params" => [
				"id" => 0,
				"config" => [
					"name"          => $jeedomName . '_power',
					"in_mode"       => "detached",
					"in_locked"     => false,
					"initial_state" => "on",
					"auto_on"       => false,
					"auto_off"      => false,
				],
			],
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		if ($answer['result']['restart_required'] != 'false') {
			$restartRequided = true;
		}

		// Configuration KNX
		// /////////////////
		if ($device['knx'] == 1) {
			log::add(__CLASS__,"info",sprintf(__("Configuration de KNX de l'appareil %s",__FILE__),$deviceName));
			$data = [
				"id"     => $id++,
				"src"    => "jeedom_" . $jeedomName,
				"method" => "KNX.SetConfig",
				"params" => [
					"id" => 0,
					"config" => [
						"enable" => false,
					],
				],
			];
			$answer = $this->sendToDevice($data);
			$this->checkShellyAnswer($answer);
			if ($answer['result']['restart_required'] != 'false') {
				$restartRequided = true;
			}
		}

		// Configuration des actions de l'interrupteur (Webhook)
		// /////////////////////////////////////////////////////

		// List des webhook
		log::add(__CLASS__,"info",__("Liste des actions de l'interrupeur",__FILE__));
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Webhook.List",
		];
		$answer = $this->sendToDevice($data);
		$this->checkShellyAnswer($answer);
		$hooks = $answer['result']['hooks'];

		// Suppression des action de l'interrupteur
		foreach ($hooks as $hook) {
			if (in_array($hook['event'], ['input.toggle_on', 'input.toggle_off'])) {
				log::add(__CLASS__,"info",sprintf(__("Suppression du webhook %s",__FILE__),$hook['name']));
				$data = [
					"id"     => $id++,
					"src"    => "jeedom_" . $jeedomName,
					"method" => "Webhook.Delete",
					"params" => [
						"id" => $hook['id'],
					],
				];
				$this->sendToDevice($data);
			}
		}

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

		$cmd = $this->getCmd('info','maintenance');
		if (!is_object($cmd)) {
			throw new Exception(sprintf(__("Commande '%s' introuvable!",__FILE__),'maintenance'));
		}
		$cmdId = $cmd->getId();

		$apiModeKey = 'api::' . __CLASS__ . '::mode';
		if (config::byKey($apiModeKey) != 'enable') {
			config::save($apiModeKey, 'enable');
		}
		$apiKey = jeedom::getApiKey(__CLASS__);
		if  ($apiKey == '') {
			throw new Exception(__("Clé API introuvable!"));
		}

		/* Creation du hook "Maintenace ON" */
		$urls = [];
		$path  = '/core/api/jeeApi.php';
		$path .= '?plugin=' . __CLASS__;
		$path .= '&type=event';
		$path .= '&apikey=' . $apiKey;
		$path .= '&id=' . $cmdId;
		$path .= '&value=1';

		log::add(__CLASS__,"info", sprintf(__("Création du webhook pour %s",__FILE__),"Maintenace ON"));
		foreach ($ips as $ip) {
			$urls[] = 'http://' . $ip . $path;
		}
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Webhook.Create",
			"params" => [
				"event" => "input.toggle_on",
				"cid"	=> 0,
				"enable"=> true,
				"name"  => "Maintenance ON",
				"urls"  => $urls,
			]
		];
		$answer = $this->sendToDevice($data);

		/* Creation du hook "Maintenace OFF" */
		$urls = [];
		$path  = '/core/api/jeeApi.php';
		$path .= '?plugin=' . __CLASS__;
		$path .= '&type=event';
		$path .= '&apikey=' . $apiKey;
		$path .= '&id=' . $cmdId;
		$path .= '&value=0';

		log::add(__CLASS__,"info", sprintf(__("Création du webhook pour %s",__FILE__),"Maintenace OFF"));
		foreach ($ips as $ip) {
			$urls[] = 'http://' . $ip . $path;
		}
		$data = [
			"id"     => $id++,
			"src"    => "jeedom_" . $jeedomName,
			"method" => "Webhook.Create",
			"params" => [
				"event" => "input.toggle_off",
				"cid"	=> 0,
				"enable"=> true,
				"name"  => "Maintenance OFF",
				"urls"  => $urls,
			]
		];
		$answer = $this->sendToDevice($data);

		/* Récupération de la liste des scripts */
		$data = [
			"id"     => $id++,
			"method" => "Script.List",
		];
		$answer = $this->sendToDevice($data);
		$scripts = $answer['result']['scripts'];

		/* Suppression des scripts */
		foreach ($scripts as $script) {
			$data = [
				"id"     => $id++,
				"method" => "Script.Delete",
				"params" => [
					"id" => $script['id']
				]
			];
			$this->sendToDevice($data);
		}

		/* Creation du script */
		$scriptFile = __DIR__ . '/../config/' . $this->getConfiguration('deviceModel') . '.js';
		$codejs = file_get_contents($scriptFile);
		$watchdogTimeout = $this->getConfiguration('watchdogTimeout') * 60;
		$codejs = str_replace('#watchdogTimeout#', $watchdogTimeout, $codejs);
		$codejs = str_replace('#offDuration#', $this->getConfiguration('offDuration'), $codejs);

		$data = [
			"id"     => $id++,
			"method" => "Script.Create",
			"params" => [
				"name" => $jeedomName . "_watch"
			]
		];
		$answer = $this->sendToDevice($data);
		$scriptId = $answer['result']['id'];
		$data = [
			"id"     => $id++,
			"method" => "Script.putCode",
			"params" => [
				"id" => $scriptId,
				"code" => $codejs
			]
		];
		$answer = $this->sendToDevice($data);
		$data = [
			"id"     => $id++,
			"method" => "Script.SetConfig",
			"params" => [
				"id" => $scriptId,
				"config" => [
					"enable" => true
				]
			]
		];
		$answer = $this->sendToDevice($data);
		$data = [
			"id"     => $id++,
			"method" => "Script.Start",
			"params" => [
				"id" => $scriptId,
			]
		];
		$answer = $this->sendToDevice($data);
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
		if ($this->getLogicalId() == 'ping') {
			log::add("jeewatchdog","info","Kick watchdog");
			$this->getEqLogic()->_kickWatchdog();
		}
	}

	/*     * ***************************************************************** */
	/*     * **********************Getteurs Setteurs************************** */
	/*     * ***************************************************************** */
}

