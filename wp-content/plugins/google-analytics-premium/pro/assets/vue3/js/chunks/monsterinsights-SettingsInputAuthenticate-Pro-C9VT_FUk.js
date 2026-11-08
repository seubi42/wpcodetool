import { S as _export_sfc, o as openBlock, c as createElementBlock, L as renderSlot, l as ref, u as unref, G as createBlock, E as withCtx, a as createBaseVNode, t as toDisplayString, v as createCommentVNode, k as normalizeClass, H as withKeys, B as createTextVNode, b as createVNode, p as computed } from "./AppOverlays-Dpqzx_3P.js";
import { d as getMiGlobal, k as getUrl } from "./globals-C78yxw47.js";
import { a as sanitizeHtml } from "./sanitize-CE5uy2g6.js";
import { a as useToast, d as useDialog } from "./addons-rxJejcjK.js";
import { u as useLicenseStore } from "./license-D2vquWPE.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { a as authApi } from "./auth-D7PLXWYn.js";
import { S as SlideDownUp } from "./SettingsBlock-BINMceN_.js";
import { S as SettingsInputText } from "./SettingsInputText-IF6LAjxY.js";
const _sfc_main$1 = {};
const _hoisted_1$1 = { class: "monsterinsights-network-notice" };
function _sfc_render(_ctx, _cache) {
  return openBlock(), createElementBlock("div", _hoisted_1$1, [
    renderSlot(_ctx.$slots, "default")
  ]);
}
const SettingsNetworkNotice = /* @__PURE__ */ _export_sfc(_sfc_main$1, [["render", _sfc_render]]);
const _hoisted_1 = { class: "monsterinsights-settings-input monsterinsights-settings-input-authenticate" };
const _hoisted_2 = ["textContent"];
const _hoisted_3 = ["textContent"];
const _hoisted_4 = { key: 1 };
const _hoisted_5 = ["textContent"];
const _hoisted_6 = ["innerHTML"];
const _hoisted_7 = ["title", "textContent"];
const _hoisted_8 = {
  key: 0,
  class: "monsterinsights-auth-manual-connect-paragraph"
};
const _hoisted_9 = ["innerHTML"];
const _hoisted_10 = { key: 2 };
const _hoisted_11 = ["textContent"];
const _hoisted_12 = ["innerHTML"];
const _hoisted_13 = ["value"];
const _hoisted_14 = {
  key: 0,
  class: "monsterinsights-error"
};
const _hoisted_15 = ["innerHTML"];
const _hoisted_16 = ["textContent"];
const _hoisted_17 = ["innerHTML"];
const _hoisted_18 = ["value"];
const _hoisted_19 = {
  key: 3,
  class: "monsterinsights-auth-info"
};
const _hoisted_20 = ["textContent"];
const _hoisted_21 = ["textContent"];
const _hoisted_22 = ["textContent"];
const _hoisted_23 = {
  key: 0,
  class: "monsterinsights-error"
};
const _hoisted_24 = ["textContent"];
const _hoisted_25 = { key: 0 };
const _hoisted_26 = { class: "monsterinsights-auth-actions" };
const _hoisted_27 = ["textContent"];
const _hoisted_28 = ["textContent"];
const _hoisted_29 = ["textContent"];
const _hoisted_30 = { key: 4 };
const _hoisted_31 = {
  key: 5,
  class: "monsterinsights-auth-info"
};
const _hoisted_32 = ["textContent"];
const _hoisted_33 = ["textContent"];
const _hoisted_34 = { key: 0 };
const _sfc_main = {
  __name: "monsterinsights-SettingsInputAuthenticate-Pro",
  props: { label: String, description: String },
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const { loadingToast, savingToast, successToast, errorToast, closeToast } = useToast();
    const dialog = useDialog();
    const licenseStore = useLicenseStore();
    const settingsStore = useSettingsStore();
    const auth = ref(getMiGlobal("auth", { v4: "", manual_v4: "", network_v4: "", network_manual_v4: "", viewname: "", network_viewname: "", measurement_protocol_secret: "", network_measurement_protocol_secret: "" }));
    const is_network = getMiGlobal("network", false);
    const has_credentials = computed(() => {
      const flag = is_network ? auth.value.network_has_credentials : auth.value.has_credentials;
      return flag === void 0 ? true : !!flag;
    });
    const force_deauth = ref(!has_credentials.value);
    const show_buttons = ref(!has_credentials.value);
    const show_advanced_tracking = ref(false);
    const show_manual_on_click = ref(false);
    const has_error = ref(false);
    const has_v4_error = ref(false);
    const license = computed(() => licenseStore.license);
    const license_network = computed(() => licenseStore.license_network);
    const text_button_connect = __("Connect MonsterInsights", "google-analytics-for-wordpress");
    const text_button_verify = __("Verify Credentials", "google-analytics-for-wordpress");
    const text_button_reconnect = __("Reconnect MonsterInsights", "google-analytics-for-wordpress");
    const text_website_profile = __("Website Profile", "google-analytics-for-wordpress");
    const text_advanced_tracking = __("Advanced Tracking Settings", "google-analytics-for-wordpress");
    const text_active_profile = __("Active Profile", "google-analytics-for-wordpress");
    const text_missing_credentials = __("This connection is missing its Google credentials, so reports will not load. Reconnect MonsterInsights, or use Force Deauthenticate to reset the connection and start over.", "google-analytics-for-wordpress");
    const text_auth_network = __("Your website profile has been set at the network level of your WordPress Multisite.", "google-analytics-for-wordpress");
    const text_auth_network_2 = __("If you would like to use a different profile for this subsite, you can authenticate below.", "google-analytics-for-wordpress");
    const text_manual_v4_label = __("Manually enter your GA4 Measurement ID", "google-analytics-for-wordpress");
    const text_manual_v4_description = __("Warning: If you use a manual GA4 Measurement ID, you won't be able to use any of the reporting and some of the tracking features. Your Measurement ID should look like G-XXXXXXXXXX or GT-XXXXX where the X's are combination of numbers and letters.", "google-analytics-for-wordpress");
    const text_manual_connect = __("Or manually enter GA4 Measurement ID (limited functionality)", "google-analytics-for-wordpress");
    const text_v4_measurement_protocol = __("Measurement Protocol API Secret", "google-analytics-for-wordpress");
    const text_v4_measurement_protocol_description = sprintf(
      __("The Measurement Protocol API secret allows your site to send tracking data to Google Analytics. To retrieve your Measurement Protocol API Secret, follow %1$sthis guide%2$s.", "google-analytics-for-wordpress"),
      '<a href="' + getUrl("dual-tracking", "settings", "https://www.monsterinsights.com/docs/how-to-create-your-measurement-protocol-api-secret-in-ga4/") + '" target="_blank">',
      "</a>"
    );
    const text_needs_license = __("A license key is needed before connecting to MonsterInsights.", "google-analytics-for-wordpress");
    const text_custom_tracking_tag_label = __("Custom Google Tag ID", "google-analytics-for-wordpress");
    const text_custom_tracking_tag_description = __("Enter in your own Google Tag ID if you're using Google Tag Manager to avoid an extra call.", "google-analytics-for-wordpress");
    const text_custom_tracking_mp_label = __("Custom Measurement Protocol Secret", "google-analytics-for-wordpress");
    const text_custom_tracking_mp_description = sprintf(
      __("Enter your provided Measurement Protocol Secret from Google Analytics to ensure proper tracking with your custom tag. You can find this %1$shere%2$s", "google-analytics-for-wordpress"),
      '<a href="' + getUrl("advanced-tracking", "settings", "https://www.monsterinsights.com/docs/how-to-create-your-measurement-protocol-api-secret-in-ga4/") + '" target="_blank">',
      "</a>"
    );
    const text_button_disconnect_label = computed(() => force_deauth.value ? __("Force Deauthenticate", "google-analytics-for-wordpress") : __("Disconnect MonsterInsights", "google-analytics-for-wordpress"));
    const is_licensed = computed(() => !!(license_network.value.type || license.value.type));
    const is_authed = computed(() => {
      if (is_network) return !!(license_network.value.type && auth.value.network_v4);
      return !!(license.value.type && auth.value.v4);
    });
    const show_manual_v4 = computed(() => is_network ? auth.value.network_manual_v4 : auth.value.manual_v4);
    const measurement_protocol_secret = computed(() => is_network ? auth.value.network_measurement_protocol_secret : auth.value.measurement_protocol_secret);
    const text_advanced_tracking_status = computed(() => {
      const custom_tag = settingsStore.getSettings.gtag_selector_tracking_tag;
      return custom_tag ? __("Active", "google-analytics-for-wordpress") : __("Not Active", "google-analytics-for-wordpress");
    });
    function iconClass(value) {
      let cls = "monstericon-arrow";
      if (value) cls += " monstericon-down";
      return cls;
    }
    async function doAuth(e) {
      e.preventDefault();
      if (!is_licensed.value) return;
      loadingToast(__("Authenticating", "google-analytics-for-wordpress"));
      try {
        const response = await authApi.getAuthRedirect(is_network);
        if (response.redirect || response.data?.redirect) {
          window.location = response.redirect || response.data.redirect;
        } else {
          closeToast();
          errorToast({ title: response.data?.message || __("Error", "google-analytics-for-wordpress") });
        }
      } catch (err) {
        closeToast();
        errorToast({
          title: err?.message || err?.title || __("Error getting auth redirect", "google-analytics-for-wordpress")
        });
      }
    }
    async function doReAuth(e) {
      e.preventDefault();
      if (!is_licensed.value) return;
      loadingToast(__("Re-Authenticating", "google-analytics-for-wordpress"));
      try {
        const response = await authApi.getReAuthRedirect(is_network);
        if (response.redirect || response.data?.redirect) {
          window.location = response.redirect || response.data.redirect;
        } else {
          closeToast();
          errorToast({ title: response.data?.message || __("Error", "google-analytics-for-wordpress") });
        }
      } catch (err) {
        closeToast();
        errorToast({
          title: err?.message || err?.title || __("Error getting re-auth redirect", "google-analytics-for-wordpress")
        });
      }
    }
    async function handleVerifyAuth(e) {
      e.preventDefault();
      if (!is_licensed.value) return;
      loadingToast(__("Verifying Credentials", "google-analytics-for-wordpress"));
      try {
        const response = await authApi.verifyAuth(is_network);
        closeToast();
        dialog.alert({
          variant: "success",
          title: response.message || __("Successfully verified.", "google-analytics-for-wordpress"),
          message: __("Your site is connected to MonsterInsights!", "google-analytics-for-wordpress"),
          confirmText: __("Ok", "google-analytics-for-wordpress")
        });
      } catch (err) {
        closeToast();
        dialog.alert({
          variant: "error",
          title: __("Error", "google-analytics-for-wordpress"),
          html: err?.message || __("Could not verify credentials.", "google-analytics-for-wordpress"),
          confirmText: __("Ok", "google-analytics-for-wordpress")
        });
      }
    }
    async function handleDeleteAuth(e) {
      e.preventDefault();
      if (!is_licensed.value) return;
      loadingToast(__("Deauthenticating", "google-analytics-for-wordpress"));
      try {
        const response = await authApi.deleteAuth(is_network, force_deauth.value);
        const freshAuth = await authApi.fetchAuth();
        if (freshAuth) auth.value = freshAuth;
        closeToast();
        dialog.alert({
          variant: "success",
          title: response.message || __("Disconnected", "google-analytics-for-wordpress"),
          message: __("You've disconnected your site from MonsterInsights. Your site is no longer being tracked by Google Analytics and you won't see reports anymore.", "google-analytics-for-wordpress"),
          confirmText: __("Ok", "google-analytics-for-wordpress")
        });
      } catch (err) {
        closeToast();
        dialog.alert({
          variant: "error",
          title: __("Error", "google-analytics-for-wordpress"),
          html: err?.message || __("Could not disconnect.", "google-analytics-for-wordpress"),
          confirmText: __("Ok", "google-analytics-for-wordpress")
        });
        force_deauth.value = true;
      }
    }
    function processActionResponse(response) {
      if (response.success === false || response === false) {
        if (response.data?.v4_error) has_v4_error.value = true;
        else has_v4_error.value = false;
        has_error.value = response.data?.error || true;
        errorToast({});
      } else {
        has_v4_error.value = false;
        has_error.value = false;
        successToast({});
      }
    }
    async function handleUpdateManualV4(e) {
      savingToast({});
      has_error.value = false;
      try {
        const response = await authApi.updateManualV4(e.target.value, is_network);
        processActionResponse(response);
      } catch {
        errorToast({});
      }
    }
    async function handleUpdateMeasurementProtocolSecret(e) {
      savingToast({});
      has_error.value = false;
      try {
        const response = await authApi.updateMeasurementProtocolSecret(e.target.value, is_network);
        processActionResponse(response);
      } catch {
        errorToast({});
      }
    }
    function toggleButtons(e) {
      e.preventDefault();
      show_buttons.value = !show_buttons.value;
    }
    function toggleAdvancedTracking(e) {
      e.preventDefault();
      show_advanced_tracking.value = !show_advanced_tracking.value;
    }
    function showManualClick() {
      show_manual_on_click.value = true;
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        auth.value.network_v4 && !unref(is_network) && !auth.value.v4 ? (openBlock(), createBlock(SettingsNetworkNotice, { key: 0 }, {
          default: withCtx(() => [
            createBaseVNode("strong", {
              textContent: toDisplayString(unref(text_auth_network))
            }, null, 8, _hoisted_2),
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_auth_network_2))
            }, null, 8, _hoisted_3)
          ]),
          _: 1
        })) : createCommentVNode("", true),
        !is_authed.value ? (openBlock(), createElementBlock("div", _hoisted_4, [
          createBaseVNode("span", {
            class: "monsterinsights-dark",
            textContent: toDisplayString(__props.label)
          }, null, 8, _hoisted_5),
          createBaseVNode("p", { innerHTML: __props.description }, null, 8, _hoisted_6),
          renderSlot(_ctx.$slots, "before_connect"),
          createBaseVNode("button", {
            class: normalizeClass(["monsterinsights-button", { "monsterinsights-button-disabled": !is_licensed.value }]),
            title: !is_licensed.value ? unref(text_needs_license) : "",
            onClick: doAuth,
            textContent: toDisplayString(unref(text_button_connect))
          }, null, 10, _hoisted_7),
          !show_manual_v4.value && !show_manual_on_click.value ? (openBlock(), createElementBlock("p", _hoisted_8, [
            createBaseVNode("a", {
              href: "#monsterinsights-auth-manual-ua-input",
              class: "monsterinsights-auth-manual-connect-text",
              onClick: showManualClick,
              innerHTML: unref(text_manual_connect)
            }, null, 8, _hoisted_9)
          ])) : createCommentVNode("", true)
        ])) : createCommentVNode("", true),
        show_manual_on_click.value || show_manual_v4.value ? (openBlock(), createElementBlock("div", _hoisted_10, [
          _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
          createBaseVNode("span", {
            class: "monsterinsights-dark",
            textContent: toDisplayString(unref(text_manual_v4_label))
          }, null, 8, _hoisted_11),
          createBaseVNode("p", { innerHTML: unref(text_manual_v4_description) }, null, 8, _hoisted_12),
          createBaseVNode("input", {
            id: "monsterinsights-auth-manual-v4-input",
            type: "text",
            class: "monsterinsights-manual-ua",
            value: unref(is_network) ? auth.value.network_manual_v4 : auth.value.manual_v4,
            onChange: handleUpdateManualV4
          }, null, 40, _hoisted_13),
          has_v4_error.value ? (openBlock(), createElementBlock("label", _hoisted_14, [
            _cache[0] || (_cache[0] = createBaseVNode("i", { class: "monstericon-warning-triangle" }, null, -1)),
            createBaseVNode("span", {
              innerHTML: unref(sanitizeHtml)(has_error.value)
            }, null, 8, _hoisted_15)
          ])) : createCommentVNode("", true),
          _cache[2] || (_cache[2] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
          createBaseVNode("label", {
            for: "monsterinsights-v4-measurement-protocol",
            class: "monsterinsights-dark",
            textContent: toDisplayString(unref(text_v4_measurement_protocol))
          }, null, 8, _hoisted_16),
          createBaseVNode("p", {
            class: "monsterinsights-field-description",
            innerHTML: unref(text_v4_measurement_protocol_description)
          }, null, 8, _hoisted_17),
          createBaseVNode("input", {
            id: "monsterinsights-v4-measurement-protocol",
            type: "text",
            class: "monsterinsights-manual-ua",
            value: measurement_protocol_secret.value,
            onChange: handleUpdateMeasurementProtocolSecret
          }, null, 40, _hoisted_18)
        ])) : createCommentVNode("", true),
        is_authed.value ? (openBlock(), createElementBlock("div", _hoisted_19, [
          createBaseVNode("span", {
            class: "monsterinsights-settings-input-toggle-collapsible",
            role: "button",
            onClick: toggleButtons,
            onKeyup: [
              withKeys(toggleButtons, ["enter"]),
              withKeys(toggleButtons, ["space"])
            ]
          }, [
            createBaseVNode("i", {
              class: normalizeClass(iconClass(show_buttons.value)),
              tabindex: "0",
              onkeypress: "if(event.keyCode==32||event.keyCode==13){return false;};"
            }, null, 2)
          ], 32),
          createBaseVNode("span", {
            class: "monsterinsights-dark",
            textContent: toDisplayString(unref(text_website_profile))
          }, null, 8, _hoisted_20),
          createBaseVNode("p", null, [
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_active_profile))
            }, null, 8, _hoisted_21),
            _cache[3] || (_cache[3] = createTextVNode(": ", -1)),
            createBaseVNode("span", {
              textContent: toDisplayString(unref(is_network) ? auth.value.network_viewname : auth.value.viewname)
            }, null, 8, _hoisted_22)
          ]),
          !has_credentials.value ? (openBlock(), createElementBlock("label", _hoisted_23, [
            _cache[4] || (_cache[4] = createBaseVNode("i", { class: "monstericon-warning-triangle" }, null, -1)),
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_missing_credentials))
            }, null, 8, _hoisted_24)
          ])) : createCommentVNode("", true),
          createVNode(SlideDownUp, null, {
            default: withCtx(() => [
              show_buttons.value ? (openBlock(), createElementBlock("div", _hoisted_25, [
                createBaseVNode("div", _hoisted_26, [
                  createBaseVNode("button", {
                    class: "monsterinsights-button",
                    onClick: doReAuth,
                    textContent: toDisplayString(unref(text_button_reconnect))
                  }, null, 8, _hoisted_27),
                  createBaseVNode("button", {
                    class: "monsterinsights-button monsterinsights-button-secondary",
                    onClick: handleVerifyAuth,
                    textContent: toDisplayString(unref(text_button_verify))
                  }, null, 8, _hoisted_28),
                  createBaseVNode("button", {
                    class: "monsterinsights-button monsterinsights-button-secondary",
                    onClick: handleDeleteAuth,
                    textContent: toDisplayString(text_button_disconnect_label.value)
                  }, null, 8, _hoisted_29)
                ])
              ])) : createCommentVNode("", true)
            ]),
            _: 1
          })
        ])) : createCommentVNode("", true),
        is_authed.value ? (openBlock(), createElementBlock("hr", _hoisted_30)) : createCommentVNode("", true),
        is_authed.value ? (openBlock(), createElementBlock("div", _hoisted_31, [
          createBaseVNode("span", {
            class: "monsterinsights-settings-input-toggle-collapsible",
            role: "button",
            onClick: toggleAdvancedTracking,
            onKeyup: [
              withKeys(toggleAdvancedTracking, ["enter"]),
              withKeys(toggleAdvancedTracking, ["space"])
            ]
          }, [
            createBaseVNode("i", {
              class: normalizeClass(iconClass(show_advanced_tracking.value)),
              tabindex: "0",
              onkeypress: "if(event.keyCode==32||event.keyCode==13){return false;};"
            }, null, 2)
          ], 32),
          createBaseVNode("span", {
            class: "monsterinsights-dark",
            textContent: toDisplayString(unref(text_advanced_tracking))
          }, null, 8, _hoisted_32),
          createBaseVNode("p", null, [
            createBaseVNode("span", {
              textContent: toDisplayString(text_advanced_tracking_status.value)
            }, null, 8, _hoisted_33)
          ]),
          createVNode(SlideDownUp, null, {
            default: withCtx(() => [
              show_advanced_tracking.value ? (openBlock(), createElementBlock("div", _hoisted_34, [
                _cache[5] || (_cache[5] = createBaseVNode("br", null, null, -1)),
                createVNode(SettingsInputText, {
                  label: unref(text_custom_tracking_tag_label),
                  description: unref(text_custom_tracking_tag_description),
                  name: "gtag_selector_tracking_tag"
                }, null, 8, ["label", "description"]),
                _cache[6] || (_cache[6] = createBaseVNode("br", null, null, -1)),
                createVNode(SettingsInputText, {
                  label: unref(text_custom_tracking_mp_label),
                  description: unref(text_custom_tracking_mp_description),
                  name: "gtag_selector_tracking_mp"
                }, null, 8, ["label", "description"])
              ])) : createCommentVNode("", true)
            ]),
            _: 1
          })
        ])) : createCommentVNode("", true)
      ]);
    };
  }
};
export {
  _sfc_main as _
};
