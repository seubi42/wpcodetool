import { d as getMiGlobal } from "./globals-C78yxw47.js";
import { c as useAddonsStore } from "./addons-rxJejcjK.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { u as useLicenseStore } from "./license-D2vquWPE.js";
import { _ as _sfc_main$5 } from "./SettingsBlock-BINMceN_.js";
import { _ as _sfc_main$4 } from "./SettingsAddonDisabled-CJebef83.js";
import { _ as _sfc_main$3 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { _ as _sfc_main$2 } from "./SettingsAddonUpgrade-Cn5s6oQE.js";
import { o as openBlock, G as createBlock, E as withCtx, a as createBaseVNode, c as createElementBlock, b as createVNode, u as unref, v as createCommentVNode, t as toDisplayString, p as computed } from "./AppOverlays-Dpqzx_3P.js";
import "./sanitize-CE5uy2g6.js";
import "./useNotices-CwGRydDs.js";
import "./Modal-D1CHxx1Y.js";
import "./monsterinsights-Pro-B9tAtBLR.js";
import "./TheAppHeader-CJeDav-v.js";
import "./SettingsInfoTooltip-QCvq3iSl.js";
const _hoisted_1$1 = ["innerHTML"];
const _sfc_main$1 = {
  __name: "monsterinsights-SettingsTabEcommerce-Lite-Simple",
  props: {
    text: {
      type: String,
      required: true
    }
  },
  setup(__props) {
    return (_ctx, _cache) => {
      return openBlock(), createBlock(_sfc_main$2, { addon: "ecommerce" }, {
        default: withCtx(() => [
          createBaseVNode("span", { innerHTML: __props.text }, null, 8, _hoisted_1$1)
        ]),
        _: 1
      });
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-settings-content monsterinsights-settings-ecommerce" };
const _hoisted_2 = { key: 0 };
const _hoisted_3 = ["innerHTML"];
const _hoisted_4 = { key: 1 };
const _hoisted_5 = ["innerHTML"];
const _hoisted_6 = { key: 0 };
const _hoisted_7 = ["textContent"];
const _hoisted_8 = ["textContent"];
const _hoisted_9 = ["innerHTML"];
const _hoisted_10 = { class: "monsterinsights-easy-affiliate" };
const _hoisted_11 = { class: "monsterinsights-affiliate-wp" };
const _hoisted_12 = { key: 1 };
const _hoisted_13 = ["innerHTML"];
const _hoisted_14 = ["innerHTML"];
const _sfc_main = {
  __name: "monsterinsights-SettingsTabEcommerce-Pro",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const addonsStore = useAddonsStore();
    useSettingsStore();
    const licenseStore = useLicenseStore();
    const is_network = getMiGlobal("network", false);
    const addons_url = getMiGlobal("addons_url", "#");
    function isAddonActive(addon) {
      const addons = addonsStore.addons || {};
      if (addons[addon]) {
        return addons[addon].active;
      }
      return false;
    }
    const showUpsell = computed(() => {
      const license = is_network ? licenseStore.license_network : licenseStore.license;
      const isLicenseLoaded = license && license.type && license.type !== "";
      const isAddonsLoaded = addonsStore.addons && Object.keys(addonsStore.addons).length > 0;
      if (!isLicenseLoaded && !isAddonsLoaded) return null;
      return (license.type === "plus" || license.type === "starter") && !isAddonActive("ecommerce");
    });
    const getUserIdDescription = computed(() => {
      if (isAddonActive("eu-compliance")) {
        return __("Because you have the EU Compliance addon installed, MonsterInsights has disabled the User ID tracking feature.", "google-analytics-for-wordpress");
      }
      return sprintf(
        __("To the extent that Google allows webmasters to discern single users, this setting allows you to identify users by their WordPress user ID if logged-in. To use this feature, you will need to turn this feature on in Google Analytics. %1$sRead our step by step directions%2$s.", "google-analytics-for-wordpress"),
        '<a href="https://www.monsterinsights.com/docs/how-to-setup-user-tracking/" target="_blank">',
        "</a>"
      );
    });
    const getUserIdFaux = computed(() => isAddonActive("eu-compliance"));
    const text_ecommerce_title = __("eCommerce Tracking", "google-analytics-for-wordpress");
    const text_ecommerce_input_label = __("Enable eCommerce Tracking", "google-analytics-for-wordpress");
    const text_addon_inactive = sprintf(__("In order to use the eCommerce tracking features, please %1$sinstall and activate%2$s the eCommerce addon.", "google-analytics-for-wordpress"), '<a href="' + addons_url + '">', "</a>");
    const text_ecommerce_upgrade = __("In order to use eCommerce tracking features, upgrade to MonsterInsights Pro", "google-analytics-for-wordpress");
    const text_affiliate_upgrade = __("In order to use Affiliate tracking features, upgrade to MonsterInsights Pro", "google-analytics-for-wordpress");
    const text_ecommerce_faux_tooltip = __("Deactivate the eCommerce addon to disable eCommerce tracking.", "google-analytics-for-wordpress");
    const text_ecommerce_faux_tooltip_off = __("Activate the eCommerce addon to enable eCommerce tracking.", "google-analytics-for-wordpress");
    const text_label_woocommerce = sprintf(__("%1$sWooCommerce%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_woocommerce_disabled = sprintf(__("%1$sWooCommerce%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_edd = sprintf(__("%1$sEasy Digital Downloads%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_edd_disabled = sprintf(__("%1$sEasy Digital Downloads%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_memberpress = sprintf(__("%1$sMemberPress%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_memberpress_disabled = sprintf(__("%1$sMemberPress%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_lifterlms = sprintf(__("%1$sLifterLMS%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_lifterlms_disabled = sprintf(__("%1$sLifterLMS%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_givewp = sprintf(__("%1$sGiveWP%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_givewp_disabled = sprintf(__("%1$sGiveWP%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_rcp = sprintf(__("%1$sRestrict Content Pro%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_rcp_disabled = sprintf(__("%1$sRestrict Content Pro%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_membermouse = sprintf(__("%1$sMemberMouse%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_membermouse_disabled = sprintf(__("%1$sMemberMouse%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_charitable = sprintf(__("%1$sCharitable%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_charitable_disabled = sprintf(__("%1$sCharitable%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_wishlist_member = sprintf(__("%1$sWishList Member%2$s has been detected and eCommerce data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_wishlist_member_disabled = sprintf(__("%1$sWishList Member%2$s has not been detected and eCommerce data is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_woocommerce_faux_tooltip = __("When WooCommerce is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_edd_faux_tooltip = __("When Easy Digital Downloads is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_memberpress_faux_tooltip = __("When MemberPress is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_lifterlms_faux_tooltip = __("When LifterLMS is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_givewp_faux_tooltip = __("When GiveWP is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_rcp_faux_tooltip = __("When Restrict Content Pro is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_membermouse_faux_tooltip = __("When MemberMouse is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_charitable_faux_tooltip = __("When Charitable is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_wishlist_member_faux_tooltip = __("When WishList Member is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_alert_givewp_analytics_active = sprintf(__("Attention: It is advised to disable %1$sGive - Google Analytics Donation Tracking%2$s plugin. Which may interfere with the tracking done by MonsterInsights.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_affiliate_title = __("Affiliate Tracking", "google-analytics-for-wordpress");
    const text_affiliate_setup_title = __("Setup Complete", "google-analytics-for-wordpress");
    const text_affiliate_inactive_setup_title = __("Setup Instructions", "google-analytics-for-wordpress");
    const text_affiliate_setup_description = __("When enabled, MonsterInsights will automatically send affiliate traffic and sales data into Google Analytics for analysis.", "google-analytics-for-wordpress");
    const text_label_easy_affiliate = sprintf(__("%1$sEasyAffiliate%2$s has been detected and Affiliate Transactions data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_easy_affiliate_disabled = sprintf(__("%1$sEasyAffiliate%2$s has not been detected and Affiliate Transactions is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_easy_affiliate_faux_tooltip = __("When EasyAffiliate is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_label_affiliate_wp = sprintf(__("%1$sAffiliateWP%2$s has been detected and Affiliate Transactions data is being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_label_affiliate_wp_disabled = sprintf(__("%1$sAffiliateWP%2$s has not been detected and Affiliate Transactions is not being tracked.", "google-analytics-for-wordpress"), "<strong>", "</strong>");
    const text_affiliate_wp_faux_tooltip = __("When AffiliateWP is activated, MonsterInsights will detect it and automatically turn on tracking for it.", "google-analytics-for-wordpress");
    const text_addon_inactive_ecommerce_addon = sprintf(__("In order to use the eCommerce Affiliate tracking features, please %1$sinstall and activate%2$s the eCommerce addon.", "google-analytics-for-wordpress"), '<a href="' + addons_url + '">', "</a>");
    const text_user_id_title = __("User ID Tracking", "google-analytics-for-wordpress");
    const text_label_user_id = __("Enable User ID tracking.", "google-analytics-for-wordpress");
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("main", _hoisted_1, [
        createVNode(_sfc_main$5, { title: unref(text_ecommerce_title) }, {
          default: withCtx(() => [
            isAddonActive("ecommerce") ? (openBlock(), createElementBlock("div", _hoisted_2, [
              createVNode(_sfc_main$3, {
                label: unref(text_ecommerce_input_label),
                faux: true,
                faux_tooltip: unref(text_ecommerce_faux_tooltip),
                faux_tooltip_off: unref(text_ecommerce_faux_tooltip_off)
              }, {
                collapsible: withCtx(() => [
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("woocommerce"),
                    label: isAddonActive("woocommerce") ? unref(text_label_woocommerce) : unref(text_label_woocommerce_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_woocommerce_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[0] || (_cache[0] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("easy_digital_downloads"),
                    label: isAddonActive("easy_digital_downloads") ? unref(text_label_edd) : unref(text_label_edd_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_edd_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[1] || (_cache[1] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("memberpress"),
                    label: isAddonActive("memberpress") ? unref(text_label_memberpress) : unref(text_label_memberpress_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_memberpress_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[2] || (_cache[2] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("lifterlms"),
                    label: isAddonActive("lifterlms") ? unref(text_label_lifterlms) : unref(text_label_lifterlms_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_lifterlms_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[3] || (_cache[3] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("givewp"),
                    label: isAddonActive("givewp") ? unref(text_label_givewp) : unref(text_label_givewp_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_givewp_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  isAddonActive("givewp_google_analytics") ? (openBlock(), createElementBlock("span", {
                    key: 0,
                    class: "monsterinsights-inline-warning",
                    innerHTML: unref(text_alert_givewp_analytics_active)
                  }, null, 8, _hoisted_3)) : createCommentVNode("", true),
                  _cache[4] || (_cache[4] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("rcp"),
                    label: isAddonActive("rcp") ? unref(text_label_rcp) : unref(text_label_rcp_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_rcp_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[5] || (_cache[5] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("membermouse"),
                    label: isAddonActive("membermouse") ? unref(text_label_membermouse) : unref(text_label_membermouse_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_membermouse_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[6] || (_cache[6] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("charitable"),
                    label: isAddonActive("charitable") ? unref(text_label_charitable) : unref(text_label_charitable_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_charitable_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"]),
                  _cache[7] || (_cache[7] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                  createVNode(_sfc_main$3, {
                    faux: true,
                    default: isAddonActive("wishlist_member"),
                    label: isAddonActive("wishlist_member") ? unref(text_label_wishlist_member) : unref(text_label_wishlist_member_disabled),
                    faux_tooltip: unref(text_ecommerce_faux_tooltip),
                    faux_tooltip_off: unref(text_wishlist_member_faux_tooltip)
                  }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"])
                ]),
                _: 1
              }, 8, ["label", "faux_tooltip", "faux_tooltip_off"])
            ])) : (openBlock(), createElementBlock("div", _hoisted_4, [
              showUpsell.value === true ? (openBlock(), createBlock(_sfc_main$1, {
                key: 0,
                text: unref(text_ecommerce_upgrade)
              }, null, 8, ["text"])) : showUpsell.value === false ? (openBlock(), createBlock(_sfc_main$4, { key: 1 }, {
                default: withCtx(() => [
                  createBaseVNode("span", { innerHTML: unref(text_addon_inactive) }, null, 8, _hoisted_5)
                ]),
                _: 1
              })) : createCommentVNode("", true)
            ]))
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$5, { title: unref(text_affiliate_title) }, {
          default: withCtx(() => [
            isAddonActive("ecommerce") ? (openBlock(), createElementBlock("div", _hoisted_6, [
              createBaseVNode("div", null, [
                createBaseVNode("p", null, [
                  isAddonActive("easy_affiliate") ? (openBlock(), createElementBlock("span", {
                    key: 0,
                    class: "monsterinsights-dark",
                    textContent: toDisplayString(unref(text_affiliate_setup_title))
                  }, null, 8, _hoisted_7)) : (openBlock(), createElementBlock("span", {
                    key: 1,
                    class: "monsterinsights-dark",
                    textContent: toDisplayString(unref(text_affiliate_inactive_setup_title))
                  }, null, 8, _hoisted_8)),
                  _cache[8] || (_cache[8] = createBaseVNode("br", null, null, -1))
                ])
              ]),
              createBaseVNode("div", null, [
                createBaseVNode("span", { innerHTML: unref(text_affiliate_setup_description) }, null, 8, _hoisted_9)
              ]),
              createBaseVNode("div", _hoisted_10, [
                _cache[9] || (_cache[9] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                createVNode(_sfc_main$3, {
                  faux: true,
                  default: isAddonActive("easy_affiliate"),
                  label: isAddonActive("easy_affiliate") ? unref(text_label_easy_affiliate) : unref(text_label_easy_affiliate_disabled),
                  faux_tooltip: unref(text_ecommerce_faux_tooltip),
                  faux_tooltip_off: unref(text_easy_affiliate_faux_tooltip)
                }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"])
              ]),
              createBaseVNode("div", _hoisted_11, [
                _cache[10] || (_cache[10] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
                createVNode(_sfc_main$3, {
                  faux: true,
                  default: isAddonActive("affiliate-wp"),
                  label: isAddonActive("affiliate-wp") ? unref(text_label_affiliate_wp) : unref(text_label_affiliate_wp_disabled),
                  faux_tooltip: unref(text_ecommerce_faux_tooltip),
                  faux_tooltip_off: unref(text_affiliate_wp_faux_tooltip)
                }, null, 8, ["default", "label", "faux_tooltip", "faux_tooltip_off"])
              ])
            ])) : (openBlock(), createElementBlock("div", _hoisted_12, [
              showUpsell.value === true ? (openBlock(), createBlock(_sfc_main$1, {
                key: 0,
                text: unref(text_affiliate_upgrade)
              }, null, 8, ["text"])) : showUpsell.value === false ? (openBlock(), createBlock(_sfc_main$4, { key: 1 }, {
                default: withCtx(() => [
                  createBaseVNode("span", { innerHTML: unref(text_addon_inactive_ecommerce_addon) }, null, 8, _hoisted_13)
                ]),
                _: 1
              })) : createCommentVNode("", true)
            ]))
          ]),
          _: 1
        }, 8, ["title"]),
        createVNode(_sfc_main$5, { title: unref(text_user_id_title) }, {
          default: withCtx(() => [
            createBaseVNode("p", {
              class: "monsterinsights-settings-block-description",
              innerHTML: getUserIdDescription.value
            }, null, 8, _hoisted_14),
            _cache[11] || (_cache[11] = createBaseVNode("div", { class: "monsterinsights-separator" }, null, -1)),
            createVNode(_sfc_main$3, {
              name: "userid",
              faux: getUserIdFaux.value,
              default: false,
              label: unref(text_label_user_id)
            }, null, 8, ["faux", "label"])
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
