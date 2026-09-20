/* This file is part of Jeedom.
// vi: tabstop=4 autoindent
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

"use strict";

if (typeof jeewatchdogFrontEnd === "undefined") {
  var jeewatchdogFrontEnd = {
    ajaxUrl: "plugins/jeewatchdog/core/ajax/jeewatchdog.ajax.php",
  };

  /* Initialisation après chargement de la page */
  jeewatchdogFrontEnd.init = function () {
    document
      .getElementById("div_pageContainer")
      .addEventListener("click", function (event) {
        let _target = null;

        if (
          (_target = event.target.closest(
            ".eqLogicAction[data-action=configureDevice]",
          ))
        ) {
          jeewatchdogFrontEnd.configureDevice();
          return;
        }

        if ((_target = event.target.closest(".bt_openShelly"))) {
          jeewatchdogFrontEnd.openShellyPage(_target);
          return;
        }
      });
    document
      .getElementById("div_pageContainer")
      .addEventListener("change", function (event) {
        let _target = null;

        if (
          (_target = event.target.closest(
            ".eqLogicAttr[data-l1key=configuration][data-l2key=deviceModel]",
          ))
        ) {
          jeewatchdogFrontEnd.deviceChanged(_target);
          return;
        }
      });
  };

  /* Configuration du device */
  jeewatchdogFrontEnd.configureDevice = function () {
    if (jeeFrontEnd.modifyWithoutSave) {
      jeeDialog.alert("{{Vous devez d'abord sauvegarder vos modifications}}");
      return;
    }
    domUtils.showLoading();
    domUtils.ajax({
      url: jeewatchdogFrontEnd.ajaxUrl,
      data: {
        action: "configureDevice",
        id: document.querySelector('.eqLogicAttr[data-l1key="id"]').jeeValue(),
      },
      success: function (data) {
        if (data.state != "ok") {
          jeedomUtils.showAlert({ message: data.result, level: "danger" });
          return;
        }
      },
    });
  };

  /* Modification de la sélection de device */
  jeewatchdogFrontEnd.deviceChanged = function (selector) {
    if (typeof selector.selectedOptions[0] !== "undefined") {
      let nbSwitches = selector.selectedOptions[0].dataset.nbswitch;
      if (nbSwitches < 2) {
        document
          .getElementById("div_pageContainer")
          .querySelector("#divPlugs")
          .unseen();
      } else {
        let divPlugs = document
          .getElementById("div_pageContainer")
          .querySelector("#divPlugs");
        divPlugs.seen();
        divPlugs
          .querySelectorAll("[data-l2key='switches']")
          .forEach(function (checkbox) {
            if (checkbox.dataset.l3key >= nbSwitches) {
              checkbox.closest("label").unseen();
            } else {
              checkbox.closest("label").seen();
            }
          });
      }
    }
  };

  /* Ouverture de la page de config du Shelly */
  jeewatchdogFrontEnd.openShellyPage = function (btn) {
    let shelly = btn
      .closest("div.input-group")
      .querySelector("input")
      .jeeValue();
    let url = "http://" + shelly;
    window.open(url);
  };

  /* Affichage d'une commande */
  jeewatchdogFrontEnd.addCmdToTable = function (_cmd) {
    if (!isset(_cmd)) {
      var _cmd = { configuration: {} };
    }
    if (!isset(_cmd.configuration)) {
      _cmd.configuration = {};
    }
    var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
    tr += '<td class="hidden-xs">';
    tr += '<span class="cmdAttr" data-l1key="id"></span>';
    tr += "</td>";
    tr += "<td>";
    tr += '<div class="input-group">';
    tr +=
      '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
    tr +=
      '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
    tr +=
      '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
    tr += "</div>";
    tr += "</td>";
    tr += "<td>";
    tr +=
      '<input class="cmdAttr form-control input-sm" data-l1key="type" disabled=1</input>';
    tr +=
      '<input class="cmdAttr form-control input-sm" data-l1key="subType" disabled=1</input>';
    tr += "</td>";
    tr += "<td>";
    tr +=
      '<input class="cmdAttr form-control input-sm" data-l1key="logicalId" disabled=1</input>';
    tr += "</td>";
    tr += "<td>";
    tr +=
      '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
    tr +=
      '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
    tr +=
      '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
    tr += '<div style="margin-top:7px;">';
    tr +=
      '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr +=
      '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr +=
      '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="Unité" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
    tr += "</div>";
    tr += "</td>";
    tr += "<td>";
    tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
    tr += "</td>";
    tr += "<td>";
    if (is_numeric(_cmd.id)) {
      tr +=
        '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
      tr +=
        '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
    }
    tr += "</td>";
    tr += "</tr>";
    $("#table_cmd tbody").append(tr);
    var tr = $("#table_cmd tbody tr").last();
    jeedom.eqLogic.buildSelectCmd({
      id: $(".eqLogicAttr[data-l1key=id]").value(),
      filter: { type: "info" },
      error: function (error) {
        $("#div_alert").showAlert({ message: error.message, level: "danger" });
      },
      success: function (result) {
        tr.setValues(_cmd, ".cmdAttr");
        jeedom.cmd.changeType(tr, init(_cmd.subType));
      },
    });
  };
}

jeewatchdogFrontEnd.init();
addCmdToTable = jeewatchdogFrontEnd.addCmdToTable;

/* Permet la réorganisation des commandes dans l'équipement */
$("#table_cmd").sortable({
  axis: "y",
  cursor: "move",
  items: ".cmd",
  placeholder: "ui-state-highlight",
  tolerance: "intersect",
  forcePlaceholderSize: true,
});
