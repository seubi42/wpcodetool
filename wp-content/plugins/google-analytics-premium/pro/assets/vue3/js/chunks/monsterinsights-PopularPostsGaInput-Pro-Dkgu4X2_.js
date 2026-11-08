import { j as getUpgradeUrl, k as getUrl, d as getMiGlobal } from "./globals-C78yxw47.js";
import { o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, u as unref, z as onMounted, b as createVNode, k as normalizeClass, v as createCommentVNode, l as ref, p as computed, G as createBlock, F as Fragment } from "./AppOverlays-Dpqzx_3P.js";
import { a as useToast, c as useAddonsStore, d as useDialog } from "./addons-rxJejcjK.js";
import { S as SettingsInputText } from "./SettingsInputText-IF6LAjxY.js";
import { s as sharedcountGetIndexProgress, a as sharedcountStartIndexing, b as getPostTypes, c as grabPopularPostsReport } from "./popularPosts-CjeH2w_s.js";
import { S as SettingsInputSelect } from "./SettingsInputSelect-Ck4iKotP.js";
import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { u as useLicenseStore } from "./license-D2vquWPE.js";
import { _ as _sfc_main$5 } from "./SettingsInputCheckbox-kcgcZSVs.js";
import { _ as _sfc_main$6 } from "./SettingsInfoTooltip-QCvq3iSl.js";
import { d as _sfc_main$4, P as PopularPostsThemeLicense } from "./monsterinsights-Pro-B9tAtBLR.js";
const _hoisted_1$2 = { class: "monsterinsights-pp-upgrade-overlay" };
const _hoisted_2$2 = ["textContent"];
const _hoisted_3$2 = ["href", "textContent"];
const _sfc_main$3 = {
  __name: "PopularPostsUpgradeOverlay",
  props: {
    campaign: {
      type: String,
      default: "overlay"
    }
  },
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const text_unlock = sprintf(
      __("Unlock with %s", "google-analytics-premium"),
      "MonsterInsights Pro"
    );
    const text_upgrade = __("Upgrade", "google-analytics-premium");
    const form_upgrade_button_url = getUpgradeUrl("settings-panel", "conversions-forms");
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$2, [
        _cache[0] || (_cache[0] = createBaseVNode("svg", {
          width: "14",
          height: "17",
          viewBox: "0 0 14 17",
          fill: "none",
          xmlns: "http://www.w3.org/2000/svg"
        }, [
          createBaseVNode("path", {
            d: "M12.5 7.05859C12.9167 7.05859 13.2708 7.20443 13.5625 7.49609C13.8542 7.78776 14 8.14193 14 8.55859V14.5586C14 14.9753 13.8542 15.3294 13.5625 15.6211C13.2708 15.9128 12.9167 16.0586 12.5 16.0586H1.5C1.08333 16.0586 0.729167 15.9128 0.4375 15.6211C0.145833 15.3294 0 14.9753 0 14.5586V8.55859C0 8.14193 0.145833 7.78776 0.4375 7.49609C0.729167 7.20443 1.08333 7.05859 1.5 7.05859H2.25V4.80859C2.25 3.49609 2.70833 2.38151 3.625 1.46484C4.5625 0.527344 5.6875 0.0585938 7 0.0585938C8.3125 0.0585938 9.42708 0.527344 10.3438 1.46484C11.2812 2.38151 11.75 3.49609 11.75 4.80859V7.05859H12.5ZM9.25 7.05859V4.80859C9.25 4.18359 9.03125 3.65234 8.59375 3.21484C8.15625 2.77734 7.625 2.55859 7 2.55859C6.375 2.55859 5.84375 2.77734 5.40625 3.21484C4.96875 3.65234 4.75 4.18359 4.75 4.80859V7.05859H9.25Z",
            fill: "#338EEF"
          })
        ], -1)),
        createBaseVNode("span", {
          textContent: toDisplayString(unref(text_unlock))
        }, null, 8, _hoisted_2$2),
        createBaseVNode("a", {
          href: unref(form_upgrade_button_url),
          class: "monsterinsights-button monsterinsights-button-small",
          textContent: toDisplayString(unref(text_upgrade))
        }, null, 8, _hoisted_3$2)
      ]);
    };
  }
};
const _hoisted_1$1 = { class: "monsterinsights-pp-sharedcount-controls" };
const _hoisted_2$1 = ["textContent"];
const _hoisted_3$1 = ["innerHTML"];
const _hoisted_4$1 = { class: "inline-field" };
const _hoisted_5$1 = ["disabled"];
const _hoisted_6 = ["textContent"];
const _hoisted_7 = {
  key: 0,
  class: "monsterinsights-sharedcount-status"
};
const _hoisted_8 = ["innerHTML"];
const _sfc_main$2 = {
  __name: "PopularPostsSharedCount",
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const { errorToast } = useToast();
    const text_sharedcount_api = __("SharedCount API Key", "google-analytics-premium");
    const text_sharedcount_api_description = sprintf(
      // Translators: Placeholders add a link to the sharedcount account page.
      __(
        "Insert your sharedcount API key found in your %1$sSettings%2$s panel. After, click Start Indexing.",
        "google-analytics-premium"
      ),
      '<a href="https://www.sharedcount.com/app/account-info" target="_blank" rel="noreferrer noopener">',
      "</a>"
    );
    const text_start_indexing = __("Start Indexing", "google-analytics-premium");
    const text_indexing_progress = __(
      "%1$sIndex Progress: %2$s%%.%3$s You may leave this page during indexing.",
      "google-analytics-premium"
    );
    const text_indexing_complete = __(
      "Indexing completed, counts will update automatically every day.",
      "google-analytics-premium"
    );
    const indexingButtonDisabled = ref(false);
    const sharedcountStatus = ref(false);
    const text_indexing_button = ref(text_start_indexing);
    const sharedcountButtonClass = computed(() => {
      let cls = "monsterinsights-button monsterinsights-button-gray";
      if (indexingButtonDisabled.value) {
        cls += " monsterinsights-button-disabled";
      }
      return cls;
    });
    function update_progress_text(percent) {
      if (percent === 100) {
        sharedcountStatus.value = text_indexing_complete;
      } else {
        sharedcountStatus.value = sprintf(text_indexing_progress, "<b>", percent, "</b>");
      }
    }
    async function start_sharedcount_indexing() {
      if (indexingButtonDisabled.value) return;
      indexingButtonDisabled.value = true;
      text_indexing_button.value = __("Please wait", "google-analytics-premium");
      try {
        const data = await sharedcountStartIndexing();
        text_indexing_button.value = __("Indexing started", "google-analytics-premium");
        if (data?.max_pages) {
          const progress = Math.floor(100 / data.max_pages);
          update_progress_text(progress);
        }
      } catch (err) {
        indexingButtonDisabled.value = false;
        text_indexing_button.value = text_start_indexing;
        if (err?.message) {
          errorToast({ title: err.message });
        }
      }
    }
    async function get_sharedcount_index_progress() {
      indexingButtonDisabled.value = true;
      try {
        const data = await sharedcountGetIndexProgress();
        if (data?.progress) {
          indexingButtonDisabled.value = true;
          text_indexing_button.value = __("Indexing started", "google-analytics-premium");
          update_progress_text(data.progress);
        } else {
          indexingButtonDisabled.value = false;
        }
      } catch (_err) {
        indexingButtonDisabled.value = false;
      }
    }
    onMounted(() => {
      get_sharedcount_index_progress();
    });
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$1, [
        createBaseVNode("p", null, [
          createBaseVNode("span", {
            class: "monsterinsights-dark",
            textContent: toDisplayString(unref(text_sharedcount_api))
          }, null, 8, _hoisted_2$1),
          _cache[0] || (_cache[0] = createBaseVNode("br", null, null, -1)),
          createBaseVNode("span", { innerHTML: unref(text_sharedcount_api_description) }, null, 8, _hoisted_3$1)
        ]),
        createBaseVNode("div", _hoisted_4$1, [
          createVNode(SettingsInputText, {
            name: "sharedcount_key",
            auth_disabled: false
          }),
          createBaseVNode("button", {
            class: normalizeClass(sharedcountButtonClass.value),
            disabled: indexingButtonDisabled.value,
            onClick: start_sharedcount_indexing
          }, [
            createBaseVNode("span", {
              textContent: toDisplayString(text_indexing_button.value)
            }, null, 8, _hoisted_6)
          ], 10, _hoisted_5$1)
        ]),
        sharedcountStatus.value ? (openBlock(), createElementBlock("div", _hoisted_7, [
          createBaseVNode("span", { innerHTML: sharedcountStatus.value }, null, 8, _hoisted_8)
        ])) : createCommentVNode("", true)
      ]);
    };
  }
};
const _sfc_main$1 = {
  __name: "PopularPostsPostTypePicker",
  props: {
    name: String,
    label: String,
    description: String
  },
  setup(__props) {
    const post_types = ref([]);
    function setPostTypes(data) {
      const next = [];
      for (const value in data) {
        if (Object.hasOwn(data, value)) {
          next.push({
            label: data[value],
            value
          });
        }
      }
      post_types.value = next;
    }
    onMounted(async () => {
      try {
        const response = await getPostTypes();
        if (response) {
          setPostTypes(response);
        }
      } catch (error) {
        console.warn("Failed to load post types:", error);
      }
    });
    return (_ctx, _cache) => {
      return openBlock(), createBlock(SettingsInputSelect, {
        options: post_types.value,
        multiple: true,
        name: __props.name,
        label: __props.label,
        description: __props.description
      }, null, 8, ["options", "name", "label", "description"]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-popular-posts-ga-settings" };
const _hoisted_2 = { class: "monsterinsights-dark" };
const _hoisted_3 = ["textContent"];
const _hoisted_4 = ["innerHTML"];
const _hoisted_5 = ["textContent"];
const _sfc_main = {
  __name: "monsterinsights-PopularPostsGaInput-Pro",
  props: {
    name: {
      type: String,
      required: true
    }
  },
  setup(__props) {
    const { __, sprintf } = wp.i18n;
    const props = __props;
    const settingsStore = useSettingsStore();
    const licenseStore = useLicenseStore();
    const addonsStore = useAddonsStore();
    const { alert, confirm, loading } = useDialog();
    const text_add_top_5_ga = __("Add Top 5 Posts from Google Analytics", "google-analytics-premium");
    const text_add_top_5_ga_tooltip = __(
      "To load the top posts from Google Analytics, you must enable the Custom Dimensions addon and set up the Post Type custom dimension in the MonsterInsights settings.",
      "google-analytics-premium"
    );
    const text_check_data = __("Test Automated Posts", "google-analytics-premium");
    const text_check_ga_data = __(
      "Click this button to run a series of checks that will confirm your setup is completed to load Popular Posts from Google Analytics.",
      "google-analytics-premium"
    );
    const text_automated = __("Automated + Curated", "google-analytics-premium");
    const text_automated_description = sprintf(
      __(
        "Automatically add the top 5 Posts from the past 30 days to your Curated list of Posts using %1$sCustom Dimensions%2$s. Also requires Sort By - Curated to be selected. Setup steps can be found in our %3$sknowledge base%4$s.",
        "google-analytics-premium"
      ),
      '<a href="#/conversions">',
      "</a>",
      `<a target="_blank" href="${getUrl("popular-posts", "automated-ga", "https://monsterinsights.com/docs/how-to-include-your-popular-posts-from-google-analytics/")}">`,
      "</a>"
    );
    const text_needs_pro = __("Pro version is required.", "google-analytics-premium");
    const ga_enabled = computed(() => {
      const settings = settingsStore.getSettings;
      return settings?.[props.name] ? settings[props.name] : false;
    });
    const checking_ga_data = computed(() => false);
    const ga_check_button_class = computed(() => {
      let cls = "monsterinsights-button monsterinsights-button-secondary";
      if (checking_ga_data.value) {
        cls += " monsterinsights-button-disabled";
      }
      return cls;
    });
    const licenseType = computed(() => {
      const isNetwork = getMiGlobal("network", false);
      const license = isNetwork ? licenseStore.license_network : licenseStore.license;
      return license?.type || "";
    });
    const canaccess = computed(() => PopularPostsThemeLicense.canaccess(licenseType.value));
    function isAddonInstalled(addon) {
      const addons = addonsStore.addons || {};
      return addons[addon]?.installed;
    }
    async function start_tracking() {
      const loader = loading({ title: __("Verifying Popular Posts data", "google-analytics-premium") });
      try {
        await grabPopularPostsReport();
        loader.close();
        await alert({
          variant: "success",
          title: __("Popular Posts data can be fetched correctly", "google-analytics-premium"),
          message: __(
            "Please note: depending on when you set up the Custom Dimensions settings, it may take up to 7 days to see relevant Popular Posts data loading from Google Analytics.",
            "google-analytics-premium"
          ),
          confirmText: __("Close", "google-analytics-premium")
        });
      } catch (err) {
        loader.close();
        let error_message = err?.message || "";
        const footer = err?.footer || "";
        if (footer === "install_addon") {
          const verb = isAddonInstalled("dimensions") ? __("activate", "google-analytics-premium") : __("install", "google-analytics-premium");
          error_message = sprintf(error_message, verb);
          const goToAddons = await confirm({
            title: __("Error", "google-analytics-premium"),
            message: error_message,
            confirmText: __("Visit addons page", "google-analytics-premium"),
            cancelText: __("Close", "google-analytics-premium")
          });
          if (goToAddons) {
            window.location = getMiGlobal("addons_url", "#");
          }
          return;
        }
        if (footer) {
          const footerdiv = document.createElement("div");
          footerdiv.innerHTML = footer;
          const anchor = footerdiv.firstChild;
          const goToLink = await confirm({
            title: __("Error", "google-analytics-premium"),
            message: error_message,
            confirmText: anchor?.innerText || __("OK", "google-analytics-premium"),
            cancelText: __("Close", "google-analytics-premium")
          });
          if (goToLink && anchor?.href) {
            window.location = anchor.href;
          }
          return;
        }
        await alert({
          variant: "error",
          title: __("Error", "google-analytics-premium"),
          message: error_message
        });
      }
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        createBaseVNode("p", null, [
          createBaseVNode("span", _hoisted_2, [
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_automated))
            }, null, 8, _hoisted_3),
            createVNode(_sfc_main$4, { level: "pro" })
          ]),
          _cache[0] || (_cache[0] = createBaseVNode("br", null, null, -1)),
          createBaseVNode("span", { innerHTML: unref(text_automated_description) }, null, 8, _hoisted_4)
        ]),
        canaccess.value ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
          createVNode(_sfc_main$5, {
            auth_disabled: false,
            name: __props.name,
            label: unref(text_add_top_5_ga),
            tooltip: unref(text_add_top_5_ga_tooltip)
          }, null, 8, ["name", "label", "tooltip"]),
          ga_enabled.value ? (openBlock(), createElementBlock(Fragment, { key: 0 }, [
            _cache[1] || (_cache[1] = createBaseVNode("p", null, null, -1)),
            createBaseVNode("p", null, [
              createBaseVNode("button", {
                class: normalizeClass(ga_check_button_class.value),
                onClick: start_tracking,
                textContent: toDisplayString(unref(text_check_data))
              }, null, 10, _hoisted_5),
              createVNode(_sfc_main$6, { content: unref(text_check_ga_data) }, null, 8, ["content"])
            ])
          ], 64)) : createCommentVNode("", true)
        ], 64)) : (openBlock(), createBlock(_sfc_main$5, {
          key: 1,
          faux: true,
          default: false,
          faux_tooltip_off: unref(text_needs_pro),
          label: unref(text_add_top_5_ga),
          tooltip: unref(text_add_top_5_ga_tooltip)
        }, null, 8, ["faux_tooltip_off", "label", "tooltip"]))
      ]);
    };
  }
};
export {
  _sfc_main$3 as _,
  _sfc_main$2 as a,
  _sfc_main as b,
  _sfc_main$1 as c
};
