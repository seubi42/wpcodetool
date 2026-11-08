import { o as openBlock, c as createElementBlock, b as createVNode, E as withCtx, a as createBaseVNode, t as toDisplayString, G as createBlock, C as withModifiers, w as withDirectives, x as vModelText, v as createCommentVNode, p as computed } from "./AppOverlays-Dpqzx_3P.js";
import { _ as _sfc_main$3 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$1 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { _ as _sfc_main$2 } from "./SettingsInputRadio-BxhcBMU_.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./sanitize-CE5uy2g6.js";
import "./globals-C78yxw47.js";
import "./addons-rxJejcjK.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./license-D2vquWPE.js";
import "./SettingsInfoTooltip-QCvq3iSl.js";
const _hoisted_1 = { class: "monsterinsights-settings-content monsterinsights-tools-url-builder" };
const _hoisted_2 = ["textContent"];
const _hoisted_3 = ["textContent"];
const _hoisted_4 = ["textContent"];
const _hoisted_5 = ["textContent"];
const _hoisted_6 = ["textContent"];
const _hoisted_7 = ["textContent"];
const _hoisted_8 = ["textContent"];
const _hoisted_9 = ["textContent"];
const _hoisted_10 = { class: "monsterinsights-shortcode" };
const _hoisted_11 = ["textContent"];
const _sfc_main = {
  __name: "ToolsTabVerifiedBadge",
  setup(__props) {
    const { __ } = wp.i18n;
    const settingsStore = useSettingsStore();
    function getSetting(key, defaultValue = null) {
      const settings = settingsStore.settings || {};
      return Object.hasOwn(settings, key) ? settings[key] : defaultValue;
    }
    const texts = {
      automatic_main_label: __("Automatic Display", "google-analytics-premium"),
      automatic_heading: __("Automatically Display Website Badge in Website Footer", "google-analytics-premium"),
      automatic_subheading: __("Enabling will add the chosen badge in your website’s footer.", "google-analytics-premium"),
      automatic_enable: __("Enable Automatic Display", "google-analytics-premium"),
      appearance_label: __("Appearance", "google-analytics-premium"),
      appearance_heading: __("Choose your badge style.", "google-analytics-premium"),
      position_label: __("Position", "google-analytics-premium"),
      position_heading: __("Select the position of the badge in your website’s footer.", "google-analytics-premium"),
      manual_main_label: __("Manual Display", "google-analytics-premium"),
      manual_heading: __("Manual Display", "google-analytics-premium"),
      manual_subheading: __("Manual Display", "google-analytics-premium"),
      copy: __("Copy", "google-analytics-premium")
    };
    const appearance_options = [
      { value: "light", label: __("Light", "google-analytics-premium") },
      { value: "dark", label: __("Dark", "google-analytics-premium") }
    ];
    const position_options = [
      { value: "left", label: __("Left", "google-analytics-premium") },
      { value: "center", label: __("Center", "google-analytics-premium") },
      { value: "right", label: __("Right", "google-analytics-premium") }
    ];
    const shortcodeText = computed(() => {
      const appearance = getSetting("verified_appearance", "light");
      const position = getSetting("verified_position", "left");
      return '[monsterinsights-badge appearance="' + appearance + '" position="' + position + '"/]';
    });
    function copyToClipboard() {
      const el = document.querySelector("#monsterinsights_shortcode");
      if (!el) return;
      el.select();
      document.execCommand("copy");
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        createVNode(_sfc_main$3, {
          title: texts.automatic_main_label
        }, {
          default: withCtx(() => [
            createBaseVNode("div", {
              class: "monsterinsights-dark",
              textContent: toDisplayString(texts.automatic_heading)
            }, null, 8, _hoisted_2),
            createBaseVNode("p", {
              textContent: toDisplayString(texts.automatic_subheading)
            }, null, 8, _hoisted_3),
            createVNode(_sfc_main$1, {
              name: "verified_automatic",
              label: texts.automatic_enable
            }, null, 8, ["label"]),
            _cache[3] || (_cache[3] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createBaseVNode("div", {
              class: "monsterinsights-dark",
              textContent: toDisplayString(texts.appearance_label)
            }, null, 8, _hoisted_4),
            createBaseVNode("p", {
              textContent: toDisplayString(texts.appearance_heading)
            }, null, 8, _hoisted_5),
            createVNode(_sfc_main$2, {
              options: appearance_options,
              name: "verified_appearance",
              classes: "badge-appearance-container"
            }, {
              light: withCtx(() => [..._cache[1] || (_cache[1] = [
                createBaseVNode("div", { class: "monsterinsights-badge-light" }, null, -1)
              ])]),
              dark: withCtx(() => [..._cache[2] || (_cache[2] = [
                createBaseVNode("div", { class: "monsterinsights-badge-dark" }, null, -1)
              ])]),
              _: 1
            }),
            _cache[4] || (_cache[4] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createBaseVNode("div", {
              class: "monsterinsights-dark",
              textContent: toDisplayString(texts.position_label)
            }, null, 8, _hoisted_6),
            createBaseVNode("p", {
              textContent: toDisplayString(texts.position_heading)
            }, null, 8, _hoisted_7),
            createVNode(_sfc_main$2, {
              options: position_options,
              name: "verified_position"
            })
          ]),
          _: 1
        }, 8, ["title"]),
        !getSetting("verified_automatic") ? (openBlock(), createBlock(_sfc_main$3, {
          key: 0,
          title: texts.manual_main_label
        }, {
          default: withCtx(() => [
            createBaseVNode("div", {
              class: "monsterinsights-dark",
              textContent: toDisplayString(texts.manual_heading)
            }, null, 8, _hoisted_8),
            createBaseVNode("p", {
              textContent: toDisplayString(texts.manual_subheading)
            }, null, 8, _hoisted_9),
            createBaseVNode("div", _hoisted_10, [
              createBaseVNode("a", {
                href: "#",
                onClick: withModifiers(copyToClipboard, ["prevent"]),
                textContent: toDisplayString(texts.copy)
              }, null, 8, _hoisted_11),
              withDirectives(createBaseVNode("input", {
                id: "monsterinsights_shortcode",
                "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => shortcodeText.value = $event),
                type: "text",
                readonly: ""
              }, null, 512), [
                [vModelText, shortcodeText.value]
              ])
            ])
          ]),
          _: 1
        }, 8, ["title"])) : createCommentVNode("", true)
      ]);
    };
  }
};
export {
  _sfc_main as default
};
