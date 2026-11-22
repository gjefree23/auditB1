sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageToast, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.Users", {
    onInit: function () {
      this.getView().setModel(new JSONModel({
        busy: false,
        users: [],
        dialogTitle: "",
        edit: { id: null, username: "", full_name: "", email: "", role: "AUDITOR", password: "", active: 1 }
      }));
      this.getOwnerComponent().getRouter().getRoute("users").attachPatternMatched(this._onMatched, this);
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
      this.loadUsers();
    },

    loadUsers: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("users.php?action=list")
        .then(function (res) {
          oModel.setProperty("/users", res.data || []);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onAdd: function () {
      this.getView().getModel().setProperty("/dialogTitle", "Nuevo usuario");
      this.getView().getModel().setProperty("/edit", {
        id: null, username: "", full_name: "", email: "", role: "AUDITOR", password: "", active: 1
      });
      this.byId("userDialog").open();
    },

    onEdit: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var u = Object.assign({}, ctx.getObject());
      u.password = "";
      this.getView().getModel().setProperty("/dialogTitle", "Editar usuario");
      this.getView().getModel().setProperty("/edit", u);
      this.byId("userDialog").open();
    },

    onCancel: function () {
      this.byId("userDialog").close();
    },

    onActiveCheck: function (oEvent) {
      this.getView().getModel().setProperty("/edit/active", oEvent.getParameter("selected") ? 1 : 0);
    },

    onSave: function () {
      var edit = this.getView().getModel().getProperty("/edit");
      var that = this;
      var req;
      if (!edit.id) {
        req = Api.post("users.php?action=create", edit);
      } else {
        req = Api.post("users.php?action=update", edit);
      }
      req.then(function () {
        MessageToast.show("Guardado");
        that.byId("userDialog").close();
        that.loadUsers();
      }).catch(function (err) { Api.handleError(err); });
    },

    onToggle: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var id = ctx.getProperty("id");
      var active = oEvent.getParameter("state") ? 1 : 0;
      var that = this;
      Api.post("users.php?action=toggle", { id: id, active: active })
        .then(function () {
          MessageToast.show(active ? "Usuario activado" : "Usuario desactivado");
          that.loadUsers();
        })
        .catch(function (err) {
          Api.handleError(err);
          that.loadUsers();
        });
    }
  });
});
