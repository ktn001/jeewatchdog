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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function jeewatchdog_goto_2() {
	foreach (jeewatchdog::byType('jeewatchdog') as $eqLogic) {
		$cmd = $eqLogic->getCmd('action','ping');
		if (is_object($cmd)) {
			$cmd->setLogicalId('kick');
			$cmd->save();
		}
	}
}

function jeewatchdog_goto_1() {
	$packagesjson = __DIR__ . '/packages.json';
	if (file_exists($packagesjson)){
		unlink($packagesjson);
	}
}

function jeewatchdog_upgrade() {
	$lastLevel = 2;

	$pluginLevel = config::byKey('pluginLevel', 'jeewatchdog', 0);
	log::add("jeewatchdog","info","pluginLevel: " . $pluginLevel . " => " . $lastLevel);
	for ($level = 0; $level <= $lastLevel; $level++) {
		if ($pluginLevel < $level) {
			$function = 'jeewatchdog_goto_' . $level;
			if (function_exists($function)) {
				log::add("jeewatchdog","debug","execution de " . $function . "()");
				$function();
			}
			config::save('pluginLevel',$level,'jeewatchdog');
			$pluginLevel = $level;
			log::add("jeewatchdog","info","pluginLevel: " . $pluginLevel);
		}
	}
}


// Fonction exécutée automatiquement après l'installation du plugin
function jeewatchdog_install() {
	log::add("jeewatchdog","info","Lancement de 'jeewatchdog_install()'");
	jeewatchdog_upgrade();
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function jeewatchdog_update() {
	log::add("jeewatchdog","info","Lancement de 'jeewatchdog_update()'");
	jeewatchdog_upgrade();
}

// Fonction exécutée automatiquement après la suppression du plugin
function jeewatchdog_remove() {
}
