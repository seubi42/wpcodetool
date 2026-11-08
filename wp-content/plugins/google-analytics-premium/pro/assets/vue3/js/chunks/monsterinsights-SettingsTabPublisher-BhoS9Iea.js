import { d as getMiGlobal, k as getUrl } from "./globals-C78yxw47.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { c as useAddonsStore } from "./addons-rxJejcjK.js";
import { _ as _sfc_main$5 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$7 } from "./SettingsInputRepeater-C3ckM9GQ.js";
import { _ as _sfc_main$6 } from "./SettingsInfoTooltip-QCvq3iSl.js";
import { _ as _sfc_main$3 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { _ as _sfc_main$4 } from "./SettingsAddonDisabled-CJebef83.js";
import { o as openBlock, G as createBlock, E as withCtx, u as unref, a as createBaseVNode, p as computed, c as createElementBlock, F as Fragment, b as createVNode, S as _export_sfc, t as toDisplayString, v as createCommentVNode } from "./AppOverlays-Dpqzx_3P.js";
import { _ as _sfc_main$8 } from "./SettingsInputRadio-BxhcBMU_.js";
import { U as UniversallyPromo } from "./UniversallyPromo-zip1hHZv.js";
import "./sanitize-CE5uy2g6.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./license-D2vquWPE.js";
import "./default-i18n-KrIlCc2E.js";
const _hoisted_1$2 = ["innerHTML"];
const _hoisted_2$1 = ["innerHTML"];
const _sfc_main$2 = {
  __name: "monsterinsights-SettingsInputAmp-Pro",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const isEM = false;
    const addonsStore = useAddonsStore();
    const addons = computed(() => addonsStore.addons || {});
    function isAddonActive(addon) {
      if (addons.value[addon]) {
        return addons.value[addon].active;
      }
      return false;
    }
    const install_amp_url = getMiGlobal("install_amp_url", "#");
    const addons_url = getMiGlobal("addons_url", "#");
    const text_amp_title = __("Google AMP", "google-analytics-for-wordpress");
    const text_amp_label = __("AMP setup has been detected and tracking is occurring automatically. No setup required.", "google-analytics-for-wordpress");
    const text_amp_disabled = sprintf(
      __("In order to use this feature you need to %1$sinstall the official Google-developed AMP plugin for WordPress.%2$s", "google-analytics-for-wordpress"),
      '<a href="' + install_amp_url + '">',
      "</a>"
    );
    const text_amp_addon_disabled = sprintf(
      __("In order to use this feature you need to %1$sinstall & activate the MonsterInsights AMP Addon.%2$s", "google-analytics-for-wordpress"),
      '<a href="' + addons_url + '">',
      "</a>"
    );
    return (_ctx, _cache) => {
      return openBlock(), createBlock(_sfc_main$5, {
        title: unref(text_amp_title),
        icon: "",
        collapsible: isEM
      }, {
        default: withCtx(() => [
          isAddonActive("amp") && isAddonActive("google_amp") ? (openBlock(), createBlock(_sfc_main$3, {
            key: 0,
            faux: true,
            label: unref(text_amp_label)
          }, null, 8, ["label"])) : isAddonActive("amp") ? (openBlock(), createBlock(_sfc_main$4, { key: 1 }, {
            default: withCtx(() => [
              createBaseVNode("span", { innerHTML: unref(text_amp_disabled) }, null, 8, _hoisted_1$2)
            ]),
            _: 1
          })) : (openBlock(), createBlock(_sfc_main$4, { key: 2 }, {
            default: withCtx(() => [
              createBaseVNode("span", { innerHTML: unref(text_amp_addon_disabled) }, null, 8, _hoisted_2$1)
            ]),
            _: 1
          }))
        ]),
        _: 1
      }, 8, ["title", "icon"]);
    };
  }
};
const _hoisted_1$1 = ["innerHTML"];
const _sfc_main$1 = {
  __name: "monsterinsights-SettingsInputMedia-Pro",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const isEM = false;
    const addonsStore = useAddonsStore();
    const addons = computed(() => addonsStore.addons || {});
    function isAddonActive(addon) {
      if (addons.value[addon]) {
        return addons.value[addon].active;
      }
      return false;
    }
    const addons_url = getMiGlobal("addons_url", "#");
    const text_media_title = __("Media Tracking", "google-analytics-for-wordpress");
    const text_label_html_videos = __("Enable tracking for HTML5 videos.", "google-analytics-for-wordpress");
    const text_label_youtube_videos = __("Enable tracking for embedded YouTube videos.", "google-analytics-for-wordpress");
    const text_label_vimeo_videos = __("Enable tracking for embedded Vimeo videos.", "google-analytics-for-wordpress");
    const text_media_video_faux_tooltip = __("Deactivate the Media addon to disable video tracking", "google-analytics-for-wordpress");
    const text_media_video_faux_tooltip_disabled = __("Install and activate the Media addon to enable video tracking", "google-analytics-for-wordpress");
    const text_media_addon_disabled = sprintf(
      __("Gain insights into how popular and engaging your videos are on your website with completion rates, watch time, and more. Works automatically with embedded YouTube, Vimeo, and HTML5 videos. %1$sInstall & Activate Now.%2$s", "google-analytics-for-wordpress"),
      '<a href="' + addons_url + '">',
      "</a>"
    );
    return (_ctx, _cache) => {
      return openBlock(), createBlock(_sfc_main$5, {
        title: unref(text_media_title),
        icon: "",
        collapsible: isEM
      }, {
        default: withCtx(() => [
          isAddonActive("media") ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
            createVNode(_sfc_main$3, {
              faux: true,
              default: isAddonActive("media"),
              label: unref(text_label_html_videos),
              faux_tooltip: unref(text_media_video_faux_tooltip),
              faux_tooltip_off: unref(text_media_video_faux_tooltip_disabled)
            }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
            _cache[0] || (_cache[0] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createVNode(_sfc_main$3, {
              faux: true,
              default: isAddonActive("media"),
              label: unref(text_label_youtube_videos),
              faux_tooltip: unref(text_media_video_faux_tooltip),
              faux_tooltip_off: unref(text_media_video_faux_tooltip_disabled)
            }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
            _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createVNode(_sfc_main$3, {
              faux: true,
              default: isAddonActive("media"),
              label: unref(text_label_vimeo_videos),
              faux_tooltip: unref(text_media_video_faux_tooltip),
              faux_tooltip_off: unref(text_media_video_faux_tooltip_disabled)
            }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"])
          ], 64)) : (openBlock(), createBlock(_sfc_main$4, { key: 1 }, {
            default: withCtx(() => [
              createBaseVNode("span", { innerHTML: unref(text_media_addon_disabled) }, null, 8, _hoisted_1$1)
            ]),
            _: 1
          }))
        ]),
        _: 1
      }, 8, ["title", "icon"]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-settings-content settings-publisher" };
const _hoisted_2 = ["innerHTML"];
const _hoisted_3 = { class: "monsterinsights-settings-block-inner-field-label" };
const _hoisted_4 = { class: "monsterinsights-dark" };
const _hoisted_5 = ["innerHTML"];
const _sfc_main = {
  __name: "monsterinsights-SettingsTabPublisher",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    useSettingsStore();
    const addonsStore = useAddonsStore();
    const addons = computed(() => addonsStore.addons || {});
    const site_url = getMiGlobal("site_url", "");
    const repeater_structure = [
      {
        name: "path",
        // Translators: Example path (/go/).
        label: sprintf(__("Path (example: %s)", "google-analytics-for-wordpress"), "/go/"),
        pattern: /^\/\S+$/,
        error: __("Path has to start with a / and have no spaces", "google-analytics-for-wordpress")
      },
      {
        name: "label",
        // Translators: Example label (aff).
        label: sprintf(__("Label (example: %s)", "google-analytics-for-wordpress"), "aff"),
        pattern: /^\S+$/,
        error: __("Label can't contain any spaces", "google-analytics-for-wordpress")
      }
    ];
    const text_affiliate_title = __("Affiliate Links", "google-analytics-for-wordpress");
    const text_affiliate_description_tooltip = sprintf(
      __("Enable tracking for your custom affiliate links to see how they perform. By setting a path like '/go/,' any URL starting with this will be tracked. In Google Analytics, these links are uniquely labeled with 'outbound-link-' followed by your custom label, helping you identify them easily. Read %1$shere%2$s for a detailed guide on setting up affiliate link tracking.", "google-analytics-for-wordpress"),
      '<a href="' + getUrl("settings-panel", "publisher-tab", "https://www.monsterinsights.com/how-to-set-up-affiliate-link-tracking-in-wordpress/") + '" target="_blank">',
      "</a>"
    );
    const text_affiliate_repeater_description = __("Our affiliate link tracking works by setting path for internal links to track as outbound links.", "google-analytics-for-wordpress");
    const text_headline_analyzer = __("Headline Analyzer", "google-analytics-for-wordpress");
    const text_headline_analyzer_description = __("The MonsterInsights Headline Analyzer tool in the Gutenberg editor enables you to write irresistible SEO-friendly headlines that drive traffic, social media shares, and rank better in search results.", "google-analytics-for-wordpress");
    const text_disable_headline_analyzer = __("Disable the Headline Analyzer", "google-analytics-for-wordpress");
    const text_pretty_links_settings = __("Pretty Links Tracking Settings", "google-analytics-for-wordpress");
    const text_pretty_links_settings_description = __("Select how you want MonsterInsights to track Pretty Links URLs.", "google-analytics-for-wordpress");
    const prettyLinksoptions = [
      {
        value: "target_url",
        label: "Target URL - https://affiliatewebsite.com/productName/&trackingparmeters=123xyz"
      },
      {
        value: "pretty_link",
        label: "Pretty Link URL - " + site_url + "/productName"
      }
    ];
    const isLoadHeadlineAnalyzerSettings = computed(() => {
      if ("false" === getMiGlobal("load_headline_analyzer_settings")) {
        return false;
      }
      return true;
    });
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("main", _hoisted_1, [
        createVNode(_sfc_main$5, { title: unref(text_affiliate_title) }, {
          default: withCtx(() => [
            createBaseVNode("p", null, [
              createBaseVNode("span", { innerHTML: unref(text_affiliate_repeater_description) }, null, 8, _hoisted_2),
              createVNode(_sfc_main$6, { content: unref(text_affiliate_description_tooltip) }, null, 8, ["content"])
            ]),
            createVNode(_sfc_main$7, {
              structure: repeater_structure,
              name: "affiliate_links"
            }),
            addons.value && addons.value["pretty-link"] && addons.value["pretty-link"].active ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
              _cache[0] || (_cache[0] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
              createBaseVNode("p", _hoisted_3, [
                createBaseVNode("span", _hoisted_4, toDisplayString(unref(text_pretty_links_settings)), 1),
                createBaseVNode("span", null, toDisplayString(unref(text_pretty_links_settings_description)), 1)
              ]),
              createVNode(_sfc_main$8, {
                options: prettyLinksoptions,
                name: "pretty_links_backend_track"
              })
            ], 64)) : createCommentVNode("", true),
            createVNode(UniversallyPromo, {
              class: "monsterinsights-publisher-settings__universally-promo",
              "promo-id": "universally_affiliate_links",
              "show-info": "",
              text: unref(__)("Make more money by translating your website in seconds.", "google-analytics-for-wordpress"),
              "link-text": unref(__)("Install Universally", "google-analytics-for-wordpress")
            }, null, 8, ["text", "link-text"])
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$2),
        createVNode(_sfc_main$1),
        isLoadHeadlineAnalyzerSettings.value ? (openBlock(), createBlock(_sfc_main$5, {
          key: 0,
          title: unref(text_headline_analyzer)
        }, {
          default: withCtx(() => [
            createBaseVNode("p", null, [
              createBaseVNode("span", { innerHTML: unref(text_headline_analyzer_description) }, null, 8, _hoisted_5)
            ]),
            createVNode(_sfc_main$3, {
              name: "disable_headline_analyzer",
              label: unref(text_disable_headline_analyzer)
            }, null, 8, ["label"])
          ]),
          _: 1
        }, 8, ["title"])) : createCommentVNode("", true)
      ]);
    };
  }
};
const monsterinsightsSettingsTabPublisher = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-22b275e4"]]);
export {
  monsterinsightsSettingsTabPublisher as default
};
