import { _ as __ } from "./default-i18n-KrIlCc2E.js";
import { S as _export_sfc, o as openBlock, c as createElementBlock, a as createBaseVNode, t as toDisplayString, v as createCommentVNode, L as renderSlot, F as Fragment } from "./AppOverlays-Dpqzx_3P.js";
const _hoisted_1 = ["aria-label"];
const _hoisted_2 = {
  key: 0,
  class: "mi-spinner-text"
};
const _sfc_main = {
  __name: "LoadingSpinnerInline",
  props: {
    label: { type: String, default: __("Loading", "google-analytics-for-wordpress") },
    text: { type: String, default: "" }
  },
  setup(__props) {
    return (_ctx, _cache) => {
      return openBlock(), createElementBlock(Fragment, null, [
        createBaseVNode("span", {
          class: "mi-spinner",
          "aria-label": __props.label,
          role: "status"
        }, null, 8, _hoisted_1),
        __props.text ? (openBlock(), createElementBlock("span", _hoisted_2, toDisplayString(__props.text), 1)) : createCommentVNode("", true),
        renderSlot(_ctx.$slots, "default", {}, void 0, true)
      ], 64);
    };
  }
};
const LoadingSpinnerInline = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-f987e014"]]);
export {
  LoadingSpinnerInline as L
};
