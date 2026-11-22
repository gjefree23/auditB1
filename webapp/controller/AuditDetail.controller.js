sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/ui/model/Filter",
  "sap/ui/model/FilterOperator",
  "sap/m/MessageBox",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, Filter, FilterOperator, MessageBox, MessageToast, Api) {
  "use strict";

  return Controller.extend("auditb1.controller.AuditDetail", {
    onInit: function () {
      this.getView().setModel(new JSONModel({
        busy: false,
        audit: null,
        photoUrl: "",
        signUrl: "",
        itemPhotoUrl: "",
        itemPhotoTitle: "",
        itemPhotoNotes: "",
        trend: [],
        totalMermaValue: 0
      }));
      this.getOwnerComponent().getRouter().getRoute("auditDetail").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("history");
    },

    _onMatched: function (oEvent) {
      if (!this.getOwnerComponent().getModel("session").getProperty("/token")) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      this._load(oEvent.getParameter("arguments").id);
    },

    _load: function (id) {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      Api.get("audits.php?action=get&id=" + encodeURIComponent(id))
        .then(function (res) {
          var a = res.data;
          var mermaTotal = (a.items || []).reduce(function (acc, it) {
            return acc + (parseFloat(it.merma_value) || 0);
          }, 0);
          oModel.setProperty("/audit", a);
          oModel.setProperty("/photoUrl", a.photo_annotated_path || a.photo_path || "");
          oModel.setProperty("/signUrl", a.signature_path || "");
          oModel.setProperty("/totalMermaValue", Math.round(mermaTotal * 100) / 100);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onFilterItems: function (oEvent) {
      var query = (oEvent.getParameter("newValue") || "").trim();
      var oBinding = this.byId("itemsTable").getBinding("items");
      if (!oBinding) { return; }
      if (!query) {
        oBinding.filter([]);
        return;
      }
      oBinding.filter([
        new Filter({
          filters: [
            new Filter("item_code", FilterOperator.Contains, query),
            new Filter("item_name", FilterOperator.Contains, query),
            new Filter("barcode", FilterOperator.Contains, query),
            new Filter("notes", FilterOperator.Contains, query)
          ],
          and: false
        })
      ]);
    },

    onOpenItemPhoto: function (oEvent) {
      var item = oEvent.getSource().getBindingContext().getObject();
      var oModel = this.getView().getModel();
      oModel.setProperty("/itemPhotoUrl", item.photo_path || "");
      oModel.setProperty("/itemPhotoTitle", (item.item_code || "") + " — " + (item.item_name || ""));
      oModel.setProperty("/itemPhotoNotes", item.notes || "");
      this.byId("itemPhotoDialog").open();
    },

    onCloseItemPhoto: function () {
      this.byId("itemPhotoDialog").close();
    },

    onItemTrend: function (oEvent) {
      var item = oEvent.getSource().getBindingContext().getObject();
      var audit = this.getView().getModel().getProperty("/audit") || {};
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      var that = this;
      var url = "audits.php?action=item_trend&item_code=" + encodeURIComponent(item.item_code) +
        "&warehouse=" + encodeURIComponent(audit.warehouse_code || "");
      Api.get(url)
        .then(function (res) {
          oModel.setProperty("/trend", res.data || []);
          that.byId("trendDialog").setTitle("Tendencia: " + (item.item_name || item.item_code));
          that.byId("trendDialog").open();
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onCloseTrend: function () {
      this.byId("trendDialog").close();
    },

    onDownloadPdf: function () {
      var id = this.getView().getModel().getProperty("/audit/id");
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      window.open("api/generate_pdf.php?id=" + id + "&download=1&token=" + encodeURIComponent(token), "_blank");
    },

    onExportExcel: function () {
      var id = this.getView().getModel().getProperty("/audit/id");
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      window.open("api/export_excel.php?action=detail&id=" + id + "&token=" + encodeURIComponent(token), "_blank");
    },

    onApprove: function () {
      var that = this;
      var id = this.getView().getModel().getProperty("/audit/id");
      MessageBox.confirm("¿Aprobar acta y permitir envío a SAP?", {
        onClose: function (act) {
          if (act !== MessageBox.Action.OK) { return; }
          Api.post("audits.php?action=approve", { id: id, note: "Aprobado" })
            .then(function (res) {
              MessageToast.show("Aprobada · " + (res.sap_status || ""));
              that._load(id);
            })
            .catch(function (err) { Api.handleError(err); });
        }
      });
    },

    onReject: function () {
      var that = this;
      var id = this.getView().getModel().getProperty("/audit/id");
      var note = window.prompt("Motivo del rechazo:");
      if (note === null) { return; }
      if (!String(note).trim()) {
        MessageToast.show("Debe indicar un motivo");
        return;
      }
      Api.post("audits.php?action=reject", { id: id, note: String(note).trim() })
        .then(function () {
          MessageToast.show("Acta rechazada");
          that._load(id);
        })
        .catch(function (err) { Api.handleError(err); });
    },

    onRetrySap: function () {
      var that = this;
      var id = this.getView().getModel().getProperty("/audit/id");
      MessageBox.confirm("¿Reintentar envío a SAP Business One?", {
        onClose: function (action) {
          if (action !== MessageBox.Action.OK) { return; }
          that.getView().getModel().setProperty("/busy", true);
          Api.post("audits.php?action=retry_sap", { id: id })
            .then(function (res) {
              MessageToast.show("Estado: " + (res.sap_status || "OK"));
              that._load(id);
            })
            .catch(function (err) {
              var d = (err && err.data) || {};
              var detail = d.error || err.message || "Fallo al reintentar";
              if (d.AbsoluteEntry) {
                detail += " · Adjunto SAP #" + d.AbsoluteEntry + " guardado para reutilizar";
              }
              if (d.lines_ok && !d.attachment_linked) {
                detail += " · Cantidades OK, falta vincular PDF";
              }
              MessageBox.error(detail, { title: "Reintento SAP" });
              that._load(id);
            })
            .finally(function () {
              that.getView().getModel().setProperty("/busy", false);
            });
        }
      });
    }
  });
});
