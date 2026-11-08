import { d as getMiGlobal, k as getUrl, l as isPro } from "./globals-C78yxw47.js";
import { u as useLicenseStore } from "./license-D2vquWPE.js";
import { _ as _sfc_main$3 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$6 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { _ as _sfc_main$4 } from "./monsterinsights-SettingsInputAuthenticate-Pro-C9VT_FUk.js";
import { o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, u as unref, C as withModifiers, k as normalizeClass, v as createCommentVNode, w as withDirectives, x as vModelText, b as createVNode, B as createTextVNode, p as computed, l as ref, S as _export_sfc, a9 as createSlots, E as withCtx, G as createBlock } from "./AppOverlays-Dpqzx_3P.js";
import { a as sanitizeHtml } from "./sanitize-CE5uy2g6.js";
import { a as useToast } from "./addons-rxJejcjK.js";
import { _ as _sfc_main$2 } from "./SettingsInfoTooltip-QCvq3iSl.js";
import { _ as _sfc_main$7 } from "./SettingsLiteUpsellLarge-5wNFfHoF.js";
import { _ as _sfc_main$5 } from "./LaunchWizardButton-CiLSAg2u.js";
import "./useNotices-CwGRydDs.js";
import "./settings-WrluSu6E.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./auth-D7PLXWYn.js";
import "./SettingsInputText-IF6LAjxY.js";
import "./Modal-D1CHxx1Y.js";
const _hoisted_1$1 = { class: "monsterinsights-settings-license-network" };
const _hoisted_2$1 = { key: 0 };
const _hoisted_3$1 = { key: 1 };
const _hoisted_4$1 = ["innerHTML"];
const _hoisted_5$1 = { key: 2 };
const _hoisted_6$1 = ["readonly", "value", "placeholder"];
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
const _sfc_main$1 = {
  __name: "monsterinsights-SettingsInputLicenseNetwork-Pro",
  props: {
    label: String
  },
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const licenseStore = useLicenseStore();
    const { savingToast, successToast, errorToast, loadingToast, closeToast } = useToast();
    const is_loading = ref(false);
    const has_error = ref(false);
    const updated_license = ref(false);
    const license_changed = ref(false);
    const exit_url = getMiGlobal("exit_url", "#");
    const license_network = computed(() => licenseStore.license_network);
    const isNetworkLicenseExpired = computed(() => !!license_network.value?.is_expired);
    const text_license_refresh = __("Refresh Key", "google-analytics-for-wordpress");
    const text_license_refresh_tooltip = sprintf(__("Click %1$sRefresh Key%2$s if your license has been recently upgraded or the license type is incorrect. ", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_license_verify = __("Verify", "google-analytics-for-wordpress");
    const text_license_deactivate = __("Deactivate", "google-analytics-for-wordpress");
    const text_license_placeholder = __("Paste your license key here", "google-analytics-for-wordpress");
    const text_license_expired_1 = __("Your license key has expired. Reactivate the current license, or ", "google-analytics-for-wordpress");
    const text_license_expired_2 = __("click here to deactivate", "google-analytics-for-wordpress");
    const text_license_expired_3 = __(" and add a new one.", "google-analytics-for-wordpress");
    const text_license_expired_reactivate = __("Reactivate This License", "google-analytics-for-wordpress");
    const text_license_type = sprintf(__("Your license key type for this site is %s. ", "google-analytics-for-wordpress"), '<span class="monsterinsights-dark">%s</span>');
    const text_subscription_renews_prefix = __("Your subscription renews on:", "google-analytics-for-wordpress");
    const text_renew_now = __("Renew Now", "google-analytics-for-wordpress");
    const text_subscription_renew_tooltip = __("Click here if your license is about to expire or you want to renew it before it ends", "google-analytics-for-wordpress");
    const renewal_url = getUrl("license", "settings_panel", "https://www.monsterinsights.com/my-account/");
    const license_key = computed({
      get() {
        return updated_license.value !== false ? updated_license.value : license_network.value.key;
      },
      set(value) {
        updated_license.value = value;
      }
    });
    const text_license_verify_button = computed(() => {
      return license_changed.value ? __("Change Key", "google-analytics-for-wordpress") : text_license_verify;
    });
    const displayExpiryDate = computed(() => {
      if (license_network.value && license_network.value.key && license_network.value.expiry_date) {
        return license_network.value.expiry_date;
      }
      return "";
    });
    const shouldShowSubscriptionRenewalRow = computed(() => {
      if (!displayExpiryDate.value) return false;
      if (isNetworkLicenseExpired.value) return false;
      return !!(license_network.value && license_network.value.type && license_network.value.key);
    });
    const getLicenseType = computed(() => {
      const type = license_network.value.is_agency ? "Agency" : license_network.value.type;
      return sprintf(text_license_type, type);
    });
    let fieldInputTimer = null;
    function fieldInput(e) {
      clearTimeout(fieldInputTimer);
      const value = e.target.value;
      fieldInputTimer = setTimeout(() => {
        if (value === "") return;
        is_loading.value = true;
        savingToast({ title: __("Verifying License", "google-analytics-for-wordpress") });
        has_error.value = false;
        licenseStore.updateNetworkLicense(value).then(() => {
          is_loading.value = false;
          closeToast();
          successToast({ title: __("License activated", "google-analytics-for-wordpress") });
        }).catch((err) => {
          is_loading.value = false;
          closeToast();
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
        const response = await licenseStore.verifyLicense(license_key.value, true);
        closeToast();
        if (is_expired) {
          window.location = exit_url;
        } else {
          successToast({ title: response?.message || __("License verified", "google-analytics-for-wordpress") });
        }
      } catch {
        closeToast();
        errorToast({ title: __("There was an error verifying your license", "google-analytics-for-wordpress") });
      }
    }
    async function handleRefreshLicense() {
      loadingToast(__("Refreshing License", "google-analytics-for-wordpress"));
      try {
        await licenseStore.validateLicense(true);
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
        await licenseStore.deactivateLicense(true);
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
        await licenseStore.deactivateExpiredLicense(true);
        closeToast();
        window.location = exit_url;
      } catch {
        closeToast();
        errorToast({ title: __("There was an error deactivating your license", "google-analytics-for-wordpress") });
      }
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$1, [
        isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("div", _hoisted_2$1, [
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
            for: "monsterinsights-license-network-key",
            innerHTML: __props.label
          }, null, 8, _hoisted_4$1)
        ])),
        license_network.value.is_invalid || "" === license_network.value.type ? (openBlock(), createElementBlock("div", _hoisted_5$1, [
          createBaseVNode("input", {
            id: "monsterinsights-license-network-key",
            readonly: is_loading.value,
            type: "text",
            autocomplete: "off",
            value: license_network.value.key,
            class: normalizeClass({ "monsterinsights-has-error": has_error.value }),
            placeholder: unref(text_license_placeholder),
            onInput: fieldInput
          }, null, 42, _hoisted_6$1)
        ])) : createCommentVNode("", true),
        isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("div", _hoisted_7, [
          !license_network.value.is_invalid && license_network.value.type ? (openBlock(), createElementBlock("div", _hoisted_8, [
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
          !license_network.value.is_invalid && license_network.value.type ? (openBlock(), createElementBlock("div", _hoisted_12, [
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
        license_network.value.type && license_network.value.key ? (openBlock(), createElementBlock("div", _hoisted_18, [
          createBaseVNode("span", {
            innerHTML: unref(sanitizeHtml)(getLicenseType.value)
          }, null, 8, _hoisted_19),
          !isNetworkLicenseExpired.value ? (openBlock(), createElementBlock("span", _hoisted_20, [
            createBaseVNode("a", {
              href: "#",
              onClick: withModifiers(handleRefreshLicense, ["prevent"])
            }, [
              createBaseVNode("span", {
                textContent: toDisplayString(unref(text_license_refresh))
              }, null, 8, _hoisted_21)
            ]),
            createVNode(_sfc_main$2, { content: unref(text_license_refresh_tooltip) }, null, 8, ["content"])
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
          createVNode(_sfc_main$2, { content: unref(text_subscription_renew_tooltip) }, null, 8, ["content"])
        ])) : createCommentVNode("", true)
      ]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-settings-content settings-network" };
const _hoisted_2 = { class: "monsterinsights-license-expired-tag" };
const _hoisted_3 = ["textContent"];
const _hoisted_4 = ["textContent"];
const _hoisted_5 = ["innerHTML"];
const _hoisted_6 = ["innerHTML"];
const _sfc_main = {
  __name: "monsterinsights-SettingsNetwork",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const licenseStore = useLicenseStore();
    const isProBuild = isPro();
    const license_network = computed(() => licenseStore.license_network);
    const isNetworkLicenseExpired = computed(() => !!license_network.value?.is_expired);
    const text_license_title = __("License Key", "google-analytics-for-wordpress");
    const text_license_label = sprintf(__("Add your MonsterInsights license key from the email receipt or account area. %1$sRetrieve your license key%2$s.", "google-analytics-for-wordpress"), '<a href="#">', "</a>");
    const text_auth_title = __("Google Authentication", "google-analytics-for-wordpress");
    const text_auth_label = __("Connect Google Analytics + WordPress", "google-analytics-for-wordpress");
    const text_auth_description = __("You will be taken to the MonsterInsights website where you'll need to connect your Analytics account.", "google-analytics-for-wordpress");
    const text_setup_wizard_title = __("Setup Wizard", "google-analytics-for-wordpress");
    const text_setup_wizard_label = __("Use our configuration wizard to properly set up Google Analytics with WordPress (with just a few clicks).", "google-analytics-for-wordpress");
    const text_setup_wizard_button = __("Launch Setup Wizard", "google-analytics-for-wordpress");
    const text_onboarding_note = __("Note: You will be transferred to MonsterInsights.com to complete the setup wizard.", "google-analytics-for-wordpress");
    const text_misc_title = __("Miscellaneous", "google-analytics-for-wordpress");
    const text_announcements_title = __("Hide Announcements", "google-analytics-for-wordpress");
    const text_announcements_description = __("Hides plugin announcements and update details. This includes critical notices we use to inform about deprecations and important required configuration changes.", "google-analytics-for-wordpress");
    const text_announcements_label = __("Hide Announcements", "google-analytics-for-wordpress");
    const text_tag_expired = __("Expired", "google-analytics-for-wordpress");
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("main", _hoisted_1, [
        createVNode(_sfc_main$3, { title: unref(text_license_title) }, createSlots({
          default: withCtx(() => [
            createVNode(_sfc_main$1, { label: unref(text_license_label) }, null, 8, ["label"])
          ]),
          _: 2
        }, [
          isNetworkLicenseExpired.value ? {
            name: "expired-license-tag",
            fn: withCtx(() => [
              createBaseVNode("span", _hoisted_2, toDisplayString(unref(text_tag_expired)), 1)
            ]),
            key: "0"
          } : void 0
        ]), 1032, ["title"]),
        createVNode(_sfc_main$3, { title: unref(text_auth_title) }, {
          default: withCtx(() => [
            createVNode(_sfc_main$4, {
              label: unref(text_auth_label),
              description: unref(text_auth_description)
            }, null, 8, ["label", "description"])
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$3, { title: unref(text_setup_wizard_title) }, {
          default: withCtx(() => [
            createBaseVNode("label", {
              textContent: toDisplayString(unref(text_setup_wizard_label))
            }, null, 8, _hoisted_3),
            createVNode(_sfc_main$5, {
              "button-class": "monsterinsights-button",
              "button-text": unref(text_setup_wizard_button)
            }, null, 8, ["button-text"]),
            createBaseVNode("p", {
              class: "monsterinsights-disclaimer-note",
              textContent: toDisplayString(unref(text_onboarding_note))
            }, null, 8, _hoisted_4)
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$3, { title: unref(text_misc_title) }, {
          default: withCtx(() => [
            createBaseVNode("p", null, [
              createBaseVNode("span", {
                class: "monsterinsights-dark",
                innerHTML: unref(text_announcements_title)
              }, null, 8, _hoisted_5),
              createBaseVNode("span", { innerHTML: unref(text_announcements_description) }, null, 8, _hoisted_6)
            ]),
            createVNode(_sfc_main$6, {
              name: "hide_am_notices",
              label: unref(text_announcements_label)
            }, null, 8, ["label"])
          ]),
          _: 1
        }, 8, ["title"]),
        !unref(isProBuild) ? (openBlock(), createBlock(_sfc_main$7, { key: 0 })) : createCommentVNode("", true)
      ]);
    };
  }
};
const monsterinsightsSettingsNetwork = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-023a7460"]]);
export {
  monsterinsightsSettingsNetwork as default
};
