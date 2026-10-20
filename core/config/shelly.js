// Configuration globale
let CONFIG = {
  jeedomName: '#jeedomName#',
  jeedomKey: '#jeedomKey#',
  eqLogicName: '#eqLogicName#',
  eqLogicId: '#eqLogicId#',
  logicalId: '#logicalId#',
  relayId: [#relayId#],
  inputId: #inputId#,
  watchdogTimeout: #watchdogTimeout#,
  offDuration: #offDuration#,
  virtualKey: '#virtualKey#',
  urlsOn: [#urlsOn#],
  urlsOff: [#urlsOff#],
};

// Declaration et initialisation de variables globales
let counter = CONFIG.watchdogTimeout;
let isOff = false;
print("Script demarre. Compteur initialise a :", counter);

// webhook pour le virtuel (on informe Jeedom lors des changements d'etat)
if (CONFIG.virtualKey != ''){
  virtualMaintenance = Virtual.getHandle(CONFIG.virtualKey)
  virtualMaintenance.on("change",function(event){
    let urls = event.value ? CONFIG.urlsOn : CONFIG.urlsOff
    for (let url of urls) {
      Shelly.call("HTTP.GET",{url: url, timeout: 5})
    }
  })
}

// Desactivation de la maintenance virtuelle sur desactivation maintenance physique
Shelly.addEventHandler(function(event) {
    // Verifie si l'evenement provient d'une entree (interrupteur)
    if (event.component === ("input:" + CONFIG.inputId)) {
        if (event.info.event === "toggle" && event.info.state === false) {
            let vmaintenance = Virtual.getHandle(CONFIG.virtualKey);
            if (vmaintenance !== null) {
                vmaintenance.setValue(false)
            }
        }
    }
})

// Serveur HTTP pour les pings de Jeedom
HTTPServer.registerEndpoint("jeePing",function(request,response) {
  counter = CONFIG.watchdogTimeout
  print("Compteur reinitialise a distance a :", counter);
  if (request.method !== 'GET') {
    response.code = 405
    response.body = "Only GET method is allowed"
    response.send()
    return
  }
  if (CONFIG.inputId >= 0){
    Shelly.call("Input.GetStatus", {id: CONFIG.inputId}, function (result, error_code, error_message, response){
      jeePong (response, result)
    }, response)
  } else {
    jeePong(response)
  }
})

// Reponse au Jeeping
function jeePong(response,inputStatus) {
  let status = {}
  if (typeof inputStatus !== 'undefined'){
    status.maintenance = inputStatus.state ? '1' : '0'
  }
  boolean = Virtual.getHandle(CONFIG.virtualKey)
  if (boolean) {
    status.vmaintenance = boolean.getValue() ? '1' : '0'
  }
  response.code = 200
  response.headers = [["Content-Type", "application/json"]]
  response.body = JSON.stringify(status)
  response.send()
}

// Fonction que Jeedom va pouvoir appeler a distance
function setCounterRemote(newValue) {
  counter = newValue;
  print("Compteur reinitialise a distance a :", counter);
}


// Commande des relais
function setRelayState(state) {
  for (let i = 0; i < CONFIG.relayId.length; i++){
    Shelly.call("Switch.Set", { id: CONFIG.relayId[i], on: state });
  }
}

// Fonction de surveillance
let watch = function(inputStatus) {
  let maintenance = false
  if (typeof inputStatus !== 'undefined'){
    if (inputStatus.state){
      maintenance = true
    }
  }
  if (CONFIG.virtualKey != ''){
    boolean = Virtual.getHandle(CONFIG.virtualKey)
    if (boolean) {
      if (boolean.getValue()){
        maintenance = true
      }
    }
  }

  if (maintenance){
    print("Jeedom est en maintenance, le watchdog est en pause");
    counter = CONFIG.watchdogTimeout;
    isOff = false; // On annule une eventuelle attente en cours
    setRelayState(true); // On maintient le relais active pour ne pas couper Jeedom pendant sa maintenance
    return;
  }

  if (isOff) return;

  counter = counter -1
  print("Temps restant : " + JSON.stringify(counter) + "s");
  if (counter > 0) {
    setRelayState(true);
  } else {
    setRelayState(false);
    isOff = true
    print("Le compteur a atteint zero.");
    setRelayState(false);
    isOff = true;

    Timer.set(CONFIG.offDuration * 1000, false, function() {
      print("Reinitialisation automatique a : ", counter, "s");
      counter = CONFIG.watchdogTimeout;
      setRelayState(true);
      isOff = false;
    });
  }
}

// Boucle principale
Timer.set(1000, true, function() {
  if (CONFIG.inputId >= 0){
    Shelly.call("Input.GetStatus", {id: CONFIG.inputId}, watch)
  } else {
    watch()
  }
})

