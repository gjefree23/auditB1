sap.ui.define([
  "sap/ui/core/UIComponent",
  "sap/ui/model/json/JSONModel",
  "sap/ui/Device",
  "auditb1/model/Api"
], function (UIComponent, JSONModel, Device, Api) {
  "use strict";

  return UIComponent.extend("auditb1.Component", {
    metadata: {
      manifest: "json"
    },

    init: function () {
      UIComponent.prototype.init.apply(this, arguments);

      var oDeviceModel = new JSONModel(Device);
      oDeviceModel.setDefaultBindingMode("OneWay");
      this.setModel(oDeviceModel, "device");

      var oSession = new JSONModel({
        token: localStorage.getItem("auditb1_token") || "",
        user: JSON.parse(localStorage.getItem("auditb1_user") || "null"),
        isAdmin: false,
        isSupervisor: false,
        simulationMode: true
      });
      if (oSession.getProperty("/user")) {
        var role = oSession.getProperty("/user/role");
        oSession.setProperty("/isAdmin", role === "ADMIN");
        oSession.setProperty("/isSupervisor", role === "ADMIN" || role === "SUPERVISOR");
      }
      this.setModel(oSession, "session");

      Api.setComponent(this);
      this.getRouter().initialize();

      var sToken = oSession.getProperty("/token");
      if (sToken) {
        Api.get("auth.php?action=me").then(function (res) {
          if (res && res.ok) {
            oSession.setProperty("/user", res.user);
            oSession.setProperty("/isAdmin", res.user.role === "ADMIN");
            oSession.setProperty("/isSupervisor", res.user.role === "ADMIN" || res.user.role === "SUPERVISOR");
            localStorage.setItem("auditb1_user", JSON.stringify(res.user));
            Api.get("sap_service.php?action=mode").then(function (m) {
              if (m && m.ok) {
                oSession.setProperty("/simulationMode", !!m.simulation_mode);
              }
            }).catch(function () {});
          }
        }).catch(function () {
          localStorage.removeItem("auditb1_token");
          localStorage.removeItem("auditb1_user");
          oSession.setData({ token: "", user: null, isAdmin: false, simulationMode: true });
        });
      }
    },

    setSession: function (token, user) {
      var oSession = this.getModel("session");
      oSession.setProperty("/token", token);
      oSession.setProperty("/user", user);
      oSession.setProperty("/isAdmin", user && user.role === "ADMIN");
      oSession.setProperty("/isSupervisor", user && (user.role === "ADMIN" || user.role === "SUPERVISOR"));
      localStorage.setItem("auditb1_token", token);
      localStorage.setItem("auditb1_user", JSON.stringify(user));
    },

    clearSession: function () {
      localStorage.removeItem("auditb1_token");
      localStorage.removeItem("auditb1_user");
      this.getModel("session").setData({
        token: "",
        user: null,
        isAdmin: false,
        isSupervisor: false,
        simulationMode: true
      });
    }
  });
});
