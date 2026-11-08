import { _ as __vitePreload } from "./sanitize-CE5uy2g6.js";
const getMiGlobal = (key, defaultValue = null) => {
  return typeof window !== "undefined" && window.monsterinsights && typeof window.monsterinsights === "object" && Object.hasOwn(window.monsterinsights, key) ? window.monsterinsights[key] : defaultValue;
};
const getMonsterInsightsUrl = (type, id, fallbackUrl) => {
  if (typeof window !== "undefined" && window.monsterinsights) {
    if (typeof window.monsterinsights.getUrl === "function") {
      try {
        const url = window.monsterinsights.getUrl(type, id, fallbackUrl);
        if (url) {
          return url;
        }
      } catch (_e) {
      }
    }
    if (window.monsterinsights.utils && typeof window.monsterinsights.utils.getUrl === "function") {
      try {
        const url = window.monsterinsights.utils.getUrl(type, id, fallbackUrl);
        if (url) {
          return url;
        }
      } catch (_e) {
      }
    }
  }
  return fallbackUrl;
};
async function ensureBearerToken(minValidityMs = 5 * 60 * 1e3) {
  if (typeof window === "undefined") {
    return false;
  }
  const bearerToken = getMiGlobal("bearer_token");
  const bearerExpires = getMiGlobal("bearer_expires");
  if (bearerToken && bearerExpires) {
    const expiresAtMs = bearerExpires * 1e3;
    const now = Date.now();
    const remainingMs = expiresAtMs - now;
    if (remainingMs > minValidityMs) {
      return true;
    }
  }
  if (!window.wp || !window.wp.ajax) {
    return false;
  }
  const nonce = getMiGlobal("nonce");
  try {
    const response = await window.wp.ajax.post("monsterinsights_get_bearer_token", {
      nonce
    });
    const body = response?.data && typeof response.data === "object" ? response.data : response;
    const inner = body?.data && typeof body.data === "object" ? body.data : body;
    const token = inner?.token ?? body?.token;
    const expiresAt = inner?.expires_at ?? body?.expires_at;
    if (token && typeof token === "string") {
      if (window.monsterinsights && typeof window.monsterinsights === "object") {
        window.monsterinsights.bearer_token = token;
        window.monsterinsights.bearer_expires = expiresAt ?? 0;
      }
      return true;
    }
  } catch (_e) {
  }
  return false;
}
const isPro = () => {
  return true;
};
const isAddonActive = (addon) => {
  const addons = getMiGlobal("addons", {});
  return !!addons[addon];
};
const isAddonInstalled = (addon) => {
  const info = getMiGlobal("addons_info", {});
  return !!info[addon]?.installed;
};
const getAddonBasename = (addon) => {
  const info = getMiGlobal("addons_info", {});
  return info[addon]?.basename || "";
};
const getAddonsPageUrl = () => {
  return getMiGlobal("addons_page_url", "/wp-admin/admin.php?page=monsterinsights_settings#/addons");
};
const isAuthed = () => {
  return getMiGlobal("authed", false);
};
const REPORTING_REAUTH_ERROR = "reporting_reauthenticate";
const hasReportingCredentials = () => {
  const auth = getMiGlobal("auth", {}) || {};
  const key = getMiGlobal("network", false) ? "network_has_credentials" : "has_credentials";
  return auth[key] === void 0 ? true : !!auth[key];
};
const isReportingConnected = () => {
  if (getMiGlobal("sample_data_enabled", false)) {
    return true;
  }
  const license = getMiGlobal("license", {}) || {};
  const auth = getMiGlobal("auth", {}) || {};
  if (!hasReportingCredentials()) {
    return false;
  }
  if (getMiGlobal("network", false)) {
    return !!(license.license_network?.type && auth.network_v4);
  }
  return !!(license.type && auth.v4);
};
const guardReportingConnection = () => {
  if (!isReportingConnected()) {
    throw new Error(REPORTING_REAUTH_ERROR);
  }
};
const isNetworkAdmin = () => {
  return getMiGlobal("network", false);
};
const sampleDataModules = /* @__PURE__ */ Object.assign({ "../stores/sample-data/custom-dashboard/sample-view.json": () => __vitePreload(() => import("./sample-view-DCgpbF5N.js"), true ? [] : void 0, import.meta.url), "../stores/sample-data/custom-dashboard/widgets-data.json": () => __vitePreload(() => import("./widgets-data-TjimP1Ju.js"), true ? [] : void 0, import.meta.url) });
const getSampleData = async (type) => {
  if (!type) {
    return null;
  }
  const path = `../stores/sample-data/${type}.json`;
  if (sampleDataModules[path]) {
    try {
      const module = await sampleDataModules[path]();
      return module.default?.data || module.default;
    } catch (error) {
      console.error(`Error loading sample data for '${type}':`, error);
      return null;
    }
  }
  return null;
};
function addQueryArg(uri, key, value) {
  let hash = "";
  const re = new RegExp(`([?&])${key}=.*?(&|#|$)`, "i");
  const separator = uri.indexOf("?") !== -1 ? "&" : "?";
  if (uri.match(re)) {
    return uri.replace(re, `$1${key}=${value}$2`);
  } else {
    if (uri.indexOf("#") !== -1) {
      hash = uri.replace(/.*#/, "#");
      uri = uri.replace(/#.*/, "");
    }
    return `${uri + separator + key}=${value}${hash}`;
  }
}
function getUrl(medium, campaign, url) {
  const source = "proplugin", default_url = "my-account/", content = getMiGlobal("plugin_version", "1.0.0");
  medium = medium ? medium : "defaultmedium";
  campaign = campaign ? campaign : "defaultcampaign";
  url = url ? url : `https://www.monsterinsights.com/${default_url}`;
  url = addQueryArg(url, "utm_source", source);
  url = addQueryArg(url, "utm_medium", medium);
  url = addQueryArg(url, "utm_campaign", campaign);
  url = addQueryArg(url, "utm_content", content);
  return url;
}
const MONSTERINSIGHTS_UPGRADE_TIERS = ["basic", "plus", "pro"];
function getUpgradeLicenseKey() {
  const license = getMiGlobal("license", {}) || {};
  const type = String(license.type || "").toLowerCase();
  if (!MONSTERINSIGHTS_UPGRADE_TIERS.includes(type)) {
    return "";
  }
  return license.key || "";
}
function getUpgradeUrl(medium, campaign, url) {
  let target = url;
  let licenseKey = "";
  if (!target && isPro()) {
    licenseKey = getUpgradeLicenseKey();
    if (licenseKey) {
      target = "https://www.monsterinsights.com/pricing/";
    }
  }
  let upgrade_url = getUrl(medium, campaign, target);
  if (licenseKey) {
    upgrade_url = addQueryArg(
      upgrade_url,
      "license_key",
      encodeURIComponent(licenseKey)
    );
  }
  {
    return upgrade_url;
  }
}
export {
  REPORTING_REAUTH_ERROR as R,
  isReportingConnected as a,
  isAuthed as b,
  getAddonBasename as c,
  getMiGlobal as d,
  ensureBearerToken as e,
  isAddonInstalled as f,
  guardReportingConnection as g,
  getAddonsPageUrl as h,
  isAddonActive as i,
  getUpgradeUrl as j,
  getUrl as k,
  isPro as l,
  isNetworkAdmin as m,
  getSampleData as n,
  hasReportingCredentials as o,
  getMonsterInsightsUrl as p
};
