<?php
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
        log::add(__CLASS__, 'debug', json_encode($_GET));
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
        log::add(__CLASS__, 'info', 'kick watchdog from cron');
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
        return $models[$model] ?? null;
    }

    /**
    *** Retourne un identifiant lisible pour nommer les composants dans le Shelly
    **/
    public static function readableID() {
        $consonants = 'bcdfghjklmnpqrstvwxz';
        $vowels = 'aeiouy';
        $maxConsonant = strlen($consonants) -1;
        $maxVowel = strlen($vowels) -1;
        $id = '';
        $startWithConsonant = (random_int(0, 1) === 0);

        for ($i = 0; $i < 8; $i++) {
            if ($startWithConsonant) {
                $id .= ($i % 2 === 0) ? $consonants[random_int(0, $maxConsonant)] : $vowels[random_int(0, $maxVowel)];
            } else {
                $id .= ($i % 2 === 0) ? $vowels[random_int(0, $maxVowel)] : $consonants[random_int(0, $maxConsonant)];
            }
        }
        return ucfirst($id);
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
    *** Vérification de la validité des configuration avant la mise à jour de l'eqLogic
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
    *** On force un LogicalId aléatoire
    **/
    public function preSave() {
        if ($this->getLogicalId() == ''){
            $this->setLogicalId(self::readableID());
        }
    }

    /**
    *** Création des commandes en même temps que l'eqLogic
    ***/
    public function postInsert() {
    }

    /**
    *** Mise à jour du cron et de l'appareil
    ***/
    public function postSave() {
        $this->createCmds();
        if ($this->getIsEnable()) {
            $this->configureDevice();
            $this->createOrUpdateCron();
            } else {
                $cron = $this->getCron(false);
                if (is_object($cron)) {
                    $cron->remove();
                }
                if ($this->getConfiguration('deviceModel') != '') {
                    $this->configureDevice();
                }
            }
    }

    /**
    *** Suppression du cron à la suppression de l'eqLogic
    ***/
    public function preRemove() {
        $cron = $this->getCron(false);
        if (is_object($cron)) {
            $cron->remove();
        }
        $this->unconfigureDevice();
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
        $cmd = $this->getCmd('info', 'maintenance');
        if (!is_object($cmd)) {
            $cmd = new jeewatchdogCmd();
            $cmd->setName(__('maintenance (physique)', __FILE__));
            $cmd->setLogicalId('maintenance');
            $cmd->setEqLogic_Id($this->getId());
            $cmd->setType('info');
            $cmd->setSubType('binary');
            $cmd->save();
        }

        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['virtual'] == 1) {
            $stateCmd = $this->getCmd('info', 'vmaintenance');
            if (!is_object($stateCmd)) {
                $stateCmd = new jeewatchdogCmd();
                $stateCmd->setName(__('maintenance (virtuel)', __FILE__));
                $stateCmd->setLogicalId('vmaintenance');
                $stateCmd->setEqLogic_Id($this->getId());
                $stateCmd->setType('info');
                $stateCmd->setSubType('binary');
                $stateCmd->setIsVisible(0);
                $stateCmd->save();
            }
            $onCmd = $this->getCmd('action', 'vmaintenance_on');
            if (!is_object($onCmd)) {
                $onCmd = new jeewatchdogCmd();
                $onCmd->setName(__('maintenance on', __FILE__));
                $onCmd->setLogicalId('vmaintenance_on');
                $onCmd->setEqLogic_Id($this->getId());
                $onCmd->setType('action');
                $onCmd->setSubType('other');
                $onCmd->setValue($stateCmd->getId());
                $onCmd->setTemplate('dashboard', "core::toggle");
                $onCmd->setTemplate('mobile', "core::toggle");
                $onCmd->save();
            }
            $offCmd = $this->getCmd('action', 'vmaintenance_off');
            if (!is_object($offCmd)) {
                $offCmd = new jeewatchdogCmd();
                $offCmd->setName(__('maintenance off', __FILE__));
                $offCmd->setLogicalId('vmaintenance_off');
                $offCmd->setEqLogic_Id($this->getId());
                $offCmd->setType('action');
                $offCmd->setSubType('other');
                $offCmd->setValue($stateCmd->getId());
                $offCmd->setTemplate('dashboard', "core::toggle");
                $offCmd->setTemplate('mobile', "core::toggle");
                $offCmd->save();
            }
        }

        $cmd = $this->getCmd('action', 'kick');
        if (!is_object($cmd)) {
            $cmd = new jeewatchdogCmd();
            $cmd->setName('kick');
            $cmd->setLogicalId('kick');
            $cmd->setEqLogic_Id($this->getId());
            $cmd->setType('action');
            $cmd->setSubType('other');
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
            log::add(__CLASS__, 'debug', sprintf(__("Création du cron pour %s (id: %s)", __FILE__), $this->getName(), $this->getId()));
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
            log::add(__CLASS__, 'warning', __("Le timeout du watchdog n'est pas défini!", __FILE__));
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

    public function _kickWatchdog() {
        $watchdogTimeout = $this->getConfiguration('watchdogTimeout') * 60;
        foreach (range(0, 15) as $i) {
            $id = $this->searchScript(true, true);
            if (is_numeric($id)) {
                break;
            }
            sleep(2);
        }
        if (!is_numeric($id)) {
            throw new Exception (__("Script Id introuvable", __FILE__));
        }
        $endPoint = '/script/' . $id .'/jeePing';
        $answer = $this->getFromEndPoint($endPoint);
        if ($answer['code'] != 200) {
            log::add(__CLASS__, "error", "Code HTTP final : " . $answer['code']);
            log::add(__CLASS__, "error", "header: ". $answer['header']);
            log::add(__CLASS__, "error", "body: ". $answer['body']);
            throw new Exception (sprintf(__("Code HTTP: %s: %s <br>Veuillez vérifier le log du plugin pour plus d'info.", __FILE__), $answer['code'], $answer['body']));
        }
        $infos = json_decode($answer['body'], true);
        foreach($infos as $logicalId => $value){
            $this->checkAndUpdateCmd($logicalId, $value);
        }
    }

    private function getFromEndpoint ($endpoint) {
        $deviceIP = $this->getConfiguration('deviceIP');
        $url = 'http://' . $deviceIP . $endpoint;
        log::add(__CLASS__, "debug", "  URL: $url");
        $password = $this->getConfiguration('password');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, "admin:$password");
        $response = curl_exec($ch);
        if ($response === false) {
            log::add(__CLASS__, 'error', __("Erreur lors de l'envoi de la requête", __FILE__) . ': ['. curl_errno($ch) . '] ' . curl_error($ch));
            log::add(__CLASS__, 'error', 'URL: ' . $url);
            throw new Exception (__("Erreur lors de l'envoi de la requête", __FILE__));
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

    private function postRpcRequest ($data) {
        $deviceIP = $this->getConfiguration('deviceIP');

        // Ajout d'une identification de la quequête
        $data['id'] = $this->getCache('requestId', 1);
        $this->setCache('requestId', $data['id']+1);

        // Ajout, si nécessaire, de la source de la requête
        if (!isset($data['src'])) {
            $data['src'] = "jeedom::" . config::byKey('name');
        }

        log::add(__CLASS__, "debug", "  " . sprintf(__("Envoi de la requete %s", __FILE__), json_encode($data)));
        $url = 'http://' . $deviceIP . '/rpc';
        log::add(__CLASS__, "debug", "  URL: $url");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        if ($response === false) {
            log::add(__CLASS__, 'error', __("Erreur lors de l'envoi de la requête", __FILE__) . ': ['. curl_errno($ch) . '] ' . curl_error($ch));
            log::add(__CLASS__, 'error', 'URL: ' . $url);
            throw new Exception (__("Erreur lors de l'envoi de la requête", __FILE__));
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
    private function sendToRpcDevice($data) {
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
        $response = $this->postRpcRequest($data);

        // 2. Si le nonce a expiré entre temps ou si c'est le tout premier appel (401)
        if ($response['code'] == 401) {
            log::add(__CLASS__, "debug", $indent . __("(ré)authentification", __FILE__));
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
                    $this->setCache('requestId', 1);
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
        $response = $this->postRpcRequest($data);
        }

        if ($response['code'] == 401) {
            // On retire du cache les donnée d'authentication
            $this->setCache('currentNonce', null);
            $this->setCache('ncCounter', null);
            $this->setCache('currentRealm', 0);
            throw new Exception(__("Accès au switch non autorisé. Veuillez vérifier le password.", __FILE__));
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
            sprintf(__("Code HTTP: %s: %s <br>Veuillez vérifier le log du plugin pour plus d'info.", __FILE__), $response['code'], $response['body']));
        }
        $indent .= '  ';
        log::add(__CLASS__, "debug", $indent . "Code HTTP final : " . $response['code']);
        log::add(__CLASS__, "debug", $indent . "body: ". $response['body']);

        $return = json_decode($response['body'], true);
        if (isset($return['error'])) {
            throw new Exception(sprintf("error(%s): %s", $return['error']['code'], $return['error']['message']));
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
           throw new Exception(sprintf("error(%s): %s", $answer['error']['code'], $answer['error']['message']));
       }
       return true;
    }

    /**
    *** Appel d'une méthode API du Shelly
    **/
    private function callShellyMethod($method, $params = null){
        $data = [
            "method" => $method,
            "params" => $params,
        ];
        return $this->sendToRpcDevice($data);
    }

    /**
    *** Récupération de la config du Device
    ***/
    private function deviceConfiguration($l1Key=null, $l2Key=null, $l3Key=null, $l4key=null) {
        if (!isset($this->_deviceConfiguration) or !is_array($this->_deviceConfiguration)) {
            log::add(__CLASS__, "info", __("Récupération de la configuration actuelle de l'appareil", __FILE__));
            $answer = $this->callShellyMethod('Shelly.GetConfig');
            $this->_deviceConfiguration = $answer['result'];
        }
        $return = $this->_deviceConfiguration;
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

    /*********************************************** D E V I C E    B L E ************************/

    /**
    *** Configuration du composant "BLE" de l'appareil
    ***/
    private function configureDeviceBLE() {
        if ($this->deviceConfiguration('ble', 'rpc', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation de BLE", __FILE__));
            $params = [
                'config' => [
                    'rpc' => [ 'enable' => false ]
                ]
            ];
            $this->callShellyMethod("BLE.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    C L O U D ************************/

    /**
    *** Configuration du composant "Cloud" de l'appareil
    ***/
    private function configureDeviceCloud() {
        if ($this->deviceConfiguration('cloud', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation du cloud", __FILE__));
            $params = [
                'config' => [ 'enable' => false ]
            ];
            $this->callShellyMethod("Cloud.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    I N P U T ************************/

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
            throw new Exception (__("Les appareils ayant plusieurs inputs ne sont pas supportés", __FILE__));
        }
        $config = [];

        if ($this->getIsEnable()) {
            if (! $this->deviceConfiguration('input:0', 'enable')){
                log::add(__CLASS__, "info", __("Activation de l'input 0 (bouton maintenance)", __FILE__));
                $config['enable'] = true;
            }
            $name = $this->getLogicalId() . "_" . __("maintenance", __FILE__);
            $inputName = $this->deviceConfiguration('input:0', 'name');
            if ( $inputName != $name ) {
                log::add(__CLASS__, "info", __("Configuration du nom de l'input (bouton maintenance)", __FILE__));
                $config['name'] = $name;
            }
            if ( "switch" != $this->deviceConfiguration('input:0', 'type')){;
                log::add(__CLASS__, "info", __("Activation de l'input 0 (bouton maintenance)", __FILE__));
                $config['type'] = 'switch';
            }
        } else {
            if ($this->deviceConfiguration('input:0', 'enable')){
                log::add(__CLASS__, "info", __("Désactivation de l'input 0 (bouton maintenance)", __FILE__));
                $config['enable'] = false;
            }
        }

        if (count($config) > 0) {
            $params = [
                'id' => 0,
                'config' => $config
            ];
            $this->callShellyMethod("Input.setConfig", $params);
        }
    }

    /**
    *** Déconfiguration de l'input pour le bouton de maintenance
    ***/
    private function unconfigureDeviceInput() {
        log::add(__CLASS__, "info", __("Déconfiguration du bouton de maintenance", __FILE__));
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['nbInput'] == 0) {
            return;
        }
        if ($device['nbInput'] > 1) {
            throw new Exception (__("Les appareils ayant plusieurs inputs ne sont pas supportés", __FILE__));
        }
        $params = [
            "id"     => 0,
            "config" => [
                "name" => "",
                "enable" => false,
            ]
        ];
        $this->callShellyMethod("Input.setConfig", $params);
    }

    /*********************************************** D E V I C E    K N X ************************/

    /**
    *** Configuration du composant "KNX" de l'appareil
    ***/
    private function configureDeviceKNX() {
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['knx'] == 0) {
            return;
        }
        if ($this->deviceConfiguration('knx', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation du KNX", __FILE__));
            $params = [
                'config' => [ 'enable' => false ]
            ];
            $this->callShellyMethod("KNX.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    M O D B U S ************************/

    /**
    *** Configuration du composant "Modbus" de l'appareil
    ***/
    private function configureDeviceModbus() {
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['modbus'] == 0) {
            return;
        }
        if ($this->deviceConfiguration('modbus', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation du modbus", __FILE__));
            $params = [
                'config' => [ 'enable' => false ]
            ];
            $this->callShellyMethod("Modbus.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    M Q T T ************************/

    /**
    *** Configuration du composant "Mqtt" de l'appareil
    ***/
    private function configureDeviceMqtt() {
        if ($this->deviceConfiguration('mqtt', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation du mqtt", __FILE__));
            $params = [
                'config' => [ 'enable' => false ]
            ];
            $this->callShellyMethod("MQTT.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    S C R I P T ************************/

    /**
    *** Enregistrement de l'id du script dans le cache
    **/
    public function setScriptId($_scriptId) {
        $this->setCache('scriptId', $_scriptId);
        return $this;
    }

    /**
    *** Récupération de l'id du script dans le cache
    **/
    public function getScriptId() {
        return $this->getCache('scriptId', null);
    }

    /**
    *** Retourne le nom du script
    **/
    private function scriptName() {
        return $this->getLogicalId() . "::jeeWatchdog";
    }

    /**
    *** Retourne les infos de script
    **/
    private function scriptInfos($id = null, $noCache = false){
        $getConfig = <<<GETCONFIG
            let conf = "{}"
            if (typeof CONFIG !== 'undefined') {
                conf = JSON.stringify(CONFIG)
            }
            conf
        GETCONFIG;

        if (!isset($this->_scriptsInfos) or $noCache) {
            $answer = $this->callShellyMethod("Script.List");
            $this->_scriptsInfos = [];
            foreach ($answer['result']['scripts'] as $script) {
                $this->_scriptsInfos[$script['id']] = ['meta' => $script];
            }
        }
        if ($id !== null){
            if (!isset($this->_scriptsInfos[$id])){
                return null;
            }
            if (!isset($this->_scriptsInfos[$id]['config'])) {
                if ($this->_scriptsInfos[$id]['meta']['running']){
                    $params = [
                        "id" => $id,
                        "code" => $getConfig,
                    ];
                    $answer = $this->callShellyMethod("Script.Eval", $params);
                    $config = json_decode($answer['result']['result'], true);
                    $this->_scriptsInfos[$id]['config'] = $config;
                }
            }
            return $this->_scriptsInfos[$id];
        }
        return $this->_scriptsInfos;
    }

    /**
    *** Vérifie si le script est celui de l'équipement
    ***
    *** return
    ***   0 : non
    ***   1 : peut-être
    ***   2 : oui
    **/
    private function isMyScript($id){
        $infos = $this->scriptInfos($id);
        if ($infos['meta']['name'] === $this->scriptName()) {
            return 2;
        }
        if (!$infos['meta']['running']) {
            return 1;
        }
        $config = $infos['config'];
        if (count($config) == 0) {
            return 0;
        }
        if (!isset($config['jeedomKey']) or $config['jeedomKey'] != config::byKey('jeedom::installKey')){
            return 0;
        }
        if (!isset($config['eqLogicId']) or $config['eqLogicId'] != $this->getId()){
            return 0;
        }
        return 2;
    }

    /**
    *** Recherche du script installé dans le Shelly
    **/
    private function searchScript($idOnly = false, $runningOnly = false) {
        log::add(__CLASS__, "info", __("Recherche du script", __FILE__));

        $scriptId = $this->getScriptId();
        log::add(__CLASS__, "debug", "  Script Id fron cache: $scriptId");
        if ($scriptId !== null) {
            $probability = $this->isMyScript($scriptId);
            if ($probability == 2){
                return $scriptId;
            }
        }

        $probabilities = [[], [], []];
        foreach (array_keys($this->scriptInfos()) as $id) {
            $probability = $this->isMyScript($id);
            $probabilities[$probability][] = $id;
        }

        if (count($probabilities[2]) > 0){
            return $probabilities[2][0];
        }
        if (count($probabilities[1]) > 0){
            return $probabilities[1][0];
        }
        return null;
    }

    /**
    *** Configuration du script
    ***/
    private function configureDeviceScript(){
        log::add(__CLASS__, "info", __("Configuration du script", __FILE__));
        $scriptId = $this->searchScript();
        if ($scriptId === null){
            $answer = $this->callShellyMethod("Script.Create", [ "name" => $this->scriptName() ]);
            $scriptId = $answer['result']['id'];
            $scriptInfos = $this->scriptInfos($scriptId, true);
        } else {
            $scriptInfos = $this->scriptInfos($scriptId);
        }

        /* Creation du code */
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

        // Les adresses IP de Jeedom
        //
        $ips = $this->getIPs();
        if (count($ips) == 0) {
            throw new Exception(__("Adresse IP de jeedom introuvable!", __FILE__));
        }
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

        // L'id de la commande
        //
        $cmd = $this->getCmd('info', 'vmaintenance');
        if (!is_object($cmd)) {
            throw new Exception(sprintf(__("Commande '%s' introuvable!", __FILE__), 'vmaintenance'));
        }
        $cmdId = $cmd->getId();

        $path  = '/core/api/jeeApi.php';
        $path .= '?plugin=' . __CLASS__;
        $path .= '&type=event';
        $path .= '&apikey=' . $apiKey;
        $path .= '&id=' . $cmdId;
        $path .= '&value=';

        $urlsOn = [];
        $urlsOff = [];
        foreach ($ips as $ip) {
            $urlsOn[] = '"http://' . $ip . $path . '1"';
            $urlsOff[] = '"http://' . $ip . $path . '0"';
        }

        $code = file_get_contents($scriptFile);
        $watchdogTimeout = $this->getConfiguration('watchdogTimeout') * 60;

        $virtualKey = "";
        if ($model['virtual'] == 1){
            $virtual = $this->boolean();
            if (is_array($virtual)){
                $virtualKey = $virtual['key'];
            }
        }
        log::add(__CLASS__, "debug", print_r($virtual, true));

        $replace = [
            '#jeedomName#'      => config::byKey('name'),
            '#jeedomKey#'       => config::byKey('jeedom::installKey'),
            '#eqLogicName#'     => $this->getName(),
            '#eqLogicId#'       => $this->getId(),
            '#logicalId#'       => $this->getLogicalId(),
            '#watchdogTimeout#' => $watchdogTimeout,
            '#offDuration#'     => $this->getConfiguration('offDuration'),
            '#relayId#'         => join(',', $switchesToDrive),
            '#inputId#'         => $inputId,
            '#virtualKey#'      => $virtualKey,
            '#urlsOn#'          => join(',', $urlsOn),
            '#urlsOff#'         => join(',', $urlsOff),
        ];
        $code = str_replace(array_keys($replace), $replace, $code);

        $answer = $this->callShellyMethod("Script.GetCode", [ "id" => $scriptId ]);
        $actualCode = $answer['result']['data'];
        if ($actualCode !== $code) {
            if ($scriptInfos['meta']['running']) {
                $this->callShellyMethod("Script.Stop", [ "id" => $scriptId ]);
                $scriptInfos['meta']['running'] = false;
            }
            $params = [
                "id" => $scriptInfos['meta']['id'],
                "code" => $code
            ];
            $this->callShellyMethod("Script.putCode", $params);
        }

        $config = [];
        if ($scriptInfos['meta']['name'] !== $this->scriptName()) {
            $config['name'] = $this->scriptName();
        }

        if ($this->getIsEnable()) {
            if (!$scriptInfos['meta']['enable']) {
                $config['enable'] = true;
            }
        } else {
            if ($scriptInfos['meta']['enable']) {
                $config['enable'] = false;
            }
        }

        if (count($config) > 0){
            $params = [
                'id'     => $scriptInfos['meta']['id'],
                'config' => $config
            ];
            $this->callShellyMethod("Script.SetConfig", $params);
        }

        if ($this->getIsEnable()) {
            if (!$scriptInfos['meta']['running']) {
                $this->callShellyMethod("Script.Start", [ 'id' => $scriptInfos['meta']['id'] ]);
            }
        } else {
            if ($scriptInfos['meta']['running']) {
                $this->callShellyMethod("Script.Stop", [ 'id' => $scriptInfos['meta']['id'] ]);
            }
        }

        $this->setScriptId($scriptInfos['meta']['id']);
    }

    /**
    *** Déconfiguration du script
    ***/
    private function unconfigureDeviceScript(){
        log::add(__CLASS__, "info", __("Déconfiguration du script", __FILE__));
        $scriptId = $this->searchScript();
        if ($scriptId === null){
            return;
        }
        $scriptInfos = $this->scriptInfos($scriptId);
        if (is_array($scriptInfos)) {
            if ($scriptInfos['meta']['running']) {
                $this->callShellyMethod("Script.Stop", [ 'id' => $scriptInfos['meta']['id'] ]);
            }
            $this->callShellyMethod("Script.Delete", [ 'id' => $scriptInfos['meta']['id'] ]);
        }
        $this->setScriptId(null);
    }

    /*********************************************** D E V I C E    S W I T C H ************************/

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

                $actualName = $this->deviceConfiguration($switchKey, 'name');
                $name = config::byKey('name') . "::supply:" . $this->getId() . "_" . $switchId;
                if ($actualName != $name) {
                    log::add(__CLASS__, "info", sprintf(__("Configuration du nom du switch %s", __FILE__), $switchKey));
                    $config['name'] = $name;
                }

                if ("detached" != $this->deviceConfiguration($switchKey, 'in_mode')){
                    log::add(__CLASS__, "info", sprintf(__("Détachement du switch %s", __FILE__), $switchKey));
                    $config['in_mode'] = "detached";
                }

                if ("on" != $this->deviceConfiguration($switchKey, 'initial_state')){
                    log::add(__CLASS__, "info", sprintf(__("Configuration de l'état du switch %s au boot", __FILE__), $switchKey));
                    $config['initial_state'] = "on";
                }

                if ($this->deviceConfiguration($switchKey, 'in_locked')){
                    log::add(__CLASS__, "info", sprintf(__("Désactivation du lock du switch %s", __FILE__), $switchKey));
                    $config['in_locked'] = false;
                }

                if ($this->deviceConfiguration($switchKey, 'auto_on')){
                    log::add(__CLASS__, "info", sprintf(__("Désactivation de l'auto_on du switch %s", __FILE__), $switchKey));
                    $config['auto_on'] = false;
                }

                if ($this->deviceConfiguration($switchKey, 'auto_off')){
                    log::add(__CLASS__, "info", sprintf(__("Désactivation de l'auto_off du switch %s", __FILE__), $switchKey));
                    $config['auto_off'] = false;
                }

                if (count($config) > 0) {
                    $params = [
                        'id' => $switchId,
                        'config' => $config,
                    ];
                    $this->callShellyMethod("Switch.SetConfig", $params);
                }
            } else {
                $actualName = $this->deviceConfiguration($switchKey, 'name');
                $name = config::byKey('name') . "::supply:" . $this->getId() . "_" . $switchId;
                if ($actualName == $name) {
                    log::add(__CLASS__, "info", sprintf(__("Déconfiguration du nom du switch %s", __FILE__), $switchKey));
                    $params = [
                        'id' => $switchId,
                        'config' => [ 'name' => '' ],
                    ];
                    $this->callShellyMethod("Switch.SetConfig", $params);
                }
            }
        }
    }

    /**
    *** Déconfiguration du/des switch(es)
    ***/
    private function unconfigureDeviceSwitch() {
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
                $params = [
                    'id' => $switchId,
                    'config' => [ 'name' => '' ],
                ];
                $this->callShellyMethod("Switch.SetConfig", $params);
            }
        }
    }

    /*********************************************** D E V I C E    S Y S T E M ************************/

    /**
    *** Retourne le nom du l'appareil
    **/
    private function deviceName() {
        $jeedomName = config::byKey('name');
        $deviceName = $jeedomName == '' ? $this->getName() : $jeedomName . "::" . $this->getLogicalId();
        return $deviceName;
    }

    /**
    *** Configuration du composant "System" de l'appareil
    ***/
    private function configureDeviceSystem() {
        if (!$this->getIsEnable()) {
            return;
        }
        $config = [];

        // Configuration du nom de l'appareil
        $actualDeviceName = $this->deviceConfiguration('sys', 'device', 'name');
        $deviceName = $this->deviceName();
        if ($actualDeviceName != $deviceName) {
            log::add(__CLASS__, "info", __("Configuration du nom de l'appareil", __FILE__) . ": '$actualDeviceName' => '$deviceName'");
            $config['device'] = $config['device'] ?? [];
            $config["device"]["name"] = $deviceName;
        }

        // Configuration de debug mqtt
        if ($this->deviceConfiguration('sys', 'debug', 'mqtt', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation de debug mqtt", __FILE__));
            $config['debug'] = $config['debug'] ?? [];
            $config['debug']['mqtt'] =  $config['debug']['mqtt'] ?? [];
            $config['debug']['mqtt']['enable'] = false;
        }

        // Configuration de debug websocket
        if (!$this->deviceConfiguration('sys', 'debug', 'websocket', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation de debug websocket", __FILE__));
            $config['debug'] = $config['debug'] ?? [];
            $config['debug']['websocket'] = $config['debug']['websocket'] ?? [];
            $config['debug']['websocket']['enable'] = true;
        }

        if (count($config) > 0) {
            $this->callShellyMethod("Sys.SetConfig", ['config' => $config]);
        }
    }

    private function unconfigureDeviceSystem(){
        log::add(__CLASS__, "info", __("Déconfiguration du nom de l'appareil", __FILE__));
        $params = [
            "config" => [
                "device" => [ "name" => "" ],
            ]
        ];
        $this->callShellyMethod("Sys.setConfig", $params);
    }

    /*********************************************** D E V I C E    V I R T U A L ************************/

    /**
    *** Retourne le nom du virtuel
    **/
    private function virtualName(){
        return $this->getLogicalId() . "::virtual_maintenance";
    }

    /**
    *** Retourne le groupe de virtuel
    **/
    private function group($create = true) {
        if (isset($this->_group)){
            return $this->_group;
        }
        if (!isset($this->_virtualsComponants)) {
            $answer = $this->callShellyMethod("Shelly.GetComponents", [ 'dynamic_only' => true ]);
            $this->_virtualsComponents = $answer['result']['components'];
        }

        $groupName = 'Maintenance';
        $groups = [];
        foreach ($this->_virtualsComponents as $component) {
            [$type, $id] = explode(':', $component['key']);
            if ($type === 'group') {
                $groups[] = $component;
            }
        }
        $group = null;
        foreach ($groups as $grp) {
            if ($grp['config']['name'] === $groupName) {
                $group = $grp;
                break;
            }
        }
        if ($group === null and $create){
            $params = [
                'type' => 'group',
                'config' => [
                    'name' => $groupName,
                ]
            ];
            $answer = $this->callShellyMethod("Virtual.Add", $params);
            $params = [
                'keys' => [ 'group:' . $answer['result']['id'] ]
            ];
            $answer = $this->callShellyMethod("Shelly.GetComponents", $params);
            $group = $answer['result']['components'][0];
        }
        $this->_group = $group;
            return $group;
    }

    /**
    *** Retoune le bouton virtuel de maintenance
    **/
    private function boolean($create=true, $force=false){
        if (isset($this->_boolean )){
            return $this->_boolean;
        }
        if (!isset($this->_virtualsComponants)) {
            $answer = $this->callShellyMethod("Shelly.GetComponents", [ 'dynamic_only' => true ]);
            $this->_virtualsComponents = $answer['result']['components'];
        }

        $booleanName = $this->getLogicalId();
        $booleans = [];
        foreach ($this->_virtualsComponents as $component) {
            [$type, $id] = explode(':', $component['key']);
            if ($type === 'boolean') {
                $booleans[] = $component;
            }
        }
        $boolean = null;
        foreach ($booleans as $bool) {
            if ($bool['config']['name'] === $booleanName) {
                $boolean = $bool;
                break;
            }
        }
        if ($boolean === null and $create){
            $params = [
                'type' => 'boolean',
                'config' => [
                    'name' => $booleanName,
                ]
            ];
            $answer = $this->callShellyMethod("Virtual.Add", $params);
            $params = [
                'keys' => [ 'boolean:' . $answer['result']['id'] ]
            ];
            $answer = $this->callShellyMethod("Shelly.GetComponents", $params);
            $boolean = $answer['result']['components'][0];
        }
        $this->_boolean = $boolean;
        return $boolean;
    }

    /**
    *** Modifie l'état du boolean
    **/
    public function setVirtualMaintenance($state){
        $boolean = $this->boolean(false);
        if (!is_array($boolean)){
            throw new Exception (__("Le bouton virtuel de maintenance est introuvable", __FILE__));
        }
        $params = [
            'id' => $boolean['config']['id'],
            'value' => $state
        ];
        $this->callShellyMethod("Boolean.Set", $params);
    }

    /**
    *** Configuration du bouton virtuel de maintenance
    **/
    private function configureDeviceVirtual() {
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['virtual'] == 0) {
            return;
        }
        log::add(__CLASS__, "info", __("Configuration du bouton virtuel de maintenance", __FILE__));

        $boolean = $this->boolean();

        // Configuration du boolean
        $config = [];
        if (!$boolean['config']['persisted']){
            $config['persisted'] = true;
        }
        if ($boolean['config']['default_value']) {
            $config['default_value'] = false;
        }
        if (count($config) > 0){
            $params = [
                'id' => $boolean['config']['id'],
                'config' => $config,
            ];
            $this->callShellyMethod("Boolean.SetConfig", $params);
        }

        // Le boolean doit être dans le group
        //
        $group = $this->group();
        if (!is_array($group['status']['value'])) {
            $group['status']['value'] = [];
        }
        if (!in_array($boolean['key'], $group['status']['value'])) {
            $group['status']['value'][] = $boolean['key'];
            $params = [
                "id" => $group['config']['id'],
                "value" => $group['status']['value'],
            ];
            $this->callShellyMethod("Group.Set", $params);
        }
    }

    /**
    *** Déconfiguration du bouton virtuel de maintenance
    **/
    private function unconfigureDeviceVirtual() {
        $boolean = $this->boolean(false);
        if (is_array($boolean)){
            $this->callShellyMethod("Virtual.Delete", ['key'=> $boolean['key']]);
        }

        $group = $this->group(false);
        if (is_array($group) and (!is_array($group['status']['value']) or count($group['status']['value']) == 0) ){
            $this->callShellyMethod("Virtual.Delete", ['key'=> $group['key']]);
        }
    }

    /*********************************************** D E V I C E    W E B H O O K ************************/

    /**
    *** Retourne la liste des adresses IP du serveur Jeedom
    **/
    private function getIPs() {
        $interfaces = network::getInterfacesInfo();
        $ips = [];
        foreach ($interfaces as $interface) {
            if (in_array('LOOPBACK', $interface['flags'])){
                continue;
            }
            if (!in_array('UP', $interface['flags'])){
                continue;
            }
            foreach ($interface['addr_info'] as $addrInfo) {
                if ($addrInfo['family'] != 'inet') {
                    continue;
                }
                $ips[] = $addrInfo['local'];
            }
        }
        return $ips;
    }

    /**
    *** Configuration des webhooks pour signaler l'état des bouton physique et virtuel de "maintenance" à Jeedom
    ***/
    private function configureDeviceWebhook(){
        log::add(__CLASS__, "info", __("Configuration des webhooks", __FILE__));

        // Les infos du Shelly
        //
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device['nbInput'] == 0){
            return;
        }

        // Les adresses IP de Jeedom
        //
        $ips = $this->getIPs();
        if (count($ips) == 0) {
            throw new Exception(__("Adresse IP de jeedom introuvable!", __FILE__));
        }

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

        $whInfos = [];

        // L'id de la commande
        //
        $cmd = $this->getCmd('info', 'maintenance');
        if (!is_object($cmd)) {
            throw new Exception(sprintf(__("Commande '%s' introuvable!", __FILE__), 'maintenance'));
        }
        $whInfos['cmdId'] = $cmd->getId();
        $whInfos['cid'] = 0;

        $path  = '/core/api/jeeApi.php';
        $path .= '?plugin=' . __CLASS__;
        $path .= '&type=event';
        $path .= '&apikey=' . $apiKey;
        $path .= '&id=' . $whInfos['cmdId'];
        $path .= '&value=';

        foreach (['on', 'off'] as $state){
            $whInfos[$state] = [];
            $whInfos[$state]['status'] = 'notFound';

            // Le nom
            //
            $whInfos[$state]['name'] = $this->getLogicalId() . "::" . __('maintenance', __FILE__) . "::" . $state;

            // l'event
            //
            $whInfos[$state]['event'] = "input.toggle_" . $state;

            // Les urls
            //
            $value = $state == 'on' ? 1 : 0;
            $whInfos[$state]['urls'] = [];
            foreach ($ips as $ip){
                $whInfos[$state]['urls'][] = 'http://' . $ip . $path . $value;
            }
        }

        // Traitement des webhooks existants
        //
        log::add(__CLASS__, "info", __("Récupération de la liste de webhooks", __FILE__));
        $answer = $this->callShellyMethod("Webhook.List");
        $hooks= $answer['result']['hooks'];

        foreach ($hooks as $hook){
            if ($hook['cid'] != $whInfos['cid']) {
                continue;
            }
            if (substr($hook['event'], 0, 13) !== 'input.toggle_') {
                log::add(__CLASS__, "info", sprintf(__("Suppression du webHook %s (event %s inconnu)", __FILE__), $actualHook['id'], $actualHook['event']));
                $this->callShellyMethod("Webhook.Delete", ['id' => $hook['id']]);
                continue;
            }
            $hookState = substr($hook['event'], 13);  // "on" ou "off"

            //Supression de duplicate
            //
            if ($whInfos[$hookState]['status'] !== 'notFound') {
                log::add(__CLASS__, "info", sprintf(__("Suppression du webHook %s (dupliqué)", __FILE__), $hook['id']));
                $this->callShellyMethod("Webhook.Delete", ['id' => $hook['id']]);
                continue;
            }

            $whInfos[$hookState]['status'] = 'found';

            // Check name
            //
            $params = [];
            if ($whInfos[$hookState]['name'] !== $hook['name']){
                $params['name'] = $whInfos[$hookState]['name'];
            }

            // Check enable
            //
            if ($this->getIsEnable()){
                if (!$hook['enable']){
                    $params['enable'] = true;
                }
            } else {
                if ($hook['enable']){
                    $params['enable'] = false;
                }
            }

            // Check urls
            //
            $urlsOK = true;
            if (count($whInfos[$hookState]['urls']) != count($hook['urls'])) {
                log::add(__CLASS__, "debug", __("Le nombre d'urls ne correspond pas", __FILE__));
                $urlsOK = false;
            } else {
                foreach ($whInfos[$hookState]['urls'] as $url){
                    $found = false;
                    foreach($hook['urls'] as $actualUrl){
                        if ($url === $actualUrl) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found){
                        $urlsOK = false;
                        continue;
                    }
                }
            }
            if (!$urlsOK){
                $params['urls'] = $whInfos[$hookState]['urls'];
            }

            if (count($params)){
                $params['id'] = $hook['id'];
                $this->callShellyMethod("Webhook.Update", $params);
            }

        }

        // Création des hooks qui n'ont pas été trouvés
        //
        foreach (['on', 'off'] as $state) {
            if ($whInfos[$state]['status'] == 'notFound') {
                $enable = $this->getIsEnable() ? true : false;
                $params = [
                    "event" => $whInfos[$state]['event'],
                    "cid"   => $whInfos['cid'],
                    "enable"=> $enable,
                    "name"  => $whInfos[$state]['name'],
                    "urls"  => $whInfos[$state]['urls'],
                ];
                $this->callShellyMethod("Webhook.Create", $params);
            }
        }
    }

    /**
    *** Déconfiguration de Webhook
    **/
    private function unconfigureDeviceWebhook(){
        log::add(__CLASS__, "info", __("Récupération de la liste de webhooks", __FILE__));
        $answer = $this->callShellyMethod("Webhook.List");
        $hooks= $answer['result']['hooks'];

        $ips = $this->getIPs();
        foreach ($hooks as $hook){
            $ok = true;
            foreach ($hook['urls'] as $url) {
                $elems = parse_url($url);
                if (!in_array($elems['host'], $ips)) {
                    $ok = false;
                    continue;
                }
                if ($elems['path'] !== '/core/api/jeeApi.php') {
                    $ok = false;
                    continue;
                }
                $args = [];
                parse_str($elems['query'], $args);
                if ($args['plugin'] != 'jeewatchdog') {
                    $ok = false;
                    continue;
                }
                $cmd = cmd::byId($args['id']);
                if (!is_object($cmd)) {
                    $ok = false;
                    continue;
                }
                if ($cmd->getEqLogic_id() != $this->getId()){
                    $ok = false;
                    continue;
                }
            }
            if ($ok){
                log::add(__CLASS__, "info", sprintf(__("Suppression de l'action %s", __FILE__), $hook['id']));
                $this->callShellyMethod("Webhook.Delete", ['id' => $hook['id']]);
            }
        }
    }

    /*********************************************** D E V I C E    W E B S O C K E T ************************/

    /**
    *** Configuration du composant "Outbound Websocket" de l'appareil
    ***/
    private function configureDeviceWs() {
        if ($this->deviceConfiguration('ws', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation de outbound Websocket", __FILE__));
            $params = [
                'config' => [ 'enable' => false ]
            ];
            $this->callShellyMethod("Ws.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E    W I F I ************************/

    /**
    *** Configuration du composant "Wifi" de l'appareil
    ***/
    private function configureDeviceWifi() {
        // Configuration le l'access point
        if ($this->deviceConfiguration('wifi', 'ap', 'enable')){
            log::add(__CLASS__, "info", __("Désactivation de l'access point", __FILE__));
            $params = [
                'config' => [
                    'ap' => [ 'enable' => false ]
                ]
            ];
            $this->callShellyMethod("wifi.setConfig", $params);
        }
    }

    /*********************************************** D E V I C E ************************/

    /**
    *** Configuration de l'appareil
    ***/
    public function configureDevice() {
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device === null) {
            throw new Exception (sprintf(__("Le modèle %s est inconnu.", __FILE__), $deviceModel));
        }
        $jeedomName = config::byKey('name');

        $deviceIP = $this->getConfiguration('deviceIP');
        if ($deviceIP == '') {
            throw new Exception (__("Adresse IP ou nom DNS du device inconnu!", __FILE__));
        }
        $deviceName = $jeedomName == '' ? $this->getName() : $jeedomName . "::" . $this->getName();

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
        $this->configureDeviceWebhook();
        $this->configureDeviceVirtual();
        $this->configureDeviceSwitch();
        $this->configureDeviceScript();

        if ($this->_restartRequired) {
            $this->callShellyMethod("Shelly.reboot");
            unset ($this->_restartRequired);
        }
        if ($this->getIsEnable()){
            $this->_kickWatchdog();
        }
    }

    /**
    *** Déconfiguration de l'appareil
    **/
    public function unconfigureDevice() {
        $deviceModel = $this->getConfiguration('deviceModel');
        $device = self::getModel($deviceModel);
        if ($device === null) {
            return;
        }

        $deviceIP = $this->getConfiguration('deviceIP');
        if ($deviceIP == '') {
            throw new Exception (__("Adresse IP ou nom DNS du device inconnu!", __FILE__));
        }
        $jeedomName = config::byKey('name');
        $deviceName = $jeedomName == '' ? $this->getName() : $jeedomName . "::" . $this->getName();

        $this->_restartRequired = false;

        $this->unconfigureDeviceScript();
        $this->unconfigureDeviceSwitch();
        $this->unconfigureDeviceVirtual();
        $this->unconfigureDeviceWebhook();
        $this->unconfigureDeviceInput();
        $this->unconfigureDeviceSystem();

        if ($this->_restartRequired) {
            $this->callShellyMethod("Shelly.reboot");
            unset ($this->_restartRequired);
        }
    }

    /*     * ***************************************************************** */
    /*     * **********************Getteurs Setteurs************************** */
    /*     * ***************************************************************** */

    public function getDeviceModel() {
        return $this->getConfiguration('deviceModel');
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
        switch ($this->getLogicalId()) {
            case 'vmaintenance':
            case 'vmaintenance_on':
            case 'vmaintenance_off':
                return false;
        }
        return true;
    }

    // Exécution d'une commande
    public function execute($_options = array()) {
        if ($this->getType() == 'action'){
            switch ($this->getLogicalId()) {
                case 'kick':
                    log::add("jeewatchdog", "info", "Kick watchdog from cmd");
                    $this->getEqLogic()->_kickWatchdog();
                    break;
                case 'vmaintenance_on':
                    log::add("jeewatchdog", "info", "Set virtual maintenance ON");
                    $this->getEqLogic()->setVirtualMaintenance(true);
                    break;
                case 'vmaintenance_off':
                    log::add("jeewatchdog", "info", "Set virtual maintenance OFF");
                    $this->getEqLogic()->setVirtualMaintenance(false);
                    break;
            }
        }
    }

    /*     * ***************************************************************** */
    /*     * **********************Getteurs Setteurs************************** */
    /*     * ***************************************************************** */

}
