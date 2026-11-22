sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageBox",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageBox, MessageToast, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.Approvals", {
    onInit: function () {
      this.getView().setModel(new JSONModel({ busy: false, pending: [] }));
      this.getOwnerComponent().getRouter().getRoute("approvals").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("home");
    },

    _onMatched: function () {
      var s = this.getOwnerComponent().getModel("session");
      if (!s.getProperty("/token")) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      if (!s.getProperty("/isSupervisor")) {
        MessageToast.show("Solo supervisores / admin");
        this.getOwnerComponent().getRouter().navTo("home");
        return;
      }
      this.load();
    },

    onRefresh: function () { this.load(); },

    load: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("audits.php?action=pending_approval")
        .then(function (res) {
          oModel.setProperty("/pending", res.data || []);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onOpen: function (oEvent) {
      var id = oEvent.getSource().getBindingContext().getProperty("id");
      this.getOwnerComponent().getRouter().navTo("auditDetail", { id: String(id) });
    },

    onApprove: function (oEvent) {
      var id = oEvent.getSource().getBindingContext().getProperty("id");
      var that = this;
      MessageBox.confirm("¿Aprobar esta acta y habilitar envío a SAP?", {
        onClose: function (act) {
          if (act !== MessageBox.Action.OK) { return; }
          Api.post("audits.php?action=approve", { id: id, note: "Aprobado en portal" })
            .then(function (res) {
              MessageToast.show("Aprobada · SAP: " + (res.sap_status || ""));
              that.load();
            })
            .catch(function (err) { Api.handleError(err); });
        }
      });
    },

    onReject: function (oEvent) {
      var id = oEvent.getSource().getBindingContext().getProperty("id");
      var that = this;
      var note = window.prompt("Motivo del rechazo:");
      if (note === null || !String(note).trim()) {
        MessageToast.show("Indique un motivo");
        return;
      }
      Api.post("audits.php?action=reject", { id: id, note: String(note).trim() })
        .then(function () {
          MessageToast.show("Acta rechazada");
          that.load();
        })
        .catch(function (err) { Api.handleError(err); });
    }
  });
});
