// Configuration globale
let CONFIG = {
  jeedomName: '#jeedomName#',
  jeedomKey: '#jeedomKey#',
  eqLogicName: '#eqLogicName#',
  eqLogicId: '#eqLogicId#',
  relayId: [#relayId#],
  inputId: #inputId#,
  watchdogTimeout: #watchdogTimeout#,
  offDuration: #offDuration#
};

// Etat interne du script (rendu accessible globalement)
let counter = CONFIG.watchdogTimeout;
let isWaiting = false;

// Fonction que Jeedom va pouvoir appeler a distance
function setCounterRemote(newValue) {
  counter = newValue;
  print("Compteur force a distance a :", counter);
}

print("Script demarre. Compteur initialise a :", counter);

function setRelayState(state) {
  for (let relayId of CONFIG.relayId){
    Shelly.call("Switch.Set", { id: relayId, on: state });
  }
}

function watch() {
  // Attente de fin de cycle de coupure
  if (isWaiting) return;

  //Decompte standard
  counter = counter - 1;
  print("Temps restant : " + JSON.stringify(counter) + "s");

  if (counter > 0) {
    setRelayState(true);
  } else {
    print("Le compteur a atteint zero.");
    setRelayState(false);
    isWaiting = true;

    Timer.set(CONFIG.offDuration * 1000, false, function() {
      counter = CONFIG.watchdogTimeout;
      isWaiting = false;
      print("Reinitialisation automatique a : " + JSON.stringify(counter) + "s");
    });
  }
}

Timer.set(1000, true, function() {
  if (CONFIG.inputId >= 0){
    // On gère d'abord l'etat de l'interrupteur "maintenance"
    Shelly.call("Input.GetStatus", {id: CONFIG.inputId}, function(status) {
      if (status && status.state === true) {
        print("Jeedom est en maintenance, le watchdog est en pause");
        counter = CONFIG.watchdogTimeout;
        isWaiting = false; // On annule une eventuelle attente en cours
        setRelayState(true); // On maintient le relais active pour ne pas couper Jeedom pendant sa maintenance
        return;
      }
      watch()
    })
  } else {
    watch()
  }
});
// END
