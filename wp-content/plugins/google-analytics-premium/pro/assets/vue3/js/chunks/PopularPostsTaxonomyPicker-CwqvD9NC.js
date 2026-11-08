import { u as useSettingsStore } from "./settings-WrluSu6E.js";
import { u as useAuth } from "./monsterinsights-Pro-B9tAtBLR.js";
import { a as useToast } from "./addons-rxJejcjK.js";
import { o as openBlock, c as createElementBlock, a as createBaseVNode, F as Fragment, h as renderList, t as toDisplayString, p as computed, b as createVNode, E as withCtx, u as unref, l as ref } from "./AppOverlays-Dpqzx_3P.js";
import { s as script } from "./vue-multiselect.esm-hhZpumNw.js";
/* empty css                         */
import { d as debounce } from "./PopularPostsVideoModal-BBq1HOA4.js";
import { d as getTerms } from "./popularPosts-CjeH2w_s.js";
const _hoisted_1$1 = { class: "monsterinsights-settings-input-select-simple" };
const _hoisted_2$1 = ["name"];
const _hoisted_3$1 = ["value", "selected", "textContent"];
const _sfc_main$1 = {
  __name: "SettingsInputSelectSimple",
  props: {
    options: Array,
    name: String,
    auth_disabled: { type: Boolean, default: true }
  },
  setup(__props) {
    const props = __props;
    const settingsStore = useSettingsStore();
    const { isAuthed } = useAuth();
    const { savingToast, successToast, errorToast } = useToast();
    const disabled = computed(() => {
      if (!props.auth_disabled) return false;
      return !isAuthed.value;
    });
    function updateSetting(e) {
      if (disabled.value) return false;
      savingToast({});
      settingsStore.updateSetting({
        name: props.name,
        value: e.target.value
      }).then(() => {
        successToast({});
      }).catch(() => {
        errorToast({});
      });
    }
    function isChecked(value) {
      const settings = settingsStore.getSettings;
      if (settings[props.name]) {
        return value == settings[props.name];
      }
      return value === props.options[0].value;
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$1, [
        createBaseVNode("select", {
          name: __props.name,
          class: "monsterinsights-settings-select",
          autocomplete: "off",
          onChange: updateSetting
        }, [
          (openBlock(true), createElementBlock(Fragment, null, renderList(__props.options, (option) => {
            return openBlock(), createElementBlock("option", {
              key: option.value,
              value: option.value,
              selected: isChecked(option.value),
              textContent: toDisplayString(option.label)
            }, null, 8, _hoisted_3$1);
          }), 128))
        ], 40, _hoisted_2$1)
      ]);
    };
  }
};
const _hoisted_1 = { class: "monsterinsights-pp-posts-picker" };
const _hoisted_2 = ["textContent"];
const _hoisted_3 = ["textContent"];
const _sfc_main = {
  __name: "PopularPostsTaxonomyPicker",
  props: {
    name: {
      type: String,
      required: true
    },
    taxonomy: {
      type: String,
      default: "category"
    },
    placeholder: {
      type: String,
      default: () => wp.i18n.__("Select categories/search", "google-analytics-premium")
    }
  },
  setup(__props) {
    const { __ } = wp.i18n;
    const props = __props;
    const settingsStore = useSettingsStore();
    const { savingToast, successToast, errorToast } = useToast();
    const pages = ref([]);
    const isLoading = ref(false);
    const text_search_pages_placeholder = props.placeholder;
    const text_search_pages_not_found = __("Oops! No categories found.", "google-analytics-premium");
    const text_search_no_options = __("Search by category name", "google-analytics-premium");
    const posts = computed({
      get() {
        const value = settingsStore.getSettings[props.name];
        if (!value) return [];
        try {
          return JSON.parse(JSON.stringify(value));
        } catch (_e) {
          return [];
        }
      },
      set() {
      }
    });
    const findPages = debounce(async (query) => {
      isLoading.value = true;
      try {
        const response = await getTerms({
          taxonomy: props.taxonomy,
          keyword: query
        });
        pages.value = Array.isArray(response) ? response : [];
      } catch (_err) {
        errorToast({
          title: __("Can't load categories.", "google-analytics-premium")
        });
      } finally {
        isLoading.value = false;
      }
    }, 300);
    async function updateSetting(newValue) {
      isLoading.value = false;
      savingToast({});
      try {
        await settingsStore.updateSetting({
          name: props.name,
          // Raw array — `@/api/settings.js` JSON-stringifies on the way out.
          value: newValue
        });
        successToast({});
      } catch (_err) {
        errorToast({});
      }
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        createVNode(unref(script), {
          modelValue: posts.value,
          "onUpdate:modelValue": [
            _cache[0] || (_cache[0] = ($event) => posts.value = $event),
            updateSetting
          ],
          "clear-on-select": false,
          "close-on-select": true,
          "internal-search": false,
          loading: isLoading.value,
          "max-height": 600,
          options: pages.value,
          placeholder: unref(text_search_pages_placeholder),
          searchable: true,
          "show-no-results": true,
          "show-labels": false,
          label: "text",
          "track-by": "id",
          multiple: true,
          onSearchChange: unref(findPages)
        }, {
          noResult: withCtx(() => [
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_search_pages_not_found))
            }, null, 8, _hoisted_2)
          ]),
          noOptions: withCtx(() => [
            createBaseVNode("span", {
              textContent: toDisplayString(unref(text_search_no_options))
            }, null, 8, _hoisted_3)
          ]),
          _: 1
        }, 8, ["modelValue", "loading", "options", "placeholder", "onSearchChange"])
      ]);
    };
  }
};
export {
  _sfc_main$1 as _,
  _sfc_main as a
};
