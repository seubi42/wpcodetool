import { u as useRouter, b as useRoute } from "./TheAppHeader-CJeDav-v.js";
import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { o as openBlock, c as createElementBlock, L as renderSlot, G as createBlock, E as withCtx, a as createBaseVNode, H as withKeys, C as withModifiers, k as normalizeClass, aa as resolveDynamicComponent, u as unref, B as createTextVNode, t as toDisplayString, b as createVNode, m as defineStore, S as _export_sfc, z as onMounted, v as createCommentVNode, p as computed, l as ref, F as Fragment, h as renderList } from "./AppOverlays-Dpqzx_3P.js";
import { b as brandConfig } from "./monsterinsights-style-D8E96NnX.js";
import { I as Icon } from "./Icon-BEJfUbqO.js";
import { u as useCustomViewsStore } from "./customViews-CyVdMjdZ.js";
import "./sanitize-CE5uy2g6.js";
import "./globals-C78yxw47.js";
const _hoisted_1$3 = { class: "monsterinsights-grid" };
const _sfc_main$3 = {
  __name: "BaseGrid",
  props: {
    minColWidth: { type: Number, default: 320 },
    gap: { type: Number, default: 20 },
    align: { type: String, default: "start" }
  },
  setup(__props) {
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1$3, [
        renderSlot(_ctx.$slots, "default")
      ]);
    };
  }
};
const _hoisted_1$2 = { class: "monsterinsights-card__media" };
const _hoisted_2$2 = { class: "monsterinsights-card__header" };
const _hoisted_3$1 = { class: "monsterinsights-card__body" };
const _hoisted_4$1 = { class: "monsterinsights-card__footer" };
const _sfc_main$2 = {
  __name: "BaseCard",
  props: {
    as: { type: String, default: "button" },
    selectable: { type: Boolean, default: true },
    selected: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false }
  },
  emits: ["click"],
  setup(__props, { emit: __emit }) {
    const emit = __emit;
    const props = __props;
    const tag = props.as || "button";
    function onClick(event) {
      if (props.disabled) return;
      emit("click", event);
    }
    return (_ctx, _cache) => {
      return openBlock(), createBlock(resolveDynamicComponent(unref(tag)), {
        class: normalizeClass(["monsterinsights-card", {
          "monsterinsights-card--selectable": __props.selectable,
          "monsterinsights-card--selected": __props.selected,
          "monsterinsights-card--disabled": __props.disabled
        }]),
        role: "button",
        "aria-pressed": __props.selectable ? !!__props.selected : void 0,
        disabled: __props.disabled,
        onClick,
        onKeydown: [
          withKeys(withModifiers(onClick, ["prevent"]), ["enter"]),
          withKeys(withModifiers(onClick, ["prevent"]), ["space"])
        ]
      }, {
        default: withCtx(() => [
          createBaseVNode("div", _hoisted_1$2, [
            renderSlot(_ctx.$slots, "media")
          ]),
          createBaseVNode("div", _hoisted_2$2, [
            renderSlot(_ctx.$slots, "header")
          ]),
          createBaseVNode("div", _hoisted_3$1, [
            renderSlot(_ctx.$slots, "body")
          ]),
          createBaseVNode("div", _hoisted_4$1, [
            renderSlot(_ctx.$slots, "footer")
          ])
        ]),
        _: 3
      }, 40, ["class", "aria-pressed", "disabled", "onKeydown"]);
    };
  }
};
const _hoisted_1$1 = { class: "monsterinsights-template-illustration" };
const _hoisted_2$1 = {
  key: 1,
  class: "monsterinsights-template-fallback"
};
const _sfc_main$1 = {
  __name: "TemplateCard",
  props: {
    template: { type: Object, required: true },
    selected: { type: Boolean, default: false }
  },
  emits: ["select"],
  setup(__props, { emit: __emit }) {
    return (_ctx, _cache) => {
      return openBlock(), createBlock(_sfc_main$2, {
        selected: __props.selected,
        onClick: _cache[0] || (_cache[0] = ($event) => _ctx.$emit("select", __props.template.id)),
        "aria-label": `${__props.template.title}. ${__props.template.description}`
      }, {
        media: withCtx(() => [
          createBaseVNode("div", _hoisted_1$1, [
            __props.template.icon ? (openBlock(), createBlock(Icon, {
              key: 0,
              name: __props.template.icon,
              width: 188,
              height: 104
            }, null, 8, ["name"])) : (openBlock(), createElementBlock("div", _hoisted_2$1, [
              createVNode(Icon, {
                name: unref(brandConfig).icons.logoFallback,
                width: 120,
                height: 60
              }, null, 8, ["name"])
            ]))
          ])
        ]),
        header: withCtx(() => [
          createTextVNode(toDisplayString(__props.template.title), 1)
        ]),
        body: withCtx(() => [
          createTextVNode(toDisplayString(__props.template.description), 1)
        ]),
        _: 1
      }, 8, ["selected", "aria-label"]);
    };
  }
};
const useTemplatesStore = defineStore("miTemplates", {
  state: () => ({
    // Template data
    templates: [],
    // UI state
    loading: false,
    error: null,
    selectedTemplateId: null,
    // Cache control
    lastFetch: null,
    cacheTimeout: 5 * 60 * 1e3
    // 5 minutes
  }),
  getters: {
    /**
     * Get all templates
     */
    all: (state) => state.templates,
    /**
     * Get currently selected template
     */
    selected: (state) => {
      return state.templates.find((t) => t.id === state.selectedTemplateId) || null;
    },
    /**
     * Get templates by type
     */
    getTemplatesByType: (state) => {
      return (type) => state.templates.filter((template) => template.type === type);
    },
    /**
     * Check if data needs refresh
     */
    needsRefresh: (state) => {
      if (!state.lastFetch) {
        return true;
      }
      return Date.now() - state.lastFetch > state.cacheTimeout;
    },
    /**
     * Get template by ID
     */
    getTemplateById: (state) => {
      return (id) => state.templates.find((template) => template.id === id);
    }
  },
  actions: {
    /**
     * Load templates from WordPress AJAX endpoint
     * @param {boolean} force - Force refresh even if cache is valid
     * @returns {Promise<Array>}
     */
    async loadTemplates(force = false) {
      if (!force && this.templates.length > 0 && !this.needsRefresh) {
        return this.templates;
      }
      this.loading = true;
      this.error = null;
      try {
        const templates = await this._fetchFromWordPress();
        this.templates = templates;
        this.lastFetch = Date.now();
        return templates;
      } catch (error) {
        this.error = error;
        throw error;
      } finally {
        this.loading = false;
      }
    },
    /**
     * Refresh templates (alias for loadTemplates with force=true)
     */
    async refreshTemplates() {
      return this.loadTemplates(true);
    },
    /**
     * Select a template by ID
     */
    selectTemplate(id) {
      this.selectedTemplateId = id;
    },
    /**
     * Clear selection
     */
    clearSelection() {
      this.selectedTemplateId = null;
    },
    /**
     * Clear templates cache
     */
    clearCache() {
      this.templates = [];
      this.lastFetch = null;
      this.error = null;
    },
    /**
     * Private: Fetch templates from WordPress AJAX
     * @private
     */
    async _fetchFromWordPress() {
      return new Promise((resolve, reject) => {
        const wpAjax = window?.wp?.ajax;
        const cfg = window.monsterinsights || {};
        const nonce = cfg.nonce;
        if (!wpAjax || typeof wpAjax.post !== "function") {
          const error = {
            title: __("Error", "google-analytics-for-wordpress"),
            message: __(
              "WordPress AJAX is not available",
              "google-analytics-for-wordpress"
            )
          };
          reject(error);
          return;
        }
        if (!nonce) {
          const error = {
            title: __("Security Error", "google-analytics-for-wordpress"),
            message: __(
              "Security nonce is missing",
              "google-analytics-for-wordpress"
            )
          };
          reject(error);
          return;
        }
        wpAjax.post("monsterinsights_get_custom_dashboard_templates", { nonce }).done((data) => {
          try {
            const templates = Array.isArray(data) ? data : [];
            const normalizedTemplates = templates.map(
              (template) => this._normalizeTemplate(template)
            );
            resolve(normalizedTemplates);
          } catch (_error) {
            reject({
              title: __("Data Error", "google-analytics-for-wordpress"),
              message: __(
                "Invalid response format",
                "google-analytics-for-wordpress"
              )
            });
          }
        }).fail((xhr) => {
          const errorMessage = this._extractErrorMessage(xhr);
          reject({
            title: __("Error Loading Templates", "google-analytics-for-wordpress"),
            message: errorMessage
          });
        });
      });
    },
    /**
     * Private: Normalize template data from WordPress
     * @private
     */
    _normalizeTemplate(template) {
      return {
        id: String(template.id || ""),
        title: String(template.title || ""),
        description: String(template.description || ""),
        icon: String(template.icon || ""),
        type: String(template.type || "general"),
        newNavName: String(template.newNavName || ""),
        isPro: Boolean(template.isPro || template.is_pro || false),
        layout: Array.isArray(template.layout) ? template.layout : []
      };
    },
    /**
     * Private: Extract error message from AJAX response
     * @private
     */
    _extractErrorMessage(xhr) {
      if (xhr?.responseJSON?.data?.message) {
        return xhr.responseJSON.data.message;
      }
      if (xhr?.responseJSON?.message) {
        return xhr.responseJSON.message;
      }
      if (xhr?.message) {
        return xhr.message;
      }
      if (xhr?.status) {
        switch (xhr.status) {
          case 403:
            return __(
              "You do not have permission to access this resource",
              "google-analytics-for-wordpress"
            );
          case 404:
            return __(
              "The requested resource was not found",
              "google-analytics-for-wordpress"
            );
          case 500:
            return __(
              "Internal server error occurred",
              "google-analytics-for-wordpress"
            );
          default:
            return __(
              "An unexpected error occurred",
              "google-analytics-for-wordpress"
            );
        }
      }
      return __("Failed to load templates", "google-analytics-for-wordpress");
    }
  }
});
const _hoisted_1 = { class: "monsterinsights-dashboard-list" };
const _hoisted_2 = {
  key: 0,
  class: "mi-app-loading"
};
const _hoisted_3 = {
  key: 0,
  class: "mi-loading-text"
};
const _hoisted_4 = { key: 1 };
const _hoisted_5 = { class: "monsterinsights-dashboard-list__header" };
const _hoisted_6 = { class: "monsterinsights-dashboard-list__content" };
const _hoisted_7 = {
  key: 0,
  class: "monsterinsights-dashboard-loading"
};
const _hoisted_8 = {
  key: 1,
  class: "monsterinsights-dashboard-error"
};
const _hoisted_9 = { key: 2 };
const _sfc_main = {
  __name: "DashboardList.pro",
  setup(__props) {
    const router = useRouter();
    const route = useRoute();
    const templatesStore = useTemplatesStore();
    const customViewsStore = useCustomViewsStore();
    const isCheckingViews = ref(true);
    const isLoading = ref(false);
    const loadingText = ref("");
    const isLoadingTemplates = computed(() => templatesStore.loading);
    const templates = computed(() => templatesStore.all);
    const selectedId = computed(() => templatesStore.selectedTemplateId);
    const error = computed(() => templatesStore.error || customViewsStore.error);
    const shouldShowTemplateSelector = computed(() => route.meta.showTemplateSelector === true);
    onMounted(async () => {
      showLoading(__("Loading...", "google-analytics-for-wordpress"));
      try {
        if (shouldShowTemplateSelector.value) {
          await templatesStore.loadTemplates();
          hideLoading();
          return;
        }
        const views = await customViewsStore.loadViews();
        if (views && views.length > 0) {
          router.push({
            name: "dashboard-view",
            params: { id: views[0].id }
          });
        } else {
          await templatesStore.loadTemplates();
        }
      } catch (err) {
      } finally {
        hideLoading();
      }
    });
    async function onSelect(id) {
      showLoading(__("Creating new dashboard...", "google-analytics-for-wordpress"));
      try {
        const template = templatesStore.getTemplateById(id);
        if (!template) {
          hideLoading();
          return;
        }
        const newView = await customViewsStore.createViewFromTemplate(template);
        if (newView && newView.id) {
          router.push({
            name: "dashboard-edit",
            params: { id: newView.id }
          });
        }
      } catch (error2) {
      } finally {
        hideLoading();
      }
    }
    function retryLoad() {
      if (customViewsStore.error) {
        customViewsStore.clearError();
      }
      if (templatesStore.error) {
        templatesStore.clearError();
      }
      isCheckingViews.value = true;
      onMounted();
    }
    function showLoading(text = "") {
      loadingText.value = text;
      isLoading.value = true;
    }
    function hideLoading() {
      isLoading.value = false;
      isCheckingViews.value = false;
    }
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock("div", _hoisted_1, [
        isLoading.value ? (openBlock(), createElementBlock("div", _hoisted_2, [
          _cache[0] || (_cache[0] = createBaseVNode("span", { class: "dashicons dashicons-update mi-spin" }, null, -1)),
          loadingText.value ? (openBlock(), createElementBlock("p", _hoisted_3, toDisplayString(loadingText.value), 1)) : createCommentVNode("", true)
        ])) : !isCheckingViews.value ? (openBlock(), createElementBlock("div", _hoisted_4, [
          createBaseVNode("div", _hoisted_5, [
            createBaseVNode("h1", null, toDisplayString(unref(__)("Create a New Dashboard", "google-analytics-for-wordpress")), 1)
          ]),
          createBaseVNode("div", _hoisted_6, [
            isLoadingTemplates.value ? (openBlock(), createElementBlock("div", _hoisted_7, [
              createBaseVNode("p", null, toDisplayString(unref(__)("Loading templates...", "google-analytics-for-wordpress")), 1)
            ])) : error.value ? (openBlock(), createElementBlock("div", _hoisted_8, [
              createBaseVNode("h3", null, toDisplayString(error.value.title), 1),
              createBaseVNode("p", null, toDisplayString(error.value.message), 1),
              createBaseVNode("button", {
                onClick: retryLoad,
                class: "button button-primary"
              }, toDisplayString(unref(__)("Try Again", "google-analytics-for-wordpress")), 1)
            ])) : (openBlock(), createElementBlock("div", _hoisted_9, [
              createVNode(_sfc_main$3, {
                "min-col-width": 300,
                gap: 20
              }, {
                default: withCtx(() => [
                  (openBlock(true), createElementBlock(Fragment, null, renderList(templates.value, (tpl) => {
                    return openBlock(), createBlock(_sfc_main$1, {
                      key: tpl.id,
                      template: tpl,
                      selected: tpl.id === selectedId.value,
                      onSelect
                    }, null, 8, ["template", "selected"]);
                  }), 128))
                ]),
                _: 1
              })
            ]))
          ])
        ])) : createCommentVNode("", true)
      ]);
    };
  }
};
const DashboardList_pro = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-078cb9c8"]]);
export {
  DashboardList_pro as default
};
