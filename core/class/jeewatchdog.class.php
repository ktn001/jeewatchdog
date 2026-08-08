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
	/*     * *************************Attributs****************************** */

	private $currentNonce = null;
	private $currentRealm = null;
	private $ncCounter    = 0;

	/*
	* Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
	* Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
	public static $_widgetPossibility = array();
	*/

	/*
	* Permet de crypter/décrypter automatiquement des champs de configuration du plugin
	* Exemple : "param1" & "param2" seront cryptés mais pas "param3"
	public static $_encryptConfigKey = array('param1', 'param2');
	*/

	/*     * ***********************Methode static*************************** */

	/*
	* Fonction exécutée automatiquement toutes les minutes par Jeedom
	public static function cron() {}
	*/

	/*
	* Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
	public static function cron5() {}
	*/

	/*
	* Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
	public static function cron10() {}
	*/

	/*
	* Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
	public static function cron15() {}
	*/

	/*
	* Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
	public static function cron30() {}
	*/

	/*
	* Fonction exécutée automatiquement toutes les heures par Jeedom
	public static function cronHourly() {}
	*/

	/*
	* Fonction exécutée automatiquement tous les jours par Jeedom
	public static function cronDaily() {}
	*/
	
	/*
	* Permet de déclencher une action avant modification d'une variable de configuration du plugin
	* Exemple avec la variable "param3"
	public static function preConfig_param3( $value ) {
		// do some checks or modify on $value
		return $value;
	}
	*/

	/*
	* Permet de déclencher une action après modification d'une variable de configuration du plugin
	* Exemple avec la variable "param3"
	public static function postConfig_param3($value) {
		// no return value
	}
	*/

	/*
	 * Permet d'indiquer des éléments supplémentaires à remonter dans les informations de configuration
	 * lors de la création semi-automatique d'un post sur le forum community
	  public static function getConfigForCommunity() {
		  // Cette function doit retourner des infos complémentataires sous la forme d'un
		  // string contenant les infos formatées en HTML.
		  return "les infos essentiel de mon plugin";
	  }
	 */

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

	/*     * *********************Méthodes d'instance************************* */

	// Fonction exécutée automatiquement avant la création de l'équipement
	public function preInsert() {
		$this->setConfiguration('deviceModel', 'shellyplus1');
	}

	// Fonction exécutée automatiquement après la création de l'équipement
	public function postInsert() {
		$this->createMaintenanceCmd();
	}

	// Fonction exécutée automatiquement avant la mise à jour de l'équipement
	public function preUpdate() {
	}

	// Fonction exécutée automatiquement après la mise à jour de l'équipement
	public function postUpdate() {
	}

	// Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
	public function preSave() {
	}

	// Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
	public function postSave() {
	}

	// Fonction exécutée automatiquement avant la suppression de l'équipement
	public function preRemove() {
	}

	// Fonction exécutée automatiquement après la suppression de l'équipement
	public function postRemove() {
	}

	/*
	* Permet de crypter/décrypter automatiquement des champs de configuration des équipements
	* Exemple avec le champ "Mot de passe" (password)
	*/
	public function decrypt() {
		$this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
	}
	public function encrypt() {
		$this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
	}

	/*
	* Permet de modifier l'affichage du widget (également utilisable par les commandes)
	public function toHtml($_version = 'dashboard') {}
	*/

	public function createMaintenanceCmd() {
		$cmd = $this->getCmd('info','maintenance');
		if (is_object($cmd)) {
			return;
		}
		$cmd = new jeewatchdogCmd();
		$cmd->setEqLogic_Id($this->getId());
		$cmd->setType('info');
		$cmd->setSubType('binary');
		$cmd->setLogicalId('maintenance');
		$cmd->setName('maintenance');
		$cmd->save();
	}

	private function postToDevice($data) {
		$deviceIP = $this->getConfiguration('deviceIP');
		$password = $this->getConfiguration('password');
	
		// 1. Si nous avons déjà un nonce en mémoire, on tente directement une requête signée
		if ($this->currentNonce !== null) {
			$this->ncCounter++; // Incrémentation du compteur à chaque nouvel appel
			$data = $this->injectShellyAuth($data, $password, $this->currentRealm, $this->currentNonce, $this->ncCounter);
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
			$this->currentNonce = $vars['nonce'];
			$this->currentRealm = $vars['realm'];
			$this->ncCounter    = 1; // On réinitialise le compteur à 1 pour ce nouveau nonce
	
			// On injecte la nouvelle authentification fraîchement générée
			$data = $this->injectShellyAuth($data, $password, $this->currentRealm, $this->currentNonce, $this->ncCounter);
			
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
			throw new Exception (__("Accès au switch non autorisé. Veuillez vérifier le password.",__FILE__));
		}
		
		if ($code != 200) {
			log::add(__CLASS__, "error", "Code HTTP final : " . $code);
			log::add(__CLASS__, "error", "header: ". $header);
			log::add(__CLASS__, "error", "body: ". $body);
			throw new Exception (sprintf(__("Code HTTP: %s! Veuillez vérifier le log du plugin pour plus d'info.",__FILE__), $code));
		}
		log::add(__CLASS__, "debug", "Code HTTP final : " . $code);
		log::add(__CLASS__, "debug", "body: ". $body);
		
		return json_decode($body, true);
	}
	
	/**
	 * Fonction interne pour isoler le calcul de la signature Shelly
	 */
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

	public function configureDevice() {
		$deviceModel = $this->getConfiguration('deviceModel');
		$deviceIP = $this->getConfiguration('deviceIP');
		if ($deviceIP == '') {
			throw new Exception (__("Adresse IP ou nom DNS du device inconnu!",__FILE__));
		}
		$jeedomName = config::byKey('name');
		$restartRequired = false;

		switch ($deviceModel) {
			case 'shellyplus1':
				$id = 1;

				/* Configuration du switch */
				log::add(__CLASS__,"info",__("Configuration du relais",__FILE__));
				$data = [
					"id"     => $id++,
					"method" => "Switch.SetConfig",
					"params" => [
						"id" => 0,
						"config" => [
							"in_mode" => "detached",
							"name"    => $jeedomName . '_power',
						],
					],
				];
				$answer = $this->postToDevice($data);
				if ($answer['result']['restart_required'] != 'false') {
					$restartRequided = true;
				}


				/* configuration de l'interrupteur */
				log::add(__CLASS__,"info",__("Configuration de l'interrupeur",__FILE__));
				$data = [
					"id"     => $id++,
					"method" => "Input.SetConfig",
					"params" => [
						"id" => 0,
						"config" => [
							"name"    => $jeedomName . '_maintenance',
						],
					],
				];
				$answer = $this->postToDevice($data);
				if ($answer['result']['restart_required'] != 'false') {
					$restartRequided = true;
				}

				/* Configuration des actions de l'interrupeur */
				/* List des webhook */
				log::add(__CLASS__,"info",__("Liste des actions de l'interrupeur",__FILE__));
				$data = [
					"id"  => $id++,
					"method" => "Webhook.List",
				];
				$answer = $this->postToDevice($data);
				$hooks = $answer['result']['hooks'];

				/* Suppression des action de l'interrupteur */
				foreach ($hooks as $hook) {
					if (in_array($hook['event'], ['input.toggle_on', 'input.toggle_off'])) {
						log::add(__CLASS__,"info",sprintf(__("Suppression du webhook %s",__FILE__),$hook['name']));
						$data = [
							"id"     => $id++,
							"method" => "Webhook.Delete",
							"params" => [
								"id" => $hook['id'],
							],
						];
						$this->postToDevice($data);
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

				foreach ($ips as $ip) {
					$urls[] = 'http://' . $ip . $path;
				}
				$data = [
					"id"     => $id++,
					"method" => "Webhook.Create",
					"params" => [
						"event" => "input.toggle_on",
						"cid"	=> 0,
						"enable"=> true,
						"name"  => "Maintenance ON",
						"urls"  => $urls,
					]
				];
				$answer = $this->postToDevice($data);

				/* Creation du hook "Maintenace OFF" */
				$urls = [];
				$path  = '/core/api/jeeApi.php';
				$path .= '?plugin=' . __CLASS__;
				$path .= '&type=event';
				$path .= '&apikey=' . $apiKey;
				$path .= '&id=' . $cmdId;
				$path .= '&value=0';

				foreach ($ips as $ip) {
					$urls[] = 'http://' . $ip . $path;
				}
				$data = [
					"id"     => $id++,
					"method" => "Webhook.Create",
					"params" => [
						"event" => "input.toggle_off",
						"cid"	=> 0,
						"enable"=> true,
						"name"  => "Maintenance OFF",
						"urls"  => $urls,
					]
				];
				$answer = $this->postToDevice($data);



// (
// 	[id] => 1
// 	[cid] => 0
// 	[enable] => 1
// 	[event] => input.toggle_on
// 	[name] => Maintenance ON
// 	[ssl_ca] => ca.pem
// 	[urls] => Array
// 	(
// 		[0] => http://10.0.1.12/core/api/jeeApi.php?plugin=virtual&type=event&apikey=66Zyfkqc7uwhgRTdqAus4r8F3ku7JFIL&id=6721&value=1
// 	)
// 	[condition] =>
// 	[repeat_period] => 0
// )
// (
// 	[id] => 2
// 	[cid] => 0
// 	[enable] => 1
// 	[event] => input.toggle_off
// 	[name] => Maintenance OFF
// 	[ssl_ca] => ca.pem
// 	[urls] => Array
// 	(
// 		[0] => http://10.0.1.12/core/api/jeeApi.php?plugin=virtual&type=event&apikey=66Zyfkqc7uwhgRTdqAus4r8F3ku7JFIL&id=6721&value=0
// 	)
// 	[condition] =>
// 	[repeat_period] => 0
// )
				break;
			default:
				throw new Exception (__('Device non supporté',__FILE__));
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

class jeewatchdogCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*
	public static $_widgetPossibility = array();
	*/

	/*     * ***********************Methode static*************************** */


	/*     * *********************Methode d'instance************************* */

	/*
	* Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
	public function dontRemoveCmd() {
		return true;
	}
	*/

	// Exécution d'une commande
	public function execute($_options = array()) {
	}

	/*     * **********************Getteur Setteur*************************** */
}
