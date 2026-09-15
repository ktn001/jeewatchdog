// Configuration globale
let CONFIG = {
  relayId: 0,
  inputId: 0,
  watchdogTimeout: #watchdogTimeout#,
  offDuration: #offDuration#
};

// État interne du script (rendu accessible globalement)
var counter = CONFIG.watchdogTimeout;
var isWaiting = false;

// Fonction que Jeedom va pouvoir appeler a distance
function setCounterRemote(newValue) {
  counter = newValue;
  print("Compteur force a distance a :", counter);
}

print("Script demarre. Compteur initialise a :", counter);

function setRelayState(state) {
  Shelly.call("Switch.Set", { id: CONFIG.relayId, on: state });
}

Timer.set(1000, true, function() {
  // 1. On interroge d'abord l'etat de l'Input (Priorite absolue)
  Shelly.call("Input.GetStatus", {id: CONFIG.inputId}, function(status) {
    if (status && status.state === true) {
      print("Jeedom est en maintenance, le watchdog est en pause");
      counter = CONFIG.watchdogTimeout;
      isWaiting = false; // On annule une eventuelle attente en cours
      setRelayState(true); // On maintient le relais active pour ne pas couper Jeedom pendant sa maintenance
      return;
    }

    // 2. Si pas de maintenance, on gere l'attente de fin de cycle
    if (isWaiting) return;

    // 3. Decompte standard
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
  });
});
// END
