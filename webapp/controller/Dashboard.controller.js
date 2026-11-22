sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "auditb1/model/Api"
], function (Controller, JSONModel, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.Dashboard", {
    onInit: function () {
      this.getView().setModel(new JSONModel({ busy: false, data: {} }));
      this.getOwnerComponent().getRouter().getRoute("dashboard").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("home");
    },

    _onMatched: function () {
      if (!this.getOwnerComponent().getModel("session").getProperty("/token")) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      this.load();
    },

    onRefresh: function () { this.load(); },

    load: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("dashboard.php?action=summary")
        .then(function (res) {
          oModel.setProperty("/data", res.data || {});
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onExportHistory: function () {
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      window.open("api/export_excel.php?action=history&token=" + encodeURIComponent(token), "_blank");
    },

    onGoApprovals: function () {
      this.getOwnerComponent().getRouter().navTo("approvals");
    },

    onGoHistoryPendingSap: function () {
      this.getOwnerComponent().getRouter().navTo("history");
    },

    onOpenRecent: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var id = ctx.getProperty("id");
      this.getOwnerComponent().getRouter().navTo("auditDetail", { id: String(id) });
    }
  });
});
