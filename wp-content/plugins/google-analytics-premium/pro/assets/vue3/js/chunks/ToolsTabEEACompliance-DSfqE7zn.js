import { _ as _sfc_main$2 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$1 } from "./SettingsInfoTooltip-QCvq3iSl.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { d as useDialog } from "./addons-rxJejcjK.js";
import { g as getNonce, b as getAjaxUrl } from "./useNotices-CwGRydDs.js";
import { g as getMiGlobal, P as getMonsterInsightsUrl, z as onMounted, o as openBlock, c as createElementBlock, b as createVNode, E as withCtx, F as Fragment, a as createBaseVNode, t as toDisplayString, u as unref, B as createTextVNode, v as createCommentVNode, p as computed, l as ref } from "./AppOverlays-Dpqzx_3P.js";
import "./sanitize-CE5uy2g6.js";
import "./Modal-D1CHxx1Y.js";
const _hoisted_1 = { class: "monsterinsights-settings-content monsterinsights-tools-eea-compliance" };
const _hoisted_2 = { class: "monsterinsights-tools-eea-checker-criteria" };
const _hoisted_3 = { class: "monsterinsights-tools-eea-checker-compliant-status" };
const _hoisted_4 = { class: "monsterinsights-tools-eea-checker-compliant" };
const _hoisted_5 = {
  key: 0,
  width: "20",
  height: "20",
  viewBox: "0 0 20 20",
  fill: "none"
};
const _hoisted_6 = {
  key: 1,
  width: "20",
  height: "20",
  viewBox: "0 0 32 32"
};
const _hoisted_7 = ["innerHTML"];
const _hoisted_8 = ["innerHTML"];
const _hoisted_9 = ["disabled", "textContent"];
const _hoisted_10 = ["textContent"];
const _sfc_main = {
  __name: "ToolsTabEEACompliance",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const settingsStore = useSettingsStore();
    const dialog = useDialog();
    const authed = !!getMiGlobal("authed", false);
    const text_eea_compliance = __("EEA Compliance", "google-analytics-premium");
    const text_required_adlink = __("Required By Ads Link", "google-analytics-premium");
    const text_required_google_signals = __("Required by Google Signals use", "google-analytics-premium");
    const text_required_ad_plugin = __("Required by Ads Use", "google-analytics-premium");
    const text_yes = __("Yes", "google-analytics-premium");
    const text_no = __("No", "google-analytics-premium");
    const text_check = __("Check", "google-analytics-premium");
    const text_based_upon_your_current = sprintf(
      __("Based upon your current ads and analytics settings, you may need to use a Consent Management Platform (CMP) plugin. See which %1$splatforms work with MonsterInsights%2$s.", "google-analytics-premium"),
      '<a target="_blank" href="' + getMonsterInsightsUrl("tools_eea_checker", "compliance_fail", "https://www.monsterinsights.com/docs/cookie-consent-plugins-gdpr-compliance/") + '">',
      "</a>"
    );
    const text_changing_privacy_laws = __("Changing privacy laws now require website visitors from EEA based countries to provide consent in order to use personalized advertising or data modeling with either Google Ads & Analytics.", "google-analytics-premium");
    const text_our_one_click_eea_scanner = __("Our one click EEA scanner will indicate whether your website is compliant or if you might need to use a CMP (Consent Management Platform).", "google-analytics-premium");
    const text_not_connected = __("You must connect with MonsterInsights before you can check EEA Compliance.", "google-analytics-premium");
    const text_required_adlink_tooltip = __("MonsterInsights will check to see if your Analytics profile is connected to an Ads account.", "google-analytics-premium");
    const text_required_ad_plugin_tooltip = __("MonsterInsights will check to see if you have our PPC addon installed and activated.", "google-analytics-premium");
    const text_required_google_signals_tooltip = __("MonsterInsights will check your Google Analytics settings to verify if Google Signals is enabled.", "google-analytics-premium");
    const text_last_result_from = __("Last Result From", "google-analytics-premium");
    const text_currently_there_is_not = sprintf(
      __("We have not detected a requirement to use a Consent Management Tool for EEA compliance. If you'd like to learn more about this or other privacy regulations %1$splease read our tutorial%2$s.", "google-analytics-premium"),
      '<a target="_blank" href="' + getMonsterInsightsUrl("tools_eea_checker", "compliance_success", "https://www.monsterinsights.com/docs/cookie-consent-plugins-gdpr-compliance/") + '">',
      "</a>"
    );
    const checking = ref(false);
    const checkerData = computed(() => settingsStore.EEACheckerData || {});
    const getButtonText = computed(() => {
      if (checking.value) return __("Checking...", "google-analytics-premium");
      if (checkerData.value.last_checked && checkerData.value.last_checked !== "Never") {
        return __("Recheck", "google-analytics-premium");
      }
      return __("Check", "google-analytics-premium");
    });
    async function postToAjax(action) {
      const params = new URLSearchParams();
      params.append("action", action);
      params.append("nonce", getNonce());
      const response = await fetch(getAjaxUrl(), {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: params.toString()
      });
      if (!response.ok) {
        throw { message: `${response.status} ${response.statusText}` };
      }
      return response.json();
    }
    async function runCheck() {
      if (checking.value) return;
      checking.value = true;
      try {
        const json = await postToAjax("monsterinsights_vue_check_eea_compliance");
        if (json && json.success && json.data) {
          settingsStore.updateEEACheckerData(json.data);
        } else if (json && json.error_message) {
          dialog.alert({
            variant: "error",
            title: __("EEA Checker Error", "google-analytics-premium"),
            message: json.error_message
          });
        }
      } catch (_e) {
      } finally {
        checking.value = false;
      }
    }
    async function getCheck() {
      if (checkerData.value.loaded) return;
      try {
        const json = await postToAjax("monsterinsights_vue_get_eea_compliance");
        if (json && json.success && json.data) {
          settingsStore.updateEEACheckerData(json.data);
        }
      } catch (_e) {
      }
    }
    onMounted(() => {
      if (authed) getCheck();
    });
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        createVNode(_sfc_main$2, null, {
          "expired-license-tag": withCtx(() => [
            createBaseVNode("span", null, toDisplayString(unref(text_eea_compliance)), 1)
          ]),
          default: withCtx(() => [
            authed ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
              createBaseVNode("p", null, toDisplayString(unref(text_changing_privacy_laws)), 1),
              createBaseVNode("p", null, toDisplayString(unref(text_our_one_click_eea_scanner)), 1),
              createBaseVNode("div", _hoisted_2, [
                createBaseVNode("p", null, [
                  createBaseVNode("strong", null, toDisplayString(unref(text_required_adlink)), 1),
                  createVNode(_sfc_main$1, { content: unref(text_required_adlink_tooltip) }, null, 8, ["content"]),
                  createTextVNode(" : " + toDisplayString(checkerData.value.ga_checker?.["ad-links"] ? unref(text_yes) : unref(text_no)), 1)
                ]),
                createBaseVNode("p", null, [
                  createBaseVNode("strong", null, toDisplayString(unref(text_required_ad_plugin)), 1),
                  createVNode(_sfc_main$1, { content: unref(text_required_ad_plugin_tooltip) }, null, 8, ["content"]),
                  createTextVNode(" : " + toDisplayString(checkerData.value.ga_checker?.["ad-addon"] ? unref(text_yes) : unref(text_no)), 1)
                ]),
                createBaseVNode("p", null, [
                  createBaseVNode("strong", null, toDisplayString(unref(text_required_google_signals)), 1),
                  createVNode(_sfc_main$1, { content: unref(text_required_google_signals_tooltip) }, null, 8, ["content"]),
                  createTextVNode(" : " + toDisplayString(checkerData.value.ga_checker?.["google-signals"] ? unref(text_yes) : unref(text_no)), 1)
                ]),
                checkerData.value.last_checked && checkerData.value.last_checked !== "Never" ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
                  createBaseVNode("p", _hoisted_3, [
                    createBaseVNode("span", _hoisted_4, [
                      createBaseVNode("strong", null, toDisplayString(unref(text_check)), 1),
                      _cache[2] || (_cache[2] = createTextVNode(": ", -1)),
                      checkerData.value.compliant ? (openBlock(), createElementBlock("svg", _hoisted_5, [..._cache[0] || (_cache[0] = [
                        createBaseVNode("path", {
                          fill: "#008000",
                          d: "M10 0C4.488 0 0 4.488 0 10C0 15.512 4.488 20 10 20C15.512 20 20 15.512 20 10C20 4.488 15.512 0 10 0ZM15.424 7.368L8.456 14.512L4.576 10.536C4.16 10.104 4.16 9.424 4.576 8.992C5.008 8.544 5.728 8.544 6.16 8.992L8.456 11.344L13.84 5.824C14.272 5.376 14.992 5.376 15.424 5.824C15.84 6.256 15.84 6.936 15.424 7.368Z"
                        }, null, -1)
                      ])])) : (openBlock(), createElementBlock("svg", _hoisted_6, [..._cache[1] || (_cache[1] = [
                        createBaseVNode("g", {
                          stroke: "none",
                          "stroke-width": "1",
                          fill: "none",
                          "fill-rule": "evenodd"
                        }, [
                          createBaseVNode("g", {
                            transform: "translate(-570.000000, -1089.000000)",
                            fill: "#d63638"
                          }, [
                            createBaseVNode("path", { d: "M591.657,1109.24 C592.048,1109.63 592.048,1110.27 591.657,1110.66 C591.267,1111.05 590.633,1111.05 590.242,1110.66 L586.006,1106.42 L581.74,1110.69 C581.346,1111.08 580.708,1111.08 580.314,1110.69 C579.921,1110.29 579.921,1109.65 580.314,1109.26 L584.58,1104.99 L580.344,1100.76 C579.953,1100.37 579.953,1099.73 580.344,1099.34 C580.733,1098.95 581.367,1098.95 581.758,1099.34 L585.994,1103.58 L590.292,1099.28 C590.686,1098.89 591.323,1098.89 591.717,1099.28 C592.11,1099.68 592.11,1100.31 591.717,1100.71 L587.42,1105.01 L591.657,1109.24 L591.657,1109.24 Z M586,1089 C577.163,1089 570,1096.16 570,1105 C570,1113.84 577.163,1121 586,1121 C594.837,1121 602,1113.84 602,1105 C602,1096.16 594.837,1089 586,1089 L586,1089 Z" })
                          ])
                        ], -1)
                      ])]))
                    ]),
                    createBaseVNode("span", null, " -  " + toDisplayString(unref(text_last_result_from)) + ": " + toDisplayString(checkerData.value.last_checked), 1)
                  ]),
                  checkerData.value.compliant ? (openBlock(), createElementBlock("p", {
                    key: 0,
                    innerHTML: unref(text_currently_there_is_not)
                  }, null, 8, _hoisted_7)) : (openBlock(), createElementBlock("p", {
                    key: 1,
                    innerHTML: unref(text_based_upon_your_current)
                  }, null, 8, _hoisted_8))
                ], 64)) : createCommentVNode("", true)
              ]),
              createBaseVNode("div", null, [
                createBaseVNode("button", {
                  class: "monsterinsights-button",
                  disabled: checking.value,
                  onClick: runCheck,
                  textContent: toDisplayString(getButtonText.value)
                }, null, 8, _hoisted_9)
              ])
            ], 64)) : (openBlock(), createElementBlock("p", {
              key: 1,
              class: "monsterinsights-dark",
              textContent: toDisplayString(unref(text_not_connected))
            }, null, 8, _hoisted_10))
          ]),
          _: 1
        })
      ]);
    };
  }
};
export {
  _sfc_main as default
};
