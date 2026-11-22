sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageToast",
  "sap/m/MessageBox",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageToast, MessageBox, Api) {
  "use strict";

  function emptyEdit() {
    return {
      id: null,
      name: "",
      warehouse_code: "",
      warehouse_type: "GENERAL",
      active: 1,
      items: [
        { id: 0, sort_order: 1, question: "", required: 1 }
      ]
    };
  }

  return Controller.extend("auditb1.controller.ChecklistAdmin", {
    onInit: function () {
      this.getView().setModel(new JSONModel({
        busy: false,
        templates: [],
        dialogTitle: "",
        edit: emptyEdit()
      }));
      this.getOwnerComponent().getRouter().getRoute("checklist").attachPatternMatched(this._onMatched, this);
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
      this.loadTemplates();
    },

    loadTemplates: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("checklist.php?action=list_templates")
        .then(function (res) {
          oModel.setProperty("/templates", res.data || []);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onAdd: function () {
      this.getView().getModel().setProperty("/dialogTitle", "Nueva plantilla");
      this.getView().getModel().setProperty("/edit", emptyEdit());
      this.byId("tplDialog").open();
    },

    onEdit: function (oEvent) {
      var id = oEvent.getSource().getBindingContext().getProperty("id");
      var oModel = this.getView().getModel();
      var that = this;
      oModel.setProperty("/busy", true);
      Api.get("checklist.php?action=get_template&id=" + encodeURIComponent(id))
        .then(function (res) {
          var tpl = res.data.template || {};
          var items = (res.data.items || []).map(function (it) {
            return {
              id: it.id || 0,
              sort_order: it.sort_order || 1,
              question: it.question || "",
              required: it.required ? 1 : 0
            };
          });
          if (!items.length) {
            items = [{ id: 0, sort_order: 1, question: "", required: 1 }];
          }
          oModel.setProperty("/dialogTitle", "Editar plantilla");
          oModel.setProperty("/edit", {
            id: tpl.id,
            name: tpl.name || "",
            warehouse_code: tpl.warehouse_code || "",
            warehouse_type: tpl.warehouse_type || "GENERAL",
            active: tpl.active ? 1 : 0,
            items: items
          });
          that.byId("tplDialog").open();
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onCancel: function () {
      this.byId("tplDialog").close();
    },

    onActiveCheck: function (oEvent) {
      this.getView().getModel().setProperty("/edit/active", oEvent.getParameter("selected") ? 1 : 0);
    },

    onAddQuestion: function () {
      var items = this.getView().getModel().getProperty("/edit/items") || [];
      items = items.slice();
      items.push({
        id: 0,
        sort_order: items.length + 1,
        question: "",
        required: 1
      });
      this.getView().getModel().setProperty("/edit/items", items);
    },

    onRemoveQuestion: function (oEvent) {
      var path = oEvent.getSource().getBindingContext().getPath();
      var idx = parseInt(path.split("/").pop(), 10);
      var items = (this.getView().getModel().getProperty("/edit/items") || []).slice();
      if (items.length <= 1) {
        MessageToast.show("Debe quedar al menos una pregunta");
        return;
      }
      items.splice(idx, 1);
      items.forEach(function (it, i) {
        if (!it.sort_order || it.sort_order === i + 2 || it.sort_order === i) {
          it.sort_order = i + 1;
        }
      });
      this.getView().getModel().setProperty("/edit/items", items);
    },

    onRequiredCheck: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      ctx.getModel().setProperty(ctx.getPath() + "/required", oEvent.getParameter("selected") ? 1 : 0);
    },

    onSave: function () {
      var edit = this.getView().getModel().getProperty("/edit");
      var name = (edit.name || "").trim();
      if (!name) {
        MessageToast.show("Ingrese el nombre de la plantilla");
        return;
      }
      var items = (edit.items || []).filter(function (it) {
        return (it.question || "").trim() !== "";
      });
      if (!items.length) {
        MessageToast.show("Agregue al menos una pregunta");
        return;
      }
      var payload = {
        id: edit.id || 0,
        name: name,
        warehouse_code: (edit.warehouse_code || "").trim(),
        warehouse_type: edit.warehouse_type || "GENERAL",
        active: edit.active ? 1 : 0,
        items: items.map(function (it, i) {
          return {
            id: it.id || 0,
            sort_order: parseInt(it.sort_order, 10) || (i + 1),
            question: (it.question || "").trim(),
            required: it.required ? 1 : 0
          };
        })
      };
      var that = this;
      Api.post("checklist.php?action=save_template", payload)
        .then(function () {
          MessageToast.show("Plantilla guardada");
          that.byId("tplDialog").close();
          that.loadTemplates();
        })
        .catch(function (err) { Api.handleError(err); });
    },

    onToggle: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var id = ctx.getProperty("id");
      var active = oEvent.getParameter("state") ? 1 : 0;
      var that = this;
      Api.post("checklist.php?action=toggle_template", { id: id, active: active })
        .then(function () {
          MessageToast.show(active ? "Plantilla activada" : "Plantilla desactivada");
          that.loadTemplates();
        })
        .catch(function (err) {
          Api.handleError(err);
          that.loadTemplates();
        });
    },

    onDelete: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var id = ctx.getProperty("id");
      var name = ctx.getProperty("name");
      var that = this;
      MessageBox.confirm("¿Eliminar la plantilla \"" + name + "\" y sus preguntas?", {
        title: "Eliminar plantilla",
        onClose: function (action) {
          if (action !== MessageBox.Action.OK) {
            return;
          }
          Api.post("checklist.php?action=delete_template", { id: id })
            .then(function () {
              MessageToast.show("Plantilla eliminada");
              that.loadTemplates();
            })
            .catch(function (err) { Api.handleError(err); });
        }
      });
    }
  });
});
