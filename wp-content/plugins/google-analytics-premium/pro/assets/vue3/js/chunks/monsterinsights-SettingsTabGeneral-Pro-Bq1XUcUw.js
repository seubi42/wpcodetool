import { d as getMiGlobal, k as getUrl } from "./globals-C78yxw47.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { u as useLicenseStore } from "./license-D2vquWPE.js";
import { _ as _sfc_main$4 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$6 } from "./SettingsInputRadio-BxhcBMU_.js";
import { o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, u as unref, C as withModifiers, k as normalizeClass, v as createCommentVNode, w as withDirectives, x as vModelText, b as createVNode, B as createTextVNode, p as computed, l as ref, G as createBlock, a9 as createSlots, E as withCtx } from "./AppOverlays-Dpqzx_3P.js";
import { a as sanitizeHtml } from "./sanitize-CE5uy2g6.js";
import { a as useToast } from "./addons-rxJejcjK.js";
import { _ as _sfc_main$3 } from "./SettingsInfoTooltip-QCvq3iSl.js";
import { _ as _sfc_main$5 } from "./monsterinsights-SettingsInputAuthenticate-Pro-C9VT_FUk.js";
import { _ as _sfc_main$7 } from "./LaunchWizardButton-CiLSAg2u.js";
import "./useNotices-CwGRydDs.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./Modal-D1CHxx1Y.js";
import "./auth-D7PLXWYn.js";
import "./SettingsInputText-IF6LAjxY.js";
const _hoisted_1$2 = { class: "monsterinsights-settings-license-site" };
const _hoisted_2$1 = { key: 0 };
const _hoisted_3$1 = { key: 1 };
const _hoisted_4$1 = ["innerHTML"];
const _hoisted_5$1 = { key: 2 };
const _hoisted_6 = ["readonly", "value", "placeholder"];
const _hoisted_7 = { key: 3 };
const _hoisted_8 = {
  key: 0,
  class: "inline-field"
};
const _hoisted_9 = ["placeholder"];
const _hoisted_10 = ["title", "textContent"];
const _hoisted_11 = { key: 4 };
const _hoisted_12 = {
  key: 0,
  class: "inline-field"
};
const _hoisted_13 = ["placeholder"];
const _hoisted_14 = ["textContent"];
const _hoisted_15 = ["textContent"];
const _hoisted_16 = {
  key: 5,
  class: "monsterinsights-error license-key-error"
};
const _hoisted_17 = ["innerHTML"];
const _hoisted_18 = {
  key: 6,
  class: "monsterinsights-license-type-text"
};
const _hoisted_19 = ["innerHTML"];
const _hoisted_20 = { key: 0 };
const _hoisted_21 = ["textContent"];
const _hoisted_22 = {
  key: 7,
  class: "monsterinsights-license-type-text monsterinsights-license-subscription-renewal"
};
const _hoisted_23 = { class: "monsterinsights-license-subscription-renewal__line" };
const _hoisted_24 = ["textContent"];
const _hoisted_25 = ["href", "textContent"];
const _sfc_main$2 = {
  __name: "monsterinsights-SettingsInputLicenseSite",
  emits: ["verify-license-start", "verify-license-end"],
  setup(__props, { emit: __emit }) {
    const { __, sprintf } = wp.i18n;
    const emit = __emit;
    const licenseStore = useLicenseStore();
    const { savingToast, successToast, errorToast, loadingToast, closeToast } = useToast();
    const is_loading = ref(false);
    const has_error = ref(false);
    const updated_license = ref(false);
    const license_changed = ref(false);
    const exit_url = getMiGlobal("exit_url", "#");
    const license = computed(() => licenseStore.license);
    const license_network = computed(() => licenseStore.license_network);
    const isLicenseExpired = computed(() => !!license.value?.is_expired);
    const isNetworkLicenseExpired = computed(() => !!license_network.value?.is_expired);
    const text_license_refresh = __("Refresh Key", "google-analytics-for-wordpress");
    const text_license_refresh_tooltip = sprintf(__("Click %1$sRefresh Key%2$s if your license has been recently upgraded or the license type is incorrect. ", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_license_deactivate = __("Deactivate", "google-analytics-for-wordpress");
    const text_license_placeholder = __("Paste your license key here", "google-analytics-for-wordpress");
    const text_license_label_site = sprintf(__("Add your MonsterInsights license key from the email receipt or account area. %1$sRetrieve your license key%2$s.", "google-analytics-for-wordpress"), '<a href="' + getUrl("license", "settings_panel", "https://www.monsterinsights.com/my-account/") + '" target="_blank">', "</a>");
    const text_license_label_network = __("The license key is used to enable updates for MonsterInsights Pro and addons, as well enable the ability to view reports. Deactivate your license if you want to use it on another WordPress site.", "google-analytics-for-wordpress");
    const text_license_expired_1 = __("Your license key has expired. Reactivate the current license, or ", "google-analytics-for-wordpress");
    const text_license_expired_2 = __("click here to deactivate", "google-analytics-for-wordpress");
    const text_license_expired_3 = __(" and add a new one.", "google-analytics-for-wordpress");
    const text_license_expired_reactivate = __("Reactivate This License", "google-analytics-for-wordpress");
    const text_license_type = sprintf(__("Your license key type for this site is %s. ", "google-analytics-for-wordpress"), '<span class="monsterinsights-dark">%s</span>');
    const text_license_no_key_subsite = __("No license key activated on this subsite", "google-analytics-for-wordpress");
    const text_subscription_renews_prefix = __("Your subscription renews on:", "google-analytics-for-wordpress");
    const text_renew_now = __("Renew Now", "google-analytics-for-wordpress");
    const text_subscription_renew_tooltip = __("Click here if your license is about to expire or you want to renew it before it ends", "google-analytics-for-wordpress");
    const renewal_url = getUrl("license", "settings_panel", "https://www.monsterinsights.com/my-account/");
    const license_key = computed({
      get() {
        return updated_license.value !== false ? updated_license.value : license.value.key;
      },
      set(value) {
        updated_license.value = value;
      }
    });
    const text_license_verify_button = computed(() => {
      return license_changed.value ? __("Change Key", "google-analytics-for-wordpress") : __("Verify", "google-analytics-for-wordpress");
    });
    const getLabel = computed(() => {
      return license_network.value.type ? text_license_label_network : text_license_label_site;
    });
    const getLicenseType = computed(() => {
      if (license_network.value.type && !license.value.type) {
        return text_license_no_key_subsite;
      } else if (license.value && license.value.type) {
        return sprintf(text_license_type, license.value.is_agency ? "Agency" : license.value.type);
      }
      return "";
    });
    const displayExpiryDate = computed(() => {
      if (license.value && license.value.key && license.value.expiry_date) return license.value.expiry_date;
      if (license_network.value && license_network.value.key && license_network.value.expiry_date) return license_network.value.expiry_date;
      return "";
    });
    const shouldShowSubscriptionRenewalRow = computed(() => {
      if (!displayExpiryDate.value) return false;
      if (isLicenseExpired.value || isNetworkLicenseExpired.value) return false;
      return !!(license.value && license.value.type && license.value.key) || !!(license_network.value && license_network.value.type && license_network.value.key);
    });
    let fieldInputTimer = null;
    function fieldInput(e) {
      clearTimeout(fieldInputTimer);
      const value = e.target.value;
      fieldInputTimer = setTimeout(() => {
        if (value === "") return;
        is_loading.value = true;
        savingToast({ title: __("Verifying License", "google-analytics-for-wordpress") });
        emit("verify-license-start");
        has_error.value = false;
        licenseStore.updateLicense(value).then(() => {
          is_loading.value = false;
          closeToast();
          emit("verify-license-end");
          successToast({ title: __("License activated", "google-analytics-for-wordpress") });
        }).catch((err) => {
          is_loading.value = false;
          closeToast();
          emit("verify-license-end");
          has_error.value = err?.message || err?.data?.error || true;
          errorToast({
            title: err?.message || err?.data?.error || __("Could not activate license", "google-analytics-for-wordpress")
          });
        });
      }, 500);
    }
    async function handleVerifyLicense(is_expired) {
      loadingToast(__("Verifying License", "google-analytics-for-wordpress"));
      try {
        const response = await licenseStore.verifyLicense(license_key.value);
        closeToast();
        if (is_expired) {
          window.location = exit_url;
        } else {
          successToast({ title: response?.message || __("License verified", "google-analytics-for-wordpress") });
        }
      } catch (err) {
        closeToast();
        errorToast({ title: __("There was an error verifying your license", "google-analytics-for-wordpress") });
      }
    }
    async function handleRefreshLicense() {
      loadingToast(__("Refreshing License", "google-analytics-for-wordpress"));
      try {
        await licenseStore.validateLicense();
        closeToast();
        successToast({ title: __("License refreshed", "google-analytics-for-wordpress") });
      } catch {
        closeToast();
        errorToast({ title: __("There was an error refreshing your license", "google-analytics-for-wordpress") });
      }
    }
    async function handleDeactivateLicense() {
      loadingToast(__("Deactivating License", "google-analytics-for-wordpress"));
      try {
        await licenseStore.deactivateLicense();
        closeToast();
        successToast({ title: __("License deactivated", "google-analytics-for-wordpress") });
      } catch {
        closeToast();
        errorToast({ title: __("There was an error deactivating your license", "google-analytics-for-wordpress") });
      }
    }
    async function deactivateExpiredLicense() {
      loadingToast(__("Deactivating License", "google-analytics-for-wordpress"));
      try {
        await licenseStore.deactivateExpiredLicense();
        closeToast();
        window.location = exit_url;
      } catch {
        closeToast();
        errorToast({ title: __("There was an error deactivating your license", "google-analytics-for-wordpress") });
      }
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$2, [
        isLicenseExpired.value || isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("div", _hoisted_2$1, [
          createBaseVNode("label", null, [
            createBaseVNode("span", null, toDisplayString(unref(text_license_expired_1)), 1),
            createBaseVNode("a", {
              href: "#",
              title: "",
              onClick: withModifiers(deactivateExpiredLicense, ["prevent"])
            }, toDisplayString(unref(text_license_expired_2)), 1),
            createBaseVNode("span", null, toDisplayString(unref(text_license_expired_3)), 1)
          ])
        ])) : (openBlock(), createElementBlock("div", _hoisted_3$1, [
          createBaseVNode("label", {
            for: "monsterinsights-license-key",
            innerHTML: getLabel.value
          }, null, 8, _hoisted_4$1)
        ])),
        license.value.is_invalid || "" === license.value.type ? (openBlock(), createElementBlock("div", _hoisted_5$1, [
          createBaseVNode("input", {
            id: "monsterinsights-license-key",
            readonly: is_loading.value,
            type: "text",
            autocomplete: "off",
            value: license.value.key,
            class: normalizeClass({ "monsterinsights-has-error": has_error.value }),
            placeholder: unref(text_license_placeholder),
            onInput: fieldInput
          }, null, 42, _hoisted_6)
        ])) : createCommentVNode("", true),
        isLicenseExpired.value || isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("div", _hoisted_7, [
          !license.value.is_invalid && license.value.type ? (openBlock(), createElementBlock("div", _hoisted_8, [
            withDirectives(createBaseVNode("input", {
              id: "monsterinsights-license-key-valid",
              "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => license_key.value = $event),
              type: "text",
              readonly: "",
              autocomplete: "off",
              class: normalizeClass({ "monsterinsights-has-error": has_error.value }),
              placeholder: unref(text_license_placeholder)
            }, null, 10, _hoisted_9), [
              [vModelText, license_key.value]
            ]),
            createBaseVNode("a", {
              class: "monsterinsights-button",
              href: "#",
              title: unref(text_license_expired_reactivate),
              onClick: _cache[1] || (_cache[1] = withModifiers(($event) => handleVerifyLicense(true), ["prevent"])),
              textContent: toDisplayString(unref(text_license_expired_reactivate))
            }, null, 8, _hoisted_10)
          ])) : createCommentVNode("", true)
        ])) : (openBlock(), createElementBlock("div", _hoisted_11, [
          !license.value.is_invalid && license.value.type ? (openBlock(), createElementBlock("div", _hoisted_12, [
            withDirectives(createBaseVNode("input", {
              id: "monsterinsights-license-key-valid",
              "onUpdate:modelValue": _cache[2] || (_cache[2] = ($event) => license_key.value = $event),
              type: "text",
              autocomplete: "off",
              class: normalizeClass({ "monsterinsights-has-error": has_error.value }),
              placeholder: unref(text_license_placeholder),
              onInput: _cache[3] || (_cache[3] = ($event) => license_changed.value = true)
            }, null, 42, _hoisted_13), [
              [vModelText, license_key.value]
            ]),
            createBaseVNode("button", {
              class: "monsterinsights-button",
              onClick: _cache[4] || (_cache[4] = ($event) => handleVerifyLicense(false)),
              textContent: toDisplayString(text_license_verify_button.value)
            }, null, 8, _hoisted_14),
            createBaseVNode("button", {
              class: "monsterinsights-button monsterinsights-button-secondary",
              onClick: handleDeactivateLicense,
              textContent: toDisplayString(unref(text_license_deactivate))
            }, null, 8, _hoisted_15)
          ])) : createCommentVNode("", true)
        ])),
        has_error.value ? (openBlock(), createElementBlock("label", _hoisted_16, [
          _cache[5] || (_cache[5] = createBaseVNode("i", { class: "monstericon-warning-triangle" }, null, -1)),
          createBaseVNode("span", {
            innerHTML: unref(sanitizeHtml)(has_error.value)
          }, null, 8, _hoisted_17)
        ])) : createCommentVNode("", true),
        license.value.type && license.value.key || license_network.value.type && license_network.value.key ? (openBlock(), createElementBlock("div", _hoisted_18, [
          createBaseVNode("span", {
            innerHTML: unref(sanitizeHtml)(getLicenseType.value)
          }, null, 8, _hoisted_19),
          !isLicenseExpired.value && !isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("span", _hoisted_20, [
            !license.value.is_invalid && license.value.type ? (openBlock(), createElementBlock("a", {
              key: 0,
              href: "#",
              onClick: withModifiers(handleRefreshLicense, ["prevent"])
            }, [
              createBaseVNode("span", {
                textContent: toDisplayString(unref(text_license_refresh))
              }, null, 8, _hoisted_21)
            ])) : createCommentVNode("", true),
            createVNode(_sfc_main$3, { content: unref(text_license_refresh_tooltip) }, null, 8, ["content"])
          ])) : createCommentVNode("", true)
        ])) : createCommentVNode("", true),
        shouldShowSubscriptionRenewalRow.value ? (openBlock(), createElementBlock("div", _hoisted_22, [
          createBaseVNode("span", _hoisted_23, [
            createTextVNode(toDisplayString(unref(text_subscription_renews_prefix)) + " ", 1),
            createBaseVNode("strong", {
              textContent: toDisplayString(displayExpiryDate.value)
            }, null, 8, _hoisted_24),
            _cache[6] || (_cache[6] = createTextVNode(". ", -1)),
            createBaseVNode("a", {
              href: unref(renewal_url),
              target: "_blank",
              rel: "noopener noreferrer",
              textContent: toDisplayString(unref(text_renew_now))
            }, null, 8, _hoisted_25)
          ]),
          createVNode(_sfc_main$3, { content: unref(text_subscription_renew_tooltip) }, null, 8, ["content"])
        ])) : createCommentVNode("", true)
      ]);
    };
  }
};
const _hoisted_1$1 = { class: "settings-input settings-input-license" };
const _sfc_main$1 = {
  __name: "SettingsInputLicense-Pro",
  emits: ["verify-license-start", "verify-license-end"],
  setup(__props, { emit: __emit }) {
    const emit = __emit;
    const is_network = getMiGlobal("network", false);
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$1, [
        !unref(is_network) ? (openBlock(), createBlock(_sfc_main$2, {
          key: 0,
          onVerifyLicenseStart: _cache[0] || (_cache[0] = ($event) => emit("verify-license-start")),
          onVerifyLicenseEnd: _cache[1] || (_cache[1] = ($event) => emit("verify-license-end"))
        })) : createCommentVNode("", true)
      ]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-settings-content settings-general" };
const _hoisted_2 = { class: "monsterinsights-license-expired-tag" };
const _hoisted_3 = {
  key: 0,
  class: "monsterinsights-license-expiry-date"
};
const _hoisted_4 = ["textContent"];
const _hoisted_5 = ["textContent"];
const _sfc_main = {
  __name: "monsterinsights-SettingsTabGeneral-Pro",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const settingsStore = useSettingsStore();
    const licenseStore = useLicenseStore();
    const settings = computed(() => settingsStore.getSettings);
    const license = computed(() => licenseStore.license);
    const license_network = computed(() => licenseStore.license_network);
    const is_network = getMiGlobal("network", false);
    const isLicenseExpired = computed(() => !!license.value?.is_expired);
    const isNetworkLicenseExpired = computed(() => !!license_network.value?.is_expired);
    const licenseExpiryDate = computed(() => {
      if (is_network && license_network.value?.expiry_date) {
        return license_network.value.expiry_date;
      } else if (!is_network && license.value?.expiry_date) {
        return license.value.expiry_date;
      }
      return "";
    });
    const automatic_updates = [
      {
        value: "all",
        label: sprintf(__("Yes (recommended) %1$s- Get the latest features, bugfixes, and security updates as they are released.%2$s", "google-analytics-for-wordpress"), "<small>", "</small>")
      },
      {
        value: "minor",
        label: sprintf(__("Minor only %1$s- Get bugfixes and security updates, but not major features.%2$s", "google-analytics-for-wordpress"), "<small>", "</small>")
      },
      {
        value: "none",
        label: sprintf(__("None %1$s- Manually update everything.%2$s", "google-analytics-for-wordpress"), "<small>", "</small>")
      }
    ];
    const text_license_title = __("License Key", "google-analytics-for-wordpress");
    const text_auth_title = __("Google Authentication", "google-analytics-for-wordpress");
    const text_auth_label = __("Connect Google Analytics + WordPress", "google-analytics-for-wordpress");
    const text_auth_description = __("You will be taken to the MonsterInsights website where you'll need to connect your Analytics account.", "google-analytics-for-wordpress");
    const text_automatic_updates = __("Automatic Updates", "google-analytics-for-wordpress");
    const text_setup_wizard_title = __("Setup Wizard", "google-analytics-for-wordpress");
    const text_setup_wizard_label = __("Use our configuration wizard to properly set up Google Analytics with WordPress (with just a few clicks).", "google-analytics-for-wordpress");
    const text_setup_wizard_button = __("Launch Setup Wizard", "google-analytics-for-wordpress");
    const text_tag_expired = __("Expired", "google-analytics-for-wordpress");
    const text_setup_wizard_disclaimer = __("Note: You will be transferred to MonsterInsights.com to complete the setup wizard.", "google-analytics-for-wordpress");
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("main", _hoisted_1, [
        createVNode(_sfc_main$4, { title: unref(text_license_title) }, createSlots({
          default: withCtx(() => [
            createVNode(_sfc_main$1)
          ]),
          _: 2
        }, [
          isLicenseExpired.value || isNetworkLicenseExpired.value ? {
            name: "expired-license-tag",
            fn: withCtx(() => [
              createBaseVNode("span", _hoisted_2, toDisplayString(unref(text_tag_expired)), 1),
              licenseExpiryDate.value ? (openBlock(), createElementBlock("span", _hoisted_3, toDisplayString(unref(text_tag_expired) + " " + licenseExpiryDate.value), 1)) : createCommentVNode("", true)
            ]),
            key: "0"
          } : void 0
        ]), 1032, ["title"]),
        createVNode(_sfc_main$4, { title: unref(text_auth_title) }, {
          default: withCtx(() => [
            createVNode(_sfc_main$5, {
              label: unref(text_auth_label),
              description: unref(text_auth_description)
            }, null, 8, ["label", "description"])
          ]),
          _: 1
        }, 8, ["title"]),
        settings.value.automatic_updates === "minor" || settings.value.automatic_updates === "none" ? (openBlock(), createBlock(_sfc_main$4, {
          key: 0,
          title: unref(text_automatic_updates)
        }, {
          default: withCtx(() => [
            createVNode(_sfc_main$6, {
              options: automatic_updates,
              name: "automatic_updates"
            })
          ]),
          _: 1
        }, 8, ["title"])) : createCommentVNode("", true),
        createVNode(_sfc_main$4, { title: unref(text_setup_wizard_title) }, {
          default: withCtx(() => [
            createBaseVNode("label", {
              textContent: toDisplayString(unref(text_setup_wizard_label))
            }, null, 8, _hoisted_4),
            createVNode(_sfc_main$7, {
              "button-class": "monsterinsights-button",
              "button-text": unref(text_setup_wizard_button)
            }, null, 8, ["button-text"]),
            createBaseVNode("p", {
              class: "monsterinsights-disclaimer-note",
              textContent: toDisplayString(unref(text_setup_wizard_disclaimer))
            }, null, 8, _hoisted_5)
          ]),
          _: 1
        }, 8, ["title"])
      ]);
    };
  }
};
export {
  _sfc_main as default
};
