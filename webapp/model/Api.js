sap.ui.define([
  "sap/m/MessageBox"
], function (MessageBox) {
  "use strict";

  var _oComponent = null;
  var BASE = "api/";

  function getToken() {
    if (!_oComponent) {
      return localStorage.getItem("auditb1_token") || "";
    }
    return _oComponent.getModel("session").getProperty("/token") || "";
  }

  function request(method, path, body, isForm) {
    var headers = {
      "Accept": "application/json"
    };
    var token = getToken();
    if (token) {
      headers["Authorization"] = "Bearer " + token;
      headers["X-Auth-Token"] = token;
    }
    var opts = {
      method: method,
      headers: headers,
      credentials: "same-origin"
    };
    if (body !== undefined && body !== null) {
      if (isForm) {
        opts.body = body;
      } else {
        headers["Content-Type"] = "application/json";
        opts.body = JSON.stringify(body);
      }
    }
    return fetch(BASE + path, opts).then(function (res) {
      var ct = res.headers.get("content-type") || "";
      if (ct.indexOf("application/pdf") !== -1) {
        return res.blob();
      }
      return res.json().then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.error) || ("HTTP " + res.status));
          err.status = res.status;
          err.data = data;
          if (res.status === 401 && _oComponent) {
            _oComponent.clearSession();
            _oComponent.getRouter().navTo("login");
          }
          throw err;
        }
        return data;
      });
    });
  }

  return {
    setComponent: function (oComponent) {
      _oComponent = oComponent;
    },
    get: function (path) {
      return request("GET", path);
    },
    post: function (path, body) {
      return request("POST", path, body);
    },
    put: function (path, body) {
      return request("PUT", path, body);
    },
    patch: function (path, body) {
      return request("PATCH", path, body);
    },
    handleError: function (err, title) {
      var msg = (err && err.message) || "Error inesperado";
      MessageBox.error(msg, { title: title || "Error" });
    }
  };
});
