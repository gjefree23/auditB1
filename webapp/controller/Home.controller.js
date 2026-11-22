sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/m/MessageBox",
  "auditb1/model/Api"
], function (Controller, MessageBox, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.Home", {
    onInit: function () {
      var oLogo = this.byId("imgLogo");
      if (oLogo) {
        oLogo.setSrc(sap.ui.require.toUrl("auditb1/img/logo.png"));
      }
      this.getOwnerComponent().getRouter().getRoute("home").attachPatternMatched(this._guard, this);
    },

    _guard: function () {
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      if (!token) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      var that = this;
      Api.get("sap_service.php?action=mode").then(function (m) {
        if (m && m.ok) {
          that.getOwnerComponent().getModel("session").setProperty("/simulationMode", !!m.simulation_mode);
        }
      }).catch(function () {});
    },

    onNavAudit: function () {
      this.getOwnerComponent().getRouter().navTo("audit");
    },
    onNavHistory: function () {
      this.getOwnerComponent().getRouter().navTo("history");
    },
    onNavDashboard: function () {
      this.getOwnerComponent().getRouter().navTo("dashboard");
    },
    onNavApprovals: function () {
      this.getOwnerComponent().getRouter().navTo("approvals");
    },
    onNavChecklist: function () {
      this.getOwnerComponent().getRouter().navTo("checklist");
    },
    onNavUsers: function () {
      this.getOwnerComponent().getRouter().navTo("users");
    },
    onNavSap: function () {
      this.getOwnerComponent().getRouter().navTo("sapconfig");
    },
    onLogout: function () {
      var that = this;
      Api.post("auth.php?action=logout", {}).finally(function () {
        that.getOwnerComponent().clearSession();
        that.getOwnerComponent().getRouter().navTo("login");
      });
    }
  });
});
