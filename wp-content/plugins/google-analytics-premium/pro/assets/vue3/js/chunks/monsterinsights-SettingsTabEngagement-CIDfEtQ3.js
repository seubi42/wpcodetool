import { d as getMiGlobal, k as getUrl } from "./globals-C78yxw47.js";
import { c as useAddonsStore } from "./addons-rxJejcjK.js";
import { _ as _sfc_main$6 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$4 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { S as SettingsInputText } from "./SettingsInputText-IF6LAjxY.js";
import { _ as _sfc_main$7 } from "./SettingsInputRepeater-C3ckM9GQ.js";
import { _ as _sfc_main$5 } from "./SettingsAddonDisabled-CJebef83.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { S as _export_sfc, o as openBlock, c as createElementBlock, b as createVNode, E as withCtx, a as createBaseVNode, u as unref, p as computed, G as createBlock, v as createCommentVNode } from "./AppOverlays-Dpqzx_3P.js";
import "./sanitize-CE5uy2g6.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./license-D2vquWPE.js";
import "./SettingsInfoTooltip-QCvq3iSl.js";
const _hoisted_1$2 = { class: "settings-input settings-input-eu-compliance" };
const _hoisted_2$1 = { key: 0 };
const _hoisted_3$1 = ["innerHTML"];
const _hoisted_4 = ["innerHTML"];
const _hoisted_5 = ["innerHTML"];
const _hoisted_6 = ["innerHTML"];
const _hoisted_7 = ["innerHTML"];
const _hoisted_8 = ["innerHTML"];
const _hoisted_9 = ["innerHTML"];
const _hoisted_10 = { key: 1 };
const _hoisted_11 = ["innerHTML"];
const _sfc_main$3 = {
  __name: "SettingsInputEUCompliance-Pro",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    useSettingsStore();
    const addonsStore = useAddonsStore();
    const addons = computed(() => addonsStore.addons);
    const addons_url = getMiGlobal("addons_url");
    const text_addon_inactive = sprintf(__("Make your website more compliant with privacy regulations like GDPR, CCPA, and PECR. %1$sInstall and Activate%2$s Now.", "google-analytics-for-wordpress"), '<a href="' + addons_url + '">', "</a>");
    const text_addon_description = sprintf(__("MonsterInsights's EU Compliance addon can help you comply with the latest EU regulations like GDPR by automatically performing configuration changes and integrating with compatible plugins and services. For the latest recommendations, we recommend reading the EU Compliance addon %1$sGetting Started Guide%2$s for step by step directions on any needed configuration changes.", "google-analytics-for-wordpress"), '<a href="https://www.monsterinsights.com/docs/getting-started-with-the-eu-compliance-addon">', "</a>");
    const text_label_eu_compliance = __("Enable EU Compliance", "google-analytics-for-wordpress");
    const text_label_anonymize_ip = __("Anonymize IP addresses for Google Analytics hits.", "google-analytics-for-wordpress");
    const text_label_disable_demographics = __("Disable Demographics and Interests Reports for Remarketing and Advertising.", "google-analytics-for-wordpress");
    const text_label_user_id = __("Disable UserID tracking.", "google-analytics-for-wordpress");
    const text_label_author = __("Disable Author tracking.", "google-analytics-for-wordpress");
    const text_label_integrate_cookie_notice = __("Integrate with the Cookie Notice plugin.", "google-analytics-for-wordpress");
    const text_label_integrate_cookiebot = __("Integrate with the CookieBot plugin.", "google-analytics-for-wordpress");
    const text_label_integrate_complianz = __("Integrate with the Complianz plugin.", "google-analytics-for-wordpress");
    const text_label_integrate_cookie_yes = __("Integrate with the CookieYes plugin.", "google-analytics-for-wordpress");
    const text_label_integrate_consent_manager = __("Integrate with the Consent Manager plugin.", "google-analytics-for-wordpress");
    const text_compliance_plugins_title = __("Compliance plugins integration", "google-analytics-for-wordpress");
    const text_compliance_plugins_description = __("The EU Compliance Addon allows for integration with other plugins to have MonsterInsights wait to track a user into Google Analytics until consent is given. <br>If you install any of the following plugins, and activate it, the EU Compliance addon will automatically perform all required tasks to make our tracking code compatible with it for you. No code changes are required to the MonsterInsights plugin code. <br><b>Note:</b> having MonsterInsights wait until consent is given to load Google Analytics might alter your Google Analytics data completeness and accuracy.", "google-analytics-for-wordpress");
    const text_changes_title = __("Manual Google Analytics Account Changes Required", "google-analytics-for-wordpress");
    const text_changes_description = __("This addon automates a lot of the needed changes for EU compliance, however it cannot alter your Google Analytics account, and some configuration changes might be needed.", "google-analytics-for-wordpress");
    const text_disclaimer_title = __("Legal Disclaimer", "google-analytics-for-wordpress");
    const text_disclaimer_description = __("This addon is designed to automate some of the settings change required to be in compliance with various EU laws however due to the dynamic nature of websites, no plugin can offer 100 percent legal compliance. Please consult a specialist internet law attorney to determine if you are in compliance with all applicable laws for your jurisdictions and your use cases.\n As a website operator, it is solely your responsibility to ensure that you are in compliance with all applicable laws and regulations governing your use of our plugin.\n MonsterInsights, its employees/contractors, and other affiliated parties are not lawyers. Any advice given in our support, documentation, website, other mediums or through our services/products should not be considered legal advice and is for informational and/or educational purposes only and are not guaranteed to be correct, complete or up-to-date, and do not constitute creating/entering an Attorney-Client relationship.", "google-analytics-for-wordpress");
    const text_tooltip_anonymize_ip = __("This feature anonymizes all IP addresses for Google Analytics, eCommerce, and form tracking hits.", "google-analytics-for-wordpress");
    const text_tooltip_disable_demographics = __("This feature disables Demographics and Interests Reports for Remarketing and Advertising tracking on Google Analytics hits. You should also disable data sharing in your Google Analytics account.", "google-analytics-for-wordpress");
    const text_tooltip_user_id = __("This feature disables UserID tracking on Google Analytics hits, eCommerce hits, form tracking hits, and the UserID dimension in the MonsterInsights Custom Dimensions addon.", "google-analytics-for-wordpress");
    const text_tooltip_author = __("This feature disables the author dimension in the MonsterInsights Custom Dimensions addon.", "google-analytics-for-wordpress");
    const text_title_eu_compliance_title = __("EU Compliance", "google-analytics-for-wordpress");
    const text_faux_tooltip_anonymize_ip = __("Anonymize IP is automatically enabled by the EU Compliance addon", "google-analytics-for-wordpress");
    const text_faux_tooltip_demographics = __("Demographics and Interests Reports are automatically disabled by the EU Compliance addon", "google-analytics-for-wordpress");
    const text_faux_tooltip_user_id = __("UserID is automatically disabled by the EU Compliance addon", "google-analytics-for-wordpress");
    const text_faux_tooltip_author = __("Author tracking is automatically disabled by the EU Compliance addon", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookie_notice = __("Deactivate the Cookie Notice plugin to disable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookie_notice_off = __("Activate the Cookie Notice plugin to enable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookiebot = __("Deactivate the CookieBot plugin to disable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookiebot_off = __("Activate the CookieBot plugin to enable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_complianz_off = __("Activate the Complianz plugin to enable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookie_yes = __("Deactivate the CookieYes plugin to disable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_cookie_yes_off = __("Activate the CookieYes plugin to enable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_consent_manager = __("Deactivate the Consent Manager plugin to disable", "google-analytics-for-wordpress");
    const text_faux_tooltip_integrate_consent_manager_off = __("Activate the Consent Manager plugin to enable", "google-analytics-for-wordpress");
    const text_amp_consent_label = __("Wait for AMP consent box?", "google-analytics-for-wordpress");
    const text_amp_consent_tooltip = sprintf(
      __("If you use an AMP Consent Box, turning this option on ensures MonsterInsights waits for permission before tracking any visits on AMP pages. If someone says 'yes' in the box, or if you set it to say 'yes' (fallback to true) automatically, then tracking starts. %1$sImportant:%2$s If you do not have an AMP Consent Box implemented (requires custom code), enabling this option will prevent MonsterInsights from tracking all AMP users.", "google-analytics-for-wordpress"),
      "<strong>",
      "</strong>"
    );
    const text_amp_addon_faux_tooltip = __("Deactivate the Google AMP Addon to disable", "google-analytics-for-wordpress");
    const isAddonActive = computed(() => {
      if (addons.value["eu-compliance"]) {
        return addons.value["eu-compliance"].active;
      }
      return false;
    });
    const isCookieNoticePluginEnabled = computed(() => {
      return addons.value["cookie_notice"] && addons.value["cookie_notice"].active;
    });
    const isCookieBotPluginEnabled = computed(() => {
      return addons.value["cookiebot"] && addons.value["cookiebot"].active;
    });
    const isComplianzPluginEnabled = computed(() => {
      return addons.value["complianz"] && addons.value["complianz"].active;
    });
    const isCookieYesPluginEnabled = computed(() => {
      return addons.value["cookie_yes"] && addons.value["cookie_yes"].active;
    });
    const isConsentManagerPluginEnabled = computed(() => {
      return addons.value["consentmanager"] && addons.value["consentmanager"].active;
    });
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$2, [
        createVNode(_sfc_main$6, {
          title: unref(text_title_eu_compliance_title),
          icon: "monstericon-file-certificate"
        }, {
          default: withCtx(() => [
            isAddonActive.value ? (openBlock(), createElementBlock("div", _hoisted_2$1, [
              createBaseVNode("p", {
                class: "settings-input-description",
                innerHTML: unref(text_addon_description)
              }, null, 8, _hoisted_3$1),
              createVNode(_sfc_main$4, {
                name: "eu_compliance",
                label: unref(text_label_eu_compliance),
                faux: true,
                default: true
              }, {
                collapsible: withCtx(() => [
                  createVNode(_sfc_main$4, {
                    faux: true,
                    label: unref(text_label_anonymize_ip),
                    tooltip: unref(text_tooltip_anonymize_ip),
                    faux_tooltip: unref(text_faux_tooltip_anonymize_ip)
                  }, null, 8, ["label", "tooltip", "faux_tooltip"]),
                  _cache[0] || (_cache[0] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    label: unref(text_label_disable_demographics),
                    tooltip: unref(text_tooltip_disable_demographics),
                    faux_tooltip: unref(text_faux_tooltip_demographics)
                  }, null, 8, ["label", "tooltip", "faux_tooltip"]),
                  _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    label: unref(text_label_user_id),
                    tooltip: unref(text_tooltip_user_id),
                    faux_tooltip: unref(text_faux_tooltip_user_id)
                  }, null, 8, ["label", "tooltip", "faux_tooltip"]),
                  _cache[2] || (_cache[2] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    label: unref(text_label_author),
                    tooltip: unref(text_tooltip_author),
                    faux_tooltip: unref(text_faux_tooltip_author)
                  }, null, 8, ["label", "tooltip", "faux_tooltip"]),
                  _cache[3] || (_cache[3] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    label: unref(text_amp_consent_label),
                    tooltip: unref(text_amp_consent_tooltip),
                    name: "require_amp_consent",
                    faux_tooltip: unref(text_amp_addon_faux_tooltip)
                  }, null, 8, ["label", "tooltip", "faux_tooltip"]),
                  _cache[4] || (_cache[4] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createBaseVNode("p", null, [
                    createBaseVNode("span", {
                      class: "monsterinsights-dark",
                      innerHTML: unref(text_compliance_plugins_title)
                    }, null, 8, _hoisted_4),
                    createBaseVNode("span", { innerHTML: unref(text_compliance_plugins_description) }, null, 8, _hoisted_5)
                  ]),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    default: isCookieNoticePluginEnabled.value,
                    name: "cookie_notice",
                    label: unref(text_label_integrate_cookie_notice),
                    faux_tooltip: unref(text_faux_tooltip_integrate_cookie_notice),
                    faux_tooltip_off: unref(text_faux_tooltip_integrate_cookie_notice_off)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[5] || (_cache[5] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    default: isCookieBotPluginEnabled.value,
                    name: "cookiebot",
                    label: unref(text_label_integrate_cookiebot),
                    faux_tooltip: unref(text_faux_tooltip_integrate_cookiebot),
                    faux_tooltip_off: unref(text_faux_tooltip_integrate_cookiebot_off)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[6] || (_cache[6] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    default: isComplianzPluginEnabled.value,
                    name: "complianz",
                    label: unref(text_label_integrate_complianz),
                    faux_tooltip: unref(text_faux_tooltip_integrate_complianz_off),
                    faux_tooltip_off: unref(text_faux_tooltip_integrate_complianz_off)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[7] || (_cache[7] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    default: isCookieYesPluginEnabled.value,
                    name: "cookie_yes",
                    label: unref(text_label_integrate_cookie_yes),
                    faux_tooltip: unref(text_faux_tooltip_integrate_cookie_yes),
                    faux_tooltip_off: unref(text_faux_tooltip_integrate_cookie_yes_off)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[8] || (_cache[8] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$4, {
                    faux: true,
                    default: isConsentManagerPluginEnabled.value,
                    name: "consent_manager",
                    label: unref(text_label_integrate_consent_manager),
                    faux_tooltip: unref(text_faux_tooltip_integrate_consent_manager),
                    faux_tooltip_off: unref(text_faux_tooltip_integrate_consent_manager_off)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[9] || (_cache[9] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createBaseVNode("p", null, [
                    createBaseVNode("span", {
                      class: "monsterinsights-dark",
                      innerHTML: unref(text_changes_title)
                    }, null, 8, _hoisted_6),
                    createBaseVNode("span", { innerHTML: unref(text_changes_description) }, null, 8, _hoisted_7)
                  ]),
                  createBaseVNode("p", null, [
                    createBaseVNode("span", {
                      class: "monsterinsights-dark",
                      innerHTML: unref(text_disclaimer_title)
                    }, null, 8, _hoisted_8),
                    createBaseVNode("span", { innerHTML: unref(text_disclaimer_description) }, null, 8, _hoisted_9)
                  ])
                ]),
                _: 1
              }, 8, ["label"])
            ])) : (openBlock(), createElementBlock("div", _hoisted_10, [
              createVNode(_sfc_main$5, null, {
                default: withCtx(() => [
                  createBaseVNode("span", { innerHTML: unref(text_addon_inactive) }, null, 8, _hoisted_11)
                ]),
                _: 1
              })
            ]))
          ]),
          _: 1
        }, 8, ["title"])
      ]);
    };
  }
};
const SettingsInputEUCompliance = /* @__PURE__ */ _export_sfc(_sfc_main$3, [["__scopeId", "data-v-356263db"]]);
const _sfc_main$2 = {
  __name: "SettingsInputScroll-Pro",
  setup(__props) {
    const { __ } = wp.i18n;
    const text_title_scrolling = __("Scroll Tracking", "google-analytics-for-wordpress");
    const text_label_scrolling = __("Track Scrolling Depth", "google-analytics-for-wordpress");
    const text_tooltip_scrolling = __("Scroll depth tracking allows you to see how far your users scroll before they leave a page. This is great for publishers (bloggers), and eCommerce websites to boost conversions and engagement.", "google-analytics-for-wordpress");
    return (_ctx, _cache) => {
      return openBlock(), createBlock(_sfc_main$6, {
        title: unref(text_title_scrolling),
        icon: "monstericon-mouse-pointer"
      }, {
        default: withCtx(() => [
          createVNode(_sfc_main$4, {
            faux: true,
            label: unref(text_label_scrolling),
            tooltip: unref(text_tooltip_scrolling)
          }, null, 8, ["label", "tooltip"])
        ]),
        _: 1
      }, 8, ["title"]);
    };
  }
};
const _hoisted_1$1 = { class: "settings-input settings-input-privacy-guard" };
const _sfc_main$1 = {
  __name: "SettingsInputPrivacyGuard-Pro",
  setup(__props) {
    const { __ } = wp.i18n;
    const isEM = false;
    useSettingsStore();
    useAddonsStore();
    const text_title_privacy_guard = __("Enable Privacy Guard", "google-analytics-for-wordpress");
    const text_label_privacy_guard = __("Enable Privacy Guard", "google-analytics-for-wordpress");
    const text_tooltip_privacy_guard = __("Privacy Guard automatically removes potential Personally Identifiable Information (PII) from being sent to Google Analytics due to misconfigured forms, poorly coded plugins, or other miscellaneous reasons.", "google-analytics-for-wordpress");
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$1, [
        createVNode(_sfc_main$6, {
          title: unref(text_title_privacy_guard),
          icon: "",
          collapsible: isEM
        }, {
          default: withCtx(() => [
            createVNode(_sfc_main$4, {
              name: "privacy_guard",
              label: unref(text_label_privacy_guard),
              tooltip: unref(text_tooltip_privacy_guard)
            }, null, 8, ["label", "tooltip"])
          ]),
          _: 1
        }, 8, ["title", "icon"])
      ]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-settings-content settings-engagement" };
const _hoisted_2 = ["innerHTML"];
const _hoisted_3 = ["innerHTML"];
const _sfc_main = {
  __name: "monsterinsights-SettingsTabEngagement",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const addonsStore = useAddonsStore();
    let domain = window.location.origin.replace(/(^\w+:|^)\/\//, "");
    domain = domain.replace(/\./, "\\.");
    const isAddonActive = computed(() => {
      const addons = addonsStore.addons || {};
      if (addons["eu-compliance"]) {
        return addons["eu-compliance"].active;
      }
      return false;
    });
    const exit_url = getMiGlobal("exit_url", "#");
    const text_title_demographics = __("Demographics", "google-analytics-for-wordpress");
    const text_label_demographics = __("Enable Demographics and Interests Reports for Remarketing and Advertising", "google-analytics-for-wordpress");
    const text_label_anonymize_ip = __("Anonymize IP Addresses", "google-analytics-for-wordpress");
    const text_title_link_attribution = __("Link Attribution", "google-analytics-for-wordpress");
    const text_label_enhanced_link = __("Enable Enhanced Link Attribution", "google-analytics-for-wordpress");
    const text_label_anchor_tracking = __("Enable Anchor Tracking", "google-analytics-for-wordpress");
    const text_label_allow_anchor = __("Enable allowAnchor", "google-analytics-for-wordpress");
    const text_label_tag_links_in_rss = __("Enable Tag Links in RSS", "google-analytics-for-wordpress");
    const text_title_file_downloads = __("File Downloads", "google-analytics-for-wordpress");
    const text_label_extensions_of_files = __("Extensions of Files to Track as Downloads", "google-analytics-for-wordpress");
    const text_description_extensions_of_files = __("MonsterInsights will send an event to Google Analytics if a link to a file has one of the above extensions.", "google-analytics-for-wordpress");
    const text_tooltip_demographics = sprintf(
      __("Enable this setting to add the Demographics and Remarketing features to your Google Analytics tracking code. Make sure to enable Demographics and Remarketing in your Google Analytics account. We have a guide for how to do that in our %1$sknowledge base%2$s. For more information about Remarketing, we refer you to %3$sGoogle's documentation%4$s. Note that usage of this function is affected by privacy and cookie laws around the world. Be sure to follow the laws that affect your target audience.", "google-analytics-for-wordpress"),
      '<a href="' + getUrl("settings-panel", "demographics", "https://www.monsterinsights.com/docs/enable-demographics-and-interests-report-in-google-analytics/") + '" target="_blank">',
      "</a>",
      '<a href="https://support.google.com/analytics/answer/2444872?hl=en_US" target="_blank" rel="noopener noreferrer">',
      "</a>"
    );
    const text_tooltip_anonymize = __("This adds anonymizeIp, telling Google Analytics to anonymize the information sent by the tracker objects by removing the last octet of the IP address prior to its storage.", "google-analytics-for-wordpress");
    const text_tooltip_link_attribution = __("Adds the Enhanced Link Attribution to improve the accuracy of your In-Page Analytics report. This is done by automatically differentiating between multiple links to the same URL on a single page using link element IDs.", "google-analytics-for-wordpress");
    const text_tooltip_anchor_tracking = __(`This feature counts visits to each part of websites using WordPress "one-page" themes, where clicking links looks like flipping through pages but doesn't actually take users to a new page. Normally, Google Analytics counts it all as one page, which makes it hard to see how users interact with the website. This feature allows for the proper tracking of those themes.`, "google-analytics-for-wordpress");
    const text_tooltip_allow_anchor = sprintf(
      __("This adds %1$sallowAnchor%2$s to the create command of the pageview hit tracking code, and makes RSS link tagging use a # as well.", "google-analytics-for-wordpress"),
      '<a href="https://developers.google.com/analytics/devguides/collection/analyticsjs/field-reference#allowAnchor" target="_blank" rel="noopener noreferrer">',
      "</a>"
    );
    const text_tooltip_tag_links_in_rss = sprintf(
      __("This feature automatically adds links to your tags in RSS feeds, enhancing navigation. If you're using FeedBurner, it's recommended to disable this here since FeedBurner can do this automatically and better.  Check this %1$shelp page%2$s for info on how to enable this feature in FeedBurner.", "google-analytics-for-wordpress"),
      '<a href="https://support.google.com/feedburner/answer/165769?hl=en&ref_topic=13075" target="_blank" rel="noopener noreferrer">',
      "</a>"
    );
    const text_add_domain = __("Add domain", "google-analytics-for-wordpress");
    const domain_repeater_structure = [
      {
        name: "domain",
        label: sprintf(__("Domain (example: %s)", "google-analytics-for-wordpress"), "monsterinsights.com"),
        pattern: new RegExp("^(?!(?:.+)\\." + domain + "$|" + domain + "$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$"),
        error: sprintf(__("Please enter domain names only ( example: example.com not http://example.com ) and not current site domain ( %s ).", "google-analytics-for-wordpress"), domain.replace("\\", "")),
        prevent_duplicates: true
      }
    ];
    const text_cross_domain = __("Cross Domain Tracking", "google-analytics-for-wordpress");
    const text_cross_domain_description = sprintf(
      __("Cross domain tracking makes it possible for Analytics to see sessions on two related sites as a single session. More info on specific setup steps can be found in our %1$sknowledge base%2$s.", "google-analytics-for-wordpress"),
      '<a href="' + getUrl("settings", "cross-domain", "https://www.monsterinsights.com/docs/setup-cross-domain-tracking/") + '" target="_blank" rel="noopener noreferrer">',
      "</a>"
    );
    const text_edd_recommended = sprintf(
      __("Want to easily sell digital products? MonsterInsights recommends %1$sEasy Digital Downloads%2$s.", "google-analytics-for-wordpress"),
      '<a href="' + exit_url + '#/edd-installer">',
      "</a>"
    );
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("main", _hoisted_1, [
        !isAddonActive.value ? (openBlock(), createBlock(_sfc_main$6, {
          key: 0,
          title: unref(text_title_demographics)
        }, {
          default: withCtx(() => [
            createVNode(_sfc_main$4, {
              name: "demographics",
              label: unref(text_label_demographics),
              tooltip: unref(text_tooltip_demographics)
            }, null, 8, ["label", "tooltip"]),
            _cache[0] || (_cache[0] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createVNode(_sfc_main$4, {
              name: "anonymize_ips",
              label: unref(text_label_anonymize_ip),
              tooltip: unref(text_tooltip_anonymize)
            }, null, 8, ["label", "tooltip"])
          ]),
          _: 1
        }, 8, ["title"])) : createCommentVNode("", true),
        createVNode(SettingsInputEUCompliance),
        createVNode(_sfc_main$1),
        createVNode(_sfc_main$6, { title: unref(text_title_link_attribution) }, {
          default: withCtx(() => [
            createVNode(_sfc_main$4, {
              name: "link_attribution",
              label: unref(text_label_enhanced_link),
              tooltip: unref(text_tooltip_link_attribution)
            }, {
              collapsible: withCtx(() => [
                createVNode(_sfc_main$4, {
                  name: "hash_tracking",
                  label: unref(text_label_anchor_tracking),
                  tooltip: unref(text_tooltip_anchor_tracking)
                }, null, 8, ["label", "tooltip"]),
                _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                createVNode(_sfc_main$4, {
                  name: "allow_anchor",
                  label: unref(text_label_allow_anchor),
                  tooltip: unref(text_tooltip_allow_anchor)
                }, null, 8, ["label", "tooltip"]),
                _cache[2] || (_cache[2] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                createVNode(_sfc_main$4, {
                  name: "tag_links_in_rss",
                  label: unref(text_label_tag_links_in_rss),
                  tooltip: unref(text_tooltip_tag_links_in_rss)
                }, null, 8, ["label", "tooltip"])
              ]),
              _: 1
            }, 8, ["label", "tooltip"])
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$2),
        createVNode(_sfc_main$6, { title: unref(text_cross_domain) }, {
          default: withCtx(() => [
            createBaseVNode("label", null, [
              createBaseVNode("span", { innerHTML: unref(text_cross_domain_description) }, null, 8, _hoisted_2)
            ]),
            createVNode(_sfc_main$7, {
              text_add: unref(text_add_domain),
              structure: domain_repeater_structure,
              name: "cross_domains"
            }, null, 8, ["text_add"])
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$6, {
          id: "monsterinsights-settings-block-file-downloads",
          title: unref(text_title_file_downloads)
        }, {
          default: withCtx(() => [
            createVNode(SettingsInputText, {
              default_value: "doc,pdf,ppt,zip,xls,docx,pptx,xlsx",
              name: "extensions_of_files",
              label: unref(text_label_extensions_of_files),
              description: unref(text_description_extensions_of_files)
            }, null, 8, ["label", "description"]),
            createBaseVNode("div", {
              class: "monsterinsights-mt-15",
              innerHTML: unref(text_edd_recommended)
            }, null, 8, _hoisted_3)
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
