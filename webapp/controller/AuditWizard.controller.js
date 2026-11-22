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

  function emptyItem(lineNum) {
    return {
      line_num: lineNum || 0,
      item_code: "",
      item_name: "",
      barcode: "",
      system_qty: 0,
      counted_qty: 0,
      difference_qty: 0,
      unit_cost: 0,
      merma_qty: 0,
      merma_value: 0,
      status: "OK",
      notes: "",
      photo_data: null
    };
  }

  /** Diferencia + merma (faltante valorizado) */
  function applyDiffAndMerma(item) {
    var sys = parseFloat(item.system_qty) || 0;
    var cnt = parseFloat(item.counted_qty) || 0;
    var cost = parseFloat(item.unit_cost) || 0;
    var dif = Math.round((cnt - sys) * 10000) / 10000;
    var mermaQty = dif < 0 ? Math.abs(dif) : 0;
    item.difference_qty = dif;
    item.merma_qty = Math.round(mermaQty * 10000) / 10000;
    item.merma_value = Math.round(mermaQty * cost * 10000) / 10000;
    item.status = Math.abs(dif) < 0.0001 ? "OK" : "DIFERENCIA";
    return item;
  }

  function sumMermaValue(items) {
    return (items || []).reduce(function (acc, it) {
      return acc + (parseFloat(it.merma_value) || 0);
    }, 0);
  }

  function sumMermaQty(items) {
    return (items || []).reduce(function (acc, it) {
      return acc + (parseFloat(it.merma_qty) || 0);
    }, 0);
  }

  function refreshMermaTotals(oModel) {
    var items = oModel.getProperty("/items") || [];
    oModel.setProperty("/totalMermaQty", Math.round(sumMermaQty(items) * 10000) / 10000);
    oModel.setProperty("/totalMermaValue", Math.round(sumMermaValue(items) * 100) / 100);
  }

  function emptyModel() {
    return {
      busy: false,
      warehouses: [],
      countings: [],
      selectedWarehouse: "",
      selectedWarehouseName: "",
      selectedDocEntry: null,
      counting_doc_num: 0,
      counting_date: "",
      items: [],
      observation_note: "",
      verdict: "APROBADO",
      step1Valid: false,
      step2Valid: false,
      stepChecklistValid: true,
      step4Valid: false,
      canSubmit: false,
      hasPhoto: false,
      hasSignature: false,
      evidenceEdit: null,
      evidencePath: null,
      checklist: [],
      checklistTemplate: null,
      totalMermaQty: 0,
      totalMermaValue: 0
    };
  }

  return Controller.extend("auditb1.controller.AuditWizard", {
    _photoCanvas: null,
    _photoCtx: null,
    _signCanvas: null,
    _signCtx: null,
    _drawing: false,
    _photoImg: null,

    onInit: function () {
      this.getView().setModel(new JSONModel(emptyModel()));
      this.getOwnerComponent().getRouter().getRoute("audit").attachPatternMatched(this._onMatched, this);
    },

    onNavBack: function () {
      this.getOwnerComponent().getRouter().navTo("home");
    },

    _onMatched: function () {
      var token = this.getOwnerComponent().getModel("session").getProperty("/token");
      if (!token) {
        this.getOwnerComponent().getRouter().navTo("login", {}, true);
        return;
      }
      this.getView().getModel().setData(emptyModel());
      this._initCanvases();
      this._loadWarehouses();
    },

    _initCanvases: function () {
      var that = this;
      setTimeout(function () {
        var canvasHtml =
          '<div class="auditCanvasWrap">' +
          '<canvas id="auditPhotoCanvas" width="720" height="480"></canvas></div>';
        that.byId("canvasHost").setContent(canvasHtml);

        var signHtml =
          '<div class="auditSignWrap">' +
          '<canvas id="auditSignCanvas" width="480" height="160"></canvas></div>';
        that.byId("signHost").setContent(signHtml);

        setTimeout(function () {
          that._bindPhotoCanvas();
          that._bindSignCanvas();
          that._bindFileInput();
        }, 50);
      }, 100);
    },

    _bindFileInput: function () {
      var input = document.getElementById(this.createId("filePhoto"));
      // OpenUI5 HTML IDs: use query within view DOM
      var dom = this.getView().getDomRef();
      if (!dom) { return; }
      input = dom.querySelector('input[type="file"]');
      if (!input || input._bound) { return; }
      input._bound = true;
      var that = this;
      input.addEventListener("change", function (ev) {
        var file = ev.target.files && ev.target.files[0];
        if (!file) { return; }
        var reader = new FileReader();
        reader.onload = function (e) {
          that._loadPhoto(e.target.result);
        };
        reader.readAsDataURL(file);
      });
    },

    _bindPhotoCanvas: function () {
      var c = document.getElementById("auditPhotoCanvas");
      if (!c) { return; }
      this._photoCanvas = c;
      this._photoCtx = c.getContext("2d");
      this._photoCtx.fillStyle = "#1e293b";
      this._photoCtx.fillRect(0, 0, c.width, c.height);
      this._photoCtx.fillStyle = "#94a3b8";
      this._photoCtx.font = "16px sans-serif";
      this._photoCtx.fillText("Suba o tome una foto para anotar", 180, 240);
      this._attachDraw(c, this._photoCtx, true);
    },

    _bindSignCanvas: function () {
      var c = document.getElementById("auditSignCanvas");
      if (!c) { return; }
      this._signCanvas = c;
      this._signCtx = c.getContext("2d");
      this._signCtx.fillStyle = "#ffffff";
      this._signCtx.fillRect(0, 0, c.width, c.height);
      this._attachDraw(c, this._signCtx, false);
    },

    _attachDraw: function (canvas, ctx, isPhoto) {
      var that = this;
      var drawing = false;

      function pos(ev) {
        var rect = canvas.getBoundingClientRect();
        var src = ev.touches && ev.touches[0] ? ev.touches[0] : ev;
        return {
          x: (src.clientX - rect.left) * (canvas.width / rect.width),
          y: (src.clientY - rect.top) * (canvas.height / rect.height)
        };
      }

      function start(ev) {
        ev.preventDefault();
        drawing = true;
        var p = pos(ev);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        ctx.strokeStyle = isPhoto ? "#e53935" : "#111827";
        ctx.lineWidth = isPhoto ? 3 : 2.5;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
      }
      function move(ev) {
        if (!drawing) { return; }
        ev.preventDefault();
        var p = pos(ev);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
      }
      function end(ev) {
        if (!drawing) { return; }
        drawing = false;
        if (!isPhoto) {
          that.getView().getModel().setProperty("/hasSignature", true);
          that._refreshValid();
        }
      }

      canvas.onmousedown = start;
      canvas.onmousemove = move;
      canvas.onmouseup = end;
      canvas.onmouseleave = end;
      canvas.ontouchstart = start;
      canvas.ontouchmove = move;
      canvas.ontouchend = end;
    },

    _loadWarehouses: function () {
      var oModel = this.getView().getModel();
      oModel.setProperty("/busy", true);
      var that = this;
      Api.get("sap_service.php?action=warehouses")
        .then(function (res) {
          oModel.setProperty("/warehouses", res.data || []);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onWarehouseChange: function (oEvent) {
      var key = oEvent.getSource().getSelectedKey();
      var oModel = this.getView().getModel();
      var whs = (oModel.getProperty("/warehouses") || []).find(function (w) {
        return w.WarehouseCode === key;
      });
      oModel.setProperty("/selectedWarehouse", key);
      oModel.setProperty("/selectedWarehouseName", whs ? whs.WarehouseName : "");
      oModel.setProperty("/countings", []);
      oModel.setProperty("/items", []);
      oModel.setProperty("/selectedDocEntry", 0);
      oModel.setProperty("/counting_doc_num", 0);
      oModel.setProperty("/counting_date", "");
      // Solo el almacén es obligatorio
      oModel.setProperty("/step1Valid", !!key);
      oModel.setProperty("/step2Valid", false);
      this._refreshValid();

      if (!key) { return; }

      oModel.setProperty("/busy", true);
      var that = this;
      Api.get("sap_service.php?action=countings&warehouse=" + encodeURIComponent(key))
        .then(function (res) {
          oModel.setProperty("/countings", res.data || []);
          var sel = that.byId("selCounting");
          if (sel) {
            sel.setSelectedKey("0");
          }
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });

      // Cargar checklist de la bodega
      Api.get("checklist.php?action=for_warehouse&warehouse=" + encodeURIComponent(key))
        .then(function (res) {
          var d = res.data || {};
          oModel.setProperty("/checklistTemplate", d.template || null);
          oModel.setProperty("/checklist", d.items || []);
          that._validateChecklist();
        })
        .catch(function () {
          oModel.setProperty("/checklist", []);
          oModel.setProperty("/stepChecklistValid", true);
        });
    },

    onChecklistChange: function () {
      this._validateChecklist();
    },

    _validateChecklist: function () {
      var items = this.getView().getModel().getProperty("/checklist") || [];
      var ok = true;
      items.forEach(function (it) {
        if (it.required && (!it.answer || it.answer === "NA")) {
          ok = false;
        }
      });
      // Si no hay checklist, no bloquea
      if (!items.length) { ok = true; }
      this.getView().getModel().setProperty("/stepChecklistValid", ok);
      this._refreshValid();
    },

    onCountingChange: function (oEvent) {
      var rawKey = oEvent.getSource().getSelectedKey();
      var docEntry = parseInt(rawKey, 10);
      if (isNaN(docEntry)) { docEntry = 0; }
      var oModel = this.getView().getModel();
      oModel.setProperty("/selectedDocEntry", docEntry);
      oModel.setProperty("/step1Valid", !!oModel.getProperty("/selectedWarehouse"));

      // Sin documento: conteo libre de bodega
      if (docEntry <= 0) {
        oModel.setProperty("/counting_doc_num", 0);
        oModel.setProperty("/counting_date", "");
        oModel.setProperty("/items", []);
        oModel.setProperty("/step2Valid", false);
        this._refreshValid();
        MessageToast.show("Conteo libre: use «Cargar stock bodega» o agregue ítems");
        return;
      }

      oModel.setProperty("/busy", true);
      var that = this;
      Api.get("sap_service.php?action=counting&docEntry=" + docEntry)
        .then(function (res) {
          var doc = res.data || {};
          oModel.setProperty("/counting_doc_num", doc.DocumentNumber || 0);
          oModel.setProperty("/counting_date", doc.CountDate || "");
          var lines = doc.InventoryCountingLines || [];
          var items = lines.map(function (l) {
            // Priorizar cantidad del conteo (InWhsQty), NO stock total de la empresa
            var sys = parseFloat(
              l.InWhsQty != null ? l.InWhsQty
                : (l.InWarehouseQuantity != null ? l.InWarehouseQuantity
                  : (l.SystemQuantity != null ? l.SystemQuantity : 0))
            );
            var cnt = parseFloat(
              l.CountedQuantity != null ? l.CountedQuantity
                : (l.CountQuantity != null ? l.CountQuantity
                  : (l.CountedQty != null ? l.CountedQty : 0))
            );
            return applyDiffAndMerma({
              line_num: l.LineNumber != null ? l.LineNumber : (l.LineNum || 0),
              item_code: l.ItemCode || "",
              item_name: l.ItemDescription || l.ItemName || "",
              barcode: l.BarCode || l.Barcode || "",
              system_qty: sys,
              counted_qty: cnt,
              unit_cost: parseFloat(l.AvgStdPrice || l.AvgPrice || l.Price || 0) || 0,
              notes: "",
              photo_data: null
            });
          });
          oModel.setProperty("/items", items);
          refreshMermaTotals(oModel);
          oModel.setProperty("/step2Valid", items.length > 0);
          that._refreshValid();
          // Solo completar costos; conservar InWhsQty del documento de conteo
          that._refreshSystemStock(true, true);
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () {
          oModel.setProperty("/busy", false);
        });
    },

    onLoadStock: function () {
      var oModel = this.getView().getModel();
      var whs = oModel.getProperty("/selectedWarehouse");
      if (!whs) {
        MessageToast.show("Seleccione un almacén primero");
        return;
      }
      oModel.setProperty("/busy", true);
      var that = this;
      Api.get("sap_service.php?action=warehouse_stock&warehouse=" + encodeURIComponent(whs))
        .then(function (res) {
          var items = (res.data || []).map(function (it, i) {
            return applyDiffAndMerma(Object.assign(emptyItem(i), it, {
              barcode: it.barcode || it.BarCode || "",
              unit_cost: parseFloat(it.unit_cost) || 0,
              notes: it.notes || "",
              photo_data: null
            }));
          });
          oModel.setProperty("/items", items);
          refreshMermaTotals(oModel);
          oModel.setProperty("/step2Valid", items.length > 0);
          that._refreshValid();
          MessageToast.show(items.length ? (items.length + " ítems cargados") : "Sin stock para mostrar");
        })
        .catch(function (err) { Api.handleError(err); })
        .finally(function () { oModel.setProperty("/busy", false); });
    },

    onAddItem: function () {
      var oModel = this.getView().getModel();
      var items = (oModel.getProperty("/items") || []).slice();
      items.push(emptyItem(items.length));
      oModel.setProperty("/items", items);
      oModel.setProperty("/step2Valid", true);
      this._refreshValid();
    },

    onFilterItems: function (oEvent) {
      var query = (oEvent.getParameter("newValue") || oEvent.getParameter("query") || "").trim();
      var oTable = this.byId("itemsTable");
      var oBinding = oTable && oTable.getBinding("items");
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
            new Filter("barcode", FilterOperator.Contains, query)
          ],
          and: false
        })
      ]);
    },

    onScanBarcode: function (oEvent) {
      var code = "";
      if (oEvent.getParameter) {
        code = (oEvent.getParameter("query") || oEvent.getParameter("value") || "").trim();
      }
      if (!code) {
        var src = oEvent.getSource && oEvent.getSource();
        code = (src && src.getValue && src.getValue() || "").trim();
      }
      if (!code) { return; }

      var oModel = this.getView().getModel();
      var items = oModel.getProperty("/items") || [];
      var idx = -1;
      var upper = code.toUpperCase();
      for (var i = 0; i < items.length; i++) {
        var it = items[i];
        if ((it.barcode && String(it.barcode).toUpperCase() === upper) ||
            (it.item_code && String(it.item_code).toUpperCase() === upper)) {
          idx = i;
          break;
        }
      }

      if (idx >= 0) {
        var updated = applyDiffAndMerma(Object.assign({}, items[idx], {
          counted_qty: (parseFloat(items[idx].counted_qty) || 0) + 1
        }));
        oModel.setProperty("/items/" + idx, updated);
        refreshMermaTotals(oModel);
        MessageToast.show("Escaneado: " + (updated.item_code || code) + " → " + updated.counted_qty);
      } else {
        // Buscar en SAP por barcode/código y agregar
        var that = this;
        oModel.setProperty("/busy", true);
        var whs = oModel.getProperty("/selectedWarehouse") || "";
        Api.get("sap_service.php?action=item_by_code&code=" + encodeURIComponent(code)
            + (whs ? ("&warehouse=" + encodeURIComponent(whs)) : ""))
          .then(function (res) {
            var found = res.data;
            var list = (oModel.getProperty("/items") || []).slice();
            if (found) {
              var cost = parseFloat(found.unit_cost || found.AvgStdPrice || found.MovingAveragePrice || found.AvgPrice || 0) || 0;
              if (cost <= 0) {
                cost = parseFloat(found.LastPurchasePrice || 0) || 0;
              }
              list.push(applyDiffAndMerma(Object.assign(emptyItem(list.length), {
                item_code: found.ItemCode || code,
                item_name: found.ItemName || "",
                barcode: found.BarCode || code,
                system_qty: parseFloat(found.system_qty != null ? found.system_qty : (found.QuantityOnStock || 0)),
                counted_qty: 1,
                unit_cost: cost
              })));
              MessageToast.show("Ítem agregado desde SAP: " + (found.ItemCode || code));
            } else {
              list.push(applyDiffAndMerma(Object.assign(emptyItem(list.length), {
                item_code: code,
                barcode: code,
                counted_qty: 1
              })));
              MessageToast.show("Código nuevo agregado: " + code);
            }
            oModel.setProperty("/items", list);
            refreshMermaTotals(oModel);
            oModel.setProperty("/step2Valid", true);
            that._refreshValid();
          })
          .catch(function () {
            var list = (oModel.getProperty("/items") || []).slice();
            list.push(applyDiffAndMerma(Object.assign(emptyItem(list.length), {
              item_code: code,
              barcode: code,
              counted_qty: 1
            })));
            oModel.setProperty("/items", list);
            refreshMermaTotals(oModel);
            oModel.setProperty("/step2Valid", true);
            that._refreshValid();
            MessageToast.show("Código nuevo agregado: " + code);
          })
          .finally(function () {
            oModel.setProperty("/busy", false);
          });
      }

      var search = this.byId("itemSearch");
      var scan = this.byId("barcodeInput");
      if (search) { search.setValue(""); }
      if (scan) { scan.setValue(""); this.onFilterItems({ getParameter: function () { return ""; } }); }
    },

    onItemEvidence: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var path = ctx.getPath();
      var item = Object.assign({}, ctx.getObject());
      this.getView().getModel().setProperty("/evidencePath", path);
      this.getView().getModel().setProperty("/evidenceEdit", {
        item_code: item.item_code || "",
        item_name: item.item_name || "",
        notes: item.notes || "",
        photo_data: item.photo_data || null
      });
      this.byId("itemEvidenceDialog").open();
      var that = this;
      setTimeout(function () { that._bindItemFileInput(); }, 100);
    },

    _bindItemFileInput: function () {
      var dom = this.getView().getDomRef();
      if (!dom) { return; }
      var inputs = dom.querySelectorAll('input[type="file"]');
      var input = inputs.length > 1 ? inputs[1] : null;
      if (!input || input._itemBound) { return; }
      input._itemBound = true;
      var that = this;
      input.addEventListener("change", function (ev) {
        var file = ev.target.files && ev.target.files[0];
        if (!file) { return; }
        var reader = new FileReader();
        reader.onload = function (e) {
          that.getView().getModel().setProperty("/evidenceEdit/photo_data", e.target.result);
        };
        reader.readAsDataURL(file);
        ev.target.value = "";
      });
    },

    onPickItemPhoto: function () {
      var dom = this.getView().getDomRef();
      if (!dom) { return; }
      var inputs = dom.querySelectorAll('input[type="file"]');
      if (inputs.length > 1) { inputs[1].click(); }
    },

    onClearItemPhoto: function () {
      this.getView().getModel().setProperty("/evidenceEdit/photo_data", null);
    },

    onSaveItemEvidence: function () {
      var oModel = this.getView().getModel();
      var path = oModel.getProperty("/evidencePath");
      var edit = oModel.getProperty("/evidenceEdit") || {};
      if (path) {
        oModel.setProperty(path + "/notes", edit.notes || "");
        oModel.setProperty(path + "/photo_data", edit.photo_data || null);
      }
      this.byId("itemEvidenceDialog").close();
      MessageToast.show("Evidencia del ítem guardada");
    },

    onCancelItemEvidence: function () {
      this.byId("itemEvidenceDialog").close();
    },

    onQtyChange: function (oEvent) {
      var ctx = oEvent.getSource().getBindingContext();
      var path = ctx.getPath();
      var oModel = this.getView().getModel();
      var item = applyDiffAndMerma(Object.assign({}, oModel.getProperty(path)));
      oModel.setProperty(path, item);
      refreshMermaTotals(oModel);
    },

    onStatusChange: function () {
      this._refreshValid();
    },

    /**
     * Actualiza stock/costo desde SAP (por almacén).
     * @param {boolean} silent
     * @param {boolean} [costsOnly] si true, no pisa system_qty (p.ej. InWhsQty del conteo)
     */
    _refreshSystemStock: function (silent, costsOnly) {
      var oModel = this.getView().getModel();
      var items = (oModel.getProperty("/items") || []).slice();
      var codes = [];
      var seen = {};
      items.forEach(function (it) {
        var c = (it.item_code || "").trim();
        if (c && !seen[c]) {
          seen[c] = true;
          codes.push(c);
        }
      });

      if (!codes.length) {
        this._recalcDiffOnly();
        if (!silent) { MessageToast.show("Sin códigos para actualizar stock"); }
        return Promise.resolve();
      }

      oModel.setProperty("/busy", true);
      var that = this;
      return Api.post("sap_service.php?action=refresh_stock", {
        warehouse: oModel.getProperty("/selectedWarehouse") || "",
        item_codes: codes
      }).then(function (res) {
        var map = res.data || {};
        var updated = 0;
        items = oModel.getProperty("/items") || [];
        items.forEach(function (item, i) {
          var code = (item.item_code || "").trim();
          var info = code ? map[code] : null;
          if (info != null && typeof info === "object") {
            if (!costsOnly) {
              var sys = parseFloat(info.system_qty);
              if (!isNaN(sys)) {
                item.system_qty = sys;
                updated++;
              }
            }
            if (info.item_name) {
              item.item_name = info.item_name;
            }
            if (info.barcode && !item.barcode) {
              item.barcode = info.barcode;
            }
            if (info.unit_cost != null && !isNaN(parseFloat(info.unit_cost))) {
              item.unit_cost = parseFloat(info.unit_cost);
              if (costsOnly) { updated++; }
            }
          } else if (!costsOnly && typeof info === "number") {
            item.system_qty = info;
            updated++;
          }
          applyDiffAndMerma(item);
          oModel.setProperty("/items/" + i, item);
        });
        oModel.setProperty("/items", items.slice());
        refreshMermaTotals(oModel);
        if (!silent) {
          MessageToast.show(updated
            ? ((costsOnly ? "Costo actualizado: " : "Stock/costo actualizado: ") + updated + " ítems")
            : "SAP no devolvió stock para esos códigos");
        }
      }).catch(function (err) {
        that._recalcDiffOnly();
        if (!silent) { Api.handleError(err, "Actualizar stock"); }
      }).finally(function () {
        oModel.setProperty("/busy", false);
      });
    },

    _recalcDiffOnly: function () {
      var oModel = this.getView().getModel();
      var items = oModel.getProperty("/items") || [];
      items.forEach(function (item, i) {
        applyDiffAndMerma(item);
        oModel.setProperty("/items/" + i, item);
      });
      refreshMermaTotals(oModel);
    },

    onRecalcStatus: function () {
      this._refreshSystemStock(false);
    },

    onPickPhoto: function () {
      var dom = this.getView().getDomRef();
      var input = dom && dom.querySelector('input[type="file"]');
      // Primer input = foto general del paso 3
      if (input) { input.click(); }
    },

    _loadPhoto: function (dataUrl) {
      var that = this;
      var img = new Image();
      img.onload = function () {
        that._photoImg = img;
        var c = that._photoCanvas;
        var ctx = that._photoCtx;
        if (!c) { return; }
        ctx.fillStyle = "#000";
        ctx.fillRect(0, 0, c.width, c.height);
        var scale = Math.min(c.width / img.width, c.height / img.height);
        var w = img.width * scale;
        var h = img.height * scale;
        var x = (c.width - w) / 2;
        var y = (c.height - h) / 2;
        ctx.drawImage(img, x, y, w, h);
        that.getView().getModel().setProperty("/hasPhoto", true);
      };
      img.src = dataUrl;
    },

    onClearAnnot: function () {
      if (this._photoImg) {
        this._loadPhoto(this._photoImg.src);
      } else if (this._photoCanvas && this._photoCtx) {
        this._bindPhotoCanvas();
      }
    },

    onClearSign: function () {
      if (!this._signCanvas || !this._signCtx) { return; }
      this._signCtx.fillStyle = "#ffffff";
      this._signCtx.fillRect(0, 0, this._signCanvas.width, this._signCanvas.height);
      this.getView().getModel().setProperty("/hasSignature", false);
      this._refreshValid();
    },

    onVerdictChange: function () {
      this._refreshValid();
    },

    _refreshValid: function () {
      var m = this.getView().getModel();
      var step4 = !!m.getProperty("/verdict") && !!m.getProperty("/hasSignature");
      m.setProperty("/step4Valid", step4);
      m.setProperty("/canSubmit",
        !!m.getProperty("/step1Valid") &&
        !!m.getProperty("/step2Valid") &&
        !!m.getProperty("/stepChecklistValid") &&
        step4
      );
    },

    onWizardComplete: function () {
      this.onSubmit();
    },

    onSubmit: function () {
      var oModel = this.getView().getModel();
      this._refreshValid();
      this._validateChecklist();
      if (!oModel.getProperty("/canSubmit")) {
        MessageBox.warning("Complete almacén, ítems, checklist obligatorio, dictamen y firma.");
        return;
      }

      var photoData = null;
      var photoAnn = null;
      if (this._photoCanvas && oModel.getProperty("/hasPhoto")) {
        photoAnn = this._photoCanvas.toDataURL("image/png");
        photoData = photoAnn;
      }
      var signature = this._signCanvas ? this._signCanvas.toDataURL("image/png") : "";
      var docEntry = parseInt(oModel.getProperty("/selectedDocEntry"), 10) || 0;

      var payload = {
        warehouse_code: oModel.getProperty("/selectedWarehouse"),
        warehouse_name: oModel.getProperty("/selectedWarehouseName"),
        counting_doc_entry: docEntry,
        counting_doc_num: oModel.getProperty("/counting_doc_num") || 0,
        counting_date: oModel.getProperty("/counting_date") || "",
        verdict: oModel.getProperty("/verdict"),
        observation_note: oModel.getProperty("/observation_note"),
        items: oModel.getProperty("/items"),
        checklist: oModel.getProperty("/checklist") || [],
        photo_data: photoData,
        photo_annotated_data: photoAnn,
        signature_data: signature
      };

      oModel.setProperty("/busy", true);
      var that = this;
      Api.post("audits.php?action=submit", payload)
        .then(function (res) {
          MessageBox.success(
            "Auditoría " + res.audit_code + " guardada.\n" +
            "Aprobación: " + (res.approval_status || "") + "\nEstado SAP: " + res.sap_status,
            {
              title: "Éxito",
              onClose: function () {
                that.getOwnerComponent().getRouter().navTo("auditDetail", { id: String(res.id) });
              }
            }
          );
        })
        .catch(function (err) { Api.handleError(err, "Envío"); })
        .finally(function () { oModel.setProperty("/busy", false); });
    }
  });
});
