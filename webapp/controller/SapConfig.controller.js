sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageBox",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageBox, MessageToast, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.SapConfig", {
    onInit: function () {
      this.getView().setModel(new JSONModel({
        busy: false,
        testResult: "",
        config: {
          sl_url: "",
          company_db: "",
          sl_user: "",
          sl_password: "",
          has_password: false,
          simulation_mode: 1
        }
      }));
      this.getOwnerComponent().getRouter().getRoute("sapconfig").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("home");
    },

    _onMatched: function () {
      var session = this.getOwnerComponent().getModel("session");
      if (!session.getProperty("/token")) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      if (!session.getProperty("/isAdmin")) {
        MessageToast.show("Solo administradores");
        this.getOwnerComponent().getRouter().navTo("home");
        return;
      }
      this.loadConfig();
    },

    loadConfig: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("sap_config.php?action=get")
        .then(function (res) {
          var d = res.data || {};
          d.sl_password = "";
          oModel.setProperty("/config", d);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onSimChange: function (oEvent) {
      this.getView().getModel().setProperty(
        "/config/simulation_mode",
        oEvent.getParameter("state") ? 1 : 0
      );
    },

    onSave: function () {
      var cfg = this.getView().getModel().getProperty("/config");
      var that = this;
      this.getView().getModel().setProperty("/busy", true);
      Api.post("sap_config.php?action=save", cfg)
        .then(function () {
          MessageToast.show("Configuración guardada");
          that.getOwnerComponent().getModel("session").setProperty(
            "/simulationMode",
            !!cfg.simulation_mode
          );
          that.loadConfig();
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () {
          that.getView().getModel().setProperty("/busy", false);
        });
    },

    onTest: function () {
      var cfg = this.getView().getModel().getProperty("/config");
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      oModel.setProperty("/testResult", "Probando…");
      Api.post("sap_config.php?action=test", cfg)
        .then(function (res) {
          oModel.setProperty("/testResult", "✓ " + (res.message || "Conexión OK"));
          MessageBox.success(res.message || "Conexión exitosa");
        })
        .catch(function (err) {
          var msg = (err && err.message) || "Fallo de conexión";
          oModel.setProperty("/testResult", "✗ " + msg);
          Api.handleError(err, "Probar conexión");
        })
        .finally(function () {
          oModel.setProperty("/busy", false);
        });
    }
  });
});
