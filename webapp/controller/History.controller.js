sap.ui.define([
  "sap/ui/core/mvc/Controller",
  "sap/ui/model/json/JSONModel",
  "sap/m/MessageToast",
  "auditb1/model/Api"
], function (Controller, JSONModel, MessageToast, Api) {
  "use strict";

  var emptyFilter = function () {
    return { warehouse: "", verdict: "", sap_status: "", from: "", to: "", q: "" };
  };

  return Controller.extend("auditb1.controller.History", {
    _filterTimer: null,

    onInit: function () {
      this.getView().setModel(new JSONModel({
        busy: false,
        audits: [],
        stats: { withDiff: 0 },
        filter: emptyFilter()
      }));
      this.getOwnerComponent().getRouter().getRoute("history").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("home");
    },

    _onMatched: function () {
      if (!this.getOwnerComponent().getModel("session").getProperty("/token")) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      this.loadData();
    },

    onRefresh: function () { this.loadData(); },

    onFilter: function () { this.loadData(); },

    onFilterLive: function () {
      var that = this;
      if (this._filterTimer) {
        clearTimeout(this._filterTimer);
      }
      this._filterTimer = setTimeout(function () {
        that.loadData();
      }, 400);
    },

    onClearFilters: function () {
      this.getView().getModel().setProperty("/filter", emptyFilter());
      this.loadData();
    },

    onExportExcel: function () {
      var f = this.getView().getModel().getProperty("/filter") || {};
      var q = ["action=history"];
      if (f.warehouse) { q.push("warehouse=" + encodeURIComponent(f.warehouse)); }
      if (f.verdict) { q.push("verdict=" + encodeURIComponent(f.verdict)); }
      if (f.from) { q.push("from=" + encodeURIComponent(f.from)); }
      if (f.to) { q.push("to=" + encodeURIComponent(f.to)); }
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      q.push("token=" + encodeURIComponent(token));
      window.open("api/export_excel.php?" + q.join("&"), "_blank");
    },

    loadData: function () {
      var oModel = this.getView().getModel();
      var f = oModel.getProperty("/filter") || {};
      var q = [];
      if (f.q) { q.push("q=" + encodeURIComponent(f.q)); }
      if (f.warehouse) { q.push("warehouse=" + encodeURIComponent(f.warehouse)); }
      if (f.verdict) { q.push("verdict=" + encodeURIComponent(f.verdict)); }
      if (f.sap_status) { q.push("sap_status=" + encodeURIComponent(f.sap_status)); }
      if (f.from) { q.push("from=" + encodeURIComponent(f.from)); }
      if (f.to) { q.push("to=" + encodeURIComponent(f.to)); }
      var qs = q.length ? "&" + q.join("&") : "";
      oModel.setProperty("/busy", true);
      Api.get("audits.php?action=list" + qs)
        .then(function (res) {
          var rows = res.data || [];
          oModel.setProperty("/audits", rows);
          var withDiff = rows.filter(function (r) { return (r.diff_count || 0) > 0; }).length;
          oModel.setProperty("/stats/withDiff", withDiff);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onItemPress: function (oEvent) {
      var oItem = oEvent.getParameter("listItem") || oEvent.getSource();
      var ctx = oItem.getBindingContext();
      if (!ctx) { return; }
      var id = ctx.getProperty("id");
      this.getOwnerComponent().getRouter().navTo("auditDetail", { id: String(id) });
    },

    onDownloadPdf: function (oEvent) {
      oEvent.cancelBubble = true;
      if (oEvent.getParameter) {
        // evitar navegación al detalle
      }
      var ctx = oEvent.getSource().getBindingContext();
      if (!ctx) { return; }
      var id = ctx.getProperty("id");
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      window.open("api/generate_pdf.php?id=" + id + "&download=1&token=" + encodeURIComponent(token), "_blank");
    }
  });
});
