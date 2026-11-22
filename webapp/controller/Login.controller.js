sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageToast, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.Login", {
    onInit: function () {
      this.getView().setModel(new JSONModel({
        username: "",
        password: "",
        busy: false
      }));
      var oLogo = this.byId("imgLogo");
      if (oLogo) {
        oLogo.setSrc(sap.ui.require.toUrl("auditb1/img/logo.png"));
      }
      this.getOwnerComponent().getRouter().getRoute("login").attachPatternMatched(this._onMatched, this);
    },

    _onMatched: function () {
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      if (token) {
        this.getOwnerComponent().getRouter().navTo("home", {}, true);
      }
    },

    onLogin: function () {
      var oModel = this.getView().getModel();
      var user = (oModel.getProperty("/username") || "").trim();
      var pass = oModel.getProperty("/password") || "";
      if (!user || !pass) {
        MessageToast.show("Ingrese usuario y contraseña");
        return;
      }
      oModel.setProperty("/busy", true);
      var that = this;
      Api.post("auth.php?action=login", { username: user, password: pass })
        .then(function (res) {
          that.getOwnerComponent().setSession(res.token, res.user);
          return Api.get("sap_service.php?action=mode");
        })
        .then(function (m) {
          if (m && m.ok) {
            that.getOwnerComponent().getModel("session").setProperty("/simulationMode", !!m.simulation_mode);
          }
          MessageToast.show("Bienvenido");
          that.getOwnerComponent().getRouter().navTo("home");
        })
        .catch(function (err) {
          Api.handleError(err, "Login");
        })
        .finally(function () {
          oModel.setProperty("/busy", false);
        });
    }
  });
});
